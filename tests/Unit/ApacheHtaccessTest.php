<?php

namespace Tests\Unit;

use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Installer\ApacheHtaccess;
use Tests\TestCase;

class ApacheHtaccessTest extends TestCase
{
    public function test_folder_install_sets_rewrite_base(): void
    {
        $this->assertSame('/shop/', ApacheHtaccess::baseFromUrl('https://shop.example/shop'));
        $this->assertSame('/shop/', ApacheHtaccess::baseFromUrl('shop.example/shop'));
        $this->assertSame('/', ApacheHtaccess::baseFromUrl('https://shop.example'));
    }

    public function test_inserts_or_replaces_rewrite_base(): void
    {
        $added = ApacheHtaccess::withRewriteBase("RewriteEngine On\nRewriteRule ^ index.php [L]\n", '/shop/');
        $this->assertStringContainsString('RewriteBase /shop/', $added);

        $replaced = ApacheHtaccess::withRewriteBase("RewriteEngine On\n    RewriteBase /\n", '/shop/');
        $this->assertStringContainsString('RewriteBase /shop/', $replaced);
        $this->assertSame(1, preg_match_all('/RewriteBase /', $replaced));
    }

    public function test_committed_htaccess_is_not_tied_to_a_developer_folder(): void
    {
        $root = (string) file_get_contents(base_path('.htaccess'));
        $public = (string) file_get_contents(base_path('public/.htaccess'));

        $this->assertSame(ApacheHtaccess::preInstallContents(), $root);
        $this->assertStringNotContainsString('RewriteBase', $root);
        $this->assertStringNotContainsString('FallbackResource', $root);
        $this->assertMatchesRegularExpression('/^RewriteBase \/\s*$/m', $public);

        if (is_link(public_path('storage'))) {
            $this->assertSame(
                realpath(storage_path('app/public')),
                realpath(public_path('storage')),
            );
        }
    }

    public function test_shop_url_wins_over_a_stale_rewrite_base(): void
    {
        $shop = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-real-base-'.bin2hex(random_bytes(4));
        mkdir($shop, 0777, true);
        file_put_contents($shop.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\nRewriteBase /old-shop/\n");

        try {
            $this->assertSame('/shop/', ApacheHtaccess::resolvedBase($shop, 'https://shop.example/shop/'));
            $this->assertSame('/old-shop/', ApacheHtaccess::resolvedBase($shop, 'https://shop.example'));
        } finally {
            @unlink($shop.DIRECTORY_SEPARATOR.'.htaccess');
            @rmdir($shop);
        }
    }

    public function test_preinstall_htaccess_stays_in_the_shop_folder(): void
    {
        $htaccess = ApacheHtaccess::preInstallContents();
        $code = 'require '.var_export(base_path('package/checks.php'), true).'; echo wrapper_preinstall_htaccess();';
        $fallback = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

        $this->assertSame($htaccess, $fallback);
        $this->assertStringNotContainsString('RewriteBase', $htaccess);
        $this->assertStringNotContainsString('FallbackResource', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $htaccess);
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        $this->assertStringContainsString('RewriteRule (^|/)\\.env - [F,L]', $htaccess);
        $this->assertStringContainsString('RewriteRule (^|/)\\.git - [F,L]', $htaccess);
        $this->assertStringContainsString('RewriteRule \\.map$ - [F,L]', $htaccess);
        $this->assertStringContainsString('RewriteRule ^(database|vendor|node_modules|app|bootstrap|config|routes|resources|scripts|package|extensions|tests|docs|storefront|docker|plugin-packs)(/|$) - [F,L]', $htaccess);
        $this->assertStringContainsString('Options -Indexes', $htaccess);
    }

    public function test_folder_root_htaccess_sends_admin_and_storage_to_the_shop(): void
    {
        $htaccess = ApacheHtaccess::rootContents('/shop/');
        $this->assertStringContainsString('RewriteBase /shop/', $htaccess);
        $this->assertStringContainsString('RewriteRule ^admin(/.*)?$ index.php [L]', $htaccess);
        $this->assertStringContainsString('FallbackResource /shop/index.php', $htaccess);
        $this->assertStringContainsString('RewriteRule ^(build|themes|plugins|hot|images|i18n)(/.*)?$ public/$1$2 [L]', $htaccess);
        $this->assertStringContainsString('RewriteRule ^storage/(.*)$ public/storage/$1 [L]', $htaccess);
        $this->assertStringContainsString('RewriteRule (^|/)\\.env - [F,L]', $htaccess);
        $this->assertStringContainsString('RewriteRule ^(artisan|composer\\.(json|lock)|phpunit\\.xml)$ - [F,L]', $htaccess);
        $this->assertStringNotContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $htaccess);
    }

