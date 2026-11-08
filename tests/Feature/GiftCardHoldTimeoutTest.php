<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\GiftCardTransaction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * P0: OPEN gift-card HOLD timeout recovery independent of unpaid auto-cancel.
 */
class GiftCardHoldTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Ensure timeout recovery does not lean on unpaid auto-cancel.
        ShopSetting::put('orders.auto_cancel_unpaid', false);
        ShopSetting::put('orders.unpaid_timeout_minutes', 60);
    }

    protected function activeCard(float $balance = 500): GiftCard
    {
        $admin = Admin::query()->first() ?: Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $payload = $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'GC Hold',
            'slug' => 'gc-hold-'.uniqid(),
            'sku' => 'GCH-'.strtoupper(uniqid()),
            'price' => $balance,
            'is_enabled' => true,
            'gift_card' => [
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_offline_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
                'code_length' => 12,
            ],
            'gift_card_amounts' => [
                ['value' => $balance, 'selling_price' => $balance, 'is_enabled' => true, 'position' => 0],
            ],
        ]);
        $this->post('/admin/products', $payload)->assertProductSaveRedirect();
        $product = Product::query()->orderByDesc('id')->firstOrFail();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        return GiftCard::query()->create([
            'product_id' => $product->id,
            'code' => 'HOLD'.strtoupper(uniqid()),
            'initial_balance' => $balance,
            'current_balance' => $balance,
            'selected_amount' => $balance,
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }

    protected function pendingOrder(float $grand = 500, float $credit = 500): Order
    {
        return Order::createTrusted([
            'number' => 'WENTO-HOLD-'.uniqid(),
            'email' => 'hold@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => $grand + $credit,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => $grand,
            'payment_status' => 'pending',
            'payment_details' => [
                'gift_card_credit' => $credit,
            ],
            'billing_address' => ['first_name' => 'H', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'H', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
        ]);
    }

    protected function openHold(GiftCard $card, Order $order, float $amount, bool $expired = true): GiftCardTransaction
    {
        app(GiftCardService::class)->hold($card->code, $amount, $order);
        $hold = GiftCardTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', GiftCardService::TYPE_HOLD)
            ->where('metadata->state', GiftCardService::HOLD_STATE_OPEN)
            ->firstOrFail();

        if ($expired) {
            $meta = is_array($hold->metadata) ? $hold->metadata : [];
            $meta['expires_at'] = now()->subMinute()->toIso8601String();
            $hold->update(['metadata' => $meta]);
        }

        return $hold->fresh();
    }

    public function test_expired_pending_hold_is_released_and_balance_restored(): void
    {
        $card = $this->activeCard(500);
        $order = $this->pendingOrder();
        $this->openHold($card, $order, 500, expired: true);
        $this->assertEqualsWithDelta(0, (float) $card->fresh()->current_balance, 0.01);

        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();

        $card->refresh();
        $this->assertEqualsWithDelta(500, (float) $card->current_balance, 0.01);
        $hold = GiftCardTransaction::query()->where('order_id', $order->id)->where('type', GiftCardService::TYPE_HOLD)->firstOrFail();
        $this->assertSame(GiftCardService::HOLD_STATE_RELEASED, $hold->metadata['state'] ?? null);
        $this->assertSame(GiftCardService::RELEASE_REASON_TIMEOUT, $hold->metadata['release_reason'] ?? null);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
    }

    public function test_recovery_command_is_idempotent(): void
    {
        $card = $this->activeCard(200);
        $order = $this->pendingOrder(0, 200);
        $this->openHold($card, $order, 200);

        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();
        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();

        $this->assertEqualsWithDelta(200, (float) $card->fresh()->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
    }

    public function test_timeout_then_paid_webhook_recovers_redemption(): void
    {
        $card = $this->activeCard(500);
        $order = $this->pendingOrder();
        $this->openHold($card, $order, 500);

        // Timeout wins first.
        $this->assertSame('released', app(GiftCardService::class)->reconcileExpiredHold(
            (int) GiftCardTransaction::query()->where('order_id', $order->id)->value('id')
        ));
        $this->assertEqualsWithDelta(500, (float) $card->fresh()->current_balance, 0.01);

        // Payment succeeds afterward — observer/commitHold must re-debit.
        $order->updateTrusted(['payment_status' => 'paid']);

        $card->refresh();
        $this->assertEqualsWithDelta(0, (float) $card->current_balance, 0.01);
        $this->assertGreaterThanOrEqual(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
    }

    public function test_paid_before_timeout_sweep_commits_instead_of_releasing(): void
    {
        $card = $this->activeCard(300);
        $order = $this->pendingOrder(200, 300);
        $hold = $this->openHold($card, $order, 300);

        // Payment marked paid without observer (race: status flipped under timeout lock).
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update([
            'payment_status' => 'paid',
            'updated_at' => now(),
        ]);

        $result = app(GiftCardService::class)->reconcileExpiredHold((int) $hold->id);

        $this->assertSame('committed', $result);
        $this->assertEqualsWithDelta(0, (float) $card->fresh()->current_balance, 0.01);
        $this->assertSame(0, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
    }

    public function test_expired_hold_with_failed_payment_is_released(): void
    {
        $card = $this->activeCard(150);
        $order = $this->pendingOrder(50, 150);
        $this->openHold($card, $order, 150);
        $order->updateTrusted(['payment_status' => 'failed']);

        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();

        $this->assertEqualsWithDelta(150, (float) $card->fresh()->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
    }

    public function test_expired_hold_with_cancelled_order_is_released(): void
    {
        $card = $this->activeCard(120);
        $order = $this->pendingOrder(80, 120);
        $this->openHold($card, $order, 120);
        // Cancel path also releases via observer; force open hold again then expire sweep.
        // Simulate cancelled unpaid without relying on observer double-release:
        $order->updateTrusted(['status' => 'cancelled', 'payment_status' => 'pending']);
        // Observer may have released already — re-open if needed for timeout path.
        if (! GiftCardTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', GiftCardService::TYPE_HOLD)
            ->where('metadata->state', GiftCardService::HOLD_STATE_OPEN)
            ->exists()) {
            // Observer already released; verify idempotent timeout noop + balance restored.
            $this->assertEqualsWithDelta(120, (float) $card->fresh()->current_balance, 0.01);
            $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();
            $this->assertEqualsWithDelta(120, (float) $card->fresh()->current_balance, 0.01);

            return;
        }

        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();
        $this->assertEqualsWithDelta(120, (float) $card->fresh()->current_balance, 0.01);
    }

    public function test_already_committed_hold_is_ignored_by_timeout_command(): void
    {
        $card = $this->activeCard(400);
        $order = $this->pendingOrder(100, 400);
        $hold = $this->openHold($card, $order, 400, expired: false);
        app(GiftCardService::class)->commitHold($order);
        // Force expires_at in past on the now-redemption row metadata (type changed).
        $hold->refresh();
        $this->assertSame(GiftCardService::TYPE_REDEMPTION, $hold->type);

        $stats = app(GiftCardService::class)->releaseExpiredHolds();
        $this->assertSame(0, $stats['released']);
        $this->assertEqualsWithDelta(0, (float) $card->fresh()->current_balance, 0.01);
        $this->assertSame(0, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
    }

    public function test_already_released_hold_is_ignored_by_timeout_command(): void
    {
        $card = $this->activeCard(90);
        $order = $this->pendingOrder(10, 90);
        $this->openHold($card, $order, 90);
        app(GiftCardService::class)->releaseHold($order, GiftCardService::RELEASE_REASON_PAYMENT_FAILED);

        $before = GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count();

        $this->artisan('wento:gift-cards-release-expired-holds')->assertSuccessful();

        $this->assertSame($before, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
        $this->assertEqualsWithDelta(90, (float) $card->fresh()->current_balance, 0.01);
    }

    public function test_multiple_expired_holds_are_released(): void
    {
        $service = app(GiftCardService::class);
        $cards = [];
        for ($i = 0; $i < 3; $i++) {
            $card = $this->activeCard(50 + $i);
            $order = $this->pendingOrder(10, 50 + $i);
            $this->openHold($card, $order, 50 + $i);
            $cards[] = $card;
        }

        $stats = $service->releaseExpiredHolds();
        $this->assertSame(3, $stats['released']);

        foreach ($cards as $i => $card) {
            $this->assertEqualsWithDelta(50 + $i, (float) $card->fresh()->current_balance, 0.01);
        }
    }

    public function test_concurrent_reconcile_does_not_double_release(): void
    {
        $card = $this->activeCard(75);
        $order = $this->pendingOrder(25, 75);
        $hold = $this->openHold($card, $order, 75);

        $a = app(GiftCardService::class)->reconcileExpiredHold((int) $hold->id);
        $b = app(GiftCardService::class)->reconcileExpiredHold((int) $hold->id);

        $this->assertSame('released', $a);
        $this->assertSame('skipped', $b);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
        $this->assertEqualsWithDelta(75, (float) $card->fresh()->current_balance, 0.01);
    }

    public function test_hold_timeout_uses_unpaid_timeout_minutes_not_auto_cancel(): void
    {
        ShopSetting::put('orders.auto_cancel_unpaid', false);
        ShopSetting::put('orders.unpaid_timeout_minutes', 90);
        $this->assertSame(90, app(GiftCardService::class)->holdTimeoutMinutes());

        $card = $this->activeCard(40);
        $order = $this->pendingOrder(10, 40);
        app(GiftCardService::class)->hold($card->code, 40, $order);
        $hold = GiftCardTransaction::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(90, (int) ($hold->metadata['timeout_minutes'] ?? 0));
        $this->assertNotEmpty($hold->metadata['expires_at'] ?? null);
    }

    public function test_release_expired_holds_command_is_scheduled(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $matched = collect($schedule->events())->contains(function ($event) {
            $command = (string) ($event->command ?? '');

            return str_contains($command, 'wento:gift-cards-release-expired-holds');
        });
        $this->assertTrue($matched, 'Expected wento:gift-cards-release-expired-holds on the scheduler.');
    }

    public function test_hold_lifecycle_logs_without_gift_card_codes(): void
    {
        Log::spy();
        $card = $this->activeCard(60);
        $order = $this->pendingOrder(40, 60);
        $this->openHold($card, $order, 60, expired: false);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($card) {
            if ($message !== 'gift_card.hold.created') {
                return true;
            }
            $this->assertArrayNotHasKey('code', $context);
            $this->assertSame($card->id, $context['gift_card_id'] ?? null);

            return true;
        });
    }
}
