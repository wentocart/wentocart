<?php

/**
 * Standalone installer helpers. Do not boot Laravel here — vendor/ may not exist yet.
 * Keep PHP/Node/extension numbers in sync with config/wento.php.
 */

const SETUP_PHP_NEED = '8.3.0';
const SETUP_NODE_NEED = '20.0.0';
const SETUP_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'gd', 'zip'];

function setup_root(): string
{
    return dirname(__DIR__);
}

function setup_status_path(): string
{
    return setup_private_path('setup-status.json');
}

function setup_install_status_path(): string
{
    return setup_private_path('install-status.json');
}

function setup_install_job_path(): string
{
    return setup_private_path('install-job.json');
}

function setup_private_path(string $name): string
{
    $dir = setup_root().'/storage/framework';
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir.'/'.$name;
}

/**
 * @param  array<string, mixed>  $data
 */
function setup_write_install_status(array $data): void
{
    file_put_contents(setup_install_status_path(), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * @return array<string, mixed>
 */
function setup_read_install_status(): array
{
    $path = setup_install_status_path();
    if (! is_file($path)) {
        return [
            'running' => false,
            'phase' => 'idle',
            'percent' => 0,
            'detail' => '',
            'detail_pct' => 0,
            'log' => '',
            'ok' => null,
            'error' => null,
            'shop_url' => null,
            'admin_url' => null,
            'redirect' => null,
        ];
    }

    $json = json_decode((string) file_get_contents($path), true);

    return is_array($json) ? $json : setup_read_install_status_defaults();
}

/**
 * @return array<string, mixed>
 */
function setup_read_install_status_defaults(): array
{
    return [
        'running' => false,
        'phase' => 'idle',
        'percent' => 0,
        'detail' => '',
        'detail_pct' => 0,
        'log' => '',
        'ok' => null,
        'error' => null,
        'shop_url' => null,
        'admin_url' => null,
        'redirect' => null,
    ];
}

function setup_is_windows(): bool
{
    return stripos(PHP_OS, 'WIN') === 0;
}

/**
 * Apache/XAMPP sets PHP_BINARY to php-cgi.exe, which cannot run artisan/composer CLI.
 */
function setup_cli_php_binary(?string $binary = null): string
{
    $cliName = setup_is_windows() ? 'php.exe' : 'php';
    $candidates = [];
    if (is_string($binary) && $binary !== '') {
        $candidates[] = $binary;
    }
    foreach (['WENTO_PHP', 'PHPRC'] as $env) {
        $value = getenv($env);
        if (is_string($value) && $value !== '') {
            $candidates[] = $env === 'PHPRC' ? rtrim($value, '/\\').DIRECTORY_SEPARATOR.$cliName : $value;
        }
    }
    $phprc = (string) ($_SERVER['PHPRC'] ?? '');
    if ($phprc !== '') {
        $candidates[] = rtrim($phprc, '/\\').DIRECTORY_SEPARATOR.$cliName;
    }
    $candidates[] = (string) PHP_BINARY;

    $fallback = $cliName;
    foreach ($candidates as $raw) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string) $raw));
        if ($path === '') {
            continue;
        }
        $name = strtolower(basename($path));
        $sibling = dirname($path).DIRECTORY_SEPARATOR.$cliName;
        if ((str_contains($name, 'cgi') || str_contains($name, 'fpm')) && is_file($sibling)) {
            return $sibling;
        }
        if (is_file($path) && ! str_contains($name, 'cgi') && ! str_contains($name, 'fpm')) {
            return $path;
        }
        if (is_file($sibling)) {
            $fallback = $sibling;
        }
    }

    return $fallback;
}

/** Windowless CLI for background workers. php.exe always opens a console on Windows. */
function setup_background_php_binary(?string $binary = null): string
{
    $cli = setup_cli_php_binary($binary);
    if (! setup_is_windows()) {
        return $cli;
    }
    $hidden = dirname($cli).DIRECTORY_SEPARATOR.'php-win.exe';

    return is_file($hidden) ? $hidden : $cli;
}

function setup_win_arg(string $value): string
{
    return '"'.str_replace('"', '""', $value).'"';
}

function setup_worker_log_path(string $script): string
{
    return dirname($script).DIRECTORY_SEPARATOR.'cli-worker.log';
}

function setup_worker_log(string $script, string $line): void
{
    @file_put_contents(setup_worker_log_path($script), '['.date('c').'] '.$line."\n", FILE_APPEND);
}

