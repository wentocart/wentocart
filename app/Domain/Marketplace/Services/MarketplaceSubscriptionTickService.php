<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Settings\ShopMailer;
use Throwable;

/**
 */
class MarketplaceSubscriptionTickService
{
    /** reminder windows: 9, 7, 3 and 1 days before end. */
    public const REMINDER_DAYS = [9, 7, 3, 1];

    /**
     * @return array{expired: int, reminders: int, health: int, promoted: int}
     */
    public function run(bool $refreshHealth = true): array
    {
        MarketplaceSubscriptionPlanSchema::ensure();
        $expired = 0;
        $reminders = 0;
        $promoted = 0;

        if (MarketplaceSettings::subscriptionPlansEnabled()) {
            $promoted = app(SellerPlanActivationService::class)->promoteDueScheduled();

            $due = MarketplaceSellerSubscription::query()
                ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', now())
                ->get();

            foreach ($due as $sub) {
                $sub->update(['status' => MarketplaceSellerSubscription::STATUS_EXPIRED]);
                $this->notifyExpired($sub->fresh(['seller', 'plan']));
                $expired++;
            }

            $active = MarketplaceSellerSubscription::query()
                ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
                ->whereNotNull('ends_at')
                ->where('ends_at', '>', now())
                ->where('ends_at', '<=', now()->addDays(9))
                ->get();

            foreach ($active as $sub) {
                $daysLeft = (int) now()->startOfDay()->diffInDays($sub->ends_at->copy()->startOfDay(), false);
                if ($daysLeft < 0) {
                    continue;
                }
                if (! in_array($daysLeft, self::REMINDER_DAYS, true)) {
                    continue;
                }
                $sent = is_array($sub->reminder_days_sent) ? $sub->reminder_days_sent : [];
                if (in_array($daysLeft, $sent, true)) {
                    continue;
                }
                $sent[] = $daysLeft;
                $sub->update(['reminder_days_sent' => array_values(array_unique($sent))]);
                $this->notifyExpiryReminder($sub->fresh(['seller', 'plan']), $daysLeft);
                $reminders++;
            }
        }

        $health = 0;
        if ($refreshHealth) {
            SellerAccountHealthService::ensureColumns();
            $service = app(SellerAccountHealthService::class);
            Seller::query()
                ->where('status', Seller::STATUS_APPROVED)
                ->orderBy('id')
                ->chunkById(100, function ($sellers) use ($service, &$health) {
                    foreach ($sellers as $seller) {
                        $service->refresh($seller, true);
                        $health++;
                    }
                });
        }

        return ['expired' => $expired, 'reminders' => $reminders, 'health' => $health, 'promoted' => $promoted];
    }

    private function notifyExpiryReminder(MarketplaceSellerSubscription $sub, int $daysLeft): void
    {
        $email = (string) ($sub->seller?->email ?? $sub->seller?->contact_email ?? '');
        if ($email === '') {
            return;
        }
        try {
            app(ShopMailer::class)->sendEvent('marketplace.subscription.expires_soon', [
                'days' => $daysLeft,
                'plan' => (string) ($sub->plan?->name ?? ''),
                'shop' => (string) ($sub->seller?->shop_name ?? ''),
                'ends_at' => optional($sub->ends_at)?->toDateString(),
            ], $email);
        } catch (Throwable) {
            // Reminder tracking still advances; mail failures must not block the tick.
        }
    }

    private function notifyExpired(MarketplaceSellerSubscription $sub): void
    {
        $email = (string) ($sub->seller?->email ?? $sub->seller?->contact_email ?? '');
        if ($email === '') {
            return;
        }
        try {
            app(ShopMailer::class)->sendEvent('marketplace.subscription.expired', [
                'plan' => (string) ($sub->plan?->name ?? ''),
                'shop' => (string) ($sub->seller?->shop_name ?? ''),
                'ends_at' => optional($sub->ends_at)?->toDateString(),
            ], $email);
        } catch (Throwable) {
            // Expiration must not block on mail.
        }
    }
}
