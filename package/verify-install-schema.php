<?php

/**
 * Replay the wizard DB path on MySQL/MariaDB (dump import → ALTER guard → seed).
 *
 *   php package/verify-install-schema.php --host=127.0.0.1 --port=3306 --user=wento --password=wento
 */

use App\Domain\Catalog\MerchantStoreBuilder;
use App\Domain\Catalog\Models\Category;
use App\Domain\Installer\InstallSchemaGuard;
use App\Domain\Installer\SchemaBootstrap;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_PREFIX', 'DB_URL'] as $envKey) {
    putenv($envKey);
    unset($_ENV[$envKey], $_SERVER[$envKey]);
}

$opts = getopt('', ['host::', 'port::', 'user::', 'password::']);
$host = $opts['host'] ?? '127.0.0.1';
$port = (int) ($opts['port'] ?? 3306);
$user = $opts['user'] ?? 'wento';
$pass = $opts['password'] ?? 'wento';
$prefix = 'WENTO_';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$failures = [];

function adminPdo(string $host, int $port, string $user, string $pass): PDO
{
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function bindMysql(string $host, int $port, string $user, string $pass, string $database, string $prefix): void
{
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $host,
        'database.connections.mysql.port' => (string) $port,
        'database.connections.mysql.database' => $database,
        'database.connections.mysql.username' => $user,
        'database.connections.mysql.password' => $pass,
        'database.connections.mysql.prefix' => $prefix,
    ]);
    DB::purge('mysql');
    DB::reconnect('mysql');
}

function dumpWithoutCategoryCover(bool $dropMigrationRow): string
{
    $sql = File::get(SchemaBootstrap::path());
    $sql = preg_replace(
        '/(CREATE TABLE IF NOT EXISTS `__DB_PREFIX__categories` \(.*?)`cover_path` varchar\(255\) DEFAULT NULL,\s*/s',
        '$1',
        $sql,
        1,
        $count
    );
    if ($count !== 1) {
        throw new RuntimeException('Could not strip categories.cover_path from dump.');
    }
    if ($dropMigrationRow) {
        $sql = str_replace(
            "INSERT INTO `__DB_PREFIX__migrations` (`id`, `migration`, `batch`) VALUES (96, '2026_09_07_103000_add_cover_path_to_categories', 1);\n",
            '',
            $sql
        );
    }

    $path = sys_get_temp_dir().'/wento-dump-'.bin2hex(random_bytes(4)).'.sql';
    File::put($path, $sql);

    return $path;
}

function importDump(string $prefix, ?string $path = null): void
{
    $original = SchemaBootstrap::path();
    $tmp = null;
    if ($path !== null) {
        $tmp = $original.'.verify.bak';
        File::copy($original, $tmp);
        File::copy($path, $original);
    }
    try {
        SchemaBootstrap::import($prefix);
    } finally {
        if ($tmp !== null) {
            File::copy($tmp, $original);
            File::delete($tmp);
        }
    }
}

