<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerFlag;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\SellerFlagSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags reserved for when seller reporting lands (Wave 305+).
 */
class SellerAccountHealthService
{
    public const BAND_HEALTHY = 'healthy';

    public const BAND_AT_RISK = 'at_risk';

    public const BAND_UNHEALTHY = 'unhealthy';

    public const BAND_NOT_CALCULATED = 'not_calculated';

    public const METRIC_GOOD = 'good';

    public const METRIC_NEEDS_ATTENTION = 'needs_attention';

    public const METRIC_CRITICAL = 'critical';

    public const METRIC_NO_DATA = 'no_data';

    public function __construct(
        protected ?float $cancelWarning = null,
        protected ?float $cancelCritical = null,
    ) {
        $this->cancelWarning ??= MarketplaceSettings::cancelWarningRate();
        $this->cancelCritical ??= MarketplaceSettings::cancelCriticalRate();
    }

    public static function ensureColumns(): void
    {
        if (! Schema::hasTable('sellers')) {
            return;
        }
        if (! Schema::hasColumn('sellers', 'health_score')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->unsignedTinyInteger('health_score')->nullable();
                $table->string('health_band', 32)->nullable();
                $table->decimal('health_cancellation_rate', 8, 4)->nullable();
                $table->decimal('health_avg_rating', 3, 2)->nullable();
                $table->unsignedInteger('health_flag_count')->nullable();
                $table->timestamp('health_refreshed_at')->nullable();
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(Seller $seller, bool $persist = true): array
    {
        $payload = $this->calculate($seller);
        if ($persist) {
            self::ensureColumns();
            $seller->forceFill([
                'health_score' => $payload['score'],
                'health_band' => $payload['band'],
                'health_cancellation_rate' => $payload['metrics']['cancellation']['rate'],
                'health_avg_rating' => $payload['metrics']['feedback']['average'],
                'health_flag_count' => $payload['metrics']['flags']['count'],
                'health_refreshed_at' => now(),
            ])->save();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculate(Seller $seller): array
    {
        $cancellation = $this->cancellationMetric($seller);
        $feedback = $this->feedbackMetric($seller);
        $flags = $this->flagsMetric($seller);

        $active = array_values(array_filter([
            $cancellation,
            $feedback['status'] === self::METRIC_NO_DATA ? null : $feedback,
            $flags['enabled'] ? $flags : null,
        ]));

        $share = count($active) > 0 ? (100 / count($active)) : 100;
        $score = 0.0;
        foreach ($active as $metric) {
            $score += match ($metric['status']) {
                self::METRIC_GOOD => $share,
                self::METRIC_NEEDS_ATTENTION => $share * 0.5,
                self::METRIC_CRITICAL => 0.0,
                default => $share * 0.75,
            };
        }
        $score = (int) round(min(100, max(0, $score)));

        $worst = self::METRIC_GOOD;
        foreach ($active as $metric) {
            if ($metric['status'] === self::METRIC_CRITICAL) {
                $worst = self::METRIC_CRITICAL;
                break;
            }
            if ($metric['status'] === self::METRIC_NEEDS_ATTENTION) {
                $worst = self::METRIC_NEEDS_ATTENTION;
            }
        }

        $band = match ($worst) {
            self::METRIC_CRITICAL => self::BAND_UNHEALTHY,
            self::METRIC_NEEDS_ATTENTION => self::BAND_AT_RISK,
            default => match (true) {
                $score >= 67 => self::BAND_HEALTHY,
                $score >= 34 => self::BAND_AT_RISK,
                default => self::BAND_UNHEALTHY,
            },
        };

        return [
            'sellerId' => (int) $seller->id,
            'shopName' => (string) $seller->shop_name,
            'score' => $score,
            'band' => $band,
            'overall' => $band,
            'metrics' => [
                'cancellation' => $cancellation,
                'feedback' => $feedback,
                'flags' => $flags,
            ],
            'refreshedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cancellationMetric(Seller $seller): array
    {
        $since = now()->subDays(30);
        $total = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->where('created_at', '>=', $since)
            ->count();
        $cancelled = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->where('created_at', '>=', $since)
            ->where('status', 'cancelled')
            ->count();

        if ($total === 0) {
            return [
                'key' => 'cancellation',
                'label' => 'Order Cancellation Rate',
                'rate' => null,
                'total' => 0,
                'cancelled' => 0,
                'status' => self::METRIC_NO_DATA,
            ];
        }

        $rate = round(($cancelled / $total) * 100, 4);
        $status = match (true) {
            $rate > $this->cancelCritical => self::METRIC_CRITICAL,
            $rate >= $this->cancelWarning => self::METRIC_NEEDS_ATTENTION,
            default => self::METRIC_GOOD,
        };

        return [
            'key' => 'cancellation',
            'label' => 'Order Cancellation Rate',
            'rate' => $rate,
            'total' => $total,
            'cancelled' => $cancelled,
            'status' => $status,
            'warningAt' => $this->cancelWarning,
            'criticalAt' => $this->cancelCritical,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function feedbackMetric(Seller $seller): array
    {
        $since = now()->subDays(90);
        $q = SellerReview::query()
            ->where('seller_id', $seller->id)
            ->where('status', SellerReview::STATUS_APPROVED)
            ->where('created_at', '>=', $since);
        $count = (clone $q)->count();
        if ($count === 0) {
            return [
                'key' => 'feedback',
                'label' => 'Customer Feedback',
                'average' => null,
                'count' => 0,
                'status' => self::METRIC_NO_DATA,
            ];
        }
        $avg = round((float) $q->avg('rating'), 2);
        $status = match (true) {
            $avg >= 4.0 => self::METRIC_GOOD,
            $avg >= 3.0 => self::METRIC_NEEDS_ATTENTION,
            default => self::METRIC_CRITICAL,
        };

        return [
            'key' => 'feedback',
            'label' => 'Customer Feedback',
            'average' => $avg,
            'count' => $count,
            'status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function flagsMetric(Seller $seller): array
    {
        if (! MarketplaceSettings::sellerFlagsEnabled()) {
            return [
                'key' => 'flags',
                'label' => 'Flags',
                'count' => 0,
                'enabled' => false,
                'status' => self::METRIC_NO_DATA,
            ];
        }
        SellerFlagSchema::ensure();
        $since = now()->subDays(90);
        $count = SellerFlag::query()
            ->where('seller_id', $seller->id)
            ->whereIn('status', [SellerFlag::STATUS_PENDING, SellerFlag::STATUS_ACCEPTED])
            ->where('created_at', '>=', $since)
            ->count();
        $limit = MarketplaceSettings::redFlagLimit();
        if ($count === 0) {
            return [
                'key' => 'flags',
                'label' => 'Flags',
                'count' => 0,
                'enabled' => true,
                'limit' => $limit,
                'status' => self::METRIC_GOOD,
            ];
        }
        $third = max(1, (int) ceil($limit / 3));
        $twoThirds = max(1, (int) ceil(($limit * 2) / 3));
        $status = match (true) {
            $count > $twoThirds => self::METRIC_CRITICAL,
            $count > $third => self::METRIC_NEEDS_ATTENTION,
            default => self::METRIC_NEEDS_ATTENTION, // any flag above 0 needs attention at limit 3
        };

        return [
            'key' => 'flags',
            'label' => 'Flags',
            'count' => $count,
            'enabled' => true,
            'limit' => $limit,
            'status' => $status,
        ];
    }
}
