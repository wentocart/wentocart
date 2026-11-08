<?php

namespace Tests\Feature;

use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use App\Domain\Store\ShopIdentity;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_store_settings_exposes_structured_identity(): void
    {
        $this->get('/admin/store')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Store')
                ->has('store.identity')
                ->has('store.identity.footer')
                ->has('countries')
                ->missing('purposePreviews')
                ->missing('themes'));
    }

    public function test_general_configuration_page_loads(): void
    {
        $this->get('/admin/general')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/General'));
    }

    public function test_saving_identity_composes_address_and_respects_footer_flags(): void
    {
        $this->put('/admin/store', [
            'shop_name' => 'Weblytic Shop',
            'shop_email' => 'hello@example.com',
            'shop_url' => config('app.url'),
            'phone' => '+91 9876543210',
            'line1' => '12 Market Street',
            'line2' => '2nd floor',
            'city' => 'Jaipur',
            'region' => 'Rajasthan',
            'postal' => '302001',
            'country_code' => 'IN',
            'business_number' => '22AAAAA0000A1Z5',
            'footer' => [
                'name' => true,
                'email' => true,
                'phone' => true,
                'address' => true,
                'business_number' => true,
            ],
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertNotNull($store);
        $this->assertSame('Weblytic Shop', $store->name);
        $this->assertStringContainsString('12 Market Street', (string) $store->address);
        $this->assertStringContainsString('Jaipur', (string) $store->address);
        $this->assertSame('+91 9876543210', Setting::getValue('shop_phone'));

        $public = ShopIdentity::forStorefront($store, 'en');
        $this->assertSame('Weblytic Shop', $public['name']);
        $this->assertSame('22AAAAA0000A1Z5', $public['business_number']);
        $this->assertTrue($public['footer']['business_number']);

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('shop.name', 'Weblytic Shop')
            ->assertJsonPath('shop.phone', '+91 9876543210')
            ->assertJsonPath('shop.business_number', '22AAAAA0000A1Z5')
            ->assertJsonPath('shop.footer.business_number', true)
            ->assertJsonPath('shop.footer.phone', true);
    }

    public function test_store_settings_ignores_a_posted_shop_url(): void
    {
        $store = Store::query()->where('is_default', true)->firstOrFail();
        $before = $store->primaryUrl()?->root();

        $this->put('/admin/store', [
            'shop_name' => 'Weblytic Shop',
            'shop_email' => 'hello@example.com',
            'shop_url' => 'http://localhost:8080/mobile',
        ])->assertRedirect();

        $this->assertSame($before, $store->fresh()->primaryUrl()?->root());
        $this->assertNotSame('http://localhost:8080/mobile', Setting::getValue('shop_url'));
    }

    public function test_empty_identity_does_not_invent_a_country(): void
    {
        ShopSetting::put(ShopIdentity::SETTING, []);
        foreach (['shop_line1', 'shop_line2', 'shop_city', 'shop_region', 'shop_postal', 'shop_country', 'shop_phone'] as $key) {
            Setting::setValue($key, '');
        }

        $identity = ShopIdentity::get();
        $this->assertSame('', $identity['country_code']);
        $this->assertSame('', $identity['line1']);
        $this->assertSame('', $identity['city']);
    }

    public function test_combined_address_line_splits_back_into_fields(): void
    {
        ShopSetting::put(ShopIdentity::SETTING, [
            'line1' => "12 Market Street\n2nd floor\nJaipur\nRajasthan\n302001\nIndia",
            'line2' => '',
            'city' => '',
            'region' => '',
            'postal' => '',
            'country_code' => '',
        ]);
        foreach (['shop_line2', 'shop_city', 'shop_region', 'shop_postal', 'shop_country'] as $key) {
            Setting::setValue($key, '');
        }

        $identity = ShopIdentity::get();

        $this->assertSame('12 Market Street', $identity['line1']);
        $this->assertSame('2nd floor', $identity['line2']);
        $this->assertSame('Jaipur', $identity['city']);
        $this->assertSame('Rajasthan', $identity['region']);
        $this->assertSame('302001', $identity['postal']);
        $this->assertSame('IN', $identity['country_code']);
    }

    public function test_install_fields_prefill_store_identity(): void
    {
        ShopIdentity::captureInstall([
            'store_name' => 'Install Shop',
            'store_email' => 'install@example.com',
            'store_line1' => '12 Market Street',
            'store_line2' => '2nd floor',
            'store_city' => 'Jaipur',
            'store_region' => 'Rajasthan',
            'store_postal' => '302001',
            'store_country' => 'IN',
        ]);

        $identity = ShopIdentity::forAdmin();
        $this->assertSame('Install Shop', $identity['names']['en'] ?? $identity['names'][array_key_first($identity['names'])]);
        $this->assertSame('12 Market Street', $identity['line1']);
        $this->assertSame('Jaipur', $identity['city']);
        $this->assertSame('Rajasthan', $identity['region']);
        $this->assertSame('302001', $identity['postal']);
        $this->assertSame('IN', $identity['country_code']);
        $this->assertSame('12 Market Street', Setting::getValue('shop_line1'));
        $this->assertSame('IN', Setting::getValue('shop_country'));
    }
}
