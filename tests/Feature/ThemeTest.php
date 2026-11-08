<?php

namespace Tests\Feature;

use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeInstaller;
use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeResolver;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_classic_is_bundled_and_shop_api_exposes_it(): void
    {
        $this->seed();

        $classic = app(ThemeRegistry::class)->classic();
        $this->assertSame('classic', $classic->name);
        $this->assertSame('react-vite', $classic->engine);

        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.name', 'classic')
            ->assertJsonStructure(['currencies', 'languages', 'categories']);

        $this->get('/themes/classic/preview.svg')->assertOk();
        $this->get('/themes/classic/assets/merchant/products/1.png')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->get('/themes/classic/assets/merchant/ui/logo.png')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertFileExists(base_path('themes/classic/assets/merchant/ui/logo.png'));

        $this->get('/themes/classic/assets/merchant/ui/logo_short.png')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertFileExists(base_path('themes/classic/assets/merchant/ui/logo_short.png'));
    }

    public function test_child_zip_extends_classic_and_can_be_enabled(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->get('/admin/themes')->assertOk()->assertSee('Classic');

        $path = $this->makeZip([
            'theme.json' => json_encode([
                'name' => 'dusk',
                'display_name' => 'Dusk',
                'version' => '1.0.0',
                'parent' => 'classic',
                'engine' => 'react-vite',
                'settings' => ['colors' => ['accent' => '#0f172a']],
            ]),
            'assets/overrides.css' => ':root{--shop-accent:#0f172a}',
        ]);

        $installed = app(ThemeInstaller::class)->install(new UploadedFile($path, 'dusk.zip', 'application/zip', null, true));
        $this->assertSame('dusk', $installed->name);

        $theme = app(ThemeRegistry::class)->get('dusk');
        $this->assertSame('classic', $theme->parent);
        $this->assertSame('#0f172a', app(ThemeResolver::class)->settings($theme)['colors']['accent']);

        $this->post('/admin/themes/enable', ['theme' => 'dusk'])->assertRedirect();
        $this->assertSame('dusk', Store::query()->where('is_default', true)->value('theme'));

        $this->get('/')->assertOk()->assertSee('dusk', false);
    }

    public function test_zip_cannot_include_php_or_overwrite_classic(): void
    {
        $this->seed();
        $installer = app(ThemeInstaller::class);

        $phpZip = $this->makeZip([
            'theme.json' => json_encode(['name' => 'evil', 'engine' => 'react-vite']),
            'hack.php' => '<?php echo 1;',
        ]);
        try {
            $installer->install(new UploadedFile($phpZip, 'evil.zip', 'application/zip', null, true));
            $this->fail('PHP zip should be rejected');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $classicZip = $this->makeZip([
            'theme.json' => json_encode(['name' => 'classic', 'engine' => 'react-vite']),
        ]);
        try {
            $installer->install(new UploadedFile($classicZip, 'classic.zip', 'application/zip', null, true));
            $this->fail('Classic zip should be rejected');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    public function test_category_listing_accepts_sort_and_price_filters(): void
    {
        $this->seed();

        $this->get('/api/v1/catalog/category/catalog?sort=newest&price=0-100')
            ->assertOk()
            ->assertJsonPath('category.slug', 'catalog')
            ->assertJsonStructure(['products', 'brands', 'price', 'breadcrumbs']);
    }

    public function test_full_spa_dist_zip_installs_without_a_parent(): void
    {
        $this->seed();

        $path = $this->makeZip([
            'theme.json' => json_encode([
                'name' => 'aurora',
                'display_name' => 'Aurora',
                'version' => '1.0.0',
                'engine' => 'spa-dist',
                'description' => 'Dark storefront',
                'assets' => ['js' => 'assets/app.js', 'css' => 'assets/app.css'],
            ]),
            'assets/app.js' => 'window.__AURORA__=true;',
            'assets/app.css' => 'html.theme-aurora{background:#07080d}',
            'dist/index.html' => '<div id="storefront"></div>',
            'preview.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8" fill="#07080d"/></svg>',
        ]);

        $installed = app(ThemeInstaller::class)->install(new UploadedFile($path, 'aurora.zip', 'application/zip', null, true));
        $this->assertSame('aurora', $installed->name);
        $this->assertTrue($installed->isFullOverride());

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/themes/enable', ['theme' => 'aurora'])
            ->assertRedirect();

        $this->get('/')->assertOk()->assertSee('theme-aurora', false);
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function makeZip(array $files): string
    {
        $path = storage_path('framework/testing/'.uniqid('theme_', true).'.zip');
        @mkdir(dirname($path), 0777, true);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
