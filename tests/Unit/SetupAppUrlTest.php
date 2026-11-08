<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SetupAppUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2).'/setup/lib.php';
    }

    public function test_wizard_rejects_a_shop_address_on_another_host_or_port(): void
    {
        $opened = 'http://127.0.0.1:8099';

        $this->assertTrue(setup_same_serving_origin($opened, 'http://localhost:8099/'));
        $this->assertFalse(setup_same_serving_origin($opened, 'http://127.0.0.1:8000'));
        $this->assertFalse(setup_same_serving_origin($opened, 'http://example.test:8099'));
        $this->assertFalse(setup_same_serving_origin($opened, 'https://127.0.0.1:8099'));
        $this->assertFalse(setup_same_serving_origin($opened, 'http://127.0.0.1:8099/shop'));
    }

    public function test_wizard_accepts_underscores_inside_table_prefix(): void
    {
        $this->assertTrue(setup_db_prefix_valid('new_'));
        $this->assertTrue(setup_db_prefix_valid('NEW'));
        $this->assertTrue(setup_db_prefix_valid('wento'));
        $this->assertTrue(setup_db_prefix_valid('tiger_'));
        $this->assertTrue(setup_db_prefix_valid('tigerrrr_____'));
        $this->assertTrue(setup_db_prefix_valid('tia_2_'));
        $this->assertTrue(setup_db_prefix_valid('Lo_as_as_'));
        $this->assertSame('NEW', setup_db_prefix_stem('new_'));
        $this->assertSame('TIGER', setup_db_prefix_stem('tiger_'));
        $this->assertSame('TIGERRRR', setup_db_prefix_stem('tigerrrr_____'));
        $this->assertSame('TIA_2', setup_db_prefix_stem('tia_2_'));
        $this->assertSame('LO_AS_AS', setup_db_prefix_stem('Lo_as_as_'));
        $this->assertFalse(setup_db_prefix_valid('_new'));
        $this->assertFalse(setup_db_prefix_valid('n_'));
        $this->assertFalse(setup_db_prefix_valid('2tiger'));
        $this->assertFalse(setup_db_prefix_valid('tiger-shop'));
        $this->assertFalse(setup_db_prefix_valid('bad prefix'));
    }

    public function test_street_address_strips_html_and_keeps_punctuation(): void
    {
        $clean = setup_plain_text("test shop,\nbihari colony, #12 <script>x</script>delhi-110032");

        $this->assertStringContainsString('test shop', $clean);
        $this->assertStringContainsString('#12', $clean);
        $this->assertStringContainsString('delhi-110032', $clean);
        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('</script>', $clean);
    }

    public function test_compose_joins_worldwide_fields_and_falls_back_to_store_address(): void
    {
        $joined = setup_compose_store_address([
            'store_line1' => '350 Fifth Avenue',
            'store_city' => 'New York',
            'store_region' => 'NY',
            'store_postal' => '10118',
            'store_country' => 'US',
        ]);

        $this->assertStringContainsString('350 Fifth Avenue', $joined);
        $this->assertStringContainsString('New York', $joined);
        $this->assertStringContainsString('United States', $joined);

        $legacy = setup_compose_store_address([
            'store_address' => "test shop,\nbihari colony, #12",
        ]);
        $this->assertStringContainsString('test shop', $legacy);
        $this->assertStringContainsString('#12', $legacy);
    }

    public function test_app_url_issue_explains_street_vs_missing_scheme_vs_wrong_host(): void
    {
        $detected = 'http://127.0.0.1:8099';

        $this->assertStringContainsString('street address', strtolower((string) setup_app_url_issue('Shop no 12, Delhi', $detected)));
        $this->assertStringContainsString('http://', (string) setup_app_url_issue('example.com', $detected));
        $this->assertStringContainsString('127.0.0.1:8099', (string) setup_app_url_issue('http://example.com', $detected));
        $this->assertNull(setup_app_url_issue('http://localhost:8099/', $detected));
    }

    public function test_php_helper_is_detected_from_development_server_banner(): void
    {
        $this->assertTrue(setup_is_php_development_server('PHP 8.3.35 Development Server'));
        $this->assertFalse(setup_is_php_development_server('Apache/2.4.58 (Win64)'));
    }

    public function test_helper_url_splits_host_and_port(): void
    {
        $parts = setup_shop_url_parts('http://127.0.0.1:8099');

        $this->assertSame('http://127.0.0.1', $parts['host_url']);
        $this->assertSame(8099, $parts['port']);
    }

    public function test_install_done_links_use_chosen_app_url_not_detected_helper(): void
    {
        $this->assertSame(
            'http://127.0.0.1:8098',
            setup_install_public_base([
                'app_url' => 'http://127.0.0.1:8098',
                'detected_url' => 'http://127.0.0.1:8099',
            ]),
        );
    }

    public function test_helper_can_rewrite_shop_port_on_the_detected_url(): void
    {
        $this->assertSame('http://127.0.0.1:8100', setup_url_with_port('http://127.0.0.1:8099', 8100));
        $this->assertSame('http://127.0.0.1', setup_url_with_port('http://127.0.0.1:8099', 80));
        $this->assertNull(setup_url_with_port('http://127.0.0.1:8099', 0));
        $this->assertNull(setup_helper_shop_port_error('http://127.0.0.1:8099', 8099));
        $this->assertSame('Enter a Port from 1 to 65535.', setup_helper_shop_port_error('http://127.0.0.1:8099', 0));
    }

    public function test_xampp_cgi_binary_resolves_to_cli_php_exe(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-cli-php-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $cliName = stripos(PHP_OS, 'WIN') === 0 ? 'php.exe' : 'php';
        $cgiName = stripos(PHP_OS, 'WIN') === 0 ? 'php-cgi.exe' : 'php-cgi';
        $cli = $dir.DIRECTORY_SEPARATOR.$cliName;
        $cgi = $dir.DIRECTORY_SEPARATOR.$cgiName;
        file_put_contents($cli, '');
        file_put_contents($cgi, '');

        $this->assertSame($cli, setup_cli_php_binary($cgi));
        $this->assertSame($cli, setup_cli_php_binary($cli));
        if (stripos(PHP_OS, 'WIN') === 0) {
            $hidden = $dir.DIRECTORY_SEPARATOR.'php-win.exe';
            file_put_contents($hidden, '');
            $this->assertSame($hidden, setup_background_php_binary($cgi));
        }
    }

    public function test_cli_php_uses_phprc_folder_when_binary_is_cgi(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-phprc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $cliName = stripos(PHP_OS, 'WIN') === 0 ? 'php.exe' : 'php';
        $cli = $dir.DIRECTORY_SEPARATOR.$cliName;
        file_put_contents($cli, '');
        $cgi = $dir.DIRECTORY_SEPARATOR.(stripos(PHP_OS, 'WIN') === 0 ? 'php-cgi.exe' : 'php-cgi');
        file_put_contents($cgi, '');

        $prev = getenv('PHPRC');
        putenv('PHPRC='.$dir);
        try {
            $this->assertSame($cli, setup_cli_php_binary($cgi));
        } finally {
            if ($prev === false) {
                putenv('PHPRC');
            } else {
                putenv('PHPRC='.$prev);
            }
        }
    }

    public function test_country_region_lists_hide_countries_without_states(): void
    {
        $regions = setup_country_regions();

        $this->assertArrayHasKey('IN', $regions);
        $this->assertContains('Delhi', array_column($regions['IN'], 'name'));
        $this->assertArrayNotHasKey('SG', $regions);
    }

    public function test_apache_shop_url_uses_htdocs_folder_and_listen_port(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-apache-'.bin2hex(random_bytes(4));
        mkdir($base.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'conf', 0777, true);
        $shop = $base.DIRECTORY_SEPARATOR.'htdocs'.DIRECTORY_SEPARATOR.'shop';
        mkdir($shop, 0777, true);
        file_put_contents(
            $base.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'httpd.conf',
            "Listen 443\nListen 8080\nServerName localhost:8080\n"
        );

        try {
            $this->assertSame('http://localhost:8080/shop', setup_detect_apache_shop_url($shop));
            $this->assertSame(
                'http://127.0.0.1:8080/shop',
                setup_accept_apache_shop_url('http://127.0.0.1:8080/shop/', $shop)
            );
            $this->assertNull(setup_accept_apache_shop_url('http://localhost:8080/other', $shop));
            $this->assertNull(setup_accept_apache_shop_url('http://evil.test:8080/shop', $shop));
        } finally {
            $this->removeTree($base);
        }
    }

    public function test_linux_xampp_httpd_conf_sets_the_folder_url_and_replaces_a_stale_rewrite_base(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-lampp-'.bin2hex(random_bytes(4));
        $shop = $base.DIRECTORY_SEPARATOR.'htdocs'.DIRECTORY_SEPARATOR.'shop';
        mkdir($base.DIRECTORY_SEPARATOR.'etc', 0777, true);
        mkdir($shop, 0777, true);
        file_put_contents($base.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'httpd.conf', "Listen 80\n");
        file_put_contents($shop.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\nRewriteBase /old-shop/\n");

        try {
            $this->assertSame('http://localhost/shop', setup_detect_apache_shop_url($shop));
            $this->assertSame('/shop/', \App\Domain\Installer\ApacheHtaccess::resolvedBase($shop, 'http://localhost'));
        } finally {
            $this->removeTree($base);
        }
    }

    public function test_shop_at_the_web_root_uses_the_domain_root(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-lampp-root-'.bin2hex(random_bytes(4));
        $shop = $base.DIRECTORY_SEPARATOR.'htdocs';
        mkdir($base.DIRECTORY_SEPARATOR.'etc', 0777, true);
        mkdir($shop, 0777, true);
        file_put_contents($base.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'httpd.conf', "Listen 80\n");
        file_put_contents($shop.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\nRewriteBase /old-shop/\n");

        try {
            $this->assertSame('http://localhost', setup_detect_apache_shop_url($shop));
            $this->assertSame('/', \App\Domain\Installer\ApacheHtaccess::resolvedBase($shop, 'http://localhost'));
            $this->assertSame('/', \App\Domain\Installer\ApacheHtaccess::resolvedBase($shop, 'http://shop.example'));
        } finally {
            $this->removeTree($base);
        }
    }

    public function test_undetectable_folder_keeps_its_existing_rewrite_base(): void
    {
        $shop = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-plain-root-'.bin2hex(random_bytes(4));
        mkdir($shop, 0777, true);
        file_put_contents($shop.DIRECTORY_SEPARATOR.'.htaccess', "RewriteEngine On\nRewriteBase /shop/\n");

        try {
            $this->assertSame('/shop/', \App\Domain\Installer\ApacheHtaccess::resolvedBase($shop, 'http://shop.example'));
        } finally {
            $this->removeTree($shop);
        }
    }

    public function test_permission_and_database_errors_are_explained_in_plain_language(): void
    {
        $commands = setup_permission_commands('/var/www/wentocart', 'www-data');
        if (setup_is_windows()) {
            $this->assertStringContainsString('icacls', $commands[0]);
            $this->assertStringContainsString('wentocart', $commands[0]);
        } else {
            $this->assertSame([
                "sudo chown -R www-data:www-data '/var/www/wentocart'",
                "sudo chmod -R ug+rwx '/var/www/wentocart'",
            ], $commands);
        }
        $this->assertStringContainsString('cannot write', setup_friendly_error('file_put_contents(/var/www/wentocart/storage/logs/laravel.log): Failed to open stream: Permission denied'));
        $this->assertStringContainsString('64 characters', setup_friendly_error("SQLSTATE[42000]: Syntax error or access violation: 1059 Identifier name 'WENTO1234_attachment_translations_attachment_id_language_code_unique' is too long"));
        $this->assertStringContainsString('pdo_mysql', setup_friendly_error('could not find driver'));
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
