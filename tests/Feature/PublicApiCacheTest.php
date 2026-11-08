<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicApiCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_shop_payload_is_served_from_cache_on_second_hit(): void
    {
        $this->seed();
        ShopSetting::put('performance.api_cache', true);
        ShopSetting::put('performance.catalog_ttl', '600');
        Cache::flush();

        $first = $this->getJson('/api/v1/store')->assertOk();
        $this->assertSame('MISS', $first->headers->get('X-Wento-Cache'));

        $second = $this->getJson('/api/v1/store')->assertOk();
        $this->assertSame('HIT', $second->headers->get('X-Wento-Cache'));
        $this->assertSame($first->json('name'), $second->json('name'));
    }

    public function test_signed_in_customers_bypass_the_api_cache(): void
    {
        $this->seed();
        ShopSetting::put('performance.api_cache', true);
        ShopSetting::put('performance.catalog_ttl', '600');
        Cache::flush();

        $customer = \App\Domain\Customer\Models\Customer::factory()->create([
            'store_id' => app(\App\Domain\Store\StoreContext::class)->requireId(),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store')
            ->assertOk()
            ->assertHeaderMissing('X-Wento-Cache');
    }

    public function test_cart_routes_are_never_cached(): void
    {
        $this->seed();
        ShopSetting::put('performance.api_cache', true);
        ShopSetting::put('performance.catalog_ttl', '600');
        Cache::flush();

        $this->getJson('/api/v1/cart')->assertOk();
        $this->getJson('/api/v1/cart')->assertOk();
        // No HIT header means the middleware skipped the route.
        $this->assertNull($this->getJson('/api/v1/cart')->headers->get('X-Wento-Cache'));
    }

    public function test_rest_shop_checkout_routes_are_never_cached(): void
    {
        $this->seed();
        ShopSetting::put('performance.api_cache', true);
        ShopSetting::put('performance.catalog_ttl', '600');
        ShopSetting::put('api.require_storefront_key', false);
        Cache::flush();

        // Addresses may be empty (200); shipping/payment need a cart (500) — neither may be cached.
        foreach ([
            '/api/v1/store/checkout-addresses',
            '/api/v1/store/checkout-shipping-methods',
            '/api/v1/store/payment-methods',
            '/api/v1/store/customer-profile',
            '/api/v1/store/customer-orders',
        ] as $uri) {
            $res = $this->getJson($uri);
            $this->assertContains($res->status(), [200, 401, 403, 500], $uri);
            $this->assertNull($res->headers->get('X-Wento-Cache'), $uri);
        }
    }
}
