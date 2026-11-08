<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Seo\SeoFixJournal;
use App\Domain\Seo\SeoFixLive;
use App\Domain\Seo\ShopAudit;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SeoFixLive::CACHE_KEY);
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_seo_studio_dashboard_and_health_share_scores(): void
    {
        $this->get('/admin/seo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Seo', false)
                ->has('seo.title_pattern')
                ->has('audit.scores.overall')
                ->has('audit.issues')
                ->where('tab', 'dashboard')
            );

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Health/Overview', false)
            );
    }

    public function test_fix_fills_missing_product_meta_titles(): void
    {
        $product = $this->makeProduct('SEO-FIX-1', 'Fixable Lamp');
        $this->assertSame('', (string) $product->translation('en')?->meta_title);

        ShopAudit::flush();
        $this->post('/admin/seo/fix', ['id' => 'fill_product_titles'])->assertRedirect();

        $title = (string) $product->fresh()->translation('en')?->meta_title;
        $this->assertNotSame('', $title);
        $this->assertStringContainsString('Fixable Lamp', $title);
    }

    public function test_fix_lengthens_short_product_meta_descriptions(): void
    {
        $product = $this->makeProduct('SEO-SHORT-1', 'Short Meta Lamp');
        $product->translation('en')?->update(['meta_description' => 'Too short.']);
        $this->assertLessThan(70, mb_strlen((string) $product->fresh()->translation('en')?->meta_description));

        ShopAudit::flush();
        $this->post('/admin/seo/fix', ['id' => 'fill_product_descriptions'])->assertRedirect();

        $desc = (string) $product->fresh()->translation('en')?->meta_description;
        $this->assertGreaterThanOrEqual(70, mb_strlen($desc));
        $this->assertStringContainsString('Short Meta Lamp', $desc);
    }

    public function test_health_fix_sets_og_image_from_a_product_cover(): void
    {
        $product = $this->makeProduct('SEO-OG-1', 'Og Lamp');
        $product->update(['cover_path' => 'catalog/products/og.png']);
        ShopSetting::put('seo.og_image', '');
        ShopAudit::flush();

        $this->post('/admin/seo/fix', ['id' => 'set_og_image'])->assertRedirect();
        $this->assertSame('catalog/products/og.png', (string) ShopSetting::get('seo.og_image'));
    }

    public function test_health_fix_is_recorded_and_can_roll_back_the_last_run(): void
    {
        $product = $this->makeProduct('SEO-RB-1', 'Rollback Lamp');
        ShopAudit::flush();
        $this->post('/admin/seo/fix', ['id' => 'fill_product_titles'])->assertRedirect();
        $this->assertNotSame('', (string) $product->fresh()->translation('en')?->meta_title);

        $history = app(SeoFixJournal::class)->history();
        $this->assertNotEmpty($history);
        $this->assertTrue((bool) ($history[0]['can_rollback'] ?? false));

        $this->post('/admin/seo/rollback', ['last' => 1])->assertRedirect();
        $this->assertSame('', (string) $product->fresh()->translation('en')?->meta_title);
    }

    public function test_health_can_roll_back_one_changed_row(): void
    {
        $alpha = $this->makeProduct('SEO-RB-A', 'Alpha Lamp');
        $beta = $this->makeProduct('SEO-RB-B', 'Beta Lamp');
        ShopAudit::flush();
        $this->post('/admin/seo/fix', ['id' => 'fill_product_titles'])->assertRedirect();

        $history = app(SeoFixJournal::class)->history();
        $this->assertNotEmpty($history);
        $itemId = (int) ($history[0]['items'][0]['id'] ?? 0);
        $this->assertGreaterThan(0, $itemId);

        $this->post('/admin/seo/rollback', [
            'operation_id' => $history[0]['id'],
            'item_id' => $itemId,
        ])->assertRedirect();

        $empty = 0;
        if ((string) $alpha->fresh()->translation('en')?->meta_title === '') {
            $empty++;
        }
        if ((string) $beta->fresh()->translation('en')?->meta_title === '') {
            $empty++;
        }
        $this->assertSame(1, $empty);
    }

    public function test_health_fix_writes_canonical_urls_and_expands_short_titles(): void
    {
        $product = $this->makeProduct('SEO-CAN-1', 'Canonical Lamp');
        $translation = $product->translation('en');
        $this->assertNotNull($translation);
        $translation->update(['meta_title' => 'Short title']);
        $this->assertSame('Short title', $product->fresh()->translation('en')?->meta_title);
        UrlRewrite::query()->create([
            'language_code' => 'en',
            'request_path' => 'seo-can-1-canonical-lamp',
            'entity_type' => 'product',
            'entity_id' => $product->id,
        ]);
        ShopAudit::flush();

        $this->post('/admin/seo/fix', ['id' => 'fill_product_canonicals'])->assertRedirect();
        $canonical = (string) $product->fresh()->translation('en')?->canonical_url;
        $this->assertStringContainsString('seo-can-1-canonical-lamp', $canonical);

        $this->post('/admin/seo/fix', ['id' => 'expand_short_titles'])->assertRedirect();
        $title = (string) $product->fresh()->translation('en')?->meta_title;
        $this->assertGreaterThanOrEqual(30, mb_strlen($title));

        $this->post('/admin/seo/rollback', ['last' => 1])->assertRedirect();
        $this->assertSame('Short title', (string) $product->fresh()->translation('en')?->meta_title);
    }

    public function test_health_fix_inserts_focus_keyword_into_the_title(): void
    {
        $product = $this->makeProduct('SEO-FOCUS-1', 'Focus Lamp');
        $product->translation('en')?->update([
            'meta_title' => 'Bright desk lighting for home offices',
            'focus_keyword' => 'brass lamp',
        ]);
        ShopAudit::flush();

        $this->post('/admin/seo/fix', ['id' => 'place_focus_in_title'])->assertRedirect();
        $title = (string) $product->fresh()->translation('en')?->meta_title;
        $this->assertStringContainsStringIgnoringCase('brass lamp', $title);
    }

    public function test_live_fix_runs_in_the_background_without_blocking_admin(): void
    {
        $product = $this->makeProduct('SEO-LIVE-1', 'Live Lamp');
        ShopAudit::flush();

        $response = $this->postJson('/admin/seo/fix', [
            'id' => 'fill_product_titles',
            'live' => 1,
            'jobs' => [[
                'id' => 'fill_product_titles',
                'title' => 'Fill product titles',
                'count' => 1,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('run.status', 'complete')
            ->assertJsonPath('run.complete', true);
        $this->assertNotSame('', (string) $product->fresh()->translation('en')?->meta_title);

        $this->getJson('/admin/seo-fix/status')
            ->assertOk()
            ->assertJsonPath('run.status', 'complete');

        $this->get('/admin/products')->assertOk();
    }

    public function test_queued_fix_leaves_admin_http_free_until_the_worker_runs(): void
    {
        $product = $this->makeProduct('SEO-BG-1', 'Queued Lamp');
        ShopAudit::flush();

        $run = app(SeoFixLive::class)->begin(
            'fill_product_titles',
            [['id' => 'fill_product_titles', 'title' => 'Fill product titles', 'count' => 1]],
            'health',
            false,
        );

        $this->assertSame('queued', $run['status']);
        $this->assertSame('', (string) $product->fresh()->translation('en')?->meta_title);
        $this->get('/admin/products')->assertOk();
        $this->getJson('/admin/seo-fix/status')
            ->assertOk()
            ->assertJsonPath('run.status', 'queued');

        app(SeoFixLive::class)->execute($run['id']);

        $this->assertNotSame('', (string) $product->fresh()->translation('en')?->meta_title);
        $this->getJson('/admin/seo-fix/status')
            ->assertOk()
            ->assertJsonPath('run.status', 'complete');
    }

    public function test_windows_seo_fix_spawn_does_not_use_php_path_as_a_window_title(): void
    {
        $cmd = app(SeoFixLive::class)->backgroundCommand('run-id-123', 'Windows');

        $this->assertStringContainsString('start /B ""', $cmd);
        $this->assertStringContainsString('wento:seo-fix', $cmd);
        $this->assertStringContainsString('run-id-123', $cmd);
        $this->assertStringNotContainsString('start /B "'.PHP_BINARY.'"', $cmd);
    }

    public function test_page_editor_saves_keywords_and_sitemap_lists_the_product(): void
    {
        $product = $this->makeProduct('SEO-PAGE-1', 'Keyword Kettle');

        $this->get('/admin/seo?tab=pages&type=product&filter=missing_keywords')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'pages')
                ->where('pages.type', 'product')
            );

        $this->patch('/admin/seo/pages/product/'.$product->id, [
            'meta_title' => 'Keyword Kettle for sale',
            'meta_description' => 'A kettle with a unique meta description for search snippets.',
            'meta_keywords' => 'kettle, kitchen, boil',
            'focus_keyword' => 'kettle',
            'robots' => 'index,follow',
        ])->assertRedirect();

        $row = $product->fresh()->translation('en');
        $this->assertSame('Keyword Kettle for sale', $row->meta_title);
        $this->assertSame('kettle, kitchen, boil', $row->meta_keywords);
        $this->assertSame('kettle', $row->focus_keyword);

        $path = UrlRewrite::query()
            ->where('entity_type', 'product')
            ->where('entity_id', Product::query()->where('sku', 'DEMO-001')->value('id'))
            ->value('request_path');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee($path, false)
            ->assertSee('<urlset', false);
    }

    public function test_meta_length_sql_uses_the_prefixed_table_name(): void
    {
        $connection = DB::connection();
        $original = $connection->getTablePrefix();
        $connection->setTablePrefix('WENTO_');
        try {
            $audit = new class extends ShopAudit
            {
                public function expr(string $column): string
                {
                    return $this->lengthExpr($column);
                }
            };
            $sql = $audit->expr('product_translations.meta_title');
            $this->assertStringContainsString('WENTO_product_translations', $sql);
            $this->assertStringNotContainsString('(product_translations.meta_title)', $sql);
        } finally {
            $connection->setTablePrefix($original);
        }
    }

    protected function makeProduct(string $sku, string $name): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => $sku,
            'price' => 12,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'catalog_search',
            'available_for_order' => true,
            'show_price' => true,
            'manage_stock' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => $name,
            'slug' => strtolower($sku),
            'meta_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
            'focus_keyword' => '',
        ]);
        $product->unsetRelation('translations');

        return $product;
    }
}
