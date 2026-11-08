<?php

namespace App\Domain\Installer;

/**
 * Terminal + wizard progress helpers for wento:install / setup worker.
 */
final class InstallProgress
{
    /**
     * @param  (callable(string): void)|null  $out
     */
    public static function say(?callable $out, string $line): void
    {
        if ($out) {
            $out($line);
        }
    }

    /**
     * Structured progress for the browser wizard (and harmless if CLI ignores it).
     *
     * @param  (callable(string): void)|null  $out
     */
    public static function tick(?callable $out, string $phase, int $done, int $total, string $message, ?int $phasePct = null): void
    {
        if (! $out) {
            return;
        }

        $payload = [
            'phase' => $phase,
            'done' => max(0, $done),
            'total' => max(1, $total),
            'message' => $message,
        ];
        if ($phasePct !== null) {
            $payload['phase_pct'] = max(0, min(100, $phasePct));
        }

        $out('WENTO_PROGRESS:'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * User-facing log line for the wizard (also shown in CLI).
     *
     * @param  (callable(string): void)|null  $out
     */
    public static function note(?callable $out, string $message): void
    {
        if (! $out) {
            return;
        }

        $out('WENTO_NOTE:'.$message);
        $out('  '.$message);
    }

    /**
     * Single-line progress (caller should write without trailing newline when line starts with \\r).
     * Uses ASCII so every terminal shows motion clearly.
     */
    public static function bar(int $done, int $total, string $label = '', int $width = 28): string
    {
        $total = max(1, $total);
        $done = max(0, min($done, $total));
        $ratio = $done / $total;
        $filled = (int) floor($width * $ratio);
        $bar = str_repeat('#', $filled).str_repeat('-', $width - $filled);
        $pct = (int) floor($ratio * 100);
        $label = $label !== '' ? ' '.$label : '';
        // Pad/truncate label so \\r overwrites cleanly.
        $label = mb_strimwidth($label, 0, 40, '…');

        return sprintf("\r  [%s] %3d%% (%d/%d)%-40s", $bar, $pct, $done, $total, $label);
    }

    /**
     * @param  (callable(string): void)|null  $out
     */
    public static function stepDone(?callable $out, string $label, float $startedAt): void
    {
        $seconds = max(0, (int) round(microtime(true) - $startedAt));
        self::say($out, '  OK '.$label.' ('.$seconds.'s)');
    }
}
