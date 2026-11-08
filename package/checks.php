<?php

/**
 * Pre-extract checks. Keep in sync with setup/lib.php and config/wento.php.
 */
const SETUP_PHP_NEED = '8.3.0';
const SETUP_NODE_NEED = '20.0.0';
const SETUP_SHOP_ARCHIVE = 'shop.zip';
const SETUP_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'gd', 'zip'];

function wrapper_is_windows(): bool
{
    return stripos(PHP_OS, 'WIN') === 0;
}

function wrapper_php_ini(): string
{
    return php_ini_loaded_file() ?: 'php.ini';
}

/**
 * Browser path under this install folder (the install folder, not only the domain root).
 */
function wrapper_href(string $file, ?string $scriptName = null): string
{
    $script = str_replace('\\', '/', (string) ($scriptName ?? ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    // A missing setup/index.php is rewritten onto this installer. Keep asset URLs in the shop folder.
    if (str_ends_with($script, '/setup/index.php')) {
        $script = substr($script, 0, -strlen('/setup/index.php')).'/index.php';
    }
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '/'.ltrim($file, '/');
    }

    return rtrim($dir, '/').'/'.ltrim($file, '/');
}

function wrapper_shop_archive(): string
{
    return __DIR__.DIRECTORY_SEPARATOR.SETUP_SHOP_ARCHIVE;
}

function wrapper_setup_ready(): bool
{
    return is_file(__DIR__.DIRECTORY_SEPARATOR.'setup'.DIRECTORY_SEPARATOR.'index.php');
}

function wrapper_node_version(): ?string
{
    $null = wrapper_is_windows() ? '2>NUL' : '2>/dev/null';
    exec('node -v '.$null, $out, $code);
    if ($code !== 0 || ! isset($out[0])) {
        return null;
    }

    return ltrim(trim($out[0]), 'v');
}

/**
 * @return array<string, mixed>
 */
function wrapper_compatibility(): array
{
    $phpOk = version_compare(PHP_VERSION, SETUP_PHP_NEED, '>=');
    $extensions = [];
    $allExt = true;
    foreach (SETUP_EXTENSIONS as $ext) {
        $ok = extension_loaded($ext);
        $allExt = $allExt && $ok;
        $extensions[] = ['name' => $ext, 'ok' => $ok];
    }

    $node = wrapper_node_version();
    $nodeVersionOk = $node !== null && version_compare($node, SETUP_NODE_NEED, '>=');
    // Merchant outer zip ships shop.zip with public/build — Node not required to unpack.
    $merchantZip = is_file(wrapper_shop_archive());
    $setupReady = wrapper_setup_ready();
    $assetsReady = is_file(__DIR__.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json');
    $nodeOk = $merchantZip || $setupReady || $assetsReady || $nodeVersionOk;
    $archiveOk = $merchantZip || $setupReady;

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
            'hint' => $phpOk ? null : 'This page is running PHP '.PHP_VERSION.'. Need '.SETUP_PHP_NEED.'+.',
        ],
        [
            'id' => 'extensions',
            'label' => 'php.ini extensions',
            'value' => $allExt ? 'Yes' : 'No',
            'ok' => $allExt,
            'hint' => $allExt ? null : 'Enable missing extensions in '.wrapper_php_ini().', save, restart THIS PHP, then reload. Reload alone is not enough.',
            'extensions' => $extensions,
        ],
        [
            'id' => 'node',
            'label' => 'Node.js available',
            'value' => ($merchantZip || $setupReady || $assetsReady)
                ? ($nodeVersionOk ? 'Yes (v'.$node.', assets in zip)' : 'Not required (assets in zip)')
                : ($nodeVersionOk ? 'Yes (v'.$node.')' : ($node ? 'No (v'.$node.', need '.SETUP_NODE_NEED.'+)' : 'No')),
            'ok' => $nodeOk,
            'hint' => $nodeOk ? null : 'Install Node.js '.SETUP_NODE_NEED.'+ on PATH, then reload. Or use a merchant zip that already includes public/build.',
        ],
        [
            'id' => 'shop_archive',
            'label' => 'Shop archive',
            'value' => $setupReady ? 'Unpacked' : ($merchantZip ? 'Yes ('.SETUP_SHOP_ARCHIVE.')' : 'Missing'),
            'ok' => $archiveOk,
            'status' => $archiveOk ? 'Compatible' : 'Incomplete',
            'hint' => $archiveOk ? null : 'Unzip the customer wentocart.zip into this folder so '.SETUP_SHOP_ARCHIVE.' sits next to the installer, then click Refresh check.',
        ],
    ];

    $ready = true;
    foreach ($rows as $row) {
        if (! $row['ok']) {
            $ready = false;
            break;
        }
    }

    return ['ready' => $ready, 'rows' => $rows, 'ini' => wrapper_php_ini()];
}

