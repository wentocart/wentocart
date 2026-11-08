<?php

namespace App\Domain\Health;

use App\Domain\Health\Models\HealthFinding;
use App\Domain\Health\Models\HealthScan;
use App\Domain\Ops\ShopStats;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

final class ScanEngine
{
    public const LIVE_KEY = 'health.scan.live';

    public function __construct(
        protected HealthAuditor $auditor,
        protected ScoreService $scores,
    ) {}

    public function start(string $type = 'full', bool $inline = false): HealthScan
    {
        $running = HealthScan::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($running) {
            // Stuck "queued" (Windows spawn often fails) — kick it again.
            if ($running->status === 'queued' && ! $inline) {
                $this->spawn($running->id);
            } elseif ($running->status === 'queued' && $inline) {
                $this->execute($running->id);
            }

            return $running->fresh() ?? $running;
        }

        $admin = Auth::guard('admin')->user();
        $scan = HealthScan::query()->create([
            'uuid' => (string) Str::uuid(),
            'store_id' => app(StoreContext::class)->id() ?: null,
            'type' => $type,
            'status' => 'queued',
            'initiated_by' => $admin instanceof Admin ? $admin->id : null,
            'pages_total' => (int) ShopStats::estimate('url_rewrites'),
            'current_step' => 'Queued',
        ]);

        if ($inline) {
            $this->execute($scan->id);

            return $scan->fresh() ?? $scan;
        }

        $this->spawn($scan->id);

        return $scan;
    }

