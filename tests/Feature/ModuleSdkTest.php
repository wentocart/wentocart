<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ModuleSdkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PluginKernel::reset();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->installFixtureExtension('wento', 'sample-kit');
        $this->post('/admin/apps/wento/sample-kit/enable', ['enabled' => true])->assertRedirect();
        PluginKernel::reset();
    }

    public function test_sample_kit_adds_account_manager_menu(): void
    {
        $this->get('/admin/dashboards')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moduleNav.0.label', 'Account Manager')
                ->where('moduleNav.0.items.0.label', 'Configuration')
                ->where('moduleNav.0.items.0.href', '/admin/modules/wento/sample-kit/configuration')
                ->where('moduleNav.0.items.1.label', 'Employee Settings')
                ->where('moduleNav.0.items.2.label', 'Customer Information')
            );
    }

    public function test_hosted_admin_page_renders_page_document(): void
    {
        $this->get('/admin/modules/wento/sample-kit/configuration')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->where('document.title', 'Configuration')
                ->where('css.0', '/plugins/wento/sample-kit/assets/admin.css')
            );
    }

    public function test_hosted_admin_save_keeps_values_and_shows_validation_errors(): void
    {
        $this->post('/admin/modules/wento/sample-kit/configuration', [
            'values' => ['office_name' => ''],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->where('document.errors.0', 'Office name is required.')
                ->where('document.field_errors.office_name', 'Office name is required.')
                ->where('values.office_name', '')
            );

        $this->post('/admin/modules/wento/sample-kit/configuration', [
            'values' => ['office_name' => 'North office'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->where('document.errors', [])
                ->where('values.office_name', 'North office')
            );
    }

    public function test_hosted_admin_list_supports_row_open_and_bulk_ids(): void
    {
        $this->get('/admin/modules/wento/sample-kit/customer-information')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->where('document.list.rows.0.id', '1')
                ->where('document.list.rows.0.href', '/admin/modules/wento/sample-kit/customer-information?id=1')
                ->where('document.list.bulk.0.key', 'enable')
                ->where('document.list.selectable', true)
            );

        $this->post('/admin/modules/wento/sample-kit/customer-information', [
            'list_action' => 'delete',
            'ids' => ['1'],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->has('document.list.rows', 1)
                ->where('document.list.rows.0.id', '2')
            );

        $this->get('/admin/modules/wento/sample-kit/customer-information?id=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/ModulePage', false)
                ->where('document.title', 'Edit contact')
                ->where('values.email', 'ben@example.com')
            );
    }

    public function test_product_list_gains_supplier_column(): void
    {
        $this->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moduleList.columns.0.key', 'supplier')
                ->where('moduleList.columns.0.label', 'Supplier')
            );
    }

    public function test_product_edit_shows_view_slot(): void
    {
        $product = Product::query()->first();
        $this->assertNotNull($product);

        $this->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moduleSlots.0.title', 'Sample kit')
            );
    }

    public function test_product_delete_increments_kit_setting(): void
    {
        $product = Product::query()->latest('id')->first();
        $this->assertNotNull($product);

        $this->delete('/admin/products/'.$product->id)->assertRedirect();

        $plugin = app(PluginKernel::class)->instance('wento', 'sample-kit');
        $this->assertSame(1, (int) $plugin->context()->settings()->get('deleted_products', 0));
    }

    public function test_customer_list_gains_notes_column(): void
    {
        $this->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moduleList.columns.0.key', 'notes')
                ->where('moduleList.columns.0.label', 'Notes')
            );
    }

    public function test_shop_api_exposes_storefront_and_account_slots(): void
    {
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('modules.header_top.0.text', 'Sample kit header slot')
            ->assertJsonPath('modules.footer.0.text', 'Sample kit footer slot')
            ->assertJsonPath('modules.account_menu.0.href', '/m/wento/sample-kit/board')
            ->assertJsonPath('modules.account_menu.0.label', 'Account board')
            ->assertJsonPath('modules.account_menu.0.icon.kind', 'kit')
            ->assertJsonPath('modules.account_menu.0.icon.value', 'user')
            ->assertJsonPath('modules.account_menu.1.href', '/m/wento/sample-kit/credits')
            ->assertJsonPath('modules.account_menu.1.label', 'Store credit list');
    }

    public function test_storefront_module_page_requires_customer(): void
    {
        $this->getJson('/api/v1/modules/wento/sample-kit/board')->assertUnauthorized();

        $customer = Customer::factory()->create([
            'email' => 'kit-board@wentocart.test',
            'is_enabled' => true,
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/modules/wento/sample-kit/board')
            ->assertOk()
            ->assertJsonPath('document.title', 'Account board');

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/modules/wento/sample-kit/credits?per_page=10')
            ->assertOk()
            ->assertJsonPath('document.title', 'Store credit list')
            ->assertJsonPath('document.list.search', true)
            ->assertJsonPath('document.list.rows.0.status_tone', 'danger')
            ->assertJsonPath('document.list.rows.0.actions.0.label', 'View');
    }

    public function test_sample_kit_packs_through_the_scanner(): void
    {
        $code = Artisan::call('wento:app-pack', ['id' => 'wento/sample-kit']);
        $this->assertSame(0, $code);
        $this->assertFileExists(base_path('plugin-packs/wento-sample-kit.zip'));
    }

    public function test_javascript_outside_assets_is_rejected_even_with_admin_assets(): void
    {
        $installer = app(PluginInstaller::class);
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'sneaky-js',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'admin.assets'],
            ]),
            'src/Plugin.php' => $this->emptyPluginPhp('Acme', 'SneakyJs'),
            'public/hack.js' => 'alert(1)',
        ]);

        try {
            $installer->install(new UploadedFile($path, 'sneaky.zip', 'application/zip', null, true));
            $this->fail('javascript outside assets/ should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_eval_javascript_is_rejected(): void
    {
        $installer = app(PluginInstaller::class);
        $path = $this->makeZip([
            'app.json' => json_encode([
                'vendor' => 'acme',
                'name' => 'eval-js',
                'type' => 'safe',
                'capabilities' => ['admin.page', 'admin.assets'],
            ]),
            'src/Plugin.php' => $this->emptyPluginPhp('Acme', 'EvalJs'),
            'assets/admin.js' => 'eval("1")',
        ]);

        try {
            $installer->install(new UploadedFile($path, 'eval-js.zip', 'application/zip', null, true));
            $this->fail('eval javascript should be rejected');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    protected function emptyPluginPhp(string $vendor, string $name): string
    {
        return <<<PHP
<?php

namespace Modules\\{$vendor}\\{$name};

use App\\Domain\\Plugin\\Support\\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void {}
}
PHP;
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function makeZip(array $files): string
    {
        if (! isset($files['README.md'])) {
            $files['README.md'] = "# Test extension\n";
        }
        $path = storage_path('framework/testing/'.uniqid('sdk_', true).'.zip');
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
