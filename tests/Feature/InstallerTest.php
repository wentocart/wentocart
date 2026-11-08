<?php

namespace Tests\Feature;

use App\Domain\Security\AdminPath;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['wento.lock_path' => storage_path('framework/testing/installer.lock')]);
        @unlink(config('wento.lock_path'));
    }

    public function test_install_path_redirects_to_setup_wizard(): void
    {
        $this->get('/install')->assertRedirect('/setup/index.php');
    }

    public function test_setup_wizard_has_compatibility_and_store_fields(): void
    {
        $wizard = (string) file_get_contents(base_path('setup/index.php'));

        $this->assertStringContainsString('System Compatibility Check', $wizard);
        $this->assertStringContainsString('id="store_email"', $wizard);
        $this->assertStringContainsString('id="store_line1"', $wizard);
        $this->assertStringContainsString('id="store_country"', $wizard);
        $this->assertStringContainsString('id="db_prefix"', $wizard);
        $this->assertStringContainsString('Address line 1', $wizard);
    }

    public function test_table_prefix_validation_matches_installer_rules(): void
    {
        $this->assertFalse(wento_db_prefix_valid('bad prefix'));
        $this->assertFalse(wento_db_prefix_valid(''));
        $this->assertTrue(wento_db_prefix_valid('Lo_as_as_'));
        $this->assertTrue(wento_db_prefix_valid('wento'));
        $this->assertTrue(wento_db_prefix_valid('WBL'));
    }

    public function test_installed_page_links_use_the_live_request_when_app_url_mismatches(): void
    {
        config(['app.url' => 'http://example.test:9999']);

        $this->get('/installed')
            ->assertOk()
            ->assertSee('http://localhost/admin/login', false)
            ->assertDontSee('http://example.test:9999', false);
    }

    public function test_installed_page_shows_shop_and_admin_links_without_passwords(): void
    {
        $this->get('/installed')
            ->assertOk()
            ->assertSee('Installation has completed', false)
            ->assertSee('<style nonce="', false)
            ->assertSee('Open shop website')
            ->assertSee('Go to Admin Panel')
            ->assertSee('href="http://localhost/" target="_blank"', false)
            ->assertSee('href="http://localhost/admin/login" target="_blank"', false)
            ->assertDontSee('demo@demo.com');
    }

    public function test_installed_page_respects_custom_admin_segment(): void
    {
        AdminPath::write('desk');

        try {
            $this->get('/installed')
                ->assertOk()
                ->assertSee('http://localhost/desk/login', false)
                ->assertDontSee('http://localhost/admin/login', false);
        } finally {
            AdminPath::write('admin');
        }
    }

    public function test_non_interactive_cli_install_requires_admin_password(): void
    {
        $this->artisan('wento:install', [
            '--no-interaction' => true,
            '--prefix' => 'WBL',
            '--admin-email' => 'admin@example.test',
        ])->expectsOutputToContain('admin-password')
            ->assertFailed();
    }

    public function test_assert_empty_database_skips_sqlite(): void
    {
        $this->expectNotToPerformAssertions();
        $this->app->make(\App\Domain\Installer\InstallerService::class)
            ->assertEmptyDatabase([
                'db_connection' => 'sqlite',
                'db_database' => ':memory:',
            ]);
    }
}