/** Detached `php script.php`. Apache `start /B` often no-ops; prefer proc_open / WScript. */
function setup_spawn_background_php(string $script): bool
{
    if (! is_file($script)) {
        return false;
    }

    $php = setup_background_php_binary();
    $log = setup_worker_log_path($script);
    $cwd = dirname($script);
    setup_worker_log($script, 'spawn php='.$php.' script='.$script);

    if (setup_is_windows()) {
        if (class_exists('COM')) {
            try {
                $shell = new COM('WScript.Shell');
                $cmd = 'cmd /c '.setup_win_arg($php).' '.setup_win_arg($script).' >> '.setup_win_arg($log).' 2>&1';
                $shell->Run($cmd, 0, false);
                setup_worker_log($script, 'spawned via WScript.Shell (hidden)');

                return true;
            } catch (Throwable $e) {
                setup_worker_log($script, 'COM spawn failed: '.$e->getMessage());
            }
        }

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $proc = @proc_open(
            [$php, $script],
            $spec,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (is_resource($proc)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            setup_worker_log($script, 'spawned via proc_open');

            return true;
        }

        $inner = setup_win_arg($php).' '.setup_win_arg($script).' >> '.setup_win_arg($log).' 2>&1';
        $handle = @popen('start /B "" cmd /c '.setup_win_arg($inner), 'r');
        if (is_resource($handle)) {
            pclose($handle);
            setup_worker_log($script, 'spawned via start /B');

            return true;
        }

        return false;
    }

    exec(escapeshellarg($php).' '.escapeshellarg($script).' >> '.escapeshellarg($log).' 2>&1 &');

    return true;
}

function setup_inline_php_worker(string $script): void
{
    if (! defined('SETUP_INLINE_WORKER')) {
        define('SETUP_INLINE_WORKER', true);
    }
    setup_worker_log($script, 'running worker inline (Apache php-cgi / spawn missed)');
    require $script;
}

function setup_worker_heartbeat(string $script, float $seconds = 1.2): bool
{
    $install = str_contains(basename($script), 'install-worker');
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        usleep(120000);
        if ($install) {
            $status = setup_read_install_status();
            $detail = (string) ($status['detail'] ?? '');
            $log = (string) ($status['log'] ?? '');
            if ($detail !== 'Queued…' || ! str_starts_with($log, "Queued installation…")) {
                return true;
            }
            continue;
        }
        $status = setup_read_status();
        $phase = (string) ($status['phase'] ?? '');
        if ($phase !== '' && $phase !== 'queued') {
            return true;
        }
        if ((int) ($status['percent'] ?? 0) >= 5) {
            return true;
        }
    }

    return false;
}

/** Spawn a CLI worker; if Apache never launches php.exe, finish this request after JSON is flushed. */
function setup_begin_php_worker(string $script): void
{
    $spawned = setup_spawn_background_php($script);
    if ($spawned && setup_worker_heartbeat($script)) {
        return;
    }
    setup_inline_php_worker($script);
}

function setup_json_flush(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Connection: close');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('Content-Length: '.strlen((string) $body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }
}

function setup_dev_null(): string
{
    return setup_is_windows() ? '2>NUL' : '2>/dev/null';
}

function setup_php_ini(): string
{
    return php_ini_loaded_file() ?: 'php.ini';
}

function setup_node_version(): ?string
{
    exec('node -v '.setup_dev_null(), $out, $code);
    if ($code !== 0 || ! isset($out[0])) {
        return null;
    }

    return ltrim(trim($out[0]), 'v');
}

function setup_install_in_progress(): bool
{
    if (is_file(setup_root().'/storage/app/installing.lock')) {
        return true;
    }

    $status = setup_read_install_status();

    return ! empty($status['running']);
}

function setup_already_installed(): bool
{
    // .env is marked installed only at the end, but an in-progress run must
    // keep the wizard API alive so the browser can still read progress.
    if (setup_install_in_progress()) {
        return false;
    }

    $lock = setup_root().'/storage/app/installed.lock';
    if (is_file($lock)) {
        return true;
    }

    $flag = strtolower(trim(setup_env_value('WENTO_INSTALLED', 'false')));
    if (! in_array($flag, ['true', '1', 'yes'], true)) {
        return false;
    }

    // Heal a missing lock after a successful install (e.g. storage was not writable).
    $dir = setup_root().'/storage/app';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($lock, date('c'));
    }

    return true;
}

function setup_env_value(string $key, string $default = ''): string
{
    $path = setup_root().'/.env';
    if (! is_file($path)) {
        return $default;
    }
    $env = (string) file_get_contents($path);
    if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m)) {
        return $default;
    }
    $value = trim($m[1]);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
        $value = substr($value, 1, -1);
    }

    return $value !== '' ? $value : $default;
}

function setup_vendor_ready(): bool
{
    return is_file(setup_root().'/vendor/autoload.php');
}

function setup_assets_ready(): bool
{
    return is_file(setup_root().'/public/build/manifest.json');
}

function setup_prepared(): bool
{
    return setup_vendor_ready() && setup_assets_ready();
}

/**
 * @return array<string, mixed>
 */