    public function execute(int $scanId): HealthScan
    {
        $scan = HealthScan::query()->findOrFail($scanId);
        if (in_array($scan->status, ['completed', 'failed'], true)) {
            return $scan;
        }
        if ($scan->status === 'running' && $scan->started_at && $scan->started_at->gt(now()->subMinutes(15))) {
            return $scan;
        }

        $scan->update([
            'status' => 'running',
            'started_at' => now(),
            'current_step' => 'Starting scan…',
        ]);
        $this->publishLive($scan);

        try {
            $previous = HealthScan::query()
                ->where('status', 'completed')
                ->where('id', '!=', $scan->id)
                ->latest('id')
                ->first();

            $hits = $this->auditor->evaluate(function (string $step) use ($scan) {
                $scan->update(['current_step' => $step]);
                $this->publishLive($scan->fresh() ?? $scan);
            });

            $score = $this->scores->fromHits($hits);
            $this->persistFindings($scan, $hits);

            $comparison = null;
            if ($previous) {
                $comparison = [
                    'previous_score' => (int) $previous->overall_score,
                    'current_score' => $score['overall'],
                    'delta' => $score['overall'] - (int) $previous->overall_score,
                    'previous_critical' => (int) $previous->critical_count,
                    'previous_warning' => (int) $previous->warning_count,
                ];
            }

            $scan->update([
                'status' => 'completed',
                'completed_at' => now(),
                'current_step' => 'Scan complete',
                'products_checked' => (int) ShopStats::estimate('products'),
                'images_checked' => (int) ShopStats::estimate('products'),
                'assets_checked' => (int) ($score['passed'] + $score['critical'] + $score['warning'] + $score['opportunity']),
                'pages_checked' => (int) $scan->pages_total,
                'issues_found' => $score['critical'] + $score['warning'] + $score['opportunity'],
                'critical_count' => $score['critical'],
                'warning_count' => $score['warning'],
                'opportunity_count' => $score['opportunity'],
                'passed_count' => $score['passed'],
                'overall_score' => $score['overall'],
                'category_scores' => $score['categories'],
                'comparison' => $comparison,
                'error' => null,
            ]);
            Cache::forget(self::LIVE_KEY);
            HealthOverview::flush();

            return $scan->fresh() ?? $scan;
        } catch (Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $e->getMessage(),
                'current_step' => 'Scan failed',
            ]);
            Cache::forget(self::LIVE_KEY);
            throw $e;
        }
    }

    /**
     * Detached `php artisan wento:health-scan`. On Windows, `start` treats the
     * first quoted token as a window title — same pitfall as SeoFixLive.
     */
    public function backgroundCommand(int $scanId, ?string $osFamily = null): string
    {
        $osFamily ??= PHP_OS_FAMILY;
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/health-scan.log');

        if ($osFamily === 'Windows') {
            $inner = sprintf(
                '%s %s wento:health-scan %d >> %s 2>&1',
                $this->winArg($php),
                $this->winArg($artisan),
                $scanId,
                $this->winArg($log),
            );

            return 'start /B "" cmd /c '.$this->winArg($inner);
        }

        return sprintf(
            'nohup %s %s wento:health-scan %d >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg($artisan),
            $scanId,
            escapeshellarg($log),
        );
    }

    public function spawn(int $scanId): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        File::ensureDirectoryExists(storage_path('logs'));
        $cmd = $this->backgroundCommand($scanId);

        try {
            $cwd = getcwd() ?: base_path();
            chdir(base_path());
            try {
                if (PHP_OS_FAMILY === 'Windows') {
                    $handle = @popen($cmd, 'r');
                    if (is_resource($handle)) {
                        pclose($handle);
                    }
                } else {
                    shell_exec($cmd);
                }
            } finally {
                chdir($cwd);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Fallback: if the detached process never starts (common on Windows
        // built-in PHP / Install.vbs), finish after the HTTP response.
        app()->terminating(function () use ($scanId) {
            $scan = HealthScan::query()->find($scanId);
            if (! $scan || $scan->status !== 'queued') {
                return;
            }
            try {
                $this->execute($scanId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    protected function winArg(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     */
    protected function persistFindings(HealthScan $scan, array $hits): void
    {
        $seen = [];
        $now = now();
        foreach ($hits as $hit) {
            if (! empty($hit['passed'])) {
                continue;
            }
            $code = (string) $hit['code'];
            $seen[] = $code;
            $existing = HealthFinding::query()
                ->where('rule_code', $code)
                ->whereIn('status', ['open', 'snoozed', 'accepted_risk'])
                ->whereNull('resource_id')
                ->first();

            $payload = [
                'store_id' => $scan->store_id,
                'scan_id' => $scan->id,
                'rule_code' => $code,
                'category' => RuleCatalog::get($code)['category'] ?? 'technical',
                'severity' => (string) $hit['severity'],
                'status' => $existing && $existing->status === 'accepted_risk' ? 'accepted_risk' : 'open',
                'resource_type' => $hit['resource_type'] ?? 'STORE',
                'title' => (string) $hit['title'],
                'detail' => (string) ($hit['detail'] ?? ''),
                'impact' => (string) ($hit['impact'] ?? ''),
                'recommendation' => (string) ($hit['recommendation'] ?? ''),
                'evidence' => ['samples' => $hit['samples'] ?? []],
                'affected_count' => (int) ($hit['affected_count'] ?? 0),
                'fix_capabilities' => $hit['fix'] ?? ['MANUAL'],
                'last_seen_at' => $now,
            ];

            if ($existing) {
                if ($existing->snoozed_until && $existing->snoozed_until->isFuture()) {
                    $payload['status'] = 'snoozed';
                }
                $existing->update($payload);
            } else {
                $payload['first_detected_at'] = $now;
                HealthFinding::query()->create($payload);
            }
        }

        HealthFinding::query()
            ->where('status', 'open')
            ->whereNotIn('rule_code', $seen)
            ->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

        foreach ($hits as $hit) {
            if (empty($hit['passed'])) {
                continue;
            }
            $code = (string) $hit['code'];
            HealthFinding::query()->updateOrCreate(
                [
                    'rule_code' => $code,
                    'resource_id' => null,
                    'status' => 'passed',
                ],
                [
                    'store_id' => $scan->store_id,
                    'scan_id' => $scan->id,
                    'category' => RuleCatalog::get($code)['category'] ?? 'technical',
                    'severity' => 'passed',
                    'resource_type' => $hit['resource_type'] ?? 'STORE',
                    'title' => (string) $hit['title'],
                    'detail' => (string) ($hit['detail'] ?? ''),
                    'impact' => (string) ($hit['impact'] ?? ''),
                    'recommendation' => (string) ($hit['recommendation'] ?? ''),
                    'affected_count' => 0,
                    'fix_capabilities' => $hit['fix'] ?? [],
                    'first_detected_at' => $now,
                    'last_seen_at' => $now,
                    'resolved_at' => $now,
                ]
            );
        }
    }

    protected function publishLive(HealthScan $scan): void
    {
        Cache::put(self::LIVE_KEY, [
            'id' => $scan->id,
            'uuid' => $scan->uuid,
            'status' => $scan->status,
            'type' => $scan->type,
            'current_step' => $scan->current_step,
            'pages_total' => $scan->pages_total,
            'pages_checked' => $scan->pages_checked,
            'products_checked' => $scan->products_checked,
            'images_checked' => $scan->images_checked,
            'overall_score' => $scan->overall_score,
            'percent' => $scan->status === 'completed' ? 100 : ($scan->status === 'running' ? 55 : 5),
        ], 3600);
    }

    public function live(): ?array
    {
        $cached = Cache::get(self::LIVE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $scan = HealthScan::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();

        return $scan ? [
            'id' => $scan->id,
            'uuid' => $scan->uuid,
            'status' => $scan->status,
            'current_step' => $scan->current_step,
            'percent' => 10,
        ] : null;
    }
}
