<?php

namespace Tests\Feature;

use App\Domain\Security\AdminPath;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_segment_opens_login_and_the_old_admin_address_is_not_found(): void
    {
        config(['wento.admin_prefix' => 'desk']);

        $this->get('/desk/login')->assertOk();
        $this->get('/admin/login')->assertNotFound();
        $this->get('/admin/general')->assertNotFound();
    }

    public function test_public_reference_replaces_only_the_admin_segment(): void
    {
        config(['wento.admin_prefix' => 'desk']);

        $this->assertSame('/desk/login', AdminPath::toPublicReference('/admin/login'));
        $this->assertSame('/desk/staff/admins', AdminPath::toPublicReference('/admin/staff/admins'));
        $this->assertSame('http://localhost/desk/general', AdminPath::toPublicReference('http://localhost/admin/general'));
    }

    public function test_admin_session_cookie_uses_the_shop_folder_not_the_address_word(): void
    {
        config(['app.url' => 'http://localhost:8000/shop']);

        $request = \Illuminate\Http\Request::create('http://localhost:8000/shop/admin/login', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
        ]);
        app()->instance('request', $request);

        app(\App\Http\Middleware\ConfigureSessionBySurface::class)->handle($request, function () {
            $this->assertSame('/shop', config('session.path'));
            $this->assertSame((string) config('session.admin_cookie'), (string) config('session.cookie'));

            return response('ok');
        });
    }

    public function test_changing_the_admin_address_reloads_that_screen(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wento-admin-path-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir.DIRECTORY_SEPARATOR.'admin-path.php';
        file_put_contents($file, "<?php\n\nreturn ['segment' => 'admin'];\n");
        config([
            'wento.admin_path_file' => $file,
            'wento.admin_prefix' => 'admin',
            'admin-path.segment' => 'admin',
            'app.url' => 'http://localhost:8000/shop',
        ]);

        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        try {
            $response = $this->from('/admin/general')->post('/admin/store/admin-path', [
                'segment' => 'desk',
            ]);

            $response->assertRedirect('/admin/general');
            $response->assertSessionHas('admin_path_next', fn ($url) => is_string($url) && str_contains($url, '/desk/general') && ! str_contains($url, '/admin/general'));
            $this->assertSame('desk', AdminPath::segment());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_sensitive_files_are_not_served(): void
    {
        require_once base_path('app/Domain/Installer/block-sensitive.php');

        $this->assertTrue(wento_sensitive_request('/.env'));
        $this->assertTrue(wento_sensitive_request('/database/schema/mysql-schema.sql'));
        $this->assertTrue(wento_sensitive_request('/vendor/autoload.php'));
        $this->assertTrue(wento_sensitive_request('/composer.json'));
        $this->assertTrue(wento_sensitive_request('/resources/js/admin/app.jsx'));
        $this->assertTrue(wento_sensitive_request('/package/checks.php'));
        $this->assertTrue(wento_sensitive_request('/extensions/weblytic/mainmenu/README.md'));
        $this->assertFalse(wento_sensitive_request('/setup/index.php'));
        $this->assertFalse(wento_sensitive_request('/'));
        $this->assertFileExists(base_path('app/Http/index.php'));
        $this->assertStringContainsString('Location: ../', (string) file_get_contents(base_path('app/Http/index.php')));
        $this->assertFileDoesNotExist(base_path('config/index.php'));
        $this->assertFileExists(base_path('config/index.html'));
    }
}
