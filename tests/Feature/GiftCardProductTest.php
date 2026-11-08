<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Mail\ShopHtmlMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GiftCardProductTest extends TestCase
{
    use RefreshDatabase;

    protected function giftCardPayload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'gift_card',
            'name' => 'Store Gift Card',
            'slug' => 'store-gift-card',
            'sku' => 'GC-100',
            'price' => 100,
            'is_enabled' => true,
            'gift_card' => [
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'days',
                'validity_days' => 365,
                'allow_online_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
                'recipient_name_enabled' => true,
                'recipient_email_enabled' => true,
                'sender_name_enabled' => true,
                'gift_message_enabled' => true,
            ],
            'gift_card_amounts' => [
                ['value' => 500, 'selling_price' => 500, 'is_enabled' => true, 'position' => 0],
                ['value' => 1000, 'selling_price' => 900, 'is_enabled' => true, 'position' => 1],
            ],
        ], $overrides));
    }

    public function test_admin_can_create_and_edit_gift_card_product(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/products/create?type=gift_card')->assertOk();

        $this->post('/admin/products', $this->giftCardPayload())->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'GC-100')->first();
        $this->assertNotNull($product);
        $this->assertSame('gift_card', $product->type);
        $this->assertFalse((bool) $product->manage_stock);

        $config = app(GiftCardService::class)->presentConfig($product);
        $this->assertSame('fixed', $config['amount_type']);
        $this->assertCount(2, $config['amounts']);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();

        $this->put('/admin/products/' . $product->id, $this->giftCardPayload([
            'gift_card' => [
                'amount_type' => 'custom',
                'min_amount' => 100,
                'max_amount' => 10000,
                'default_amount' => 1000,
                'amount_step' => 100,
                'delivery_method' => 'both',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
            ],
            'gift_card_amounts' => [],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $config = app(GiftCardService::class)->presentConfig($product->fresh());
        $this->assertSame('custom', $config['amount_type']);
        $this->assertSame(100.0, $config['min_amount']);
    }

    public function test_gift_card_is_issued_after_payment_and_can_be_redeemed(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/products', $this->giftCardPayload());

        $product = Product::query()->where('sku', 'GC-100')->firstOrFail();
        $amount = $product->giftCardAmounts()->where('value', 1000)->firstOrFail();
        $cart = Cart::query()->create(['session_id' => 'gift-card-test']);
        $item = app(CartService::class)->add($cart, $product, 1, [], [], null, [
            'fixed_amount_id' => $amount->id,
            'recipient_name' => 'Alex',
            'recipient_email' => 'alex@example.com',
            'sender_name' => 'Sam',
            'gift_message' => 'Enjoy!',
        ]);

        $order = Order::createTrusted([
            'number' => 'WENTO-GC-1',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 900,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 900,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Town', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Town', 'country_code' => 'US'],
        ]);

        $orderItem = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Store Gift Card',
            'qty' => 1,
            'unit_price' => 900,
            'total' => 900,
            'gift_card_data' => $item->gift_card_data,
        ]);

        $this->assertSame(0, GiftCard::query()->count());
        Mail::fake();
        // payment_status is not mass-assignable; production transitions go through
        // the trusted writer (CheckoutService / admin / gateway callbacks).
        $order->updateTrusted(['payment_status' => 'paid', 'amount_paid' => 900]);

        $card = GiftCard::query()->first();
        $this->assertNotNull($card);
        $this->assertSame('active', $card->status);
        $this->assertSame('alex@example.com', $card->recipient_email);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('alex@example.com'));
        $this->assertEquals(1000, (float) $card->initial_balance);
        $this->assertEquals(1000, (float) $card->current_balance);
        $this->assertNotEmpty($card->code);

        app(GiftCardService::class)->redeem($card->code, 400);
        $card->refresh();
        $this->assertEquals(600, (float) $card->current_balance);
        $this->assertCount(2, $card->transactions);
    }

    public function test_virtual_and_simple_products_remain_unaffected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'License',
            'slug' => 'license',
            'sku' => 'LIC-1',
            'price' => 10,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $virtual = Product::query()->where('sku', 'LIC-1')->first();
        $this->assertFalse((bool) $virtual->manage_stock);
    }
}