    public function test_ensure_rewrites_when_rewrite_base_mismatches_app_url(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-htaccess-ensure-'.bin2hex(random_bytes(4));
        mkdir($dir);
        mkdir($dir.DIRECTORY_SEPARATOR.'public');
        mkdir($dir.DIRECTORY_SEPARATOR.'storage');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.htaccess', ApacheHtaccess::rootContents('/'));
        file_put_contents($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\n    RewriteBase /\n");

        try {
            $this->assertTrue(ApacheHtaccess::ensure($dir, 'http://localhost/www/shop'));
            $root = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertStringContainsString('RewriteBase /www/shop/', $root);
            $this->assertStringContainsString('FallbackResource /www/shop/index.php', $root);
            $this->assertFalse(ApacheHtaccess::ensure($dir, 'http://localhost/www/shop'));
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'.htaccess');
            @rmdir($dir.DIRECTORY_SEPARATOR.'storage');
            @rmdir($dir.DIRECTORY_SEPARATOR.'public');
            @rmdir($dir);
        }
    }

    public function test_pathless_app_url_does_not_send_a_folder_install_to_the_server_root(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-htaccess-keep-'.bin2hex(random_bytes(4));
        mkdir($dir);
        mkdir($dir.DIRECTORY_SEPARATOR.'public');
        mkdir($dir.DIRECTORY_SEPARATOR.'storage');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.htaccess', ApacheHtaccess::rootContents('/shop/'));
        file_put_contents($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\n    RewriteBase /shop/public/\n");

        try {
            ApacheHtaccess::ensure($dir, 'http://localhost');
            $root = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertStringContainsString('RewriteBase /shop/', $root);
            $this->assertStringContainsString('FallbackResource /shop/index.php', $root);
            $this->assertStringNotContainsString('FallbackResource /index.php', $root);
            $this->assertStringContainsString('WENTO-STATIC-CACHE', (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess'));
            $this->assertFalse(ApacheHtaccess::ensure($dir, 'http://localhost'));
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'.htaccess');
            @rmdir($dir.DIRECTORY_SEPARATOR.'storage');
            @rmdir($dir.DIRECTORY_SEPARATOR.'public');
            @rmdir($dir);
        }
    }

    public function test_write_keeps_local_php83_cgi_bridge(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-htaccess-'.bin2hex(random_bytes(4));
        mkdir($dir);
        mkdir($dir.DIRECTORY_SEPARATOR.'public');
        mkdir($dir.DIRECTORY_SEPARATOR.'storage');
        file_put_contents($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\n");
        $bridge = <<<'HT'
# Force PHP 8.3 CGI on this folder (XAMPP Apache ships PHP 7.4).
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
Options +ExecCGI
AddHandler cgi-script .cgi
RemoveHandler .php
RemoveType .php
AddType application/x-httpd-php83 .php
Action application/x-httpd-php83 /shop/php83.cgi virtual

HT;
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.htaccess', $bridge."<IfModule mod_rewrite.c>\n    RewriteEngine On\n</IfModule>\n");

        try {
            ApacheHtaccess::write($dir, 'http://localhost/shop');
            $out = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'.htaccess');
            $this->assertStringContainsString('Action application/x-httpd-php83 /shop/php83.cgi virtual', $out);
            $this->assertStringContainsString('php_flag engine off', $out);
            $this->assertStringContainsString('RewriteBase /shop/', $out);
            $this->assertSame(1, preg_match_all('/RewriteEngine On/', $out));
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'.htaccess');
            @unlink($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'.htaccess');
            @rmdir($dir.DIRECTORY_SEPARATOR.'storage');
            @rmdir($dir.DIRECTORY_SEPARATOR.'public');
            @rmdir($dir);
        }
    }

    public function test_plain_htaccess_gets_no_cgi_bridge(): void
    {
        $this->assertSame('', ApacheHtaccess::extractPhpCgiBridge("RewriteEngine On\nRewriteBase /\n"));
    }

    public function test_storage_htaccess_does_not_deny_public_media(): void
    {
        $htaccess = ApacheHtaccess::storageContents();
        $this->assertStringContainsString('Require all granted', $htaccess);
        $this->assertStringContainsString('app/public/$1', $htaccess);
        $this->assertStringNotContainsString('Require all denied', $htaccess);
    }

    public function test_catalog_media_urls_include_the_shop_folder(): void
    {
        config(['app.url' => 'http://localhost:8000/shop']);

        $this->assertSame('/shop/storage/covers/a.jpg', CatalogMediaUrl::url('covers/a.jpg'));
        $this->assertSame('/shop/storage/covers/a.jpg', CatalogMediaUrl::url('/storage/covers/a.jpg'));
        $this->assertSame('/shop/images/default/p-sm-default.png', CatalogMediaUrl::url(null, 'sm'));
        $this->assertSame(
            'http://localhost:8000/shop/storage/covers/a.jpg',
            CatalogMediaUrl::asset('covers/a.jpg', 'sm'),
        );
        $this->assertStringNotContainsString('/shop/shop/', CatalogMediaUrl::asset('covers/a.jpg', 'sm'));
    }
}
