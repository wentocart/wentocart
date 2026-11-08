<?php

namespace App\Domain\Installer;

use App\Domain\Auth\Models\Role;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Localization\ShopLocaleDefaults;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\ShopIdentity;
use App\Models\Admin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InstallerService
{
    public function compatibility(): array
    {
        $phpNeed = (string) config('wento.requires.php', '8.3.0');
        $checks = [
            [
                'id' => 'php',
                'label' => 'PHP',
                'required' => $phpNeed.'+',
                'current' => PHP_VERSION,
                'ok' => version_compare(PHP_VERSION, $phpNeed, '>='),
                'fixable' => false,
                'hint' => version_compare(PHP_VERSION, $phpNeed, '>=')
                    ? null
                    : 'Install PHP '.$phpNeed.' or newer (XAMPP 8.3+). PHP cannot be upgraded from this page.',
            ],
        ];

        foreach ((array) config('wento.requires.extensions', []) as $ext) {
            $ok = extension_loaded($ext);
            $checks[] = [
                'id' => 'ext_'.$ext,
                'label' => 'PHP extension: '.$ext,
                'required' => $ext,
                'current' => $ok ? 'enabled' : 'missing',
                'ok' => $ok,
                'fixable' => false,
                'hint' => $ok ? null : 'Enable extension="'.$ext.'" in '.($this->phpIniPath()).', then reload this page. Extensions cannot be turned on from this installer.',
            ];
        }

        $vendor = is_file(base_path('vendor/autoload.php'));
        $checks[] = [
            'id' => 'vendor',
            'label' => 'Composer packages',
            'required' => 'vendor/',
            'current' => $vendor ? 'present' : 'missing',
            'ok' => $vendor,
            'fixable' => ! $vendor,
            'hint' => $vendor ? null : 'Click “Prepare this copy” or run composer install, then reload.',
        ];

        $assets = is_file(public_path('build/manifest.json'));
        $checks[] = [
            'id' => 'assets',
            'label' => 'Frontend build',
            'required' => 'public/build',
            'current' => $assets ? 'present' : 'missing',
            'ok' => $assets,
            'fixable' => ! $assets,
            'hint' => $assets ? null : 'GitLab source zips do not include built assets. Click “Prepare this copy” (needs Node '.$this->requiredNode().'+), or run npm install && npm run build.',
        ];

        if (! $assets) {
            $nodeNeed = $this->requiredNode();
            $node = $this->nodeVersion();
            $ok = $node !== null && version_compare($node, $nodeNeed, '>=');
            $checks[] = [
                'id' => 'node',
                'label' => 'Node.js',
                'required' => $nodeNeed.'+',
                'current' => $node ?? 'not found',
                'ok' => $ok,
                'fixable' => false,
                'hint' => $ok
                    ? 'Node is OK. Click “Prepare this copy” to build the admin and storefront.'
                    : 'Install Node '.$nodeNeed.' or newer from https://nodejs.org. Node cannot be upgraded from this page.',
            ];
        }

        $storage = is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'));
        $checks[] = [
            'id' => 'folders',
            'label' => 'Writable folders',
            'required' => 'storage, bootstrap/cache',
            'current' => $storage ? 'writable' : 'not writable',
            'ok' => $storage,
            'fixable' => ! $storage,
            'hint' => $storage ? null : 'The web server cannot write storage/ or bootstrap/cache. On Linux, from the shop folder: sudo chown -R www-data:www-data . && sudo chmod -R ug+rwx storage bootstrap/cache . Then reload this page.',
        ];

        return $checks;
    }

    public function ready(): bool
    {
        foreach ($this->compatibility() as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Auto-fix only what this installer can change (folders, .env, Composer, npm build).
     * PHP, Node, and extensions cannot be changed from the app.
     */
    public function repair(): array
    {
        $this->liftTimeLimit();
        $log = [];

        File::ensureDirectoryExists(storage_path('app'));
        File::ensureDirectoryExists(storage_path('framework/cache'));
        File::ensureDirectoryExists(storage_path('framework/sessions'));
        File::ensureDirectoryExists(storage_path('framework/views'));
        File::ensureDirectoryExists(storage_path('logs'));
        File::ensureDirectoryExists(base_path('bootstrap/cache'));
        $log[] = 'Checked storage and bootstrap/cache folders.';

        if (! File::exists(base_path('.env')) && File::exists(base_path('.env.example'))) {
            File::copy(base_path('.env.example'), base_path('.env'));
            try {
                @chmod(base_path('.env'), 0640);
            } catch (\Throwable) {
            }
            $log[] = 'Created .env from .env.example.';
        }

        if (! app()->runningUnitTests() && ! is_file(base_path('vendor/autoload.php'))) {
            $this->runExternal(
                $this->composerInstallCommand(),
                $log,
                'Could not install Composer packages.',
                fn () => is_file(base_path('vendor/autoload.php'))
            );
            $log[] = 'Composer packages installed.';
        }

        if (! app()->runningUnitTests() && ! is_file(public_path('build/manifest.json'))) {
            $node = $this->nodeVersion();
            if ($node === null || version_compare($node, $this->requiredNode(), '<')) {
                throw new RuntimeException('Node '.$this->requiredNode().'+ is required to build the admin UI. Install it from https://nodejs.org, then click Prepare again. Node cannot be upgraded from this page.');
            }

            $this->runExternal('npm install --no-fund --no-audit', $log, 'npm install failed.');
            $this->runExternal('npm run build', $log, 'npm run build failed.', fn () => is_file(public_path('build/manifest.json')));
            $log[] = 'Frontend assets built.';
        }

        return $log;
    }

    /**
     * @param  array<int, string>  $log
     * @param  (callable(): bool)|null  $ok
     */
    protected function runExternal(string $command, array &$log, string $fail, ?callable $ok = null): void
    {
        $output = [];
        exec($command.' 2>&1', $output, $code);
        $log[] = implode("\n", $output);
        if ($code !== 0 || ($ok && ! $ok())) {
            throw new RuntimeException($fail."\n".$log[count($log) - 1]);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function artisanOrFail(string $command, array $parameters = []): void
    {
        $code = Artisan::call($command, $parameters);
        if ($code !== 0) {
            $output = trim(Artisan::output());
            throw new RuntimeException(
                'Install step failed: php artisan '.$command
                .($output !== '' ? "\n".$output : ' (exit '.$code.')')
            );
        }
    }

    /**
     * Replace an empty/non-link public/storage folder with a real link so demo images work.
     */
    protected function ensurePublicStorageLink(): void
    {
        PublicStorageLink::ensure();
    }

    /**
     * Load database/schema/mysql-schema.sql. Pre-release shops do not run Laravel migrations.
     *
     * @param  (callable(string): void)|null  $progress
     */
    protected function migrateDatabase(string $prefix, ?callable $progress = null): void
    {
        DatabaseServerTune::raise($progress);
        DatabasePreflight::assertReady();

        if (SchemaBootstrap::shouldImport()) {
            SchemaBootstrap::import($prefix, $progress);
        }

        InstallProgress::say($progress, '  Database schema is current.');
    }

    protected function composerInstallCommand(): string
    {
        $composer = $this->composerBinary();
        if ($composer === null) {
            throw new RuntimeException('Composer was not found. Install Composer, then click Prepare again, or run composer install in this folder.');
        }

        return $composer === 'composer'
            ? 'composer install --no-interaction --prefer-dist'
            : escapeshellarg(PHP_BINARY).' '.escapeshellarg($composer).' install --no-interaction --prefer-dist';
    }

    protected function composerBinary(): ?string
    {
        if (is_file(base_path('composer.phar'))) {
            return base_path('composer.phar');
        }

        $which = $this->isWindows() ? 'where composer' : 'command -v composer';
        exec($which.' '.$this->devNull(), $out, $code);
        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            return 'composer';
        }

        return null;
    }

    protected function nodeVersion(): ?string
    {
        exec('node -v '.$this->devNull(), $out, $code);
        if ($code !== 0 || ! isset($out[0])) {
            return null;
        }

        return ltrim(trim($out[0]), 'v');
    }

    protected function requiredNode(): string
    {
        return (string) config('wento.requires.node', '20.0.0');
    }

    protected function phpIniPath(): string
    {
        return php_ini_loaded_file() ?: 'php.ini';
    }

    protected function isWindows(): bool
    {
        return stripos(PHP_OS, 'WIN') === 0;
    }

    protected function devNull(): string
    {
        return $this->isWindows() ? '2>NUL' : '2>/dev/null';
    }

    public function alreadyInstalled(): bool
    {
        return file_exists(config('wento.lock_path'));
    }

    protected function liftTimeLimit(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);
    }

    protected function installInProgressPath(): string
    {
        return storage_path('app/installing.lock');
    }

    protected function installInProgress(): bool
    {
        return is_file($this->installInProgressPath());
    }

    protected function markInstallInProgress(): void
    {
        File::ensureDirectoryExists(storage_path('app'));
        File::put($this->installInProgressPath(), now()->toIso8601String());
    }

    protected function clearInstallInProgress(): void
    {
        File::delete($this->installInProgressPath());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function testConnection(array $input): void
    {
        $this->withTemporaryConnection($input, function () {
            DB::connection('install_probe')->getPdo();
        });
    }

    /**
     * Create the database if needed, then fail if it already has tables.
     *
     * @param  array<string, mixed>  $input
     */
    public function assertEmptyDatabase(array $input): void
    {
        $connection = $input['db_connection'] ?? 'mysql';
        if (! in_array($connection, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $this->ensureDatabase($input);

        $occupied = false;
        $this->withTemporaryConnection($input, function () use (&$occupied, $connection) {
            if ($connection === 'pgsql') {
                $occupied = DB::connection('install_probe')->select(
                    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
                ) !== [];
            } else {
                $occupied = DB::connection('install_probe')->select('SHOW TABLES') !== [];
            }
        });

        if ($occupied) {
            throw new RuntimeException('This database name has already been used. Choose another empty database, or empty this database before installing.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  (callable(string): void)|null  $progress
     */
    public function install(array $input, ?callable $progress = null): void
    {
        $say = function (string $line) use ($progress): void {
            if ($progress) {
                $progress($line);
            }
        };

        $this->liftTimeLimit();

        if ($this->alreadyInstalled()) {
            throw new RuntimeException('This store is already installed.');
        }

        if (! $this->ready()) {
            $failed = array_values(array_filter(
                $this->compatibility(),
                static fn (array $check): bool => ! $check['ok']
            ));
            $lines = array_map(static function (array $check): string {
                $hint = trim((string) ($check['hint'] ?? ''));

                return $check['label'].': '.($hint !== '' ? $hint : (string) ($check['current'] ?? 'not ready'));
            }, $failed);

            throw new RuntimeException(
                "This server is not ready to install.\n".implode("\n", $lines)
            );
        }

        DatabasePreflight::validateCredentials($input);

        if (($input['db_connection'] ?? '') === 'sqlite') {
            $prefix = '';
            $input['db_prefix'] = '';
        } else {
            $prefix = wento_db_prefix($input['db_prefix'] ?? '');
            $input['db_prefix'] = $prefix;
        }
        $wantDemo = (bool) ($input['install_demo_data'] ?? false);

        $say('[phase:database]');
        InstallProgress::note($progress, 'Preparing your database…');
        InstallProgress::tick($progress, 'database', 0, 100, 'Preparing your database…');
        $this->ensureDatabase($input);
        $this->applyRuntimeDatabase($input, $prefix);
        $this->assertDatabaseAvailable();
        $this->markInstallInProgress();
        $this->writeEnv($input);

        Artisan::call('config:clear');

        $this->ensureValidAppKey();

        InstallProgress::note($progress, 'Creating store tables…');
        $tablesStarted = microtime(true);
        $this->migrateDatabase($prefix, $progress);
        InstallProgress::stepDone($progress, 'Database ready', $tablesStarted);

        $say('[phase:configuration]');
        InstallProgress::note($progress, 'Configuring your store…');
        $seedStarted = microtime(true);
        config(['wento.installing_demo' => $wantDemo]);
        config(['wento.installing' => true]);
        config(['wento.install_progress' => $progress]);
        $this->artisanOrFail('db:seed', ['--class' => 'Database\\Seeders\\CoreSeeder', '--force' => true]);
        ShopLocaleDefaults::applyChosen(
            (string) ($input['language'] ?? 'en'),
            (string) ($input['currency'] ?? 'USD'),
        );
        config(['wento.install_progress' => null]);
        InstallProgress::tick($progress, 'configuration', 100, 100, 'Languages, currencies, regions & permissions');
        InstallProgress::stepDone($progress, 'Shop settings', $seedStarted);

        $this->ensurePublicStorageLink();
        $say('[phase:design]');
        InstallProgress::note($progress, 'Installing store design…');
        app(\App\Domain\Theme\ThemeInstaller::class)->publishPublic('classic', false);
        if ($wantDemo) {
            $demoStarted = microtime(true);
            try {
                app(DemoDataService::class)->installCatalog($progress);
                InstallProgress::stepDone($progress, 'Demo catalog', $demoStarted);
            } catch (Throwable $e) {
                InstallProgress::note($progress, 'Demo data could not be installed — continuing with an empty catalog.');
                $say('  WARNING: demo data failed — continuing without it.');
                $say('  '.$e->getMessage());
            }
        } else {
            InstallProgress::tick($progress, 'design', 1, 1, 'Classic theme assets published');
            $say('[phase:demo]');
            InstallProgress::note($progress, 'Skipping demo data (empty catalog).');
            InstallProgress::tick($progress, 'demo', 1, 1, 'Demo data skipped');
        }
        $this->ensurePublicStorageLink();
        File::delete(public_path('hot'));

        $say('[phase:admin]');
        InstallProgress::note($progress, 'Saving your admin account…');
        InstallProgress::tick($progress, 'admin', 1, 3, 'Saving your admin account…');
        $adminStarted = microtime(true);
        $admin = Admin::query()->updateOrCreate(
            ['email' => $input['admin_email']],
            [
                'name' => $input['admin_name'] ?? 'Administrator',
                'password' => $input['admin_password'],
                'is_enabled' => true,
            ]
        );

        $super = Role::query()->where('slug', 'super_admin')->first();
        if ($super) {
            $admin->roles()->syncWithoutDetaching([$super->id]);
        }

        $merchantEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
        foreach (['admin@wentocart.test', 'demo@demo.com', 'Demo@demo.com'] as $defaultEmail) {
            if (strtolower($defaultEmail) === $merchantEmail) {
                continue;
            }
            $stale = Admin::query()->where('email', $defaultEmail)->first();
            if ($stale) {
                $stale->roles()->detach();
                $stale->delete();
                $say('  Removed a leftover default admin account ('.$defaultEmail.').');
            }
        }
        config(['wento.installing' => false]);

        InstallProgress::tick($progress, 'admin', 2, 3, 'Saving store website address…');
        ShopUrl::query()->updateOrCreate(
            ['is_primary' => true],
            ShopUrl::attributesFromUrl($input['app_url'] ?? config('app.url'))
        );
        Setting::setValue('shop_name', $input['store_name'] ?? config('wento.name'));
        Setting::setValue('shop_email', $input['store_email'] ?? '');
        Setting::setValue('shop_address', wento_plain_text((string) ($input['store_address'] ?? '')));
        Setting::setValue('shop_url', $input['app_url'] ?? config('app.url'));
        ShopIdentity::captureInstall($input);

        InstallProgress::tick($progress, 'admin', 3, 3, 'Finishing admin settings…');
        $lockPath = (string) config('wento.lock_path');
        File::ensureDirectoryExists(dirname($lockPath));
        if (File::put($lockPath, now()->toIso8601String()) === false) {
            throw new RuntimeException('Could not write install lock at '.$lockPath.'. Check that storage/app is writable.');
        }
        $this->writeEnvPairs(['WENTO_INSTALLED' => 'true']);
        $this->clearInstallInProgress();
        if (File::put(storage_path('app/tidy-installer'), now()->toIso8601String()) === false) {
            throw new RuntimeException('Could not write installer tidy flag. Check that storage/app is writable.');
        }

        app(\App\Domain\Plugin\ExtensionDefaults::class)->apply();
        try {
            \App\Domain\Platform\Upgrade\PlatformMeta::stamp((string) config('wento.version', '1.0.0'));
        } catch (\Throwable) {
            // meta tables created lazily on first upgrade if schema dump lags
        }
        InstallProgress::stepDone($progress, 'Admin account', $adminStarted);
        $say('[phase:finish]');
        InstallProgress::note($progress, 'Installation completed.');
        InstallProgress::tick($progress, 'finish', 1, 1, 'Installation completed');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function ensureDatabase(array $input): void
    {
        $connection = $input['db_connection'] ?? 'mysql';
        if (! in_array($connection, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $input['db_database']);
        if ($name === '') {
            throw new RuntimeException('Database name is invalid.');
        }

        if ($connection === 'pgsql') {
            $this->withTemporaryConnection($input, function () use ($name) {
                $exists = DB::connection('install_probe')->selectOne(
                    'SELECT 1 AS ok FROM pg_database WHERE datname = ?',
                    [$name]
                );
                if (! $exists) {
                    DB::connection('install_probe')->statement('CREATE DATABASE "'.$name.'"');
                }
            }, withoutDatabase: true);

            return;
        }

        $this->withTemporaryConnection($input, function () use ($name) {
            DB::connection('install_probe')->statement(
                'CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        }, withoutDatabase: true);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function applyRuntimeDatabase(array $input, string $prefix): void
    {
        $name = $input['db_connection'] ?? 'mysql';
        $conn = config('database.connections.'.$name);
        $conn['prefix'] = ($name === 'sqlite') ? '' : $prefix;

        if (in_array($name, ['mysql', 'mariadb'], true)) {
            $conn['host'] = $input['db_host'] ?? '127.0.0.1';
            $conn['port'] = $input['db_port'] ?? '3306';
            $conn['database'] = $input['db_database'];
            $conn['username'] = $input['db_username'] ?? 'root';
            $conn['password'] = $input['db_password'] ?? '';
        }

        if ($name === 'pgsql') {
            $conn['host'] = $input['db_host'] ?? '127.0.0.1';
            $conn['port'] = $input['db_port'] ?? '5432';
            $conn['database'] = $input['db_database'];
            $conn['username'] = $input['db_username'] ?? 'wento';
            $conn['password'] = $input['db_password'] ?? '';
        }

        if ($name === 'sqlite') {
            $database = (string) ($input['db_database'] ?? database_path('database.sqlite'));
            if ($database !== ':memory:' && ! str_contains($database, '/') && ! str_contains($database, '\\')) {
                $database = database_path($database);
            }
            if ($database !== ':memory:') {
                File::ensureDirectoryExists(dirname($database));
                if (! File::exists($database)) {
                    File::put($database, '');
                }
            }
            $conn['database'] = $database;
        }

        config([
            'database.connections.'.$name => $conn,
            'database.default' => $name,
            'wento.table_prefix' => ($name === 'sqlite') ? '' : $prefix,
        ]);

        DB::purge($name);
        DB::reconnect($name);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  callable(): void  $callback
     */
    protected function withTemporaryConnection(array $input, callable $callback, bool $withoutDatabase = false): void
    {
        $name = $input['db_connection'] ?? 'mysql';
        $base = config('database.connections.'.$name);

        if (in_array($name, ['mysql', 'mariadb'], true)) {
            $base['host'] = $input['db_host'] ?? '127.0.0.1';
            $base['port'] = $input['db_port'] ?? '3306';
            $base['database'] = $withoutDatabase ? '' : ($input['db_database'] ?? '');
            $base['username'] = $input['db_username'] ?? 'root';
            $base['password'] = $input['db_password'] ?? '';
            $base['prefix'] = '';
        }

        if ($name === 'pgsql') {
            $base['host'] = $input['db_host'] ?? '127.0.0.1';
            $base['port'] = $input['db_port'] ?? '5432';
            // Connect to maintenance DB when creating the target database.
            $base['database'] = $withoutDatabase ? 'postgres' : ($input['db_database'] ?? '');
            $base['username'] = $input['db_username'] ?? 'wento';
            $base['password'] = $input['db_password'] ?? '';
            $base['prefix'] = '';
        }

        if ($name === 'sqlite') {
            $base['database'] = $input['db_database'] ?? database_path('database.sqlite');
        }

        config(['database.connections.install_probe' => $base]);
        DB::purge('install_probe');

        try {
            $callback();
        } catch (Throwable $e) {
            throw new RuntimeException(DatabasePreflight::explain($e, $input), 0, $e);
        } finally {
            DB::purge('install_probe');
        }
    }

    /**
     * True when APP_KEY can drive SESSION_ENCRYPT / cookies (base64 32-byte payload).
     */
    public static function appKeyIsValid(?string $key): bool
    {
        $key = trim((string) $key);
        if ($key === '' || str_contains($key, '<blanked>') || strcasecmp($key, 'null') === 0) {
            return false;
        }
        if (! str_starts_with($key, 'base64:')) {
            return false;
        }
        $raw = base64_decode(substr($key, 7), true);

        return $raw !== false && strlen($raw) === 32;
    }

    /**
     * Replace empty or redacted APP_KEY placeholders before sessions encrypt.
     */
    protected function ensureValidAppKey(): void
    {
        if (self::appKeyIsValid((string) config('app.key'))) {
            return;
        }

        $this->writeEnvPairs(['APP_KEY' => '']);
        Artisan::call('config:clear');
        $this->artisanOrFail('key:generate', ['--force' => true]);
    }

    /**
     * Cookie auth must trust both the wizard host and the public shop host.
     *
     * @return list<string>
     */
    protected function sanctumDomainsForInstall(string $appUrl, string $detectedUrl): array
    {
        $domains = ShopUrl::sanctumDomainsFromUrl($appUrl);
        $detectedUrl = trim($detectedUrl);
        if ($detectedUrl !== '' && $detectedUrl !== $appUrl) {
            $domains = array_merge($domains, ShopUrl::sanctumDomainsFromUrl($detectedUrl));
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function writeEnv(array $input): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            if (File::exists(base_path('.env.example'))) {
                File::copy(base_path('.env.example'), $path);
            } else {
                // Folder was copied without dotfiles (cp * skips them).
                // Start from a minimal .env; writeEnvPairs() fills in every value below.
                File::put($path, "APP_NAME=WentoCart\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_URL=http://localhost\nAPP_KEY=\n");
            }
        }

        $appUrl = trim((string) ($input['app_url'] ?? config('app.url') ?? ''));
        if ($appUrl !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $appUrl)) {
            $appUrl = 'http://'.$appUrl;
        }

        $this->writeEnvPairs([
            'APP_NAME' => $input['store_name'] ?? config('wento.name'),
            'APP_URL' => $appUrl !== '' ? $appUrl : 'http://localhost',
            'DB_CONNECTION' => $input['db_connection'] ?? 'mysql',
            'DB_HOST' => $input['db_host'] ?? '127.0.0.1',
            'DB_PORT' => $input['db_port'] ?? '3306',
            'DB_DATABASE' => $input['db_database'] ?? config('wento.codename'),
            'DB_USERNAME' => $input['db_username'] ?? 'root',
            'DB_PASSWORD' => $input['db_password'] ?? '',
            'DB_PREFIX' => rtrim((string) ($input['db_prefix'] ?? ''), '_'),
            'SESSION_PATH' => ShopUrl::sessionPathFromUrl($appUrl !== '' ? $appUrl : '/'),
            'SANCTUM_STATEFUL_DOMAINS' => implode(',', $this->sanctumDomainsForInstall(
                $appUrl !== '' ? $appUrl : 'http://localhost',
                (string) ($input['detected_url'] ?? ''),
            )),
            'WENTO_LANGUAGE' => $input['language'] ?? 'en',
            'WENTO_CURRENCY' => $input['currency'] ?? 'USD',
            'MAIL_FROM_ADDRESS' => $input['store_email'] ?? 'hello@example.com',
            'MAIL_FROM_NAME' => $input['store_name'] ?? config('wento.name'),
            // Set only after installed.lock. Writing it here makes the wizard
            // treat the shop as finished and answer "Not found" while tables are still being created.
            'WENTO_INSTALLED' => 'false',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'SESSION_ENCRYPT' => 'true',
            'SESSION_ADMIN_LIFETIME' => '120',
            'SESSION_CUSTOMER_LIFETIME' => '480',
        ]);

        ApacheHtaccess::write(base_path(), $appUrl !== '' ? $appUrl : (string) config('app.url'));
    }

    /**
     * @param  array<string, scalar>  $pairs
     */
    public function writeEnvPairs(array $pairs): void
    {
        $path = base_path('.env');
        if (! File::exists($path)) {
            if (! File::exists(base_path('.env.example'))) {
                return;
            }
            File::copy(base_path('.env.example'), $path);
        }

        $env = File::get($path);
        foreach ($pairs as $key => $value) {
            $value = $this->escapeEnv((string) $value);
            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", $key.'='.$value, $env);
            } else {
                $env .= PHP_EOL.$key.'='.$value;
            }
        }

        File::put($path, $env);

        // Secrets live here (DB password, app key): never world-readable.
        // 0640 (not 0600) keeps CLI-as-another-group-user workflows alive.
        try {
            @chmod($path, 0640);
        } catch (\Throwable) {
            // Filesystems that ignore chmod (Windows/FAT) — nothing to do.
        }
    }

    protected function assertDatabaseAvailable(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        } elseif ($driver === 'pgsql') {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        } else {
            $tables = DB::select('SHOW TABLES');
        }

        if ($tables !== []) {
            throw new RuntimeException('This database name has already been used. Choose another empty database, or empty this database before installing.');
        }
    }

    protected function escapeEnv(string $value): string
    {
        if ($value === '' || str_contains($value, ' ') || str_contains($value, '#')) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
