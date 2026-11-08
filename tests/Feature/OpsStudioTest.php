<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantSettings;
use App\Domain\Ai\EntityMutator;
use App\Domain\Auth\PageCatalog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Ops\Models\Automation;
use App\Domain\Ops\Models\Operation;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Ops\Operator;
use App\Domain\Seo\ShopAudit;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\StorePurpose;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OpsStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_bulk_price_preview_execute_and_rollback(): void
    {
        $product = $this->makeProduct('OPS-1', 10);

        $operator = app(Operator::class);
        $preview = $operator->preview('catalog.product.bulk_price', [
            'ids' => [$product->id],
            'percent' => 50,
        ], ['source' => 'admin']);

        $this->assertSame('previewed', $preview->status);
        $this->assertEquals(10.0, (float) $product->fresh()->price);

        $done = $operator->execute('catalog.product.bulk_price', [
            'ids' => [$product->id],
            'percent' => 50,
        ], ['source' => 'admin']);

        $this->assertSame('completed', $done->status);
        $this->assertEquals(15.0, (float) $product->fresh()->price);
        $this->assertDatabaseHas('commerce_events', ['name' => 'product.prices.updated']);

        $undo = $operator->rollback($done->fresh());
        $this->assertSame('completed', $undo->status);
        $this->assertEquals(10.0, (float) $product->fresh()->price);
        $this->assertSame('rolled_back', $done->fresh()->status);
    }

    public function test_ai_product_update_writes_through_the_operator(): void
    {
        AssistantSettings::save(['writes_enabled' => true]);
        $product = $this->makeProduct('AI-1', 4);

        $result = EntityMutator::apply([
            'action' => 'update',
            'entity' => 'product',
            'id' => $product->id,
            'fields' => ['price' => 9.5],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['operation_id']);
        $this->assertEquals(9.5, (float) $product->fresh()->price);
        $this->assertDatabaseHas('operations', [
            'id' => $result['operation_id'],
            'ability' => 'catalog.product.update',
            'source' => 'ai',
            'status' => 'completed',
        ]);
    }

    public function test_activity_pages_and_command_palette(): void
    {
        $product = $this->makeProduct('PAL-1', 2);
        app(Operator::class)->execute('catalog.product.update', [
            'id' => $product->id,
            'fields' => ['price' => 3],
        ], ['source' => 'admin']);

        $this->get('/admin/activity')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Activity/Index', false));

        $op = Operation::query()->latest('id')->first();
        $this->get('/admin/activity/'.$op->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Activity/Show', false));

        $this->getJson('/admin/command-palette?q=Activity')
            ->assertOk()
            ->assertJsonFragment(['href' => '/admin/activity']);
        $this->getJson('/admin/command-palette?q=operator')
            ->assertOk()
            ->assertJsonMissing(['href' => '/admin/operator']);
        $this->getJson('/admin/command-palette?q=Automations')
            ->assertOk()
            ->assertJsonMissing(['href' => '/admin/automations']);
        $this->getJson('/admin/command-palette?q=Import+studio')
            ->assertOk()
            ->assertJsonMissing(['href' => '/admin/import-studio']);
    }

    public function test_activity_hides_health_and_import_rows(): void
    {
        Operation::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ability' => 'seo.health.fix',
            'source' => 'admin',
            'status' => 'completed',
            'dry_run' => false,
            'reversible' => true,
            'summary' => 'Fix all automatic issues',
        ]);
        Operation::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ability' => 'catalog.import',
            'source' => 'import',
            'status' => 'completed',
            'dry_run' => false,
            'reversible' => false,
            'summary' => 'Import products',
        ]);

        $this->get('/admin/activity')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ops/Activity/Index', false)
                ->where('operations.data', []));
    }

    public function test_admin_product_toggle_is_recorded_and_can_roll_back(): void
    {
        $product = $this->makeProduct('ACT-1', 8);
        $this->assertTrue($product->is_enabled);

        $this->post('/admin/products/'.$product->id.'/toggle')->assertRedirect();
        $this->assertFalse($product->fresh()->is_enabled);

        $op = Operation::query()->where('ability', 'staff.entity.change')->latest('id')->first();
        $this->assertNotNull($op);
        $this->assertTrue($op->canRollback());

        $this->post('/admin/activity/'.$op->id.'/rollback')->assertRedirect();
        $this->assertTrue($product->fresh()->is_enabled);
        $this->assertSame('rolled_back', $op->fresh()->status);
    }

    public function test_store_purpose_preview_and_apply_go_through_the_ledger(): void
    {
        $preview = StorePurpose::preview('catalog');
        $this->assertNotEmpty($preview);
        $this->assertTrue(collect($preview)->contains(fn ($row) => $row['key'] === 'products.catalog_mode'));

        $this->get('/admin/store')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Store', false));

        $this->post('/admin/shop/purpose', ['purpose' => 'catalog'])->assertRedirect();
        $this->assertTrue((bool) ShopSetting::get('products.catalog_mode'));
        $this->assertDatabaseHas('operations', [
            'ability' => 'settings.bulk',
            'status' => 'completed',
        ]);
    }

    public function test_health_operator_import_and_tools_pages_load(): void
    {
        $this->get('/admin/health')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Health/Overview', false));
        $this->get('/admin/operator')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Operator', false));
        $this->get('/admin/import-studio')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Import', false));
        $this->get('/admin/automations')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Automations/Index', false));
        $this->get('/admin/ops-tools')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ops/Tools', false));
    }

    public function test_health_page_loads_when_product_cover_path_is_missing(): void
    {
        ShopAudit::flush();
        Schema::table('products', function ($table) {
            $table->dropColumn('cover_path');
        });
        $this->assertFalse(Schema::hasColumn('products', 'cover_path'));

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Health/Overview', false)
                ->has('has_scan')
            );
    }

    public function test_health_page_loads_when_plugin_health_status_is_missing(): void
    {
        ShopAudit::flush();
        Schema::table('plugins', function ($table) {
            $columns = array_values(array_filter(
                ['health_status', 'last_error'],
                fn (string $column) => Schema::hasColumn('plugins', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
        $this->assertFalse(Schema::hasColumn('plugins', 'health_status'));

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Health/Overview', false)->has('has_scan'));
    }

    public function test_health_page_loads_when_the_audit_throws(): void
    {
        // Configure → SEO still uses ShopAudit; Operate → Store Health uses HealthOverview
        // and must stay up even when the SEO audit layer cannot build.
        ShopAudit::flush();
        $this->mock(ShopAudit::class, function ($mock) {
            $mock->shouldReceive('report')->andThrow(new \RuntimeException('SQLSTATE[42S22]: Unknown column cover_path'));
        });

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Health/Overview', false)
                ->has('has_scan')
            );
    }

    public function test_automation_runs_an_ability_when_an_event_fires(): void
    {
        Automation::query()->create([
            'name' => 'Flag product updates',
            'is_enabled' => true,
            'event' => 'product.updated',
            'ability' => 'settings.put',
            'action_input' => ['key' => 'ops.last_product_update', 'value' => 'yes'],
        ]);

        $product = $this->makeProduct('AUTO-1', 5);
        app(Operator::class)->execute('catalog.product.update', [
            'id' => $product->id,
            'fields' => ['price' => 6],
        ], ['source' => 'admin']);

        $this->assertSame('yes', ShopSetting::get('ops.last_product_update'));
        $this->assertDatabaseHas('automation_runs', ['status' => 'completed']);
    }

    public function test_global_halt_stops_automations(): void
    {
        ShopSetting::put('ops.automations_halted', true);
        Automation::query()->create([
            'name' => 'Halted',
            'is_enabled' => true,
            'event' => 'product.updated',
            'ability' => 'settings.put',
            'action_input' => ['key' => 'ops.halt_probe', 'value' => 'ran'],
        ]);
        $product = $this->makeProduct('HALT-1', 5);
        app(Operator::class)->execute('catalog.product.update', [
            'id' => $product->id,
            'fields' => ['price' => 7],
        ], ['source' => 'admin']);
        $this->assertNull(ShopSetting::get('ops.halt_probe'));
        ShopSetting::put('ops.automations_halted', false);
    }

    public function test_admin_can_toggle_global_automation_halt(): void
    {
        $this->post('/admin/automations/halt', ['halted' => true])->assertRedirect();
        $this->assertTrue(ShopSetting::bool('ops.automations_halted', false));
        $this->get('/admin/automations')->assertOk()
            ->assertInertia(fn ($page) => $page->where('halted', true));
        $this->post('/admin/automations/halt', ['halted' => false])->assertRedirect();
        $this->assertFalse(ShopSetting::bool('ops.automations_halted', false));
    }

    public function test_import_studio_preview_and_execute(): void
    {
        $csv = "sku,name,price,stock_qty\nIMP-9,Imported Lamp,12,4\n";
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->post('/admin/import-studio/upload', [
            'file' => $file,
            'delimiter' => ',',
        ])->assertRedirect();

        $this->post('/admin/import-studio/map', [
            'mapping' => [
                'sku' => 'sku',
                'name' => 'name',
                'price' => 'price',
                'stock_qty' => 'stock_qty',
            ],
        ])->assertRedirect();

        $this->post('/admin/import-studio/preview')->assertRedirect();
        $this->assertDatabaseHas('operations', [
            'ability' => 'catalog.import.products',
            'dry_run' => 1,
        ]);

        $this->post('/admin/import-studio/execute')->assertRedirect();
        $this->assertDatabaseHas('products', ['sku' => 'IMP-9']);
        $created = Product::query()->where('sku', 'IMP-9')->first();
        $this->assertNotNull($created);
        $this->assertEquals(12.0, (float) $created->price);
    }

    public function test_webhook_ping_writes_a_log_with_payload(): void
    {
        Http::fake([
            'https://example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->post('/admin/ops-tools/webhooks', [
            'name' => 'Test hook',
            'url' => 'https://example.test/hook',
            'events' => ['*'],
        ])->assertRedirect();

        $id = WebhookEndpoint::query()->value('id');
        $this->post('/admin/ops-tools/webhooks/'.$id.'/ping')->assertRedirect();
        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/hook');

        $this->get('/admin/ops-tools?tab=deliveries')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ops/Tools', false)
                ->has('deliveries.0.payload')
                ->has('deliveries.0.response_body')
                ->where('deliveries.0.status', 'sent'));
    }

    public function test_page_catalog_unlocks_operate_pages_from_settings_manage(): void
    {
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'ops.activity'));
        $this->assertTrue(PageCatalog::grants(['settings.manage'], 'ops.health'));
        $this->assertTrue(PageCatalog::grants(['catalog.manage'], 'ops.import'));
    }

    protected function makeProduct(string $sku, float $price): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => $sku,
            'price' => $price,
            'stock_qty' => 5,
            'is_enabled' => true,
            'visibility' => 'catalog_search',
            'available_for_order' => true,
            'show_price' => true,
            'manage_stock' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => $sku,
            'slug' => strtolower($sku),
        ]);

        return $product;
    }
}
