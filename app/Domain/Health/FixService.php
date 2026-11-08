<?php

namespace App\Domain\Health;

use App\Domain\Health\Models\HealthAiProposal;
use App\Domain\Health\Models\HealthFinding;
use App\Domain\Health\Models\HealthFixJob;
use App\Domain\Seo\SeoFixer;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Maps health rule codes to existing SeoFixer jobs where available.
 */
final class FixService
{
    /** @var array<string, string> */
    public const RULE_TO_SEO_FIX = [
        'seo.missing_title' => 'fill_product_titles',
        'seo.title_too_long' => 'trim_product_titles',
        'seo.title_too_short' => 'expand_short_titles',
        'seo.duplicate_title' => 'unique_product_titles',
        'seo.missing_description' => 'fill_product_descriptions',
        'seo.description_too_short' => 'fill_product_descriptions',
        'seo.missing_canonical' => 'fill_product_canonicals',
        'seo.sitemap_off' => 'enable_sitemap',
        'seo.sitemap_stale' => 'write_robots_sitemap',
        'seo.cms_missing_title' => 'fill_cms_meta',
        'content.missing_description' => 'fill_thin_content',
        'content.thin_description' => 'fill_thin_content',
        'content.missing_keywords' => 'fill_product_keywords',
        'content.missing_focus' => 'fill_product_focus',
        'images.missing_alt' => 'fill_image_alts',
        'images.missing_cover' => 'assign_product_covers',
        'images.old_format' => 'enable_webp',
        'images.missing_derivatives' => 'optimize_images',
        'images.oversized' => 'optimize_images',
        'perf.webp_off' => 'enable_webp',
        'perf.page_weight_images' => 'optimize_images',
        'tech.og_image' => 'set_og_image',
        'tech.default_description' => 'set_default_description',
    ];

    public function autoFix(HealthFinding $finding): array
    {
        $fixId = self::RULE_TO_SEO_FIX[$finding->rule_code] ?? null;
        if (! $fixId) {
            throw new InvalidArgumentException('This issue cannot be fixed automatically.');
        }

        $admin = Auth::guard('admin')->user();
        $job = HealthFixJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'store_id' => app(StoreContext::class)->id() ?: null,
            'finding_id' => $finding->id,
            'rule_code' => $finding->rule_code,
            'fix_type' => 'AUTO_FIX',
            'status' => 'running',
            'created_by' => $admin instanceof Admin ? $admin->id : null,
            'started_at' => now(),
            'rollback_supported' => true,
        ]);

        try {
            $finding->update(['status' => 'fixing']);
            $result = app(SeoFixer::class)->apply($fixId);
            $job->update([
                'status' => 'completed',
                'completed_at' => now(),
                'applied_data' => $result,
            ]);
            $finding->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);
            HealthOverview::flush();

            return ['job' => $job->fresh(), 'result' => $result];
        } catch (Throwable $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
            $finding->update(['status' => 'open']);
            throw $e;
        }
    }

    public function ignore(HealthFinding $finding, string $mode = 'permanent'): HealthFinding
    {
        if ($mode === 'snooze_7') {
            $finding->update(['status' => 'snoozed', 'snoozed_until' => now()->addDays(7), 'ignored_at' => now()]);
        } elseif ($mode === 'snooze_30') {
            $finding->update(['status' => 'snoozed', 'snoozed_until' => now()->addDays(30), 'ignored_at' => now()]);
        } else {
            $finding->update(['status' => 'accepted_risk', 'ignored_at' => now()]);
        }
        HealthOverview::flush();

        return $finding->fresh() ?? $finding;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fixCenterGroups(): array
    {
        $open = HealthFinding::query()->where('status', 'open')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->get();
        $quick = $ai = $bulk = $manual = [];
        foreach ($open as $f) {
            $caps = $f->fix_capabilities ?? [];
            $row = app(HealthOverview::class)->presentFinding($f);
            if (array_intersect(['AUTO_FIX'], $caps)) {
                $quick[] = $row;
            } elseif (array_intersect(['AI_FIX', 'AI_BULK_FIX'], $caps)) {
                $ai[] = $row;
            } elseif (array_intersect(['BULK_FIX'], $caps)) {
                $bulk[] = $row;
            } else {
                $manual[] = $row;
            }
        }

        return [
            'quick' => $quick,
            'ai' => $ai,
            'bulk' => $bulk,
            'manual' => $manual,
        ];
    }
}