function assertCoverPath(string $label): void
{
    if (! InstallSchemaGuard::hasColumn('categories', 'cover_path')) {
        throw new RuntimeException($label.': cover_path still missing after patch');
    }

    $id = DB::table('categories')->insertGetId([
        'is_enabled' => 1,
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $path = 'catalog/categories/'.$id.'/banner.png';
    $category = Category::query()->findOrFail($id);
    InstallSchemaGuard::write($category, [
        'cover_path' => $path,
        'image_path' => $path,
        'banner_path' => $path,
    ]);
    $physical = DB::getTablePrefix().'categories';
    DB::update(
        'update `'.$physical.'` set `cover_path` = ?, `'.$physical.'`.`updated_at` = ? where `id` = ?',
        [$path, now(), $id]
    );
    if ($category->fresh()->cover_path !== $path) {
        throw new RuntimeException($label.': cover_path UPDATE did not persist');
    }
}

function writeMerchantDummies(): void
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $root = base_path('themes/classic/assets/merchant');
    foreach (['banners', 'products', 'promo', 'home'] as $dir) {
        File::ensureDirectoryExists($root.'/'.$dir);
    }
    for ($i = 1; $i <= 19; $i++) {
        $file = $root.'/banners/'.$i.'.png';
        if (! is_file($file)) {
            File::put($file, $png);
        }
    }
    for ($i = 1; $i <= 33; $i++) {
        $file = $root.'/products/'.$i.'.png';
        if (! is_file($file)) {
            File::put($file, $png);
        }
    }
}

function runMerchantSeed(string $label): void
{
    writeMerchantDummies();
    $code = Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CoreSeeder', '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException($label.': CoreSeeder failed: '.Artisan::output());
    }
    app(MerchantStoreBuilder::class)->run();
    $withCover = (int) DB::table('categories')->whereNotNull('cover_path')->where('cover_path', '!=', '')->count();
    if ($withCover < 1) {
        throw new RuntimeException($label.': MerchantStoreBuilder did not set categories.cover_path');
    }
    $withProductCover = (int) DB::table('products')->whereNotNull('cover_path')->where('cover_path', '!=', '')->count();
    if ($withProductCover < 1) {
        throw new RuntimeException($label.': MerchantStoreBuilder did not set products.cover_path');
    }
}

function runWizardPath(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $database,
    string $prefix,
    ?string $dumpPath,
    string $label,
    bool $merchant = false
): void {
    $admin = adminPdo($host, $port, $user, $pass);
    $admin->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    bindMysql($host, $port, $user, $pass, $database, $prefix);
    if (! SchemaBootstrap::shouldImport()) {
        throw new RuntimeException($label.': shouldImport() was false on an empty database');
    }
    importDump($prefix, $dumpPath);
    InstallSchemaGuard::patch($prefix);

    foreach (InstallSchemaGuard::requiredStringColumns() as $table => $columns) {
        if (! InstallSchemaGuard::tableExists($table, $prefix)) {
            continue;
        }
        foreach ($columns as $column) {
            if (! InstallSchemaGuard::hasColumn($table, $column, $prefix)) {
                throw new RuntimeException($label.": missing {$table}.{$column} after guard");
            }
        }
    }

    assertCoverPath($label);

    if ($merchant) {
        runMerchantSeed($label);
    }

    fwrite(STDOUT, "OK: {$label}\n");
}

try {
    $admin = adminPdo($host, $port, $user, $pass);
    $admin->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to MySQL at {$host}:{$port}: {$e->getMessage()}\n");
    exit(2);
}

$staleDump = dumpWithoutCategoryCover(false);
$staleDumpAndMigration = dumpWithoutCategoryCover(true);

$cases = [
    ['wento_verify_current', null, 'current dump + guard + cover_path UPDATE', false],
    ['wento_verify_stale_dump', $staleDump, 'dump missing cover_path, 09_07 already recorded, ALTER + UPDATE', false],
    ['wento_verify_stale_mig', $staleDumpAndMigration, 'dump missing cover_path, 09_07 not recorded, ALTER + UPDATE', false],
    ['wento_verify_merchant', $staleDump, 'stale dump + CoreSeeder + MerchantStoreBuilder category/product covers', true],
];

foreach ($cases as [$database, $dumpPath, $label, $merchant]) {
    try {
        runWizardPath($host, $port, $user, $pass, $database, $prefix, $dumpPath, $label, $merchant);
    } catch (Throwable $e) {
        $failures[] = $label.': '.$e->getMessage();
        fwrite(STDERR, 'FAIL: '.$label.': '.$e->getMessage()."\n");
    }
}

@unlink($staleDump);
@unlink($staleDumpAndMigration);

if ($failures !== []) {
    fwrite(STDERR, "\n".count($failures)." MySQL install schema check(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "\nAll MySQL wizard DB checks passed.\n");
exit(0);
