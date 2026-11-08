<?php

/**
 * Background install worker. CLI php.exe, or the same Apache request after JSON is flushed.
 */
$allowInline = defined('SETUP_INLINE_WORKER') && SETUP_INLINE_WORKER;
if (PHP_SAPI !== 'cli' && ! $allowInline) {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require __DIR__.'/lib.php';

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (! is_array($err) || ! in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $current = setup_read_install_status();
    if (($current['ok'] ?? null) === true) {
        return;
    }
    setup_write_install_status([
        'running' => false,
        'phase' => 'failed',
        'percent' => (int) ($current['percent'] ?? 0),
        'detail' => '',
        'detail_pct' => (int) ($current['detail_pct'] ?? 0),
        'done' => (int) ($current['done'] ?? 0),
        'total' => (int) ($current['total'] ?? 0),
        'log' => (string) ($current['log'] ?? '')."\n".(string) ($err['message'] ?? ''),
        'ok' => false,
        'error' => setup_friendly_error((string) ($err['message'] ?? 'The installer stopped.')),
    ]);
});

$workerLock = @fopen(__DIR__.DIRECTORY_SEPARATOR.'install-worker.lock', 'c');
if (! is_resource($workerLock) || ! flock($workerLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

@set_time_limit(0);
@ini_set('memory_limit', '1G');
@ini_set('max_execution_time', '0');

$root = setup_root();
chdir($root);

$jobPath = setup_install_job_path();
if (! is_file($jobPath)) {
    setup_write_install_status([
        'running' => false,
        'phase' => 'failed',
        'percent' => 0,
        'detail' => '',
        'detail_pct' => 0,
        'done' => 0,
        'total' => 0,
        'log' => "Install job missing.\n",
        'ok' => false,
        'error' => 'Install job was not saved. Reload and try again.',
    ]);
    exit(1);
}

/** @var array<string, mixed> $input */
$input = json_decode((string) file_get_contents($jobPath), true);
if (! is_array($input)) {
    setup_write_install_status([
        'running' => false,
        'phase' => 'failed',
        'percent' => 0,
        'detail' => '',
        'detail_pct' => 0,
        'done' => 0,
        'total' => 0,
        'log' => "Invalid install job.\n",
        'ok' => false,
        'error' => 'Install job is invalid.',
    ]);
    exit(1);
}

// Fixed phase ranges — overall % only moves forward inside these bands.
$log = '';
$phase = 'database';
$percent = 0;
$detail = 'Starting installation…';
$detailPct = 0;
$done = 0;
$total = 0;
$lastLogLine = '';
$wantDemo = filter_var($input['install_demo_data'] ?? false, FILTER_VALIDATE_BOOL);
$shopPort = (int) ($input['shop_port'] ?? 0);
if ($shopPort > 0) {
    setup_write_helper_port($shopPort);
    setup_spawn_helper_listen($shopPort, 8);
}
$phaseRange = $wantDemo ? [
    'database' => [0, 40],
    'configuration' => [40, 55],
    'design' => [55, 62],
    'demo' => [62, 90],
    'admin' => [90, 98],
    'finish' => [98, 100],
] : [
    'database' => [0, 50],
    'configuration' => [50, 68],
    'design' => [68, 85],
    'admin' => [85, 98],
    'finish' => [98, 100],
];

$save = function () use (&$log, &$phase, &$percent, &$detail, &$detailPct, &$done, &$total): void {
    setup_write_install_status([
        'running' => true,
        'phase' => $phase,
        'percent' => $percent,
        'detail' => $detail,
        'detail_pct' => $detailPct,
        'done' => $done,
        'total' => $total,
        'log' => $log,
        'ok' => null,
        'error' => null,
    ]);
};

$append = function (string $line) use (&$log, &$lastLogLine, $save): void {
    $line = trim(str_replace("\r", '', $line));
    if ($line === '' || str_starts_with($line, 'WENTO_')) {
        return;
    }
    if (str_starts_with($line, '[phase:')) {
        return;
    }
    // Skip ASCII bars and identical consecutive lines (customers hate spam).
    if (preg_match('/\[(#|-)+\]\s+\d+%/ ', $line)) {
        return;
    }
    if ($line === $lastLogLine) {
        return;
    }
    $lastLogLine = $line;
    $log .= $line."\n";
    if (strlen($log) > 40000) {
        $log = substr($log, -30000);
    }
    $save();
};

$applyProgress = function (string $nextPhase, int $nextDone, int $nextTotal, string $message, ?int $phasePct = null) use (
    &$phase,
    &$percent,
    &$detail,
    &$detailPct,
    &$done,
    &$total,
    $phaseRange,
    $wantDemo,
    $save
): void {
    if ($nextPhase === 'tables') {
        $nextPhase = 'database';
    }
    if ($nextPhase === 'seed') {
        $nextPhase = 'configuration';
    }
    if (! $wantDemo && $nextPhase === 'demo') {
        $nextPhase = 'design';
    }
    if (! isset($phaseRange[$nextPhase])) {
        $nextPhase = $phase;
    }

    $phase = $nextPhase;
    $done = max(0, $nextDone);
    $total = max(1, $nextTotal);
    $detailPct = (int) min(100, floor(($done / $total) * 100));
    $detail = $message !== '' ? $message : $detail;
    $barPct = $phasePct ?? $detailPct;

    [$from, $to] = $phaseRange[$phase];

    $span = max(1, $to - $from);
    $calculated = (int) ($from + floor($span * ($barPct / 100)));
    if ($barPct >= 100) {
        $calculated = $to;
    }
    // Never move the overall bar backwards.
    $percent = max($percent, min(100, $calculated));

    // Tick messages update the stage UI only — they do not spam the details log.
    $save();
};

$progress = function (string $line) use (&$phase, $applyProgress, $append): void {
    $raw = str_replace("\r", "\n", $line);
    foreach (preg_split("/\n+/", $raw) ?: [] as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        if (str_starts_with($chunk, 'WENTO_PROGRESS:')) {
            $payload = json_decode(substr($chunk, strlen('WENTO_PROGRESS:')), true);
            if (is_array($payload)) {
                $applyProgress(
                    (string) ($payload['phase'] ?? $phase),
                    (int) ($payload['done'] ?? 0),
                    (int) ($payload['total'] ?? 1),
                    (string) ($payload['message'] ?? ''),
                    isset($payload['phase_pct']) ? (int) $payload['phase_pct'] : null,
                );
            }

            continue;
        }

        if (str_starts_with($chunk, 'WENTO_NOTE:')) {
            $append(substr($chunk, strlen('WENTO_NOTE:')));

            continue;
        }

        if (preg_match('/^\[phase:([a-z]+)\]/i', $chunk, $m)) {
            $p = strtolower($m[1]);
            if ($p === 'tables') {
                $p = 'database';
            }
            if ($p === 'seed') {
                $p = 'configuration';
            }
            $applyProgress($p, 0, 100, '');

            continue;
        }

        // Ignore CLI ASCII bars in the customer log.
        if (preg_match('/\[(#|-)+\]\s+\d+%/ ', $chunk)) {
            continue;
        }

        $append($chunk);
    }
};

try {
    $append('Starting installation…');
    $applyProgress('database', 0, 100, 'Starting installation…');

    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    app(App\Domain\Installer\InstallerService::class)->install($input, $progress);

    $base = setup_install_public_base($input);
    $live = $shopPort > 0 ? setup_spawn_helper_listen($shopPort, 15) : true;
    $open = $base;
    if ($shopPort > 0 && ! $live) {
        $log .= "Could not start Port ".$shopPort.". Keep this installer window until that Port is free, then open ".$base."/installed\n";
    }
    $percent = 100;
    $phase = 'finish';
    $detail = 'Installation completed';
    $detailPct = 100;
    $done = 1;
    $total = 1;
    $log .= "Installation completed.\n";
    setup_write_install_status([
        'running' => false,
        'phase' => 'finish',
        'percent' => 100,
        'detail' => 'Installation completed',
        'detail_pct' => 100,
        'done' => 1,
        'total' => 1,
        'log' => $log,
        'ok' => true,
        'error' => null,
        'shop_url' => $open.'/',
        'admin_url' => $open.'/'.App\Domain\Security\AdminPath::segment().'/login',
        'redirect' => $open.'/installed',
        'store_name' => $input['store_name'] ?? '',
    ]);
    @unlink($jobPath);
    exit(0);
} catch (Throwable $e) {
    setup_write_install_status([
        'running' => false,
        'phase' => 'failed',
        'percent' => $percent,
        'detail' => $e->getMessage(),
        'detail_pct' => $detailPct,
        'done' => $done,
        'total' => $total,
        'log' => $log.setup_friendly_error($e->getMessage())."\n",
        'ok' => false,
        'error' => setup_friendly_error($e->getMessage()),
    ]);
    exit(1);
}
