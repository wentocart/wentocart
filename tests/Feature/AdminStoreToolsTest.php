<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\UrlRedirect;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Settings\AdminSelectQuery;
use App\Domain\Settings\Models\EmailLog;
use App\Domain\Settings\Models\SavedQuery;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Settings\ShopSetting;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminStoreToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_configure_pages_open(): void
    {
        $this->get('/admin/urls')->assertOk()->assertInertia(fn (Assert $page) => $page->has('schemas.product'));
        $this->get('/admin/seo')->assertOk()->assertInertia(fn (Assert $page) => $page->has('seo.title_pattern'));
        $this->get('/admin/images')->assertOk()->assertInertia(fn (Assert $page) => $page->has('types')->has('webp'));
        $this->get('/admin/performance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cache_driver')
            ->has('debug')
            ->has('assets.built')
            ->where('catalog_ttl', 'auto')
            ->has('catalog_version')
            ->has('search')
        );
        $this->get('/admin/advanced')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', 'profiler'));
        $this->get('/admin/email?tab=smtp')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', 'smtp'));
    }

    public function test_db_queries_runs_select_and_rejects_writes(): void
    {
        $this->get('/admin/advanced?tab=queries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'queries')
                ->has('tables')
                ->has('saved_queries')
            );

        $this->assertNotEmpty(AdminSelectQuery::tables());

        $this->from('/admin/advanced?tab=queries')
            ->followingRedirects()
            ->post('/admin/advanced/queries/run', ['sql' => AdminSelectQuery::sampleSelect(AdminSelectQuery::tables()[0])])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'queries')
                ->has('query_result.columns')
            );

        $this->from('/admin/advanced?tab=queries')
            ->followingRedirects()
            ->post('/admin/advanced/queries/run', ['sql' => 'SELECT 1 AS n'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'queries')
                ->where('query_result.row_count', 1)
                ->where('query_result.rows.0.n', 1)
            );

        $this->from('/admin/advanced?tab=queries')
            ->post('/admin/advanced/queries/run', ['sql' => 'DELETE FROM products'])
            ->assertRedirect('/admin/advanced?tab=queries')
            ->assertSessionHasErrors('sql');

        $this->from('/admin/advanced?tab=queries')
            ->post('/admin/advanced/queries/run', ['sql' => 'SELECT 1; DROP TABLE products'])
            ->assertSessionHasErrors('sql');

        $this->from('/admin/advanced?tab=queries')
            ->post('/admin/advanced/queries', [
                'name' => 'Count products',
                'sql' => 'SELECT 1 AS n',
            ])
            ->assertRedirect();

        $saved = SavedQuery::query()->where('name', 'Count products')->first();
        $this->assertNotNull($saved);

        $this->from('/admin/advanced?tab=queries')
            ->post('/admin/advanced/queries/run', [
                'sql' => $saved->sql,
                'saved_id' => $saved->id,
            ])
            ->assertRedirect();

        $this->assertNotNull($saved->fresh()->last_run_at);

        $this->from('/admin/advanced?tab=queries')
            ->delete('/admin/advanced/queries/'.$saved->id)
            ->assertRedirect();
        $this->assertNull($saved->fresh());
    }

    public function test_profiler_history_stays_on_the_profiler_tab(): void
    {
        ShopSetting::put('advanced.profiler', true);
        $this->get('/admin/urls')->assertOk();

        $this->get('/admin/advanced?tab=profiler')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'profiler')
                ->where('profiler', true)
                ->has('queries.0')
            );

        $this->get('/admin/advanced?tab=queries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'queries')
                ->has('tables')
            );
    }

    public function test_default_product_url_uses_id_and_rewrite(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $path = UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path');

        $this->assertSame($product->id.'-demo-cotton-tee', $path);
        $this->get('/'.$path)->assertOk()->assertSee('Demo Cotton Tee', false);
        $this->get('/demo-cotton-tee')->assertOk()->assertSee('Demo Cotton Tee', false);
    }

    public function test_changing_schema_regenerates_and_keeps_a_redirect(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $old = UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path');

        $this->put('/admin/urls', [
            'schemas' => [
                'product' => '{rewrite}',
                'category' => '{id}-{rewrite}',
                'cms' => '{id}-{rewrite}',
                'brand' => 'brand/{rewrite}',
                'supplier' => 'supplier/{rewrite}',
            ],
            'suffix' => '',
        ])->assertRedirect();

        $this->post('/admin/urls/regenerate', ['type' => 'product'])->assertRedirect();

        $this->assertSame('demo-cotton-tee', UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path'));
        $this->assertTrue(UrlRedirect::query()->where('from_path', $old)->where('to_path', 'demo-cotton-tee')->exists());
        $this->get('/'.$old)->assertRedirect('/demo-cotton-tee');
    }

    public function test_saving_a_suffix_updates_storefront_paths(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $old = UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path');

        $this->put('/admin/urls', [
            'schemas' => [
                'product' => '{rewrite}',
                'category' => '{id}-{rewrite}',
                'cms' => '{id}-{rewrite}',
                'brand' => 'brand/{rewrite}',
                'supplier' => 'supplier/{rewrite}',
            ],
            'suffix' => '.html',
        ])->assertRedirect();

        $this->assertSame('demo-cotton-tee.html', UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path'));
        $this->get('/'.$old)->assertRedirect('/demo-cotton-tee.html');
        $this->get('/demo-cotton-tee')->assertRedirect('/demo-cotton-tee.html');
        $this->getJson('/api/v1/catalog/product/demo-cotton-tee.html')
            ->assertOk()
            ->assertJsonPath('product.url', '/demo-cotton-tee.html');
    }

    public function test_seo_robots_and_sitemap(): void
    {
        $this->put('/admin/seo', [
            'title_pattern' => '{name} | {shop}',
            'default_description' => 'Shop-wide description',
            'default_robots' => 'index,follow',
            'og_image' => '',
            'robots_txt' => "User-agent: *\nAllow: /\nSitemap: {sitemap}\n",
            'sitemap_enabled' => 1,
        ])->assertRedirect();

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('User-agent: *', false)
            ->assertSee('sitemap.xml', false);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $path = UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', $product->id)
            ->value('request_path');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee($path, false);
    }

    public function test_smtp_settings_and_test_email_are_logged(): void
    {
        Mail::fake();

        $this->put('/admin/email/smtp', [
            'mailer' => 'log',
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'shop@example.com',
            'from_name' => 'Demo Shop',
        ])->assertRedirect();

        $this->assertSame('shop@example.com', ShopSetting::get('mail.from_address'));

        $this->post('/admin/email/smtp/test', [
            'to' => 'reviewer@example.com',
        ])->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('reviewer@example.com'));
        $this->assertTrue(EmailLog::query()->where('to_email', 'reviewer@example.com')->where('status', 'sent')->exists());
    }

    public function test_maintenance_closes_the_storefront_but_keeps_admin(): void
    {
        $this->put('/admin/advanced/maintenance', [
            'enabled' => 1,
            'message' => '<h2>Closed for a stock take.</h2><p>Please check back soon.</p>',
            'header' => '<p><strong>Notice</strong> — brief downtime</p>',
            'footer' => '<p>Card footer note</p>',
            'banner' => '<p><strong>Ground banner</strong> — reopen shortly</p>',
            'ips' => '',
        ])->assertRedirect();

        $this->get('/')
            ->assertStatus(503)
            ->assertSee('Closed for a stock take.', false)
            ->assertSee('Please check back soon.', false)
            ->assertSee('brief downtime', false)
            ->assertSee('Card footer note', false)
            ->assertSee('Ground banner', false);
        $this->get('/admin/urls')->assertOk();

        $this->post('/admin/advanced/maintenance/preview', [
            'enabled' => 0,
            'message' => '<h2>Preview only headline</h2>',
            'header' => '<p>Preview header line</p>',
            'footer' => '',
            'banner' => '',
            'ips' => '',
        ])->assertOk()
            ->assertSee('Preview only headline', false)
            ->assertSee('Preview header line', false);

        $this->assertSame(true, \App\Domain\Settings\ShopSetting::bool('advanced.maintenance', false));
        $this->assertStringNotContainsString(
            'Preview only headline',
            (string) \App\Domain\Settings\ShopSetting::get('advanced.maintenance_message', '')
        );
    }

    public function test_debug_toggle_overrides_config_and_storefront_refresh_keeps_other_cache(): void
    {
        config(['app.debug' => false]);
        $this->put('/admin/performance/debug', ['enabled' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->get('/admin/performance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('debug', true));
        $this->assertTrue(config('app.debug'));

        $this->put('/admin/performance/debug', ['enabled' => 0])->assertRedirect();
        $this->get('/admin/performance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('debug', false));
        $this->assertFalse(config('app.debug'));

        $before = StorefrontCatalogCache::version();
        Cache::put('perf.keep', 'yes', 60);
        $this->post('/admin/performance/refresh-storefront')
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertGreaterThan($before, StorefrontCatalogCache::version());
        $this->assertSame('yes', Cache::get('perf.keep'));
    }

    public function test_clear_cache_and_image_types_save(): void
    {
        $this->put('/admin/performance', ['catalog_ttl' => '3600'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('3600', ShopSetting::get('performance.catalog_ttl'));
        $this->put('/admin/performance', ['catalog_ttl' => 'nope'])->assertSessionHasErrors('catalog_ttl');

        $this->post('/admin/performance/clear')->assertRedirect();
        $this->put('/admin/images', [
            'webp' => 1,
            'types' => [
                ['id' => 'cart', 'max_width' => 80, 'max_height' => 80],
                ['id' => 'home', 'max_width' => 250, 'max_height' => 250],
                ['id' => 'medium', 'max_width' => 452, 'max_height' => 452],
                ['id' => 'large', 'max_width' => 800, 'max_height' => 800],
                ['id' => 'category', 'max_width' => 141, 'max_height' => 180],
            ],
        ])->assertRedirect();
        $this->assertTrue(ShopSetting::bool('images.webp', false));
    }

    public function test_image_optimizer_writes_sized_files(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }

        Storage::fake('public');
        $png = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($png);
        $bytes = ob_get_clean();
        imagedestroy($png);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $path = 'catalog/products/'.$product->id.'/source.png';
        Storage::disk('public')->put($path, $bytes);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => 'Demo',
            'position' => 1,
        ]);

        $written = app(ImageOptimizer::class)->generate($path);
        $this->assertGreaterThan(0, $written);
        $this->assertTrue(Storage::disk('public')->exists(app(ImageOptimizer::class)->derivativePath($path, 'cart')));
    }

    public function test_schema_must_include_id_or_rewrite(): void
    {
        $this->from('/admin/urls')->put('/admin/urls', [
            'schemas' => [
                'product' => 'products',
                'category' => '{id}-{rewrite}',
                'cms' => '{id}-{rewrite}',
                'brand' => 'brand/{rewrite}',
                'supplier' => 'supplier/{rewrite}',
            ],
            'suffix' => '',
        ])->assertRedirect('/admin/urls')->assertSessionHasErrors('schemas.product');
    }
}