/**
 * Rules written when unpacking finishes, before the shop class is available.
 * The file is package/preinstall.htaccess. ApacheHtaccess::preInstallContents() reads the same file.
 */
function wrapper_preinstall_htaccess(): string
{
    $candidates = [
        __DIR__.DIRECTORY_SEPARATOR.'preinstall.htaccess',
        dirname(__DIR__).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'Installer'.DIRECTORY_SEPARATOR.'preinstall.htaccess',
    ];
    foreach ($candidates as $path) {
        if (! is_file($path)) {
            continue;
        }
        $body = file_get_contents($path);
        if (is_string($body) && $body !== '') {
            return str_replace("\r\n", "\n", $body);
        }
    }

    throw new RuntimeException('preinstall.htaccess is missing.');
}

function wrapper_web_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && ! empty($info['name'])) {
            return (string) $info['name'];
        }
    }

    return wrapper_is_windows() ? 'Users' : 'www-data';
}

/**
 * @return list<string>
 */
function wrapper_permission_commands(string $root): array
{
    $user = (string) preg_replace('/[^A-Za-z0-9._-]/', '', wrapper_web_user());
    if ($user === '') {
        $user = wrapper_is_windows() ? 'Users' : 'www-data';
    }
    $quoted = escapeshellarg($root);
    if (wrapper_is_windows()) {
        return ['icacls '.$quoted.' /grant "'.$user.':(OI)(CI)M" /T'];
    }

    return [
        'sudo chown -R '.$user.':'.$user.' '.$quoted,
        'sudo chmod -R ug+rwx '.$quoted,
    ];
}

/**
 * @return list<array{label: string, state: string}>
 */
function wrapper_install_steps(string $current): array
{
    $labels = [
        'System Check',
        'Install Packages',
        'Store Information',
        'Database Setup',
        'Admin Account',
        'Installation',
    ];
    $steps = [];
    $passed = false;
    foreach ($labels as $label) {
        if ($label === $current) {
            $steps[] = ['label' => $label, 'state' => 'current'];
            $passed = true;
            continue;
        }
        $steps[] = ['label' => $label, 'state' => $passed ? '' : 'done'];
    }

    return $steps;
}

/**
 * Shown when the installer cannot create files in this folder.
 */
function wrapper_render_install_guide(string $root): void
{
    $user = wrapper_web_user();
    wrapper_render_page([
        'status' => 503,
        'steps' => wrapper_install_steps('Install Packages'),
        'heading' => 'This folder is not writable',
        'paragraphs' => [
            'The web server account '.$user.' cannot create files here, so unpacking did not start. This folder is unchanged.',
            'Run the command below, then reload this page.',
        ],
        'commands' => wrapper_permission_commands($root),
        'actions' => [
            ['label' => 'Reload this page', 'reload' => true, 'primary' => true],
        ],
        'aside' => 'Give the web server account write access to this folder, then continue.',
    ]);
}

/**
 * @param  array<string, mixed>  $page
 */
function wrapper_render_page(array $page): void
{
    $brand = (string) ($page['brand'] ?? 'WentoCart');
    $steps = is_array($page['steps'] ?? null) ? $page['steps'] : [];
    $heading = (string) ($page['heading'] ?? '');
    $paragraphs = is_array($page['paragraphs'] ?? null) ? $page['paragraphs'] : [];
    $commands = is_array($page['commands'] ?? null) ? $page['commands'] : [];
    $actions = is_array($page['actions'] ?? null) ? $page['actions'] : [];
    $aside = (string) ($page['aside'] ?? '');
    $bodyView = is_string($page['bodyView'] ?? null) ? $page['bodyView'] : '';
    $showProgress = ! empty($page['showProgress']);
    $scriptUrl = is_string($page['scriptUrl'] ?? null) ? $page['scriptUrl'] : '';
    $compat = is_array($page['compat'] ?? null) ? $page['compat'] : null;
    $ready = is_array($compat) && ! empty($compat['ready']);
    $cssUrl = wrapper_href('installer.css');
    $logoUrl = wrapper_href('logo.jpeg');
    $status = (int) ($page['status'] ?? 200);

    if (! headers_sent()) {
        if ($status !== 200) {
            http_response_code($status);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    include __DIR__.DIRECTORY_SEPARATOR.'install-guide.php';
}
