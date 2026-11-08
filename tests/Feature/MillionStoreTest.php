<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Installer\MillionDataService;
use App\Domain\Order\Models\Order;
use App\Domain\Search\PrefixQuery;
use App\Domain\Settings\Models\Setting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MillionStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_demo_data_install_is_refused_after_the_first_time(): void
    {
        Setting::setValue('demo_data_installed', '1');

        $this->from('/admin/advanced?tab=demo')
            ->post('/admin/advanced/demo/install')
            ->assertRedirect('/admin/advanced?tab=demo')
            ->assertSessionHas('info', 'Demo data has already been installed. It can be added only once, during installation or from this page.');
    }

    public function test_demo_data_remove_is_a_no_op_when_nothing_is_installed(): void
    {
        Setting::setValue('demo_data_installed', '0');

        $this->from('/admin/advanced?tab=demo')
            ->post('/admin/advanced/demo/remove')
            ->assertRedirect('/admin/advanced?tab=demo')
            ->assertSessionHas('info', 'There is no demo data to remove.');
    }

    public function test_prefix_query_never_uses_leading_wildcard(): void
    {
        $this->assertSame('SCALE-%', PrefixQuery::like('SCALE-'));
        $this->assertStringStartsNotWith('%', PrefixQuery::like('headphone'));
    }

    public function test_million_store_command_inserts_scale_rows(): void
    {
        $this->artisan('wento:million-store', [
            '--products' => 12,
            '--customers' => 12,
            '--orders' => 12,
            '--reviews' => 12,
            '--wishlists' => 12,
            '--chunk' => 50,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(12, Product::query()->where('sku', 'like', MillionDataService::SKU_PREFIX.'%')->count());
        $this->assertSame(12, Customer::query()->where('email', 'like', '%@'.MillionDataService::EMAIL_DOMAIN)->count());
        $this->assertSame(12, Order::withoutGlobalScopes()->where('number', 'like', MillionDataService::ORDER_PREFIX.'%')->count());
        $this->assertDatabaseHas('product_translations', ['slug' => 'scale-product-1']);
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('category_product')->count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('order_items')->count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('reviews')->count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('wishlists')->count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('customer_addresses')->count());
    }

    public function test_admin_product_order_and_customer_lists_use_numbered_pages(): void
    {
        $this->artisan('wento:million-store', [
            '--products' => 12,
            '--customers' => 12,
            '--orders' => 12,
            '--reviews' => 0,
            '--wishlists' => 0,
            '--force' => true,
        ])->assertSuccessful();

        $this->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Products/Index', false)
                ->has('products.last_page')
                ->has('products.total')
                ->has('products.data')
                ->where('counts.all', fn ($n) => (int) $n >= 12));

        $this->get('/admin/products?q=SCALE-0000001')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'SCALE-0000001'));

        $this->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Index', false)
                ->has('orders.last_page')
                ->has('orders.total')
                ->has('orders.data'));

        $this->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index', false)
                ->has('customers.last_page')
                ->has('customers.total')
                ->has('customers.data'));
    }

    public function test_admin_can_start_millions_job_from_demo_tab(): void
    {
        $this->from('/admin/advanced?tab=demo')
            ->post('/admin/advanced/demo/millions')
            ->assertRedirect('/admin/advanced?tab=demo');

        $this->get('/admin/advanced?tab=demo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Advanced', false)
                ->has('millions.status')
                ->has('millions.products'));
    }
}
