<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave220Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_eu_withdrawals_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('privacy.eu_withdrawal_enabled', true);

        $status = $this->getJson('/api/v1/store/eu-withdrawal-status');
        $status->assertOk();
        $this->assertTrue((bool) $status->json('enabled'));
        $this->assertNotEmpty((string) $status->json('channel'));

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $order = Order::createTrusted([
            'number' => 'EU-W220-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'DE'],
            'shipping_address' => ['country_code' => 'DE'],
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'EU item',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        $created = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/eu-withdrawals', [
                'order_id' => $order->id,
                'reason_text' => 'Changed my mind.',
            ]);
        $created->assertCreated();
        $this->assertSame('received', $created->json('status'));
        $this->assertSame('EU-W220-1', $created->json('orderIncrementId'));
        $wid = (int) $created->json('id');

        $again = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/eu-withdrawals', ['order_id' => $order->id]);
        $again->assertCreated()->assertJsonPath('id', $wid);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/eu-withdrawals');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertSame($wid, (int) $list->json('0.id'));

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/eu-withdrawals/'.$wid)
            ->assertOk()
            ->assertJsonPath('uuid', $created->json('uuid'));

        $guestOrder = Order::createTrusted([
            'number' => 'EU-W220-G',
            'customer_id' => null,
            'email' => 'guest220@example.test',
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'FR'],
            'shipping_address' => ['country_code' => 'FR'],
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);
        $guestOrder->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Guest EU item',
            'qty' => 1,
            'unit_price' => 15,
            'total' => 15,
        ]);

        $guest = $this->postJson('/api/v1/store/eu-withdrawals/guest', [
            'order_increment_id' => 'EU-W220-G',
            'email' => 'guest220@example.test',
            'reason_text' => 'Guest change of mind',
        ]);
        $guest->assertCreated();
        $this->assertTrue((bool) $guest->json('isGuest'));
        $this->assertSame('received', $guest->json('status'));

        $this->postJson('/api/v1/store/eu-withdrawals/guest', [
            'order_increment_id' => 'EU-W220-G',
            'email' => 'wrong@example.test',
        ])->assertNotFound();
    }
}
