<?php

namespace App\Domain\Settings;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RequestProfiler
{
    /** @var list<array{sql: string, time: float, bindings: list<mixed>}> */
    public array $queries = [];

    public float $startedAt = 0;

    protected static bool $listening = false;

    public function start(): void
    {
        $this->queries = [];
        $this->startedAt = microtime(true);
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        DB::listen(function ($query) {
            $profiler = app(self::class);
            if (count($profiler->queries) >= 80) {
                return;
            }
            $profiler->queries[] = [
                'sql' => $query->sql,
                'time' => $query->time,
                'bindings' => $this->safeBindings($query->bindings),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Request $request): array
    {
        return [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'time_ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
            'memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
            'query_count' => count($this->queries),
            'queries' => $this->queries,
            'at' => now()->toIso8601String(),
        ];
    }

    public function store(Request $request): void
    {
        if ($this->startedAt <= 0) {
            return;
        }
        $list = Cache::get('wento.profiler', []);
        if (! is_array($list)) {
            $list = [];
        }
        array_unshift($list, $this->snapshot($request));
        Cache::put('wento.profiler', array_slice($list, 0, 40), 3600);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(): array
    {
        $list = Cache::get('wento.profiler', []);

        return is_array($list) ? $list : [];
    }

    public static function forget(): void
    {
        Cache::forget('wento.profiler');
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<mixed>
     */
    protected function safeBindings(array $bindings): array
    {
        return array_values(array_map(function ($value) {
            if (! is_string($value)) {
                return $value;
            }
            if (strlen($value) > 80 || preg_match('/pass|secret|token|key/i', $value)) {
                return '[redacted]';
            }

            return $value;
        }, $bindings));
    }
}
