<?php

namespace App\Domain\Health;

use App\Domain\Health\Models\HealthFinding;
use App\Domain\Health\Models\HealthScan;
use App\Domain\Ops\ShopStats;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class HealthOverview
{
    public const CACHE_KEY = 'health.overview';

    public const CACHE_TTL = 30;

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->build());
        } catch (Throwable $e) {
            report($e);

            return $this->empty($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(): array
    {
        $latest = HealthScan::query()->where('status', 'completed')->latest('id')->first();
        $open = HealthFinding::query()->where('status', 'open')->get();
        $critical = $open->where('severity', 'critical')->count();
        $warning = $open->where('severity', 'warning')->count();
        $opportunity = $open->where('severity', 'opportunity')->count();
        $passed = HealthFinding::query()->where('status', 'passed')->count();

        $categories = [];
        foreach (RuleCatalog::CATEGORIES as $cat) {
            $score = (int) ($latest?->category_scores[$cat]['score'] ?? 0);
            $catOpen = $open->where('category', $cat)->count();
            $categories[] = [
                'id' => $cat,
                'title' => $this->categoryTitle($cat),
                'hint' => $this->categoryHint($cat),
                'score' => $latest ? $score : null,
                'label' => $latest ? RuleCatalog::scoreLabel($score) : 'Not scanned',
                'open' => $catOpen,
                'checks' => (int) ($latest?->category_scores[$cat]['checks'] ?? 0),
            ];
        }

        $prev = HealthScan::query()->where('status', 'completed')->latest('id')->skip(1)->first();
        $delta = null;
        if ($latest && $prev) {
            $delta = (int) $latest->overall_score - (int) $prev->overall_score;
        }

        $recent = $open->sortBy(fn ($f) => match ($f->severity) {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        })->take(8)->values()->map(fn (HealthFinding $f) => $this->presentFinding($f));

        return [
            'has_scan' => (bool) $latest,
            'score' => $latest ? (int) $latest->overall_score : null,
            'score_label' => $latest ? RuleCatalog::scoreLabel((int) $latest->overall_score) : 'Not scanned',
            'delta_30' => $delta,
            'critical' => $critical,
            'warning' => $warning,
            'opportunity' => $opportunity,
            'passed' => $passed,
            'categories' => $categories,
            'recent_issues' => $recent,
            'last_scan' => $latest ? [
                'id' => $latest->id,
                'at' => optional($latest->completed_at)?->toDateTimeString(),
                'pages' => (int) $latest->pages_checked,
                'assets' => (int) $latest->assets_checked,
                'type' => $latest->type,
                'comparison' => $latest->comparison,
            ] : null,
            'counts' => [
                'products' => ShopStats::estimate('products'),
                'categories' => ShopStats::estimate('categories'),
                'cms' => ShopStats::estimate('cms_pages'),
            ],
            'settings' => HealthSettings::all(),
            'live' => app(ScanEngine::class)->live(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentFinding(HealthFinding $f): array
    {
        $rule = RuleCatalog::get($f->rule_code);

        return [
            'id' => $f->id,
            'rule_code' => $f->rule_code,
            'name' => $rule['name'] ?? $f->title,
            'title' => $f->title,
            'category' => $f->category,
            'severity' => $f->severity,
            'status' => $f->status,
            'detail' => $f->detail,
            'impact' => $f->impact,
            'recommendation' => $f->recommendation,
            'affected_count' => (int) $f->affected_count,
            'samples' => $f->evidence['samples'] ?? [],
            'fix_capabilities' => $f->fix_capabilities ?? [],
            'resource_type' => $f->resource_type,
            'resource_id' => $f->resource_id,
            'resource_label' => $f->resource_label,
            'last_seen_at' => optional($f->last_seen_at)?->toDateTimeString(),
            'first_detected_at' => optional($f->first_detected_at)?->toDateTimeString(),
        ];
    }

    protected function categoryTitle(string $cat): string
    {
        return match ($cat) {
            'seo' => 'SEO',
            'content' => 'Content',
            'images' => 'Images & Media',
            'performance' => 'Performance',
            'technical' => 'Technical',
            'cleanup' => 'Store Cleanup',
            default => ucfirst($cat),
        };
    }

    protected function categoryHint(string $cat): string
    {
        return match ($cat) {
            'seo' => 'Search visibility, metadata, indexing and structured data.',
            'content' => 'Product and CMS content quality, completeness and duplication.',
            'images' => 'Image size, format, alt text and unused media.',
            'performance' => 'Page weight, scripts, styles, caching and frontend performance.',
            'technical' => 'URLs, redirects, HTTPS, sitemap and store configuration.',
            'cleanup' => 'Unused files, stale records and unnecessary store data.',
            default => '',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function empty(string $message = ''): array
    {
        return [
            'has_scan' => false,
            'score' => null,
            'score_label' => 'Unavailable',
            'delta_30' => null,
            'critical' => 0,
            'warning' => 0,
            'opportunity' => 0,
            'passed' => 0,
            'categories' => [],
            'recent_issues' => [],
            'last_scan' => null,
            'counts' => [],
            'settings' => HealthSettings::defaults(),
            'live' => null,
            'error' => $message,
        ];
    }
}
