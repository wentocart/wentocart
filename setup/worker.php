<?php

/**
 * Background prepare worker. CLI php.exe, or the same Apache request after JSON is flushed.
 */
$allowInline = defined('SETUP_INLINE_WORKER') && SETUP_INLINE_WORKER;
if (PHP_SAPI !== 'cli' && ! $allowInline) {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require __DIR__.'/lib.php';

$workerLock = @fopen(__DIR__.DIRECTORY_SEPARATOR.'prepare-worker.lock', 'c');
if (! is_resource($workerLock) || ! flock($workerLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

@set_time_limit(0);
@ini_set('memory_limit', '1G');

$root = setup_root();
chdir($root);

$log = '';

$append = function (string $line) use (&$log): void {
    $log .= $line.(str_ends_with($line, "\n") ? '' : "\n");
};

$save = function (string $phase, int $percent, ?bool $ok = null, ?string $error = null) use (&$log): void {
    setup_write_status([
        'running' => $ok === null,
        'phase' => $phase,
        'percent' => $percent,
        'log' => $log,
        'ok' => $ok,
        'error' => $error,
    ]);
};

$run = function (string $command, string $fail) use ($append, $save): void {
    $append('$ '.$command);
    $save('running', max(5, (int) (setup_read_status()['percent'] ?? 5)));
    $output = [];
    $code = 0;
    exec($command.' 2>&1', $output, $code);
    $text = implode("\n", $output);
    if ($text !== '') {
        $append($text);
    }
    if ($code !== 0) {
        $save('failed', (int) (setup_read_status()['percent'] ?? 0), false, $fail);
        exit(1);
    }
};

try {
    $save('start', 5);
    $append('Preparing this copy in '.$root);

    foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            mkdir($root.'/'.$dir, 0777, true);
        }
    }
    $append('Checked storage and bootstrap/cache folders.');
    $save('folders', 10);

    if (! is_file($root.'/.env') && is_file($root.'/.env.example')) {
        copy($root.'/.env.example', $root.'/.env');
        $append('Created .env from .env.example.');
    }
    setup_prepare_env_file();
    $append('Prepared .env for install (file cache/session until database is configured).');

    if (! setup_vendor_ready()) {
        $save('composer', 20);
        $append('Installing Composer packages…');
        $composerCmd = setup_composer_command($root);
        $run($composerCmd.' install --no-interaction --prefer-dist --no-dev', 'composer install failed.');
        if (! setup_vendor_ready()) {
            $save('failed', 25, false, 'Composer finished but vendor/autoload.php is still missing.');
            exit(1);
        }
        $append('Composer packages installed.');
    } else {
        $append('Composer packages already present.');
    }
    $save('composer', 55);

    if (! setup_assets_ready()) {
        $save('npm', 60);
        $node = setup_node_version();
        if ($node === null || version_compare($node, SETUP_NODE_NEED, '<')) {
            $save('failed', 60, false, 'Node '.SETUP_NODE_NEED.'+ is required to build the admin UI. Install it from https://nodejs.org, then continue again.');
            exit(1);
        }
        $append('Node v'.$node.'. Building frontend…');
        $run('npm install --no-fund --no-audit', 'npm install failed.');
        $save('npm', 75);
        $run('npm run build', 'npm run build failed.');
        if (! setup_assets_ready()) {
            $save('failed', 85, false, 'npm run build finished but public/build/manifest.json is still missing.');
            exit(1);
        }
        $append('Frontend assets built.');
    } else {
        $append('Frontend build already present.');
    }

    $save('done', 100, true);
    $append('Prepare finished. Continue setup.');
    $save('done', 100, true);
} catch (Throwable $e) {
    $append($e->getMessage());
    $save('failed', (int) (setup_read_status()['percent'] ?? 0), false, $e->getMessage());
    exit(1);
}

function setup_composer_command(string $root): string
{
    putenv('COMPOSER=');
    $phar = $root.'/composer.phar';
    if (is_file($phar)) {
        return escapeshellarg(setup_cli_php_binary()).' '.escapeshellarg($phar);
    }

    $which = setup_is_windows() ? 'where composer' : 'command -v composer';
    exec($which.' '.setup_dev_null(), $out, $code);
    if ($code === 0 && isset($out[0]) && $out[0] !== '') {
        return 'composer';
    }

    $url = 'https://getcomposer.org/download/latest-stable/composer.phar';
    $bytes = setup_download($url, $phar);
    if ($bytes < 10000 || ! is_file($phar)) {
        throw new RuntimeException('Could not download composer.phar. Install Composer from https://getcomposer.org and try again.');
    }

    return escapeshellarg(setup_cli_php_binary()).' '.escapeshellarg($phar);
}

function setup_download(string $url, string $dest): int
{
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'WentoCart-Setup',
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($err) {
            throw new RuntimeException('Download failed: '.$err);
        }

        return (int) filesize($dest);
    }

    $data = @file_get_contents($url);
    if ($data === false) {
        throw new RuntimeException('Download failed. Enable curl or allow_url_fopen.');
    }
    file_put_contents($dest, $data);

    return strlen($data);
}
