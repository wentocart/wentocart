<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Ops\ShopStats;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Models\ShopLookup;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\Models\StoreSharingPolicy;
use App\Domain\Store\Sharing;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Store\StoreService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminMultishopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_install_has_one_shop_and_hides_the_switcher(): void
    {
        $this->assertSame(1, Store::query()->count());
        $this->assertFalse(filter_var(Setting::getValue('multishop_enabled', '0'), FILTER_VALIDATE_BOOLEAN));

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.multishop', false)
                ->where('shop.stores', [])
                ->where('shop.view_all', false)
            );

        $this->get('/admin/shops')->assertNotFound();
    }

    public function test_enable_shows_dropdown_and_shops_page(): void
    {
        $this->enableMultishop();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.multishop', true)
                ->has('shop.stores', 1)
                ->where('shop.stores.0.group', 'Default')
                ->where('shop.stores.0.url', fn ($url) => is_string($url) && $url !== '')
            );

        $this->get('/admin/shops')->assertOk();
    }

    public function test_enable_stays_on_across_repeated_setting_reads(): void
    {
        $this->enableMultishop();

        $ctx = app(StoreContext::class);
        for ($i = 0; $i < 5; $i++) {
            Setting::getValue('shop_name', '');
            $this->assertTrue($ctx->enabled(), 'read '.$i);
        }

        Cache::put('wento.settings', 'corrupt-cache-payload', 60);

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.multishop', true)
                ->has('shop.stores', 1)
            );
    }

    public function test_settings_cache_recovers_when_payload_cannot_be_read(): void
    {
        Setting::setValue('multishop_enabled', '1');
        $this->assertSame('1', Setting::getValue('multishop_enabled', '0'));
        $this->assertSame('1', Setting::getValue('multishop_enabled', '0'));

        Cache::put('wento.settings', 'not-settings', 60);
        $this->assertSame('1', Setting::getValue('multishop_enabled', '0'));

        $incomplete = unserialize('O:29:"Illuminate\\Support\\Collection":0:{}', ['allowed_classes' => false]);
        Cache::put('wento.settings', $incomplete, 60);
        $this->assertSame('1', Setting::getValue('multishop_enabled', '0'));
        $this->assertTrue(app(StoreContext::class)->enabled());
        $this->assertTrue(app(StoreContext::class)->enabled());
    }

    public function test_second_url_does_not_overwrite_the_default_shop_url(): void
    {
        $this->enableMultishop();
        $default = Store::query()->where('is_default', true)->firstOrFail();
        $original = ShopUrl::query()->where('store_id', $default->id)->where('is_primary', true)->firstOrFail();

        $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $this->get($this->shopUri('shop-b.test', '/'))->assertOk();

        $fresh = $original->fresh();
        $this->assertSame($original->domain, $fresh->domain);
        $this->assertSame($original->physical_uri, $fresh->physical_uri);
        $this->assertNotSame('shop-b.test', $fresh->domain);
    }

    public function test_virtual_uri_on_the_same_install_opens_the_second_shop(): void
    {
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop B',
                'email' => 'b@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => 'web-shop-2',
                'theme' => 'default',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        $this->assertSame('web-shop-2/', ShopUrl::query()->where('virtual_uri', 'web-shop-2/')->value('virtual_uri'));
        $this->get('/web-shop-2')->assertOk();
    }

    public function test_virtual_uri_keeps_capitals_and_opens_either_spelling(): void
    {
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop Caps',
                'email' => 'caps@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => 'SHOP',
                'theme' => 'default',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        $this->assertSame('SHOP/', ShopUrl::query()->where('virtual_uri', 'SHOP/')->value('virtual_uri'));
        $this->get('http://localhost/SHOP')->assertOk();

        $lower = $this->get('http://localhost/shop');
        $lower->assertRedirect();
        $this->assertStringEndsWith('/SHOP', (string) $lower->headers->get('Location'));

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop Caps Again',
                'email' => 'caps2@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => 'shop',
                'theme' => 'default',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertSessionHasErrors('virtual_uri');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Bad Path',
                'email' => 'bad-path@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => 'Shop 2',
                'theme' => 'default',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertSessionHasErrors('virtual_uri');
    }

    public function test_virtual_uri_accepts_a_non_latin_path(): void
    {
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop Han',
                'email' => 'han@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => '商店',
                'theme' => 'default',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        $this->assertSame('商店/', ShopUrl::query()->where('virtual_uri', '商店/')->value('virtual_uri'));
        $this->get('/商店')->assertOk();
    }

    public function test_same_host_shop_keeps_the_install_folder_and_opens_its_virtual_path(): void
    {
        config(['app.url' => 'http://localhost/shop']);
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop B',
                'email' => 'b@example.com',
                'scheme' => 'http',
                'domain' => 'localhost',
                'physical_uri' => '/',
                'virtual_uri' => 'web-shop-2',
                'theme' => 'classic',
                'preset' => 'empty',
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        $this->assertSame('/shop/', ShopUrl::query()->where('virtual_uri', 'web-shop-2/')->value('physical_uri'));

        $page = $this->call('GET', 'http://localhost/shop/web-shop-2', [], [], [], [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
            'HTTP_HOST' => 'localhost',
        ]);
        $page->assertOk();
        $page->assertSee('/shop/themes/classic/assets/header.css', false);
        $page->assertDontSee('/web-shop-2/themes/', false);
        $page->assertDontSee('/web-shop-2/build/', false);
        $page->assertSee('"base_path":"\/project\/6\/web-shop-2"', false);
        $page->assertSee('"asset_base":"\/project\/6"', false);

        ShopUrl::query()->where('virtual_uri', 'web-shop-2/')->update(['physical_uri' => '/']);
        $request = Request::create('http://localhost/shop/web-shop-2', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
        ]);
        $this->assertSame('web-shop-2/', ShopUrl::matchFromRequest($request)?->virtual_uri);
    }

    public function test_own_catalog_hides_products_from_the_other_storefront(): void
    {
        $this->enableMultishop();
        $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $home = $this->getJson($this->shopUri('shop-b.test', '/api/v1/catalog/home'))
            ->assertOk()
            ->json();

        $this->assertNotContains('DEMO-001', collect($home['featured'])->pluck('sku')->all());
    }

    public function test_shared_catalog_can_hide_a_product_on_one_shop(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'same');
        $product = Product::withoutGlobalScope('shop')->where('sku', 'DEMO-001')->firstOrFail();

        $home = $this->getJson($this->shopUri('shop-b.test', '/api/v1/catalog/home'))
            ->assertOk()
            ->json();
        $this->assertContains('DEMO-001', collect($home['featured'])->pluck('sku')->all());

        app(StoreService::class)->hideProductOnStore($product->id, $shopB->id);

        $hidden = $this->getJson($this->shopUri('shop-b.test', '/api/v1/catalog/home'))
            ->assertOk()
            ->json();
        $this->assertNotContains('DEMO-001', collect($hidden['featured'])->pluck('sku')->all());
    }

    public function test_own_customers_allow_the_same_email_on_another_shop(): void
    {
        $this->enableMultishop();
        $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $this->postJson($this->shopUri('localhost', '/api/v1/auth/register'), [
            'name' => 'Pat',
            'email' => 'pat@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();

        $this->flushSession();

        $this->postJson($this->shopUri('shop-b.test', '/api/v1/auth/register'), [
            'name' => 'Pat B',
            'email' => 'pat@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();

        $this->assertSame(2, Customer::withoutGlobalScope('shop')->where('email', 'pat@example.com')->count());
    }

    public function test_shared_customers_reuse_one_account(): void
    {
        $this->enableMultishop();
        $this->createShop('Shop B', 'http://shop-b.test', 'same');

        $this->postJson($this->shopUri('localhost', '/api/v1/auth/register'), [
            'name' => 'Pat',
            'email' => 'pat@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();

        $this->flushSession();

        $this->postJson($this->shopUri('shop-b.test', '/api/v1/auth/register'), [
            'name' => 'Pat B',
            'email' => 'pat@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422);

        $this->assertSame(1, Customer::withoutGlobalScope('shop')->where('email', 'pat@example.com')->count());
    }

    public function test_order_is_stamped_to_the_checkout_shop(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'same');
        $product = Product::withoutGlobalScope('shop')->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::withoutGlobalScope('shop')->firstOrFail();
        $shipping = ShippingMethod::withoutGlobalScope('shop')->firstOrFail();

        $this->postJson($this->shopUri('shop-b.test', '/api/v1/cart'), ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson($this->shopUri('shop-b.test', '/api/v1/checkout'), [
            'email' => 'guest-b@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $order = Order::withoutGlobalScope('shop')->where('email', 'guest-b@example.com')->firstOrFail();
        $this->assertSame($shopB->id, (int) $order->store_id);
    }

    public function test_shared_orders_are_visible_in_the_other_admin_shop(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'same');
        $this->placeDefaultOrder('shared-order@example.com');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['store_id' => $shopB->id])
            ->assertRedirect();

        $this->get('/admin/orders')
            ->assertOk()
            ->assertSee('shared-order@example.com', false);
    }

    public function test_own_orders_are_not_visible_in_the_other_admin_shop(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'empty');
        $this->placeDefaultOrder('own-order@example.com');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['store_id' => $shopB->id])
            ->assertRedirect();

        $this->get('/admin/orders')
            ->assertOk()
            ->assertDontSee('own-order@example.com', false);
    }

    public function test_carts_do_not_mix_across_shops(): void
    {
        $this->enableMultishop();
        $this->createShop('Shop B', 'http://shop-b.test', 'same');
        $product = Product::withoutGlobalScope('shop')->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson($this->shopUri('localhost', '/api/v1/cart'), ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $this->getJson($this->shopUri('shop-b.test', '/api/v1/cart'))
            ->assertOk()
            ->assertJsonPath('cart.items', []);
    }

    public function test_edit_form_never_saves_as_all_shops(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['store_id' => 'all'])
            ->assertRedirect();

        $this->put('/admin/store', [
            'shop_name' => 'Renamed default',
            'shop_email' => 'admin@wentocart.test',
            'shop_address' => '',
            'shop_url' => config('app.url'),
            'theme' => 'default',
        ])->assertRedirect();

        $this->assertSame('Renamed default', Store::query()->where('is_default', true)->value('name'));
        $this->assertSame('Shop B', $shopB->fresh()->name);
    }

    public function test_empty_shop_starts_with_no_existing_records(): void
    {
        $this->enableMultishop();
        $shopB = $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['store_id' => $shopB->id])
            ->assertRedirect();

        $this->get('/admin/warehouses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('warehouses.total', 0));

        $this->get('/admin/order-stages')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 0)->where('stages.total', 0));

        $this->get('/admin/customer-groups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 0));

        $this->get('/admin/addresses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 0)->where('addresses.total', 0));

        $this->get('/admin/reviews')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 0)->where('reviews.total', 0));

        $this->get('/admin/questions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', 0)->where('questions.total', 0));

        $this->assertSame(0, ShopStats::productStats()['total']);

        $default = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/admin/shop-context', ['store_id' => $default->id])->assertRedirect();
        $this->get('/admin/warehouses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('warehouses.total', fn ($total) => $total > 0));
    }

    public function test_importing_warehouses_links_them_to_the_new_shop(): void
    {
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $before = Warehouse::withoutGlobalScope('shop')->count();
        $this->assertGreaterThan(0, $before);

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Shop Import',
                'email' => 'import@example.com',
                'shop_url' => 'http://import.test',
                'theme' => 'default',
                'import_data' => true,
                'import' => ['warehouses'],
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        $shop = Store::query()->where('name', 'Shop Import')->firstOrFail();
        $this->post('/admin/shop-context', ['store_id' => $shop->id])->assertRedirect();
        $this->get('/admin/warehouses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('warehouses.total', $before));
    }

    public function test_importing_products_and_brands_does_not_share_orders_or_customers(): void
    {
        $this->enableMultishop();
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $customer = new Customer;
        $customer->forceFill([
            'store_id' => $source->id,
            'name' => 'Source Customer',
            'email' => 'source-customer@example.com',
            'password' => 'secret',
        ])->save();
        $brandLinks = ShopLookup::query()->where('family', ShopLookup::FAMILY_BRAND)->where('store_id', $source->id)->count();
        $this->assertGreaterThan(0, $brandLinks);

        Order::createTrusted([
            'store_id' => $source->id,
            'number' => 'WENTO-LEAK',
            'email' => 'leak@example.com',
            'status' => 'shipped',
            'payment_status' => 'refunded',
            'refunded_total' => 10,
            'currency_code' => 'USD',
            'grand_total' => 10,
            'billing_address' => ['first_name' => 'Leak'],
            'shipping_address' => ['first_name' => 'Leak'],
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['store_id' => $source->id])
            ->assertRedirect();
        $this->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', fn ($total) => $total > 0));

        $this->post('/admin/shops', [
            'name' => 'Iphone Import',
            'email' => 'iphone-import@example.com',
            'shop_url' => 'http://iphone-import.test',
            'theme' => 'default',
            'import_data' => true,
            'import' => ['products', 'brands'],
            'copy_from_id' => $source->id,
        ])->assertRedirect();

        $shop = Store::query()->where('name', 'Iphone Import')->firstOrFail();
        $this->assertSame(
            Sharing::MODE_OWN,
            StoreSharingPolicy::query()->where('store_id', $shop->id)->where('family', Sharing::FAMILY_ORDERS)->value('mode'),
        );
        $this->assertSame(
            Sharing::MODE_OWN,
            StoreSharingPolicy::query()->where('store_id', $shop->id)->where('family', Sharing::FAMILY_CUSTOMERS)->value('mode'),
        );
        $this->assertSame(
            $brandLinks,
            ShopLookup::query()->where('family', ShopLookup::FAMILY_BRAND)->where('store_id', $shop->id)->count(),
        );

        $this->post('/admin/shop-context', ['store_id' => $shop->id])->assertRedirect();
        $this->assertGreaterThan(0, ShopStats::productStats()['total']);
        $this->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0)->where('counts.all', 0));
        $this->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('customers.total', 0));
        $this->get('/admin/invoices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.total', 0));
        $this->get('/admin/credit-slips')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('creditSlips.total', 0));
        $this->get('/admin/delivery-slips')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('deliverySlips.total', 0));

        $this->post('/admin/shop-context', ['store_id' => $source->id])->assertRedirect();
        $this->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.all', fn ($total) => $total > 0));
    }

    public function test_deleted_shop_releases_its_url(): void
    {
        $this->enableMultishop();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => 'Mobile',
                'email' => 'mobile@example.com',
                'domain' => 'localhost',
                'port' => 8080,
                'physical_uri' => '/',
                'virtual_uri' => 'mobile',
                'theme' => 'default',
                'import_data' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $shop = Store::query()->where('name', 'Mobile')->firstOrFail();
        $this->delete('/admin/shops/'.$shop->id)->assertRedirect();
        $this->assertNull(Store::query()->where('name', 'Mobile')->first());

        StoreBootstrap::ensure();

        $default = Store::query()->where('is_default', true)->firstOrFail();
        $this->assertSame(
            0,
            ShopUrl::query()->where('store_id', $default->id)->where('virtual_uri', 'like', 'mobile%')->count(),
        );

        $this->post('/admin/shops', [
            'name' => 'Mobile',
            'email' => 'mobile@example.com',
            'domain' => 'localhost',
            'port' => 8080,
            'physical_uri' => '/',
            'virtual_uri' => 'mobile',
            'theme' => 'default',
            'import_data' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(Store::query()->where('name', 'Mobile')->first());
    }

    public function test_cannot_disable_multishop_while_a_second_shop_exists(): void
    {
        $this->enableMultishop();
        $this->createShop('Shop B', 'http://shop-b.test', 'empty');

        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/store')
            ->post('/admin/shop/multishop', ['enabled' => 0])
            ->assertRedirect('/admin/store')
            ->assertSessionHasErrors('enabled');
    }

    public function test_system_customer_groups_exist_in_every_shop_and_cannot_be_deleted(): void
    {
        $this->enableMultishop();
        $shop = $this->createShop('Bottle', 'http://bottle.test', 'empty');
        $session = ['admin.context' => 'shop:'.$shop->id, 'admin.store_id' => $shop->id];

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->get('/admin/customers/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups', 3)
                ->where('groups.0.code', 'visitor')
                ->where('groups.1.code', 'guest')
                ->where('groups.2.code', 'customer')
            );

        $visitor = CustomerGroup::withoutGlobalScope('shop')->where('code', 'visitor')->first();
        $this->assertNotNull($visitor);
        $this->assertTrue($visitor->is_system);

        foreach (Store::query()->pluck('id') as $storeId) {
            $this->assertDatabaseHas('shop_lookups', [
                'family' => ShopLookup::FAMILY_CUSTOMER_GROUP,
                'lookup_id' => $visitor->id,
                'store_id' => $storeId,
                'is_visible' => 1,
            ]);
        }

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->delete('/admin/customer-groups/'.$visitor->id)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customer_groups', ['id' => $visitor->id, 'code' => 'visitor']);

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->post('/admin/customer-groups/'.$visitor->id.'/duplicate')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('customer_groups', ['code' => 'visitor-copy']);

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->post('/admin/customers', [
                'first_name' => 'Pat',
                'last_name' => 'Shopper',
                'email' => 'pat.bottle@example.com',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'customer_group' => 'customer',
                'is_enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'email' => 'pat.bottle@example.com',
            'customer_group' => 'customer',
        ]);
    }

    public function test_customer_save_without_a_group_for_this_shop_stays_on_the_form(): void
    {
        $this->enableMultishop();
        $shop = $this->createShop('Mobile', 'http://localhost/mobile', 'empty');
        $session = ['admin.context' => 'shop:'.$shop->id, 'admin.store_id' => $shop->id];

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->from('/admin/customers/create')
            ->post('/admin/customers', [
                'first_name' => 'Pat',
                'last_name' => 'Shopper',
                'email' => 'pat.mobile@example.com',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'customer_group' => 'retailer',
            ])
            ->assertRedirect('/admin/customers/create')
            ->assertSessionHasErrors('customer_group');

        $this->assertSame('Customer group is required.', session('errors')->first('customer_group'));
        $this->assertDatabaseMissing('customers', ['email' => 'pat.mobile@example.com']);

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->post('/admin/customer-groups', [
                'name' => 'Retailer',
                'code' => 'mobile-retail',
                'color' => 'success',
                'discount_percent' => 0,
                'is_enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->post('/admin/customers', [
                'first_name' => 'Pat',
                'last_name' => 'Shopper',
                'email' => 'pat.mobile@example.com',
                'password' => 'Password1',
                'password_confirmation' => 'Password1',
                'customer_group' => 'mobile-retail',
            ])
            ->assertRedirect();

        $customer = Customer::withoutGlobalScope('shop')->where('email', 'pat.mobile@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertSame($shop->id, (int) $customer->store_id);

        $this->actingAs($this->admin(), 'admin')
            ->withSession($session)
            ->get('/admin/customers/'.$customer->id)
            ->assertOk();
    }

    protected function admin(): Admin
    {
        return Admin::query()->firstOrFail();
    }

    protected function enableMultishop(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop/multishop', ['enabled' => 1])
            ->assertRedirect();
    }

    protected function createShop(string $name, string $url, string $preset): Store
    {
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => $name,
                'email' => 'b@example.com',
                'shop_url' => $url,
                'theme' => 'default',
                'preset' => $preset,
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        return Store::query()->where('name', $name)->firstOrFail();
    }

    protected function shopUri(string $host, string $path): string
    {
        return 'http://'.$host.$path;
    }

    protected function placeDefaultOrder(string $email): void
    {
        $product = Product::withoutGlobalScope('shop')->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::withoutGlobalScope('shop')->firstOrFail();
        $shipping = ShippingMethod::withoutGlobalScope('shop')->firstOrFail();

        $this->postJson($this->shopUri('localhost', '/api/v1/cart'), ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson($this->shopUri('localhost', '/api/v1/checkout'), [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
    }

    public function test_group_context_keeps_color_settings_and_shared_cart_inside_the_group(): void
    {
        $this->enableMultishop();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-groups', [
                'name' => 'Shop2',
                'share_customers' => 1,
                'share_orders' => 1,
                'share_stock' => 1,
                'color' => '#112233',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-groups', [
                'name' => 'OrdersWithoutStock',
                'share_customers' => 1,
                'share_orders' => 1,
                'share_stock' => 0,
            ])
            ->assertSessionHasErrors('name');
        $this->assertNull(StoreGroup::query()->where('name', 'OrdersWithoutStock')->first());

        $group = StoreGroup::query()->where('name', 'Shop2')->firstOrFail();
        $this->assertTrue((bool) $group->share_stock);
        $this->assertTrue((bool) $group->share_orders);
        $this->assertSame('#112233', $group->color);

        $mobile = $this->createShopInGroup('Mobile', 'http://localhost/mobile', $group->id);
        $tablet = $this->createShopInGroup('Tablet', 'http://localhost/tablet', $group->id);

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-context', ['context' => 'group', 'group_id' => $group->id])
            ->assertRedirect();

        $this->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.context', 'group')
                ->where('shop.context_group_id', $group->id)
                ->where('shop.context_color', '#112233')
                ->where('shop.context_label', 'Shop2')
            );

        $request = Request::create('/admin/store', 'GET');
        $request->setLaravelSession($this->app['session.store']);
        $context = app(StoreContext::class);
        $context->resolveFromRequest($request);
        ShopSetting::put('test_group_banner', 'hello-group');

        $this->assertSame('hello-group', Setting::getValue(ShopSetting::groupKey($group->id, 'test_group_banner')));
        $this->assertNull(Setting::getValue(ShopSetting::namespaced('test_group_banner', $mobile->id)));
        $this->assertSame(min($mobile->id, $tablet->id), $context->cartStoreId());

        $default = Store::query()->where('is_default', true)->firstOrFail();
        $this->post('/admin/shop-context', ['context' => 'shop', 'store_id' => $default->id])->assertRedirect();
        $request = Request::create('/admin/store', 'GET');
        $request->setLaravelSession($this->app['session.store']);
        $context = app(StoreContext::class);
        $context->resolveFromRequest($request);
        $this->assertNotSame('hello-group', ShopSetting::get('test_group_banner', ''));
    }

    public function test_share_switches_stay_editable_until_the_group_has_that_data(): void
    {
        $this->enableMultishop();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shop-groups', [
                'name' => 'OpenGroup',
                'share_customers' => 1,
                'share_orders' => 0,
                'share_stock' => 0,
            ])
            ->assertRedirect();
        $group = StoreGroup::query()->where('name', 'OpenGroup')->firstOrFail();
        $shop = $this->createShopInGroup('OpenShop', 'http://localhost/openshop', $group->id);

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/shop-groups/'.$group->id, [
                'name' => 'OpenGroup',
                'share_customers' => 0,
                'share_orders' => 0,
                'share_stock' => 0,
            ])
            ->assertRedirect();
        $this->assertFalse((bool) $group->fresh()->share_customers);

        $customer = new Customer;
        $customer->forceFill([
            'store_id' => $shop->id,
            'name' => 'Group Customer',
            'email' => 'group-customer@example.com',
            'password' => 'secret',
        ])->save();

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/shop-groups/'.$group->id, [
                'name' => 'OpenGroup',
                'share_customers' => 1,
                'share_orders' => 0,
                'share_stock' => 0,
            ])
            ->assertSessionHasErrors('name');
        $this->assertFalse((bool) $group->fresh()->share_customers);
    }

    protected function createShopInGroup(string $name, string $url, int $groupId): Store
    {
        $source = Store::query()->where('is_default', true)->firstOrFail();
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/shops', [
                'name' => $name,
                'email' => 'b@example.com',
                'shop_url' => $url,
                'theme' => 'default',
                'preset' => Sharing::PRESET_EMPTY,
                'group_id' => $groupId,
                'copy_from_id' => $source->id,
            ])
            ->assertRedirect();

        return Store::query()->where('name', $name)->firstOrFail();
    }
}
