<?php

namespace App\Domain\Seo;

use App\Domain\Seo\ShopAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

class SeoFixLive
{
    public const CACHE_KEY = 'seo_fix.run';

    public const CACHE_TTL = 3600;

    public function wants(Request $request): bool
    {
        if ($request->boolean('live')) {
            return true;
        }

        return str_contains(strtolower((string) $request->header('Accept')), 'text/event-stream');
    }

    public function start(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:80'],
            'jobs' => ['nullable', 'array', 'max:40'],
            'jobs.*.id' => ['required_with:jobs', 'string', 'max:80'],
            'jobs.*.title' => ['nullable', 'string', 'max:180'],
            'jobs.*.count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $request->session()->save();

        return response()->json([
            'run' => $this->begin($data['id'], $this->normalizeJobs($data['jobs'] ?? [], $data['id']), $source),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json(['run' => $this->snapshot()]);
    }

    /**
     * Slim snapshot for the admin header. Hides finished runs after two minutes.
     *
     * @return array<string, mixed>|null
     */
    public function chrome(): ?array
    {
        $run = $this->snapshot(true);
        if ($run === null) {
            return null;
        }
        unset($run['payload']);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return array<string, mixed>
     */
    public function begin(string $id, array $jobs, string $source, ?bool $runNow = null): array
    {
        $existing = $this->snapshot();
        if ($existing && in_array($existing['status'] ?? '', ['queued', 'running'], true)) {
            if (($existing['status'] ?? '') === 'queued') {
                $this->spawn((string) $existing['id']);
            }

            return $this->snapshot() ?? $existing;
        }

        $jobs = $this->normalizeJobs($jobs, $id);
        $runId = (string) Str::uuid();
        $this->put($this->decorate([
            'id' => $runId,
            'source' => $source === 'seo' ? 'seo' : 'health',
            'fix_id' => $id,
            'status' => 'queued',
            'running' => true,
            'complete' => false,
            'error' => '',
            'steps' => array_map(fn (array $job) => [
                'id' => $job['id'],
                'title' => $job['title'],
                'count' => $job['count'],
                'total' => $job['count'],
                'done' => 0,
                'status' => 'queued',
                'message' => 'Queued',
            ], $jobs),
            'total' => max(1, count($jobs)),
            'fixed' => 0,
            'message' => '',
            'payload' => null,
            'href' => $source === 'seo' ? '/admin/seo' : '/admin/health',
            'updated_at' => now()->toIso8601String(),
        ]));

        $runNow ??= app()->runningUnitTests();
        if ($runNow) {
            $this->execute($runId);
        } else {
            $this->spawn($runId);
        }

        return $this->snapshot() ?? [];
    }

    public function execute(string $runId): void
    {
        $run = Cache::get(self::CACHE_KEY);
        if (! is_array($run) || (string) ($run['id'] ?? '') !== $runId) {
            return;
        }
        if (in_array($run['status'] ?? '', ['complete', 'error', 'running'], true)) {
            return;
        }

        $this->patch(['status' => 'running', 'running' => true, 'complete' => false]);
        $fixer = app(SeoFixer::class);
        $jobs = array_map(fn (array $step) => [
            'id' => $step['id'],
            'title' => $step['title'] ?? $step['id'],
            'count' => (int) ($step['count'] ?? $step['total'] ?? 0),
        ], $run['steps'] ?? []);
        $id = (string) ($run['fix_id'] ?? 'all');

        $send = function (array $event) {
            $this->applyEvent($event);
        };

        try {
            $send([
                'type' => 'start',
                'id' => $id,
                'total_steps' => max(1, count($jobs)),
                'steps' => $jobs,
            ]);

            $result = $id === 'all'
                ? $fixer->applyAll($send, $jobs)
                : $fixer->apply($id, true, $send, (int) ($jobs[0]['count'] ?? 0));

            $this->complete((int) ($result['fixed'] ?? 0), (string) ($result['message'] ?? ''), (string) ($run['source'] ?? 'health'));
        } catch (InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail('A fix step failed. Try again on the remaining issues.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(bool $chrome = false): ?array
    {
        $run = Cache::get(self::CACHE_KEY);
        if (! is_array($run) || ($run['id'] ?? '') === '') {
            return null;
        }
        $run = $this->decorate($run);
        if (! $chrome) {
            return $run;
        }
        $status = (string) ($run['status'] ?? '');
        if (! in_array($status, ['queued', 'running', 'complete', 'error'], true)) {
            return null;
        }
        if (in_array($status, ['complete', 'error'], true)) {
            $at = (string) ($run['updated_at'] ?? '');
            if ($at !== '' && Carbon::parse($at)->lt(now()->subMinutes(2))) {
                return null;
            }
        }

        return $run;
    }

    /**
     * Detached `php artisan wento:seo-fix` command. Windows `start` treats the
     * first quoted token as a window title, so PHP_BINARY must not be first.
     */
    public function backgroundCommand(string $runId, ?string $osFamily = null): string
    {
        $osFamily ??= PHP_OS_FAMILY;
        $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/seo-fix.log');

        if ($osFamily === 'Windows') {
            $inner = sprintf(
                '%s %s wento:seo-fix %s >> %s 2>&1',
                $this->winArg($php),
                $this->winArg($artisan),
                $this->winArg($runId),
                $this->winArg($log),
            );

            return 'start /B "" cmd /c '.$this->winArg($inner);
        }

        return sprintf(
            'nohup %s %s wento:seo-fix %s >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($runId),
            escapeshellarg($log),
        );
    }

    protected function spawn(string $runId): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        File::ensureDirectoryExists(storage_path('logs'));
        $cmd = $this->backgroundCommand($runId);
        $started = false;

        try {
            $cwd = getcwd() ?: base_path();
            chdir(base_path());
            try {
                if (PHP_OS_FAMILY === 'Windows') {
                    $handle = popen($cmd, 'r');
                    $started = is_resource($handle);
                    if ($started) {
                        pclose($handle);
                    }
                } else {
                    $pid = trim((string) shell_exec($cmd));
                    $started = $pid !== '';
                }
            } finally {
                chdir($cwd);
            }
            if (! $started) {
                throw new \RuntimeException('Could not start the SEO fix worker.');
            }
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }
        }

        app()->terminating(function () use ($runId) {
            app(self::class)->execute($runId);
        });
    }

    protected function winArg(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    protected function complete(int $fixed, string $message, string $source): void
    {
        ShopAudit::flush();
        $payload = [
            'type' => 'complete',
            'fixed' => $fixed,
            'message' => $message,
            'audit' => app(ShopAudit::class)->report(),
            'source' => $source,
        ];

        $run = Cache::get(self::CACHE_KEY);
        $steps = is_array($run) ? ($run['steps'] ?? []) : [];
        foreach ($steps as $i => $step) {
            if (in_array($step['status'] ?? '', ['queued', 'running'], true)) {
                $steps[$i]['status'] = 'done';
            }
        }

        $this->put($this->decorate(array_merge(is_array($run) ? $run : [], [
            'status' => 'complete',
            'running' => false,
            'complete' => true,
            'error' => '',
            'fixed' => $fixed,
            'message' => $message,
            'payload' => $payload,
            'steps' => $steps,
            'updated_at' => now()->toIso8601String(),
        ])));
    }

    protected function fail(string $message): void
    {
        $run = Cache::get(self::CACHE_KEY);
        $steps = is_array($run) ? ($run['steps'] ?? []) : [];
        foreach ($steps as $i => $step) {
            if (($step['status'] ?? '') === 'running') {
                $steps[$i]['status'] = 'error';
                $steps[$i]['message'] = $message;
            }
        }
        $this->put($this->decorate(array_merge(is_array($run) ? $run : [], [
            'status' => 'error',
            'running' => false,
            'complete' => false,
            'error' => $message,
            'steps' => $steps,
            'updated_at' => now()->toIso8601String(),
        ])));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function applyEvent(array $event): void
    {
        $run = Cache::get(self::CACHE_KEY);
        if (! is_array($run)) {
            return;
        }
        $type = (string) ($event['type'] ?? '');
        $id = (string) ($event['id'] ?? '');
        $steps = $run['steps'] ?? [];

        if ($type === 'start') {
            $incoming = $event['steps'] ?? [];
            if (is_array($incoming) && $incoming !== []) {
                $steps = array_map(fn (array $step) => [
                    'id' => $step['id'],
                    'title' => $step['title'] ?? $step['id'],
                    'count' => (int) ($step['count'] ?? 0),
                    'total' => (int) ($step['count'] ?? 0),
                    'done' => 0,
                    'status' => 'queued',
                    'message' => '',
                ], $incoming);
            }
            $run['status'] = 'running';
            $run['running'] = true;
            $run['complete'] = false;
            $run['error'] = '';
            $run['total'] = (int) ($event['total_steps'] ?? count($steps) ?: 1);
            $run['steps'] = $steps;
        } elseif ($type === 'step_start') {
            $run['steps'] = $this->patchStep($steps, $id, [
                'status' => 'running',
                'message' => (string) ($event['message'] ?? 'Started.'),
                'total' => (int) ($event['total'] ?? $this->stepTotal($steps, $id)),
            ]);
        } elseif ($type === 'progress') {
            $run['steps'] = $this->patchStep($steps, $id, [
                'status' => 'running',
                'done' => (int) ($event['done'] ?? 0),
                'total' => (int) ($event['total'] ?? 0),
                'message' => (string) ($event['message'] ?? ''),
            ]);
        } elseif ($type === 'step_done') {
            $run['steps'] = $this->patchStep($steps, $id, [
                'status' => 'done',
                'done' => (int) ($event['fixed'] ?? $event['done'] ?? 0),
                'message' => (string) ($event['message'] ?? 'Fixed'),
            ]);
        }

        $run['updated_at'] = now()->toIso8601String();
        $this->put($this->decorate($run));
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    protected function patchStep(array $steps, string $id, array $extra): array
    {
        foreach ($steps as $i => $step) {
            if (($step['id'] ?? '') === $id) {
                $steps[$i] = array_merge($step, $extra);
            }
        }

        return $steps;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    protected function stepTotal(array $steps, string $id): int
    {
        foreach ($steps as $step) {
            if (($step['id'] ?? '') === $id) {
                return (int) ($step['total'] ?? $step['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    protected function put(array $run): void
    {
        Cache::put(self::CACHE_KEY, $run, self::CACHE_TTL);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function patch(array $extra): void
    {
        $run = Cache::get(self::CACHE_KEY);
        if (! is_array($run)) {
            return;
        }
        $this->put($this->decorate(array_merge($run, $extra, ['updated_at' => now()->toIso8601String()])));
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    protected function decorate(array $run): array
    {
        $steps = $run['steps'] ?? [];
        $total = max(1, (int) ($run['total'] ?? count($steps) ?: 1));
        $finished = 0;
        $partial = 0.0;
        $current = null;
        foreach ($steps as $step) {
            $status = (string) ($step['status'] ?? '');
            if ($status === 'done' || $status === 'error') {
                $finished++;
            } elseif ($status === 'running') {
                $current = $step;
                $stepTotal = (int) ($step['total'] ?? 0);
                if ($stepTotal > 0) {
                    $partial = min(1, ((int) ($step['done'] ?? 0)) / $stepTotal);
                }
            }
        }
        $run['steps'] = $steps;
        $run['total'] = $total;
        $run['percent'] = (int) min(100, round((($finished + $partial) / $total) * 100));
        $run['current'] = $current;
        $run['running'] = in_array($run['status'] ?? '', ['queued', 'running'], true);
        $run['complete'] = ($run['status'] ?? '') === 'complete';
        $run['href'] = $run['href'] ?? ((($run['source'] ?? '') === 'seo') ? '/admin/seo' : '/admin/health');

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array{id: string, title: string, count: int}>
     */
    protected function normalizeJobs(array $jobs, string $id): array
    {
        $out = [];
        $seen = [];
        foreach ($jobs as $job) {
            $fix = trim((string) ($job['id'] ?? ''));
            if ($fix === '' || isset($seen[$fix])) {
                continue;
            }
            $seen[$fix] = true;
            $out[] = [
                'id' => $fix,
                'title' => trim((string) ($job['title'] ?? $fix)) ?: $fix,
                'count' => max(0, (int) ($job['count'] ?? 0)),
            ];
        }
        if ($out !== []) {
            return $out;
        }
        if ($id !== 'all') {
            return [['id' => $id, 'title' => $id, 'count' => 0]];
        }

        return [];
    }
}
