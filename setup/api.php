<?php

require_once __DIR__.'/guard.php';
wento_setup_guard_boot(dirname(__DIR__));

require __DIR__.'/lib.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$permissionProblems = setup_permission_problems();
if ($permissionProblems !== []) {
    $user = setup_web_user();
    $root = setup_root();
    setup_json([
        'ok' => false,
        'permission' => true,
        'message' => 'The web server user '.$user.' cannot write in '.$root.'. Fix the folder permissions, then reload this page. You can continue from the same step.',
    ], 503);
}

// Status polls must not open the session. An inline install holds the lock and
// the browser then receives a timeout page instead of JSON.
if (! in_array($action, ['checks', 'status', 'install-status'], true)) {
    setup_boot_session();
}

if ($action !== 'checks' && $action !== 'status' && $action !== 'install-status') {
    $token = $_SERVER['HTTP_X_SETUP_TOKEN'] ?? $_POST['_token'] ?? '';
    if (! hash_equals((string) ($_SESSION['setup_token'] ?? ''), (string) $token)) {
        setup_json(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Progress polls must keep working after .env is updated. Closing them
// made the last step show "Not found" while the install was still running.
if ($action === 'install-status') {
    setup_json(['ok' => true, 'status' => setup_read_install_status()]);
}

if (setup_already_installed()) {
    if ($action === 'install') {
        $status = setup_read_install_status();
        if (($status['ok'] ?? null) === true || ($status['phase'] ?? '') === 'finish') {
            if (empty($status['redirect'])) {
                $status['redirect'] = rtrim(setup_base_url(), '/').'/installed';
            }
            $status['ok'] = true;
            setup_json([
                'ok' => true,
                'started' => true,
                'message' => 'Installation has completed.',
                'status' => $status,
            ]);
        }
    }
    setup_json(['ok' => false, 'message' => 'Not found.'], 404);
}

switch ($action) {
    case 'checks':
        setup_json(['ok' => true, 'compatibility' => setup_compatibility()]);

    case 'status':
        setup_json(['ok' => true, 'status' => setup_read_status(), 'prepared' => setup_prepared()]);

    case 'install-status':
        setup_json(['ok' => true, 'status' => setup_read_install_status()]);

    case 'prepare':
        $compat = setup_compatibility();
        if (! $compat['ready']) {
            setup_json(['ok' => false, 'message' => 'This machine is not compatible yet. Fix the items on the compatibility screen, then reload.', 'compatibility' => $compat], 422);
        }
        $status = setup_read_status();
        if (! empty($status['running'])) {
            setup_json(['ok' => true, 'message' => 'Prepare is already running.', 'status' => $status]);
        }
        if (setup_prepared()) {
            setup_json(['ok' => true, 'prepared' => true, 'message' => 'Already prepared.']);
        }
        setup_write_status([
            'running' => true,
            'phase' => 'queued',
            'percent' => 1,
            'log' => "Starting prepare…\n",
            'ok' => null,
            'error' => null,
        ]);
        setup_json_flush(['ok' => true, 'message' => 'Prepare started.']);
        setup_begin_php_worker(__DIR__.DIRECTORY_SEPARATOR.'worker.php');
        exit;

    case 'test-db':
        setup_test_database(setup_input());

    case 'save-draft':
        setup_write_draft(setup_input());
        setup_json(['ok' => true]);

    case 'install':
        setup_start_install(setup_apply_saved_secrets(setup_input()));

    default:
        setup_json(['ok' => false, 'message' => 'Unknown action.'], 400);
}

/**
 * @return array<string, string>
 */
function setup_input(): array
{
    $src = $_POST;
    if (empty($src)) {
        $raw = file_get_contents('php://input');
        $json = json_decode((string) $raw, true);
        $src = is_array($json) ? $json : [];
    }

    $get = static fn (string $key, string $default = ''): string => trim((string) ($src[$key] ?? $default));

    $detected = setup_normalize_app_url(setup_base_url()) ?? setup_base_url();
    $appUrl = $detected;
    $serveVia = strtolower($get('serve_via', 'helper'));
    $apacheUrl = $get('apache_url');
    $shopPort = (int) $get('shop_port', '0');
    if (setup_is_php_development_server() && $serveVia === 'apache') {
        $accepted = setup_accept_apache_shop_url($apacheUrl);
        if (is_string($accepted)) {
            $appUrl = $accepted;
            $shopPort = 0;
        }
    } elseif (setup_is_php_development_server() && $shopPort > 0) {
        $withPort = setup_url_with_port($detected, $shopPort);
        if (is_string($withPort)) {
            $appUrl = $withPort;
        }
    }

    $src['store_line1'] = $get('store_line1');
    $src['store_line2'] = $get('store_line2');
    $src['store_city'] = $get('store_city');
    $src['store_region'] = $get('store_region');
    $src['store_postal'] = $get('store_postal');
    $src['store_country'] = $get('store_country');
    $src['store_address'] = $get('store_address');

    return [
        'store_name' => $get('store_name'),
        'store_email' => $get('store_email'),
        'store_line1' => $src['store_line1'],
        'store_line2' => $src['store_line2'],
        'store_city' => $src['store_city'],
        'store_region' => $src['store_region'],
        'store_postal' => $src['store_postal'],
        'store_country' => $src['store_country'],
        'store_address' => setup_compose_store_address($src),
        'detected_url' => $detected,
        'app_url' => $appUrl,
        'serve_via' => $serveVia === 'apache' ? 'apache' : 'helper',
        'apache_url' => $apacheUrl,
        'shop_port' => $shopPort > 0 ? (string) $shopPort : '',
        'db_connection' => 'mysql',
        'db_host' => $get('db_host', 'localhost'),
        'db_port' => $get('db_port', '3306'),
        'db_database' => $get('db_database'),
        'db_username' => $get('db_username', 'root'),
        'db_password' => $get('db_password'),
        'db_prefix' => $get('db_prefix', 'wento'),
        'admin_name' => $get('admin_name', 'Administrator'),
        'admin_email' => $get('admin_email'),
        'admin_password' => $get('admin_password'),
        'language' => $get('language', 'en'),
        'currency' => $get('currency', 'USD'),
        'install_demo_data' => filter_var($get('install_demo_data', '0'), FILTER_VALIDATE_BOOL),
        'use_saved_secrets' => $get('use_saved_secrets', ''),
    ];
}

/**
 * @param  array<string, string>  $input
 */
function setup_test_database(array $input): void
{
    try {
        $pdo = setup_pdo($input, true);
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $input['db_database']);
        if ($name === '') {
            throw new RuntimeException('Database name is invalid.');
        }
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = setup_pdo($input, false);

        if (! setup_db_prefix_valid((string) $input['db_prefix'])) {
            setup_json(['ok' => false, 'message' => 'Table prefix must start with a letter, then letters, numbers, or underscores (examples: wento, tiger_, tia_2_, Lo_as_as_). Spaces and hyphens are not allowed. Tables become PREFIX_products.'], 422);
        }

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        if ($tables !== []) {
            setup_json([
                'ok' => false,
                'message' => 'This database name has already been used. Choose another empty database, or empty this database before installing.',
            ], 422);
        }

        setup_json(['ok' => true, 'message' => 'Database connection works. This database is empty and ready.']);
    } catch (Throwable $e) {
        $detail = $e->getMessage();
        $hint = 'Check that MySQL is running, then verify host, port, username, and password.';
        if (stripos($detail, 'refused') !== false || stripos($detail, '2002') !== false) {
            $hint = 'MySQL is not listening on this host/port. In XAMPP click Start next to MySQL. If it stays red, the port is wrong — this PC’s my.ini may use 33306 (or 3307) instead of 3306. Put that number in Database Port, keep host 127.0.0.1.';
        }
        setup_json(['ok' => false, 'message' => $hint.' ('.$detail.')'], 422);
    }
}

/**
 * @param  array<string, string>  $input
 */
function setup_pdo(array $input, bool $withoutDatabase): PDO
{
    $host = $input['db_host'] ?: 'localhost';
    // Avoid Windows IPv6 localhost delays when MySQL listens on 127.0.0.1 only.
    if (strcasecmp($host, 'localhost') === 0) {
        $host = '127.0.0.1';
    }
    $port = $input['db_port'] ?: '3306';
    $db = $withoutDatabase ? '' : ';dbname='.$input['db_database'];
    $dsn = 'mysql:host='.$host.';port='.$port.$db.';charset=utf8mb4';

    $previous = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '5');
    try {
        $pdo = new PDO($dsn, $input['db_username'], $input['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } finally {
        if ($previous !== false) {
            ini_set('default_socket_timeout', (string) $previous);
        }
    }

    return $pdo;
}

/**
 * @param  array<string, mixed>  $input
 */
function setup_start_install(array $input): void
{
    $compat = setup_compatibility();
    if (! $compat['ready']) {
        setup_json(['ok' => false, 'message' => 'This machine is not compatible yet.'], 422);
    }
    if (! setup_prepared()) {
        setup_json(['ok' => false, 'message' => 'Prepare this copy first (Composer + frontend build).'], 422);
    }
    if ($input['store_name'] === '' || ! filter_var($input['store_email'], FILTER_VALIDATE_EMAIL)) {
        setup_json(['ok' => false, 'message' => 'Store name and a valid store email are required.'], 422);
    }
    if (! setup_db_prefix_valid((string) $input['db_prefix'])) {
        setup_json(['ok' => false, 'message' => 'Table prefix must start with a letter, then letters, numbers, or underscores (examples: wento, tiger_, tia_2_, Lo_as_as_). Spaces and hyphens are not allowed. Tables become PREFIX_products.'], 422);
    }
    if ($input['admin_email'] === '' || ! filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL) || strlen($input['admin_password']) < 8) {
        setup_json(['ok' => false, 'message' => 'Admin email and a password of at least 8 characters are required.'], 422);
    }
    if ($input['db_database'] === '') {
        setup_json(['ok' => false, 'message' => 'Database name is required.'], 422);
    }

    $detected = setup_normalize_app_url((string) ($input['detected_url'] ?? setup_base_url())) ?? setup_base_url();
    $useApache = setup_is_php_development_server() && strtolower((string) ($input['serve_via'] ?? '')) === 'apache';
    if ($useApache) {
        $normalizedUrl = setup_accept_apache_shop_url((string) ($input['apache_url'] ?? $input['app_url'] ?? ''));
        if ($normalizedUrl === null) {
            $hint = setup_detect_apache_shop_url();
            setup_json([
                'ok' => false,
                'message' => $hint
                    ? 'Use the XAMPP address '.$hint.' or the installer window.'
                    : 'This folder is not inside an XAMPP htdocs (or www) directory, so Apache cannot open it. Use the installer window, or move the folder under htdocs and run Install.vbs again.',
            ], 422);
        }
        $port = 0;
        $input['shop_port'] = '';
    } elseif (setup_is_php_development_server()) {
        $port = (int) ($input['shop_port'] ?? 0);
        if ($port < 1) {
            $port = (int) setup_shop_url_parts($detected)['port'];
        }
        $portError = setup_helper_shop_port_error($detected, $port);
        if ($portError !== null) {
            setup_json(['ok' => false, 'message' => $portError], 422);
        }
        $normalizedUrl = setup_url_with_port($detected, $port);
        if ($normalizedUrl === null) {
            setup_json(['ok' => false, 'message' => 'Enter a Port from 1 to 65535.'], 422);
        }
    } else {
        $normalizedUrl = setup_normalize_app_url((string) $input['app_url']);
        if ($normalizedUrl === null || ! setup_same_serving_origin($normalizedUrl, $detected)) {
            $hint = setup_app_url_issue((string) $input['app_url'], $detected);
            setup_json([
                'ok' => false,
                'message' => $hint ?? ('Shop website URL must match this installer page. Example: '.$detected),
            ], 422);
        }
    }
    $input['app_url'] = $normalizedUrl;
    $input['detected_url'] = $detected;
    setup_write_draft($input);
    if (setup_is_php_development_server() && $port > 0) {
        setup_write_helper_port($port);
        setup_spawn_helper_listen($port, 2);
    }

    $current = setup_read_install_status();
    if (! empty($current['running'])) {
        setup_json(['ok' => true, 'started' => true, 'message' => 'Installation is already running.', 'status' => $current]);
    }

    file_put_contents(setup_install_job_path(), json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    setup_write_install_status([
        'running' => true,
        'phase' => 'database',
        'percent' => 1,
        'detail' => 'Queued…',
        'detail_pct' => 0,
        'log' => "Queued installation…\n",
        'ok' => null,
        'error' => null,
    ]);

    setup_json_flush(['ok' => true, 'started' => true, 'message' => 'Installation started.']);
    setup_begin_php_worker(__DIR__.DIRECTORY_SEPARATOR.'install-worker.php');
    exit;
}

/**
 * @param  array<string, mixed>  $input
 */
function setup_run_install(array $input): void
{
    // Kept for direct CLI/debug; browser uses setup_start_install + install-worker.
    setup_start_install($input);
}
