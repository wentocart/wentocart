<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave210Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_store_config_rest_and_graphql(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('orders.minimum_order_enabled', true);
        ShopSetting::put('orders.min_amount', 25);
        ShopSetting::put('orders.minimum_order_description', 'Order at least $25');
        ShopSetting::put('checkout.mini_cart_shown', true);
        ShopSetting::put('checkout.mini_cart_offer_info', 'Free shipping over $50');

        $rest = $this->getJson('/api/v1/store/store-config');
        $rest->assertOk();
        $this->assertTrue((bool) $rest->json('guestCheckout') || is_bool($rest->json('guestCheckout')));
        $this->assertSame(25.0, (float) $rest->json('minimumOrderAmount'));
        $this->assertSame('Order at least $25', (string) $rest->json('minimumOrderMessage'));
        $this->assertTrue((bool) $rest->json('miniCartOfferInfoEnabled'));
        $this->assertSame('Free shipping over $50', (string) $rest->json('miniCartOfferInfo'));
        $this->assertArrayHasKey('wishlistEnabled', $rest->json() ?? []);
        $this->assertArrayHasKey('compareEnabled', $rest->json() ?? []);
        $this->assertArrayHasKey('gdprEnabled', $rest->json() ?? []);
        $this->assertArrayHasKey('euWithdrawalEnabled', $rest->json() ?? []);
        $this->assertArrayHasKey('socialLoginProviders', $rest->json() ?? []);
        $this->assertIsArray($rest->json('socialLoginProviders'));

        $alias = $this->getJson('/api/v1/store-config');
        $alias->assertOk();
        $this->assertSame((float) $rest->json('minimumOrderAmount'), (float) $alias->json('minimumOrderAmount'));

        $gql = $this->postJson('/api/v1/graphql', [
            'query' => '{ storeConfig { guestCheckout minimumOrderAmount minimumOrderMessage wishlistEnabled compareEnabled miniCartOfferInfoEnabled miniCartOfferInfo taxDisplay } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertSame(25.0, (float) $gql->json('data.storeConfig.minimumOrderAmount'));
        $this->assertSame('Order at least $25', (string) $gql->json('data.storeConfig.minimumOrderMessage'));
        $this->assertTrue((bool) $gql->json('data.storeConfig.miniCartOfferInfoEnabled'));
    }
}