function setup_compatibility(): array
{
    $phpOk = version_compare(PHP_VERSION, SETUP_PHP_NEED, '>=');
    $extensions = [];
    $allExt = true;
    foreach (SETUP_EXTENSIONS as $ext) {
        $ok = extension_loaded($ext);
        $allExt = $allExt && $ok;
        $extensions[] = [
            'name' => $ext,
            'ok' => $ok,
        ];
    }

    $node = setup_node_version();
    $nodeVersionOk = $node !== null && version_compare($node, SETUP_NODE_NEED, '>=');
    $assetsReady = setup_assets_ready();
    // Pre-built merchant zips ship public/build — Node is only required when assets are missing.
    $nodeOk = $assetsReady || $nodeVersionOk;

    $rows = [
        [
            'id' => 'php_available',
            'label' => 'PHP available',
            'value' => 'Yes'.(PHP_BINARY ? ' ('.PHP_BINARY.')' : ''),
            'ok' => true,
            'hint' => null,
        ],
        [
            'id' => 'php_version',
            'label' => 'PHP version',
            'value' => PHP_VERSION,
            'ok' => $phpOk,
            'status' => $phpOk ? 'Correct' : 'Not correct',
            'hint' => $phpOk ? null : 'This page is running PHP '.PHP_VERSION.'. Need '.SETUP_PHP_NEED.'+. Point a PHP '.SETUP_PHP_NEED.'+ binary at this folder (PATH helper or Apache/nginx). Other PHP copies are ignored.',
        ],
        [
            'id' => 'extensions',
            'label' => 'php.ini extensions',
            'value' => $allExt ? 'Yes' : 'No',
            'ok' => $allExt,
            'hint' => $allExt ? null : 'Open '.setup_php_ini().' for this PHP and enable the missing extension (example: extension=pdo_mysql), then restart the server that opened this page. Other php.ini files on this computer are ignored.',
            'extensions' => $extensions,
        ],
        [
            'id' => 'node',
            'label' => 'Node.js available',
            'value' => $assetsReady
                ? ($nodeVersionOk ? 'Yes (v'.$node.', assets pre-built)' : 'Not required (assets pre-built)')
                : ($nodeVersionOk ? 'Yes (v'.$node.')' : ($node ? 'No (v'.$node.', need '.SETUP_NODE_NEED.'+)' : 'No')),
            'ok' => $nodeOk,
            'hint' => $nodeOk ? null : 'Install Node.js '.SETUP_NODE_NEED.' or newer and ensure `node` is on PATH, then reload. This installer cannot install Node. (Or use a zip that already includes public/build.)',
        ],
    ];

    $ready = true;
    foreach ($rows as $row) {
        if (! $row['ok']) {
            $ready = false;
            break;
        }
    }

    return [
        'ready' => $ready,
        'php_need' => SETUP_PHP_NEED,
        'node_need' => SETUP_NODE_NEED,
        'ini' => setup_php_ini(),
        'prepared' => setup_prepared(),
        'installed' => setup_already_installed(),
        'rows' => $rows,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function setup_write_status(array $data): void
{
    file_put_contents(setup_status_path(), json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * @return array<string, mixed>
 */
function setup_read_status(): array
{
    $path = setup_status_path();
    if (! is_file($path)) {
        return ['running' => false, 'phase' => 'idle', 'percent' => 0, 'log' => '', 'ok' => null, 'error' => null];
    }

    $json = json_decode((string) file_get_contents($path), true);

    return is_array($json) ? $json : ['running' => false, 'phase' => 'idle', 'percent' => 0, 'log' => '', 'ok' => null, 'error' => null];
}

function setup_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function setup_base_url(): string
{
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/setup/index.php'));
    $dir = dirname($script);
    $base = preg_replace('#/setup$#', '', $dir) ?: '';
    if (in_array($base, ['/', '\\', '.', ''], true)) {
        $base = '';
    }

    return $scheme.'://'.$host.rtrim($base, '/');
}

/** True when Install.vbs / install.sh is serving via the built-in PHP server. */
function setup_is_php_development_server(?string $software = null): bool
{
    $software ??= (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');

    return stripos($software, 'Development Server') !== false;
}

/**
 * Host URL (no port) and TCP port for the helper two-field display.
 *
 * @return array{host_url: string, port: int}
 */
function setup_shop_url_parts(string $url): array
{
    $norm = setup_normalize_app_url($url) ?? trim($url);
    $parts = parse_url($norm);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    if (! in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'http';
    }
    $host = (string) ($parts['host'] ?? '127.0.0.1');
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

    return [
        'host_url' => $scheme.'://'.$host,
        'port' => $port,
    ];
}

function setup_url_with_port(string $url, int $port): ?string
{
    if ($port < 1 || $port > 65535) {
        return null;
    }
    $norm = setup_normalize_app_url($url);
    if ($norm === null) {
        return null;
    }
    $parts = parse_url($norm);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '/') {
        $path = '';
    }
    $omit = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

    return $scheme.'://'.$host.($omit ? '' : ':'.$port).$path;
}

function setup_install_public_base(array $input): string
{
    $app = rtrim((string) ($input['app_url'] ?? ''), '/');
    if ($app !== '') {
        return $app;
    }
    $detected = rtrim((string) ($input['detected_url'] ?? ''), '/');

    return $detected !== '' ? $detected : 'http://127.0.0.1:8099';
}

function setup_helper_port_path(): string
{
    return setup_root().DIRECTORY_SEPARATOR.'helper.port';
}

function setup_write_helper_port(int $port): void
{
    if ($port < 1 || $port > 65535) {
        return;
    }
    @file_put_contents(setup_helper_port_path(), (string) $port.PHP_EOL);
}

function setup_read_helper_port(): ?int
{
    $path = setup_helper_port_path();
    if (! is_file($path)) {
        return null;
    }
    $port = (int) trim((string) @file_get_contents($path));

    return ($port >= 1 && $port <= 65535) ? $port : null;
}

/** Same command Install.vbs uses so php -S is not started from C:\php. */
function setup_helper_server_command(string $php, string $root, int $port): string
{
    $server = $root.DIRECTORY_SEPARATOR.'server.php';
    if (setup_is_windows()) {
        return 'cmd /c cd /d '.setup_win_arg($root).' && '.setup_win_arg($php)
            .' -S 127.0.0.1:'.$port.' -t '.setup_win_arg($root).' '.setup_win_arg($server);
    }

    return escapeshellarg($php).' -S 127.0.0.1:'.$port
        .' -t '.escapeshellarg($root).' '.escapeshellarg($server);
}

function setup_windows_start_listen(string $php, string $root, int $port, string $cmd, string $logScript): bool
{
    $server = $root.DIRECTORY_SEPARATOR.'server.php';
    $vbs = __DIR__.DIRECTORY_SEPARATOR.'start-listen.vbs';
    $wscript = rtrim((string) (getenv('SystemRoot') ?: 'C:\\Windows'), '\\').'\\System32\\wscript.exe';
    if (is_file($vbs) && is_file($wscript)) {
        $launch = setup_win_arg($wscript).' //B //Nologo '.setup_win_arg($vbs).' '
            .setup_win_arg($php).' '.setup_win_arg($root).' '.$port;
        if (class_exists('COM')) {
            try {
                (new COM('WScript.Shell'))->Run($launch, 0, false);
                setup_worker_log($logScript, 'helper listen via start-listen.vbs');

                return true;
            } catch (Throwable $e) {
                setup_worker_log($logScript, 'helper vbs COM failed: '.$e->getMessage());
            }
        }
        $handle = @popen('start "" '.$launch, 'r');
        if (is_resource($handle)) {
            pclose($handle);
            setup_worker_log($logScript, 'helper listen via wscript start');

            return true;
        }
    }

    if (class_exists('COM')) {
        try {
            (new COM('WScript.Shell'))->Run($cmd, 0, false);
            setup_worker_log($logScript, 'helper listen via WScript.Shell');

            return true;
        } catch (Throwable $e) {
            setup_worker_log($logScript, 'helper COM spawn failed: '.$e->getMessage());
        }
    }

    $handle = @popen('start "" '.$cmd, 'r');
    if (is_resource($handle)) {
        pclose($handle);
        setup_worker_log($logScript, 'helper listen via cmd start');

        return true;
    }

    $ps = 'Start-Process -FilePath '.setup_win_arg($php)
        .' -WorkingDirectory '.setup_win_arg($root)
        .' -WindowStyle Hidden -ArgumentList @('
        .setup_win_arg('-S').', '.setup_win_arg('127.0.0.1:'.$port).', '
        .setup_win_arg('-t').', '.setup_win_arg($root).', '
        .setup_win_arg($server).')';
    $handle = @popen('powershell -NoProfile -WindowStyle Hidden -Command '.$ps, 'r');
    if (is_resource($handle)) {
        pclose($handle);
        setup_worker_log($logScript, 'helper listen via PowerShell');

        return true;
    }

    return false;
}

/**
 * Start php -S on the Port typed in the wizard. Must outlive the install worker:
 * proc_open children die when the worker exits, which left APP_URL on 8025
 * while the browser stayed on 8100.
 */
function setup_spawn_helper_listen(int $port, int $waitSeconds = 12): bool
{
    $root = setup_root();
    $server = $root.DIRECTORY_SEPARATOR.'server.php';
    $logScript = __DIR__.DIRECTORY_SEPARATOR.'install-worker.php';
    if ($port < 1 || $port > 65535 || ! is_file($server)) {
        return false;
    }
    $current = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    if ($current === $port || setup_tcp_port_in_use('127.0.0.1', $port)) {
        return true;
    }

    $php = setup_cli_php_binary();
    $cmd = setup_helper_server_command($php, $root, $port);
    setup_worker_log($logScript, 'helper listen php='.$php.' port='.$port.' root='.$root);

    if (setup_is_windows()) {
        $started = setup_windows_start_listen($php, $root, $port, $cmd, $logScript);
        if (! $started) {
            setup_worker_log($logScript, 'helper listen spawn failed port='.$port);

            return false;
        }
    } else {
        exec($cmd.' >/dev/null 2>&1 &');
    }

    $until = microtime(true) + max(3, $waitSeconds);
    while (microtime(true) < $until) {
        usleep(150000);
        if (setup_tcp_port_in_use('127.0.0.1', $port)) {
            setup_worker_log($logScript, 'helper listen ready port='.$port);

            return true;
        }
    }
    setup_worker_log($logScript, 'helper listen timeout port='.$port);

    return false;
}

function setup_tcp_port_in_use(string $host, int $port): bool
{
    if ($host === 'localhost' || $host === '::1') {
        $host = '127.0.0.1';
    }
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.4);
    if (is_resource($fp)) {
        fclose($fp);

        return true;
    }

    return false;
}

/** Null when the helper Port may be saved to APP_URL. */
function setup_helper_shop_port_error(string $detected, int $port): ?string
{
    if ($port < 1 || $port > 65535) {
        return 'Enter a Port from 1 to 65535.';
    }
    $current = setup_shop_url_parts($detected)['port'];
    if ($port === $current) {
        return null;
    }
    $host = (string) (parse_url(setup_shop_url_parts($detected)['host_url'], PHP_URL_HOST) ?: '127.0.0.1');
    if (setup_tcp_port_in_use($host, $port)) {
        return 'That Port is already in use. Try another number.';
    }

    return null;
}

/**
 * Shop URL must be a full http(s) address with a host. Rejects bare "localhost:3020".
 */
function setup_normalize_app_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || ! preg_match('#^https?://#i', $url)) {
        return null;
    }

    $parts = parse_url($url);
    if (! is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (! in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    if ($path === '/' || $path === '') {
        $path = '';
    } else {
        $path = rtrim($path, '/');
    }

    return $scheme.'://'.$host.$port.$path;
}

/**
 * Same host/port/folder the merchant opened in the browser (localhost = 127.0.0.1).
 *
 * @return array{scheme: string, host: string, port: int, path: string}|null
 */
function setup_serving_fingerprint(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || ! preg_match('#^https?://#i', $url)) {
        return null;
    }

    $parts = parse_url($url);
    if (! is_array($parts) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (! in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower((string) $parts['host']);
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $host = 'loopback';
    } elseif (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path = (string) ($parts['path'] ?? '');
    if ($path === '/' || $path === '') {
        $path = '';
    } else {
        $path = rtrim($path, '/');
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'path' => $path,
    ];
}

function setup_same_serving_origin(string $a, string $b): bool
{
    $left = setup_serving_fingerprint($a);
    $right = setup_serving_fingerprint($b);

    return $left !== null && $right !== null && $left === $right;
}

/**
 * Street address: plain text (HTML stripped). Punctuation, numbers, and line breaks stay.
 */
function setup_plain_text(string $value, int $max = 2000): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = str_replace("\0", '', $value);
    $value = trim((string) preg_replace("/\r\n|\r/", "\n", $value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

/**
 * ISO countries for the wizard (no database yet).
 *
 * @return list<array{code: string, name: string}>
 */
function setup_world_countries(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'localization'.DIRECTORY_SEPARATOR.'world-countries.json';
    if (! is_file($path)) {
        $rows = [];

        return $rows;
    }

    $data = json_decode((string) file_get_contents($path), true);
    $out = [];
    foreach ($data['countries'] ?? [] as $row) {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        $name = trim((string) ($row['name'] ?? ''));
        if ($code === '' || $name === '') {
            continue;
        }
        $out[] = ['code' => $code, 'name' => $name];
    }
    usort($out, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
    $rows = $out;

    return $rows;
}

function setup_country_name(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return '';
    }
    foreach (setup_world_countries() as $row) {
        if ($row['code'] === $code) {
            return $row['name'];
        }
    }

    return '';
}

/**
 * Pre-translated shop languages. Same six rows ShopLocaleDefaults seeds.
 *
 * @return list<array{code: string, name: string}>
 */
function setup_bundled_languages(): array
{
    return [
        ['code' => 'en', 'name' => 'English'],
        ['code' => 'fr', 'name' => 'French'],
        ['code' => 'es', 'name' => 'Spanish'],
        ['code' => 'it', 'name' => 'Italian'],
        ['code' => 'ar', 'name' => 'Arabic'],
        ['code' => 'hi', 'name' => 'Hindi'],
    ];
}

/**
 * States/regions shipped in localization packs. Countries with an empty list are omitted.
 *
 * @return array<string, list<array{code: string, name: string}>>
 */
function setup_country_regions(): array
{
    $dir = dirname(__DIR__).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'localization';
    $out = [];
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
        if (basename($path) === 'world-countries.json') {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            continue;
        }
        $code = strtoupper(trim((string) ($data['country']['code'] ?? pathinfo($path, PATHINFO_FILENAME))));
        $regions = [];
        foreach ($data['regions'] ?? [] as $region) {
            if (! is_array($region)) {
                continue;
            }
            $regionCode = strtoupper(trim((string) ($region['code'] ?? '')));
            $name = trim((string) ($region['name'] ?? ''));
            if ($code === '' || $regionCode === '' || $name === '') {
                continue;
            }
            $regions[] = ['code' => $regionCode, 'name' => $name];
        }
        if ($code !== '' && $regions !== []) {
            $out[$code] = $regions;
        }
    }

    return $out;
}

/**
 * When the shop folder sits in XAMPP htdocs (or Laragon/WAMP www), return the Apache URL.
 * Example: /var/www/htdocs/shop + Listen 8080 → http://localhost:8080/shop
 */
function setup_detect_apache_shop_url(?string $root = null): ?string
{
    $root = $root ?? setup_root();
    $shop = realpath($root) ?: $root;
    $current = $shop;
    $docroot = null;
    $httpd = null;
    for ($guard = 0; $guard < 8; $guard++) {
        $parent = dirname($current);
        if ($parent === $current || $parent === '' || $parent === '.') {
            break;
        }
        $name = strtolower(basename($current));
        if (in_array($name, ['htdocs', 'www', 'public_html'], true)) {
            $found = setup_find_httpd_conf($parent);
            if ($found !== null) {
                $docroot = $current;
                $httpd = $found;
                break;
            }
        }
        $current = $parent;
    }
    if ($docroot === null || $httpd === null) {
        return null;
    }

    $doc = rtrim(str_replace('\\', '/', $docroot), '/');
    $shopPath = str_replace('\\', '/', $shop);
    if (! str_starts_with(strtolower($shopPath), strtolower($doc))) {
        return null;
    }
    $relative = trim(substr($shopPath, strlen($doc)), '/');
    $port = setup_apache_listen_port($httpd);
    $authority = 'localhost'.($port === 80 ? '' : ':'.$port);
    $path = $relative === '' ? '' : '/'.$relative;

    return setup_normalize_app_url('http://'.$authority.$path);
}

function setup_find_httpd_conf(string $stackRoot): ?string
{
    $candidates = [
        $stackRoot.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'httpd.conf',
        // Linux XAMPP / LAMPP keeps the server config here, not under apache/conf.
        $stackRoot.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'httpd.conf',
    ];
    foreach ($candidates as $direct) {
        if (is_file($direct)) {
            return $direct;
        }
    }
    $matches = glob($stackRoot.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'httpd.conf') ?: [];
    foreach ($matches as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

function setup_apache_listen_port(string $httpdConf): int
{
    $text = (string) @file_get_contents($httpdConf);
    if (preg_match_all('/^\s*Listen\s+(?:\S+:)?(\d+)\s*$/mi', $text, $matches)) {
        foreach ($matches[1] as $raw) {
            $port = (int) $raw;
            if ($port > 0 && $port !== 443) {
                return $port;
            }
        }
    }

    return 80;
}

/**
 * Accept only the Apache URL detected for this folder (localhost or 127.0.0.1, same port and path).
 */
function setup_accept_apache_shop_url(string $posted, ?string $root = null): ?string
{
    $detected = setup_detect_apache_shop_url($root);
    $postedNorm = setup_normalize_app_url($posted);
    $detectedNorm = $detected !== null ? setup_normalize_app_url($detected) : null;
    if ($postedNorm === null || $detectedNorm === null || ! setup_same_local_shop_url($postedNorm, $detectedNorm)) {
        return null;
    }

    return $postedNorm;
}

function setup_same_local_shop_url(string $a, string $b): bool
{
    $left = parse_url($a);
    $right = parse_url($b);
    if (! is_array($left) || ! is_array($right)) {
        return false;
    }
    $local = ['localhost', '127.0.0.1', '::1'];
    $hostA = strtolower((string) ($left['host'] ?? ''));
    $hostB = strtolower((string) ($right['host'] ?? ''));
    if (! in_array($hostA, $local, true) || ! in_array($hostB, $local, true)) {
        return false;
    }
    $portA = (int) ($left['port'] ?? (($left['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $portB = (int) ($right['port'] ?? (($right['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $pathA = rtrim((string) ($left['path'] ?? ''), '/');
    $pathB = rtrim((string) ($right['path'] ?? ''), '/');

    return $portA === $portB && strcasecmp($pathA, $pathB) === 0;
}

/**
 * Join optional worldwide address fields. Falls back to store_address for older clients.
 *
 * @param  array<string, mixed>  $input
 */
function setup_compose_store_address(array $input): string
{
    $parts = [];
    foreach (['store_line1', 'store_line2', 'store_city', 'store_region', 'store_postal'] as $key) {
        $value = setup_plain_text((string) ($input[$key] ?? ''), 255);
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    $country = setup_country_name((string) ($input['store_country'] ?? $input['store_country_code'] ?? ''));
    if ($country !== '') {
        $parts[] = $country;
    }
    if ($parts !== []) {
        return setup_plain_text(implode("\n", $parts), 2000);
    }

    return setup_plain_text((string) ($input['store_address'] ?? ''), 2000);
}

/**
 * Human reason the typed install URL is wrong, or null when it matches this page.
 */
function setup_app_url_issue(string $typed, string $detected): ?string
{
    $typed = trim($typed);
    $detectedNorm = setup_normalize_app_url($detected) ?? trim($detected);
    if ($detectedNorm === '') {
        $detectedNorm = 'the URL in your browser';
    }

    if ($typed === '') {
        return 'Enter this page’s URL, or click “Use this page’s URL”. Correct: '.$detectedNorm;
    }
    if (! preg_match('#^https?://#i', $typed)) {
        if (preg_match('/[\s,#]/', $typed) || ! str_contains($typed, '.')) {
            return 'This looks like a street address, not a website. Use this page’s URL: '.$detectedNorm;
        }

        return 'Add http:// or https:// at the start. For this install use: '.$detectedNorm;
    }

    $norm = setup_normalize_app_url($typed);
    if ($norm === null) {
        return 'That is not a valid website URL. Use: '.$detectedNorm;
    }
    if (! setup_same_serving_origin($norm, $detectedNorm)) {
        return 'You’re installing at '.$detectedNorm.', not a future domain. The wrong URL here breaks this install. Click “Use this page’s URL”.';
    }

    return null;
}

/**
 * Keep internal underscores. Trailing _ is optional — tables always get one: TIGER_products.
 */
function setup_db_prefix_stem(string $raw): string
{
    return rtrim(strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', $raw)), '_');
}

function setup_db_prefix_valid(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
        return false;
    }

    $stem = setup_db_prefix_stem($raw);
    if ($stem === '' || strlen($stem) > 32) {
        return false;
    }
    $alnum = (string) preg_replace('/_/', '', $stem);

    return strlen($alnum) >= 2 && strlen($alnum) <= 32;
}

/** Ensure .env exists and does not require MySQL during composer/npm prepare. */
function setup_prepare_env_file(): void
{
    $root = setup_root();
    $path = $root.'/.env';
    if (! is_file($path) && is_file($root.'/.env.example')) {
        copy($root.'/.env.example', $path);
    }
    if (! is_file($path)) {
        return;
    }

    $env = (string) file_get_contents($path);
    // Never leave a redacted/placeholder key from a bad .env.example copy.
    if (preg_match('/^APP_KEY=(.*)$/m', $env, $m)) {
        $key = trim((string) ($m[1] ?? ''));
        if ($key === '' || str_contains($key, '<blanked>') || ! str_starts_with($key, 'base64:')) {
            $env = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=', $env) ?? $env;
        }
    }
    // Scrub any other values that a secret-redaction pass left as <blanked>.
    $env = preg_replace('/^([A-Z0-9_]+)=<blanked>\s*$/m', '$1=', $env) ?? $env;
    $pairs = [
        'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'WENTO_INSTALLED' => 'false',
    ];
    foreach ($pairs as $key => $value) {
        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
            $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $env);
        } else {
            $env .= "\n".$key.'='.$value."\n";
        }
    }
    file_put_contents($path, $env);
}

/**
 * Folders the installer must be able to create or update before the wizard runs.
 *
 * @return array<string, string> path => label
 */
function setup_permission_targets(): array
{
    $root = setup_root();

    return [
        $root => 'the shop folder',
        $root.'/storage' => 'storage',
        $root.'/storage/app' => 'storage/app',
        $root.'/storage/framework' => 'storage/framework',
        $root.'/storage/framework/cache' => 'storage/framework/cache',
        $root.'/storage/framework/sessions' => 'storage/framework/sessions',
        $root.'/storage/framework/views' => 'storage/framework/views',
        $root.'/storage/logs' => 'storage/logs',
        $root.'/bootstrap/cache' => 'bootstrap/cache',
        $root.'/setup' => 'setup',
    ];
}

function setup_dir_writable(string $dir): bool
{
    if (! is_dir($dir)) {
        return false;
    }
    $probe = $dir.DIRECTORY_SEPARATOR.'.wento-write-test';
    $ok = @file_put_contents($probe, 'ok');
    if ($ok === false) {
        return false;
    }
    @unlink($probe);

    return true;
}

/**
 * @return list<array{path: string, label: string}>
 */
function setup_permission_problems(): array
{
    $problems = [];
    foreach (setup_permission_targets() as $path => $label) {
        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (! setup_dir_writable($path)) {
            $problems[] = ['path' => $path, 'label' => $label];
        }
    }

    $root = setup_root();
    $env = $root.'/.env';
    if (! is_file($env)) {
        if (! is_writable($root)) {
            $problems[] = ['path' => $root, 'label' => '.env (created in the shop folder)'];
        }
    } elseif (! is_writable($env)) {
        $problems[] = ['path' => $env, 'label' => '.env'];
    }

    $seen = [];
    $unique = [];
    foreach ($problems as $problem) {
        if (isset($seen[$problem['path']])) {
            continue;
        }
        $seen[$problem['path']] = true;
        $unique[] = $problem;
    }

    return $unique;
}

function setup_web_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && ! empty($info['name'])) {
            return (string) $info['name'];
        }
    }
    foreach (['APACHE_RUN_USER', 'USER'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return setup_is_windows() ? 'Users' : 'www-data';
}

/**
 * @return list<string>
 */
function setup_permission_commands(string $root, string $user): array
{
    $user = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $user);
    if ($user === '') {
        $user = setup_is_windows() ? 'Users' : 'www-data';
    }
    $quoted = escapeshellarg($root);
    if (setup_is_windows()) {
        return [
            'icacls '.$quoted.' /grant "'.$user.':(OI)(CI)M" /T',
        ];
    }

    return [
        'sudo chown -R '.$user.':'.$user.' '.$quoted,
        'sudo chmod -R ug+rwx '.$quoted,
    ];
}

/**
 * @param  list<array{path: string, label: string}>  $problems
 */
function setup_render_permission_page(array $problems): void
{
    if (! defined('WENTO_INSTALL_HANDLED')) {
        define('WENTO_INSTALL_HANDLED', true);
    }
    $root = setup_root();
    $user = setup_web_user();
    $commands = setup_permission_commands($root, $user);
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $items = '';
    foreach ($problems as $problem) {
        $items .= '<li><strong>'.htmlspecialchars($problem['label'], ENT_QUOTES, 'UTF-8').'</strong><br><code>'
            .htmlspecialchars($problem['path'], ENT_QUOTES, 'UTF-8').'</code></li>';
    }
    $commandHtml = '';
    foreach ($commands as $command) {
        $commandHtml .= '<code>'.htmlspecialchars($command, ENT_QUOTES, 'UTF-8').'</code>';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>Folder permission required</title><style>'
        .'body{margin:0;min-height:100vh;font-family:system-ui,sans-serif;background:#f3f4f8;color:#0f172a}'
        .'.card{max-width:760px;margin:48px auto;background:#fff;border:1px solid #fecaca;border-radius:18px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}'
        .'h1{margin:0 0 8px;font-size:1.4rem}p{line-height:1.5;color:#334155}'
        .'ul{padding-left:18px}code{display:block;margin:8px 0;padding:10px 12px;background:#0f172a;color:#e2e8f0;border-radius:10px;overflow:auto}'
        .'button{margin-top:16px;background:#5b21b6;color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer}'
        .'</style></head><body><div class="card">'
        .'<h1>The shop folder is not writable</h1>'
        .'<p>The installer stopped here instead of showing a blank page. The web server user <strong>'
        .htmlspecialchars($user, ENT_QUOTES, 'UTF-8')
        .'</strong> cannot write in this copy of the shop.</p>'
        .'<p>Fix these paths:</p><ul>'.$items.'</ul>'
        .'<p>From a terminal, run:</p>'.$commandHtml
        .'<p>Then reload this same page. You do not need to go back to the first step. If an installation was already in progress, it will open again so you can try it once more.</p>'
        .'<button type="button" onclick="location.reload()">Reload this page</button>'
        .'</div></body></html>';
}

function setup_friendly_error(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return 'The installer stopped because of an unexpected error. Reload this page and try again.';
    }
    if (preg_match('/Permission denied|failed to open stream|Read-only file system/i', $message)) {
        $user = setup_web_user();
        $root = setup_root();

        return 'The web server user '.$user.' cannot write in '.$root.'. Give that user ownership of the shop folder, then reload this page. On Linux: sudo chown -R '.$user.':'.$user.' '.$root.' && sudo chmod -R ug+rwx '.$root;
    }
    if (preg_match('/\b1059\b|Identifier name .+ is too long/i', $message)) {
        return 'MySQL rejected a table or index name because it is longer than 64 characters. Empty this database, or choose a new empty database, then try the installation again.';
    }
    if (preg_match('/Allowed memory size/i', $message)) {
        return 'PHP ran out of memory while installing. Raise memory_limit to at least 512M in the php.ini used by this web server, restart the server, then reload this page.';
    }
    if (preg_match('/Maximum execution time/i', $message)) {
        return 'PHP stopped the installation because it ran too long. Raise max_execution_time in the php.ini used by this web server, restart the server, then reload this page.';
    }
    if (preg_match('/could not find driver|pdo_mysql/i', $message)) {
        return 'This PHP cannot talk to MySQL. Enable the pdo_mysql extension for the PHP that is serving this page, restart the server, then reload.';
    }

    return $message;
}

function setup_boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = setup_root().'/storage/framework/sessions';
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && setup_dir_writable($dir)) {
        session_save_path($dir);
    }
    try {
        if (@session_start()) {
            return;
        }
    } catch (Throwable) {
        // Fall through to the permission page.
    }
    setup_render_permission_page([[
        'path' => (string) (session_save_path() ?: $dir),
        'label' => 'PHP session folder',
    ]]);
    exit;
}

function setup_draft_path(): string
{
    return setup_root().'/storage/framework/install-draft.json';
}

/**
 * @param  array<string, mixed>  $input
 */
function setup_write_draft(array $input): void
{
    $dir = dirname(setup_draft_path());
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(setup_draft_path(), json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * @return array<string, mixed>
 */
function setup_read_draft(): array
{
    $path = setup_draft_path();
    if (! is_file($path)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($path), true);

    return is_array($json) ? $json : [];
}

/**
 * Fields safe to print back into the wizard. Passwords stay on disk.
 *
 * @return array<string, mixed>
 */
function setup_draft_for_page(): array
{
    $draft = setup_read_draft();
    unset($draft['db_password'], $draft['admin_password'], $draft['use_saved_secrets']);

    return $draft;
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function setup_apply_saved_secrets(array $input): array
{
    $flag = $input['use_saved_secrets'] ?? '';
    $use = $flag === '1' || $flag === 1 || $flag === true;
    if (! $use) {
        return $input;
    }
    $draft = setup_read_draft();
    if ((string) ($input['db_password'] ?? '') === '' && array_key_exists('db_password', $draft)) {
        $input['db_password'] = (string) $draft['db_password'];
    }
    if ((string) ($input['admin_password'] ?? '') === '' && array_key_exists('admin_password', $draft)) {
        $input['admin_password'] = (string) $draft['admin_password'];
    }

    return $input;
}
