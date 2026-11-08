<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\GiftCardTransaction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGiftCard;
use App\Domain\Catalog\Models\ProductGiftCardAmount;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ShopMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    /** Ledger transaction types. */
    public const TYPE_CREDIT = 'credit';
    public const TYPE_REDEMPTION = 'redemption';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CANCEL = 'cancel';
    /** Temporary debit while a deferred PSP capture is pending. */
    public const TYPE_HOLD = 'hold';
    /** Reversal of an open hold after payment fail/cancel. */
    public const TYPE_RELEASE = 'release';

    /** Lifecycle statuses. */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_USED = 'used';

    public const HOLD_STATE_OPEN = 'open';
    public const HOLD_STATE_COMMITTED = 'committed';
    public const HOLD_STATE_RELEASED = 'released';

    public const RELEASE_REASON_PAYMENT_FAILED = 'payment_failed';
    public const RELEASE_REASON_CANCELLED = 'cancelled';
    public const RELEASE_REASON_TIMEOUT = 'timeout';

    /** Failed apply attempts before temporary lockout. */
    public const APPLY_FAIL_LIMIT = 5;
    public const APPLY_FAIL_WINDOW_SECONDS = 900;
    public const APPLY_LOCKOUT_SECONDS = 900;

    public function syncProduct(Product $product, array $config, array $amounts = []): ProductGiftCard
    {
        if ($product->type !== 'gift_card') {
            abort(422, 'Gift card configuration applies only to gift card products.');
        }

        $this->validateConfig($config, $amounts);

        return DB::transaction(function () use ($product, $config, $amounts) {
            $row = ProductGiftCard::query()->updateOrCreate(
                ['product_id' => $product->id],
                $this->configAttributes($config),
            );

            $this->syncAmounts($product, $amounts);

            return $row->fresh(['amounts']);
        });
    }

    public function presentConfig(Product $product): ?array
    {
        $config = ProductGiftCard::query()->where('product_id', $product->id)->first();
        if (! $config) {
            return null;
        }

        $amounts = ProductGiftCardAmount::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ProductGiftCardAmount $row) => [
                'id' => $row->id,
                'value' => (float) $row->value,
                'selling_price' => $row->selling_price !== null ? (float) $row->selling_price : null,
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
            ])
            ->values()
            ->all();

        return array_merge($this->presentRow($config), ['amounts' => $amounts]);
    }

    public function presentRow(ProductGiftCard $config): array
    {
        return [
            'amount_type' => $config->amount_type,
            'min_amount' => $config->min_amount !== null ? (float) $config->min_amount : null,
            'max_amount' => $config->max_amount !== null ? (float) $config->max_amount : null,
            'default_amount' => $config->default_amount !== null ? (float) $config->default_amount : null,
            'amount_step' => $config->amount_step !== null ? (float) $config->amount_step : null,
            'delivery_method' => $config->delivery_method,
            'recipient_name_enabled' => (bool) $config->recipient_name_enabled,
            'recipient_name_required' => (bool) $config->recipient_name_required,
            'recipient_email_enabled' => (bool) $config->recipient_email_enabled,
            'recipient_email_required' => (bool) $config->recipient_email_required,
            'sender_name_enabled' => (bool) $config->sender_name_enabled,
            'sender_name_required' => (bool) $config->sender_name_required,
            'gift_message_enabled' => (bool) $config->gift_message_enabled,
            'gift_message_required' => (bool) $config->gift_message_required,
            'validity_type' => $config->validity_type,
            'validity_days' => $config->validity_days,
            'expiry_date' => $config->expiry_date?->format('Y-m-d'),
            'allow_online_redemption' => (bool) $config->allow_online_redemption,
            'allow_offline_redemption' => (bool) $config->allow_offline_redemption,
            'usage_type' => $config->usage_type,
            'allow_partial_redemption' => (bool) $config->allow_partial_redemption,
            'code_length' => (int) $config->code_length,
            'code_prefix' => $config->code_prefix,
            'code_charset' => $config->code_charset,
            'code_uppercase' => (bool) $config->code_uppercase,
            'min_order_amount' => $config->min_order_amount !== null ? (float) $config->min_order_amount : null,
            'max_order_amount' => $config->max_order_amount !== null ? (float) $config->max_order_amount : null,
            'restrictions' => $this->presentRestrictions($config->restrictions),
        ];
    }

    /**
     * Always return the full restriction shape so the admin form binds cleanly.
     *
     * @param  mixed  $restrictions
     * @return array<string, list<int>>
     */
    protected function presentRestrictions(mixed $restrictions): array
    {
        $stored = is_array($restrictions) ? $restrictions : [];
        $out = [];
        foreach (['category_ids', 'product_ids', 'exclude_category_ids', 'exclude_product_ids'] as $key) {
            $out[$key] = array_values(array_map('intval', (array) ($stored[$key] ?? [])));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolvePurchaseData(Product $product, array $data): array
    {
        $config = ProductGiftCard::query()->where('product_id', $product->id)->first();
        if (! $config) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card product is not configured.']);
        }

        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : null;
        $amountId = isset($data['fixed_amount_id']) ? (int) $data['fixed_amount_id'] : null;

        if ($config->amount_type === 'fixed') {
            $fixed = $amountId
                ? ProductGiftCardAmount::query()->where('product_id', $product->id)->where('is_enabled', true)->find($amountId)
                : null;
            if (! $fixed) {
                throw ValidationException::withMessages(['gift_card.amount' => 'Select a valid gift card amount.']);
            }
            $value = (float) $fixed->value;
            $selling = $fixed->effectiveSellingPrice();
        } else {
            if ($amount === null || $amount <= 0) {
                throw ValidationException::withMessages(['gift_card.amount' => 'Enter a valid gift card amount.']);
            }
            $min = (float) ($config->min_amount ?? 0);
            $max = (float) ($config->max_amount ?? 0);
            if ($amount < $min || $amount > $max) {
                throw ValidationException::withMessages(['gift_card.amount' => "Amount must be between {$min} and {$max}."]);
            }
            $step = (float) ($config->amount_step ?? 1);
            if ($step > 0 && fmod($amount - $min, $step) > 0.001) {
                throw ValidationException::withMessages(['gift_card.amount' => "Amount must increase in steps of {$step}."]);
            }
            $value = $amount;
            $selling = $amount;
            $amountId = null;
        }

        $recipient = $this->resolveRecipientFields($config, $data);

        return [
            'fixed_amount_id' => $amountId,
            'amount' => $value,
            'selling_price' => $selling,
            'recipient_name' => $recipient['recipient_name'] ?? null,
            'recipient_email' => $recipient['recipient_email'] ?? null,
            'sender_name' => $recipient['sender_name'] ?? null,
            'gift_message' => $recipient['gift_message'] ?? null,
        ];
    }

    /**
     * Latest persisted line items for the order.
     *
     * Never rely on a possibly stale already-loaded `items` relation: an Order
     * instance that was populated before its line items existed would make
     * loadMissing() a no-op and silently skip issuance.
     */
    protected function freshItems(Order $order): \Illuminate\Support\Collection
    {
        $order->unsetRelation('items');
        $order->load('items.product');

        return $order->items;
    }

    public function issueForOrderItem(Order $order, OrderItem $item): GiftCard
    {
        $product = $item->product;
        if (! $product || $product->type !== 'gift_card') {
            throw new \InvalidArgumentException('Order item is not a gift card product.');
        }

        $existing = GiftCard::query()->where('order_item_id', $item->id)->count();
        if ($existing >= (int) $item->qty) {
            return GiftCard::query()->where('order_item_id', $item->id)->first();
        }

        $config = ProductGiftCard::query()->where('product_id', $product->id)->firstOrFail();
        $data = $item->gift_card_data ?? [];
        $balance = round((float) ($data['amount'] ?? $item->unit_price), 2);

        $card = DB::transaction(function () use ($order, $item, $product, $config, $data, $balance) {
            $card = GiftCard::query()->create([
                'product_id' => $product->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'customer_id' => $order->customer_id,
                'code' => $this->generateUniqueCode($config),
                'initial_balance' => $balance,
                'current_balance' => $balance,
                'selected_amount' => $balance,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'sender_name' => $data['sender_name'] ?? null,
                'gift_message' => $data['gift_message'] ?? null,
                'issued_at' => now(),
                'expires_at' => $this->calculateExpiry($config),
                'status' => 'active',
            ]);

            $this->recordTransaction($card, 'credit', $balance, 0, $balance, $order->id, [
                'source' => 'issue',
                'order_number' => $order->number,
            ]);

            return $card;
        });
        $this->notifyIssued($order, $card);

        return $card;
    }

    protected function notifyIssued(Order $order, GiftCard $card): void
    {
        $config = ProductGiftCard::query()->where('product_id', $card->product_id)->first();
        if (! $config) {
            $config = new ProductGiftCard(['delivery_method' => 'email']);
        }

        $this->deliver($config, $card, $order);
    }

    /**
     * Deliver an issued card according to the product's delivery_method.
     *
     * email   - send to the recipient (falling back to the purchaser).
     * account - attach to the purchaser account; the wallet is the delivery.
     * both    - attach to the account and send the email.
     *
     * Returns whether an email was actually sent. A missing address is logged
     * rather than throwing: the card already exists and must not be lost, and a
     * mail failure must never roll back a completed payment.
     */
    public function deliver(ProductGiftCard $config, GiftCard $card, ?Order $order): bool
    {
        $method = $config->delivery_method ?: 'email';

        if ($method === 'account') {
            if (! $card->customer_id) {
                Log::info('gift_card.account_delivery_unlinked', [
                    'gift_card_id' => $card->id,
                    'code' => $card->code,
                ]);
            }

            return false;
        }

        $to = trim((string) ($card->recipient_email ?: $order?->email ?: $card->customer?->email ?: ''));
        if ($to === '') {
            Log::warning('gift_card.issued_without_email', [
                'gift_card_id' => $card->id,
                'code' => $card->code,
                'order_id' => $order?->id,
                'delivery_method' => $method,
            ]);

            return false;
        }

        try {
            MailEvents::ensure();
            app(ShopMailer::class)->sendRecipient(
                MailEvents::GIFT_CARD_ISSUED,
                'customer',
                [
                    'recipient_name' => $card->recipient_name ?: 'there',
                    'sender_name' => $card->sender_name ?: ($order?->email ?: 'A friend'),
                    'gift_card_code' => $card->code,
                    'balance' => number_format((float) $card->current_balance, 2),
                    'gift_message' => $card->gift_message ?: '',
                    'customer_name' => $card->recipient_name ?: 'there',
                ],
                $to,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('gift_card.email_failed', [
                'gift_card_id' => $card->id,
                'code' => $card->code,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function issueForPaidOrder(Order $order): void
    {
        foreach ($this->freshItems($order) as $item) {
            if ($item->product?->type !== 'gift_card') {
                continue;
            }
            $issued = GiftCard::query()->where('order_item_id', $item->id)->count();
            $remaining = max(0, (int) $item->qty - $issued);
            for ($i = 0; $i < $remaining; $i++) {
                $this->issueForOrderItem($order, $item);
            }
        }
    }

    /**
     * @return array{code: string, balance: float, status: string}
     */
    public function preview(string $code): array
    {
        $card = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $card) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
        }
        $this->settleExpiry($card);
        $this->assertUsable($card);

        return [
            'code' => (string) $card->code,
            'balance' => (float) $card->current_balance,
            'status' => (string) $card->status,
        ];
    }

    /**
     * Offline (POS) redemption preview.
     *
     * Same checks as online preview except the online-only gate is swapped for
     * the card's offline permission.
     *
     * @return array{code: string, balance: float, status: string}
     */
    public function previewForOffline(string $code): array
    {
        $card = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $card) {
            throw ValidationException::withMessages(['gift_card_code' => 'Gift card not found.']);
        }

        $this->settleExpiry($card);

        if ($card->expires_at && now()->gt($card->expires_at)) {
            throw ValidationException::withMessages(['gift_card_code' => 'This gift card has expired.']);
        }
        if ($card->status !== self::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['gift_card_code' => 'This gift card cannot be redeemed.']);
        }
        if ((float) $card->current_balance <= 0) {
            throw ValidationException::withMessages(['gift_card_code' => 'This gift card has no remaining balance.']);
        }

        $config = $this->configFor($card);
        if (! $config->allow_offline_redemption) {
            throw ValidationException::withMessages(['gift_card_code' => 'This gift card cannot be redeemed in store.']);
        }

        return [
            'code' => (string) $card->code,
            'balance' => (float) $card->current_balance,
            'status' => (string) $card->status,
        ];
    }

    /**
     * Public cart-time gate used by CartService so restrictions surface before
     * the customer reaches payment.
     */
    public function assertUsableForCart(string $code, float $orderTotal, float $credit, array $lines = [], string $channel = 'online'): void
    {
        $card = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $card) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
        }

        $this->assertUsable($card, $channel);

        $config = $this->configFor($card);
        $this->assertOrderAmount($config, $orderTotal);
        $this->assertRestrictions($config, $this->loadRestrictionCategories($lines));

        if (! $config->allow_partial_redemption && $credit < $orderTotal) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card must cover the full order amount.']);
        }
    }

    /**
     * Re-validate a cart-attached gift card immediately before payment capture.
     *
     * $preCreditOrderTotal must be the order amount BEFORE gift-card credit
     * (subtotal − discount + tax + shipping + fees). Using the post-credit total
     * would incorrectly fail min_order_amount checks.
     *
     * Returns the credit that may safely be applied now (never more than the
     * balance that was quoted), so a card drained in another session cannot be
     * redeemed after the customer has already been charged for it.
     *
     * @param  list<array{product_id: int, category_ids: list<int>}>  $lines
     * @return array{code: string, credit: float}
     */
    public function revalidateForTotals(string $code, float $quotedCredit, float $preCreditOrderTotal, array $lines = []): array
    {
        $card = GiftCard::query()->where('code', $this->normalizeCode($code))->lockForUpdate()->first();
        if (! $card) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
        }

        $this->assertUsable($card);
        $config = $this->configFor($card);
        $this->assertOrderAmount($config, $preCreditOrderTotal);
        $this->assertRestrictions($config, $this->loadRestrictionCategories($lines));

        $available = (float) $card->current_balance;
        $credit = round(min($quotedCredit, $available, max(0, $preCreditOrderTotal)), 2);
        if ($credit <= 0) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card has no remaining balance.']);
        }
        if (! $config->allow_partial_redemption && $credit < $preCreditOrderTotal) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card must cover the full order amount.']);
        }

        return ['code' => (string) $card->code, 'credit' => $credit];
    }

    /**
     * Cards visible in a customer's wallet.
     *
     * Ownership is matched on the linked customer or the recipient email, so a
     * customer can never see another customer's cards.
     *
     * @return list<array<string, mixed>>
     */
    public function walletFor(\App\Domain\Customer\Models\Customer $customer): array
    {
        $email = trim((string) $customer->email);

        return GiftCard::query()
            ->where(function ($q) use ($customer, $email) {
                $q->where('customer_id', $customer->id);
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(recipient_email) = ?', [mb_strtolower($email)]);
                }
            })
            ->orderByDesc('id')
            ->get()
            ->map(fn (GiftCard $card) => [
                'code' => $card->code,
                'balance' => (float) $card->current_balance,
                'initial_balance' => (float) $card->initial_balance,
                'status' => $card->status,
                'is_expired' => $card->isExpired(),
                'expires_at' => $card->expires_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    public function redeem(string $code, float $amount, ?Order $order = null, string $channel = 'online'): GiftCard
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['gift_card' => 'Redemption amount must be greater than zero.']);
        }

        $existing = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $existing) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
        }

        // Expiry is settled before the transaction so the 'expired' status is not
        // rolled back when the redemption below aborts.
        $this->settleExpiry($existing);

        return DB::transaction(function () use ($code, $amount, $order, $channel) {
            $card = GiftCard::query()->where('code', $this->normalizeCode($code))->lockForUpdate()->first();
            if (! $card) {
                throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
            }

            $this->assertRedeemable($card, $amount, $order, null, $channel);

            $before = (float) $card->current_balance;
            $after = round($before - $amount, 2);
            $attributes = ['current_balance' => $after];

            // A single-use card is consumed by its first redemption, even when
            // the redemption is partial and leaves a residual balance.
            $config = $this->configFor($card);
            $singleUse = $config->usage_type === 'single';
            if ($singleUse || $after <= 0) {
                $attributes['status'] = self::STATUS_USED;
            }

            $card->update($attributes);

            $this->recordTransaction($card, self::TYPE_REDEMPTION, $amount, $before, $after, $order?->id, [
                'order_number' => $order?->number,
                'channel' => $channel,
                ...($singleUse ? ['usage_type' => 'single', 'residual_balance' => $after] : []),
            ]);

            return $card->fresh();
        });
    }

    /**
     * Reserve gift-card balance for a deferred PSP capture.
     *
     * Debits the balance immediately under lock so concurrent checkouts cannot
     * spend the same funds, but records TYPE_HOLD (not redemption). On payment
     * success call commitHold(); on fail/cancel/timeout call releaseHold().
     *
     * Each open HOLD stores metadata.expires_at aligned with
     * OrderSettings::unpaidTimeoutMinutes() so abandoned deferred payments can
     * be recovered by releaseExpiredHolds() without requiring auto_cancel_unpaid.
     *
     * Idempotent per order: a second hold for the same order returns the open hold.
     */
    public function hold(string $code, float $amount, Order $order): GiftCard
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['gift_card' => 'Hold amount must be greater than zero.']);
        }

        $existing = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $existing) {
            throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
        }
        $this->settleExpiry($existing);

        return DB::transaction(function () use ($code, $amount, $order) {
            $open = $this->openHoldForOrder((int) $order->id);
            if ($open) {
                return GiftCard::query()->lockForUpdate()->findOrFail($open->gift_card_id);
            }

            // Already committed for this order — treat as success (webhook/retry).
            if ($this->committedHoldForOrder((int) $order->id)) {
                $committed = $this->committedHoldForOrder((int) $order->id);

                return GiftCard::query()->findOrFail($committed->gift_card_id);
            }

            $card = GiftCard::query()->where('code', $this->normalizeCode($code))->lockForUpdate()->first();
            if (! $card) {
                throw ValidationException::withMessages(['gift_card' => 'Gift card not found.']);
            }

            $preCredit = $this->preCreditTotalForOrder($order, $amount);
            $this->assertRedeemable($card, $amount, $order, $preCredit);

            $before = (float) $card->current_balance;
            $after = round($before - $amount, 2);
            $card->update(['current_balance' => $after]);

            $expiresAt = now()->addMinutes($this->holdTimeoutMinutes());

            $this->recordTransaction($card, self::TYPE_HOLD, $amount, $before, $after, (int) $order->id, [
                'state' => self::HOLD_STATE_OPEN,
                'order_number' => $order->number,
                'expires_at' => $expiresAt->toIso8601String(),
                'timeout_minutes' => $this->holdTimeoutMinutes(),
            ]);

            Log::info('gift_card.hold.created', [
                'gift_card_id' => $card->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $card->fresh();
        });
    }

    /**
     * Finalize an open hold after the deferred PSP capture succeeds.
     * Converts the hold ledger row into a redemption and applies single-use USED.
     * Safe under duplicate webhooks / retries.
     *
     * If a timeout job already released the hold before payment succeeded, this
     * re-debits the card and records a redemption so paid + released cannot stick.
     */
    public function commitHold(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $lockedOrder) {
                return;
            }

            $hold = $this->openHoldForOrder((int) $lockedOrder->id, forUpdate: true);
            if ($hold) {
                $this->commitOpenHoldRow($hold, $lockedOrder);

                return;
            }

            if ($this->committedHoldForOrder((int) $lockedOrder->id)) {
                Log::info('gift_card.hold.commit_noop_already_committed', [
                    'order_id' => $lockedOrder->id,
                ]);

                return;
            }

            if ($lockedOrder->payment_status === 'paid') {
                $this->recoverDebitAfterTimeoutRelease($lockedOrder);

                return;
            }

            Log::info('gift_card.hold.commit_noop_no_open', [
                'order_id' => $lockedOrder->id,
                'payment_status' => $lockedOrder->payment_status,
            ]);
        });
    }

    /**
     * Release an open hold after payment failure, cancel, or timeout.
     * Restores balance and writes TYPE_RELEASE. Idempotent.
     */
    public function releaseHold(Order $order, string $reason = self::RELEASE_REASON_PAYMENT_FAILED): void
    {
        DB::transaction(function () use ($order, $reason) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $lockedOrder) {
                return;
            }

            if (in_array((string) $lockedOrder->payment_status, ['paid', 'refunded'], true)
                && $reason !== self::RELEASE_REASON_TIMEOUT) {
                // Never release against a paid order from fail/cancel paths.
                Log::info('gift_card.hold.release_blocked_paid', [
                    'order_id' => $lockedOrder->id,
                    'reason' => $reason,
                ]);

                return;
            }

            $hold = $this->openHoldForOrder((int) $lockedOrder->id, forUpdate: true);
            if (! $hold) {
                if ($this->committedHoldForOrder((int) $lockedOrder->id)) {
                    Log::info('gift_card.hold.release_noop_already_committed', [
                        'order_id' => $lockedOrder->id,
                        'reason' => $reason,
                    ]);
                } else {
                    Log::info('gift_card.hold.release_noop_no_open', [
                        'order_id' => $lockedOrder->id,
                        'reason' => $reason,
                    ]);
                }

                return;
            }

            // Timeout path must re-check payment under the same locks.
            if ($reason === self::RELEASE_REASON_TIMEOUT
                && in_array((string) $lockedOrder->payment_status, ['paid', 'refunded'], true)) {
                $this->commitOpenHoldRow($hold, $lockedOrder);

                return;
            }

            $this->releaseOpenHoldRow($hold, $lockedOrder, $reason);
        });
    }

    /**
     * Release holds when an unpaid/failed order is cancelled.
     * Paid and refunded orders are handled by handleOrderRefunded instead.
     */
    public function handleOrderCancelled(Order $order): void
    {
        if (in_array((string) $order->payment_status, ['paid', 'refunded'], true)) {
            return;
        }

        $this->releaseHold($order, self::RELEASE_REASON_CANCELLED);
    }

    /**
     * Sweep OPEN holds past their expires_at (or created_at + hold timeout).
     *
     * Payment state is authoritative: a paid order commits rather than releases.
     * Independent of orders.auto_cancel_unpaid.
     *
     * @return array{released: int, committed: int, skipped: int}
     */
    public function releaseExpiredHolds(): array
    {
        $stats = ['released' => 0, 'committed' => 0, 'skipped' => 0];

        GiftCardTransaction::query()
            ->where('type', self::TYPE_HOLD)
            ->where('metadata->state', self::HOLD_STATE_OPEN)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$stats) {
                foreach ($rows as $hold) {
                    $result = $this->reconcileExpiredHold((int) $hold->id);
                    $stats[$result] = ($stats[$result] ?? 0) + 1;
                }
            });

        return $stats;
    }

    /**
     * Process a single OPEN hold candidate. Idempotent under row locks.
     *
     * @return 'released'|'committed'|'skipped'
     */
    public function reconcileExpiredHold(int $holdTransactionId): string
    {
        return DB::transaction(function () use ($holdTransactionId) {
            $hold = GiftCardTransaction::query()->whereKey($holdTransactionId)->lockForUpdate()->first();
            if (! $hold || $hold->type !== self::TYPE_HOLD) {
                return 'skipped';
            }

            $meta = is_array($hold->metadata) ? $hold->metadata : [];
            if (($meta['state'] ?? '') !== self::HOLD_STATE_OPEN) {
                return 'skipped';
            }

            $order = $hold->order_id
                ? Order::query()->whereKey($hold->order_id)->lockForUpdate()->first()
                : null;

            if ($order && in_array((string) $order->payment_status, ['paid', 'refunded'], true)) {
                $this->commitOpenHoldRow($hold, $order);
                Log::info('gift_card.hold.committed', [
                    'gift_card_id' => $hold->gift_card_id,
                    'order_id' => $order->id,
                    'reason' => 'paid_during_timeout_sweep',
                ]);

                return 'committed';
            }

            if (! $this->holdHasExpired($hold)) {
                return 'skipped';
            }

            $this->releaseOpenHoldRow($hold, $order, self::RELEASE_REASON_TIMEOUT);

            return 'released';
        });
    }

    /**
     * Minutes a deferred HOLD may remain OPEN before timeout release.
     * Mirrors unpaid order timeout so GC holds follow the same payment lifecycle window,
     * regardless of whether auto_cancel_unpaid is enabled.
     */
    public function holdTimeoutMinutes(): int
    {
        return OrderSettings::unpaidTimeoutMinutes();
    }

    protected function holdHasExpired(GiftCardTransaction $hold): bool
    {
        $meta = is_array($hold->metadata) ? $hold->metadata : [];
        if (! empty($meta['expires_at'])) {
            try {
                return Carbon::parse((string) $meta['expires_at'])->lte(now());
            } catch (\Throwable) {
                // Fall through to created_at window.
            }
        }

        $minutes = (int) ($meta['timeout_minutes'] ?? $this->holdTimeoutMinutes());

        return $hold->created_at !== null
            && $hold->created_at->copy()->addMinutes(max(1, $minutes))->lte(now());
    }

    protected function commitOpenHoldRow(GiftCardTransaction $hold, Order $order): void
    {
        $card = GiftCard::query()->lockForUpdate()->findOrFail($hold->gift_card_id);
        $meta = is_array($hold->metadata) ? $hold->metadata : [];
        $meta['state'] = self::HOLD_STATE_COMMITTED;
        $meta['committed_at'] = now()->toIso8601String();

        $hold->update([
            'type' => self::TYPE_REDEMPTION,
            'metadata' => $meta,
        ]);

        $config = $this->configFor($card);
        $after = (float) $card->current_balance;
        if ($config->usage_type === 'single' || $after <= 0) {
            $card->update(['status' => self::STATUS_USED]);
        }

        Log::info('gift_card.hold.committed', [
            'gift_card_id' => $card->id,
            'order_id' => $order->id,
            'hold_transaction_id' => $hold->id,
        ]);
    }

    protected function releaseOpenHoldRow(
        GiftCardTransaction $hold,
        ?Order $order,
        string $reason,
    ): void {
        $card = GiftCard::query()->lockForUpdate()->findOrFail($hold->gift_card_id);
        $amount = round((float) $hold->amount, 2);
        $before = (float) $card->current_balance;
        $after = round($before + $amount, 2);

        $card->update([
            'current_balance' => $after,
            'status' => in_array($card->status, [self::STATUS_DISABLED, self::STATUS_CANCELLED, self::STATUS_EXPIRED], true)
                ? $card->status
                : self::STATUS_ACTIVE,
        ]);

        $meta = is_array($hold->metadata) ? $hold->metadata : [];
        $meta['state'] = self::HOLD_STATE_RELEASED;
        $meta['released_at'] = now()->toIso8601String();
        $meta['release_reason'] = $reason;
        $hold->update(['metadata' => $meta]);

        $this->recordTransaction($card, self::TYPE_RELEASE, $amount, $before, $after, $order?->id ?? (int) $hold->order_id, [
            'state' => self::HOLD_STATE_RELEASED,
            'release_reason' => $reason,
            'order_number' => $order?->number,
            'hold_transaction_id' => $hold->id,
        ]);

        Log::info('gift_card.hold.released', [
            'gift_card_id' => $card->id,
            'order_id' => $order?->id ?? $hold->order_id,
            'hold_transaction_id' => $hold->id,
            'reason' => $reason,
            'amount' => $amount,
        ]);
    }

    /**
     * When a timeout release raced ahead of a successful payment webhook, re-debit
     * the card and write a redemption so the ledger matches a paid order.
     */
    protected function recoverDebitAfterTimeoutRelease(Order $order): void
    {
        $releasedHold = GiftCardTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', self::TYPE_HOLD)
            ->where('metadata->state', self::HOLD_STATE_RELEASED)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $releaseTxn = GiftCardTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', self::TYPE_RELEASE)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $releasedHold && ! $releaseTxn) {
            Log::warning('gift_card.hold.recover_missing_prior_release', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $giftCardId = (int) ($releaseTxn?->gift_card_id ?? $releasedHold?->gift_card_id);
        $amount = round((float) ($releaseTxn?->amount ?? $releasedHold?->amount ?? 0), 2);
        if ($giftCardId <= 0 || $amount <= 0) {
            return;
        }

        $card = GiftCard::query()->lockForUpdate()->findOrFail($giftCardId);
        $available = (float) $card->current_balance;
        $debit = round(min($available, $amount), 2);
        if ($debit <= 0) {
            Log::error('gift_card.hold.recover_insufficient_balance', [
                'gift_card_id' => $card->id,
                'order_id' => $order->id,
                'needed' => $amount,
                'available' => $available,
            ]);

            return;
        }

        $before = $available;
        $after = round($before - $debit, 2);
        $attributes = ['current_balance' => $after];
        $config = $this->configFor($card);
        if ($config->usage_type === 'single' || $after <= 0) {
            $attributes['status'] = self::STATUS_USED;
        }
        $card->update($attributes);

        $this->recordTransaction($card, self::TYPE_REDEMPTION, $debit, $before, $after, (int) $order->id, [
            'state' => self::HOLD_STATE_COMMITTED,
            'recovered_after_timeout_release' => true,
            'requested_amount' => $amount,
            'order_number' => $order->number,
        ]);

        Log::info('gift_card.hold.committed', [
            'gift_card_id' => $card->id,
            'order_id' => $order->id,
            'reason' => 'recovered_after_timeout_release',
            'amount' => $debit,
        ]);

        if ($debit + 0.009 < $amount) {
            Log::error('gift_card.hold.recover_partial_debit', [
                'gift_card_id' => $card->id,
                'order_id' => $order->id,
                'needed' => $amount,
                'debited' => $debit,
            ]);
        }
    }

    protected function openHoldForOrder(int $orderId, bool $forUpdate = false): ?GiftCardTransaction
    {
        $query = GiftCardTransaction::query()
            ->where('order_id', $orderId)
            ->where('type', self::TYPE_HOLD)
            ->where('metadata->state', self::HOLD_STATE_OPEN)
            ->orderBy('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    protected function committedHoldForOrder(int $orderId): ?GiftCardTransaction
    {
        return GiftCardTransaction::query()
            ->where('order_id', $orderId)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('type', self::TYPE_HOLD)
                        ->where('metadata->state', self::HOLD_STATE_COMMITTED);
                })->orWhere(function ($inner) {
                    $inner->where('type', self::TYPE_REDEMPTION);
                });
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Order grand_total is stored after gift-card credit. Rebuild the pre-credit
     * window for min/max checks.
     */
    public function preCreditTotalForOrder(Order $order, ?float $creditFallback = null): float
    {
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $credit = (float) ($details['gift_card_credit'] ?? $creditFallback ?? 0);

        return round((float) $order->grand_total + max(0, $credit), 2);
    }

    public function assertApplyAllowed(?string $clientKey): void
    {
        $key = $this->applyLockKey($clientKey);
        if ($key === null) {
            return;
        }
        if (cache()->has($key.':lock')) {
            throw ValidationException::withMessages([
                'gift_card' => 'Too many invalid gift card attempts. Please try again later.',
            ]);
        }
    }

    public function recordFailedApply(?string $clientKey): void
    {
        $key = $this->applyLockKey($clientKey);
        if ($key === null) {
            return;
        }
        $fails = (int) cache()->get($key.':fails', 0) + 1;
        cache()->put($key.':fails', $fails, self::APPLY_FAIL_WINDOW_SECONDS);
        if ($fails >= self::APPLY_FAIL_LIMIT) {
            cache()->put($key.':lock', 1, self::APPLY_LOCKOUT_SECONDS);
            cache()->forget($key.':fails');
        }
    }

    public function clearFailedApply(?string $clientKey): void
    {
        $key = $this->applyLockKey($clientKey);
        if ($key === null) {
            return;
        }
        cache()->forget($key.':fails');
        cache()->forget($key.':lock');
    }

    protected function applyLockKey(?string $clientKey): ?string
    {
        $clientKey = trim((string) $clientKey);
        if ($clientKey === '') {
            return null;
        }

        return 'gift_card_apply:'.sha1($clientKey);
    }

    /**
     * Refund handling.
     *
     * Only cards whose full balance is still intact are cancelled - a card that
     * was already redeemed must never have balance restored out of thin air.
     * Every state change is written to the ledger so the audit trail is complete.
     */
    public function handleOrderRefunded(Order $order): void
    {
        $cards = GiftCard::query()->where('order_id', $order->id)->get();
        foreach ($cards as $card) {
            if (in_array($card->status, [self::STATUS_CANCELLED, self::STATUS_USED], true)) {
                continue;
            }

            $untouched = (float) $card->current_balance >= (float) $card->initial_balance;
            $status = $untouched ? self::STATUS_CANCELLED : self::STATUS_DISABLED;
            if ($card->status === $status) {
                continue;
            }

            $balance = (float) $card->current_balance;
            $card->update(['status' => $status]);

            // Partial redemptions cannot be reversed automatically, so no balance
            // is restored; the ledger records the state change only.
            $this->recordTransaction($card, self::TYPE_CANCEL, 0, $balance, $balance, $order->id, [
                'source' => 'order_refund',
                'order_number' => $order->number,
                'status' => $status,
                'restored' => false,
            ]);
        }
    }

    /**
     * Admin-driven balance/status adjustment with a mandatory ledger entry.
     */
    public function adjust(GiftCard $card, float $amount, string $status, ?string $note = null, ?int $adminId = null): GiftCard
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($card, $amount, $status, $note, $adminId) {
            $locked = GiftCard::query()->lockForUpdate()->findOrFail($card->id);
            $before = (float) $locked->current_balance;
            $after = round(max(0, $before + $amount), 2);

            $locked->update(['current_balance' => $after, 'status' => $status]);

            if ($amount !== 0.0) {
                $this->recordTransaction($locked, self::TYPE_ADJUSTMENT, abs($amount), $before, $after, null, [
                    'source' => 'admin',
                    'admin_id' => $adminId,
                    'note' => $note,
                    'direction' => $amount >= 0 ? 'credit' : 'debit',
                ]);
            } elseif ($status !== self::STATUS_ACTIVE) {
                $this->recordTransaction($locked, self::TYPE_ADJUSTMENT, 0, $before, $after, null, [
                    'source' => 'admin',
                    'admin_id' => $adminId,
                    'note' => $note,
                    'status' => $status,
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Manually issue a gift card from the admin area.
     *
     * @param  array<string, mixed>  $data
     */
    public function issueManually(ProductGiftCard $config, array $data, ?int $adminId = null): GiftCard
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Gift card amount must be greater than zero.']);
        }

        $status = (string) ($data['status'] ?? self::STATUS_ACTIVE);
        if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid gift card status.']);
        }

        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt === '' || $expiresAt === null) {
            $expiresAt = $this->calculateExpiry($config);
        } else {
            $expiresAt = \Illuminate\Support\Carbon::parse($expiresAt)->endOfDay();
        }

        $card = DB::transaction(function () use ($config, $data, $amount, $expiresAt, $status, $adminId) {
            $card = GiftCard::query()->create([
                'product_id' => $config->product_id,
                'order_id' => ! empty($data['order_id']) ? (int) $data['order_id'] : null,
                'order_item_id' => null,
                'customer_id' => ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
                'code' => $this->generateUniqueCode($config),
                'initial_balance' => $amount,
                'current_balance' => $amount,
                'selected_amount' => $amount,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_email' => $data['recipient_email'] ?? null,
                'sender_name' => $data['sender_name'] ?? null,
                'gift_message' => $data['gift_message'] ?? null,
                'issued_at' => now(),
                'expires_at' => $expiresAt,
                'status' => $status,
            ]);

            $this->recordTransaction($card, self::TYPE_CREDIT, $amount, 0, $amount, null, [
                'source' => 'admin_manual',
                'admin_id' => $adminId,
                'reason' => $data['reason'] ?? null,
                'reference' => $data['reference'] ?? null,
            ]);

            return $card;
        });

        $this->deliver($config, $card, null);

        return $card;
    }

    /**
     * Resend the issuance email for an existing card (admin/customer support).
     */
    public function resendEmail(GiftCard $card): bool
    {
        $config = $this->configFor($card);
        $order = $card->order;

        return $this->deliver($config, $card, $order);
    }

    /**
     * Mark active cards past their expiry as expired.
     *
     * Expiry is also enforced lazily on redemption, so a missing scheduler run
     * never allows an expired card to be used.
     */
    public function expireDueCards(): int
    {
        return GiftCard::query()
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Admin listing with search and filters. Kept here so the controller stays thin.
     *
     * @param  array<string, mixed>  $filters
     */
    public function adminQuery(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = GiftCard::query()->with(['customer', 'order', 'product.translations']);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('code', 'like', '%'.$search.'%')
                    ->orWhere('recipient_email', 'like', '%'.$search.'%')
                    ->orWhere('recipient_name', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('number', 'like', '%'.$search.'%');
                    });
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            if ($status === 'expired') {
                $query->where(function ($inner) {
                    $inner->where('status', self::STATUS_EXPIRED)
                        ->orWhere(fn ($e) => $e->where('status', self::STATUS_ACTIVE)->where('expires_at', '<', now()));
                });
            } else {
                $query->where('status', $status);
            }
        }

        if (! empty($filters['balance'])) {
            if ($filters['balance'] === 'remaining') {
                $query->where('current_balance', '>', 0);
            } elseif ($filters['balance'] === 'empty') {
                $query->where('current_balance', '<=', 0);
            }
        }

        $sort = (string) ($filters['sort'] ?? 'id');
        $allowed = ['id', 'code', 'status', 'initial_balance', 'current_balance', 'expires_at', 'created_at'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'id';
        }
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderBy('id', 'desc');
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAdminRow(GiftCard $card): array
    {
        return [
            'id' => $card->id,
            'code' => $card->code,
            'status' => $card->status,
            'is_expired' => $card->expires_at !== null && now()->gt($card->expires_at),
            'initial_balance' => (float) $card->initial_balance,
            'current_balance' => (float) $card->current_balance,
            'used_amount' => round((float) $card->initial_balance - (float) $card->current_balance, 2),
            'customer' => $card->customer ? [
                'id' => $card->customer->id,
                'name' => $card->customer->name,
                'email' => $card->customer->email,
            ] : null,
            'recipient_name' => $card->recipient_name,
            'recipient_email' => $card->recipient_email,
            'order' => $card->order ? [
                'id' => $card->order->id,
                'number' => $card->order->number,
            ] : null,
            'product' => $card->product ? [
                'id' => $card->product->id,
                'name' => $card->product->translation()?->name ?? $card->product->sku,
            ] : null,
            'issued_at' => $card->issued_at?->toIso8601String(),
            'expires_at' => $card->expires_at?->toDateString(),
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAdminDetail(GiftCard $card): array
    {
        $card->loadMissing(['transactions.order']);

        return array_merge($this->presentAdminRow($card), [
            'sender_name' => $card->sender_name,
            'gift_message' => $card->gift_message,
            'selected_amount' => (float) $card->selected_amount,
            'transactions' => $card->transactions
                ->sortByDesc('id')
                ->values()
                ->map(fn (GiftCardTransaction $row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'amount' => (float) $row->amount,
                    'balance_before' => (float) $row->balance_before,
                    'balance_after' => (float) $row->balance_after,
                    'order' => $row->order?->number,
                    'metadata' => $row->metadata,
                    'created_at' => $row->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function findByCode(string $code): ?GiftCard
    {
        return GiftCard::query()->where('code', $this->normalizeCode($code))->first();
    }

    /**
     * Resolve the config that governs a specific gift card product, used by the
     * admin manual-issue form.
     */
    public function configForProduct(Product $product): ?ProductGiftCard
    {
        return ProductGiftCard::query()->where('product_id', $product->id)->first();
    }

    protected function recordTransaction(
        GiftCard $card,
        string $type,
        float $amount,
        float $before,
        float $after,
        ?int $orderId = null,
        array $metadata = [],
    ): GiftCardTransaction {
        return $card->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'order_id' => $orderId,
            'metadata' => $metadata,
        ]);
    }

    protected function configFor(GiftCard $card): ProductGiftCard
    {
        return ProductGiftCard::query()->where('product_id', $card->product_id)->first()
            ?? new ProductGiftCard([
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_offline_redemption' => false,
                'usage_type' => 'multiple',
                'allow_partial_redemption' => true,
            ]);
    }

    /**
     * Expiry/status/balance/online gate shared by preview and redemption.
     */
    /**
     * Persist the 'expired' status for a card whose expiry has passed.
     *
     * Runs outside any caller transaction so the status survives a rollback
     * triggered by the validation failure that follows it.
     */
    protected function settleExpiry(GiftCard $card): void
    {
        if (! $card->expires_at || ! now()->gt($card->expires_at)) {
            return;
        }
        if ($card->status !== self::STATUS_ACTIVE) {
            return;
        }

        DB::table('gift_cards')
            ->where('id', $card->getKey())
            ->where('status', self::STATUS_ACTIVE)
            ->update(['status' => self::STATUS_EXPIRED, 'updated_at' => now()]);

        $card->status = self::STATUS_EXPIRED;
    }

    protected function assertUsable(GiftCard $card, string $channel = 'online'): void
    {
        if ($card->expires_at && now()->gt($card->expires_at)) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card has expired.']);
        }

        if ($card->status !== self::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card cannot be redeemed.']);
        }

        if ((float) $card->current_balance <= 0) {
            throw ValidationException::withMessages(['gift_card' => 'This gift card has no remaining balance.']);
        }

        $config = $this->configFor($card);
        if ($channel === 'offline') {
            if (! $config->allow_offline_redemption) {
                throw ValidationException::withMessages(['gift_card' => 'This gift card cannot be redeemed in store.']);
            }
        } elseif (! $config->allow_online_redemption) {
            throw ValidationException::withMessages(['gift_card' => 'Online redemption is not allowed for this gift card.']);
        }
    }

    /**
     * Redemption rules that depend on the amount being spent, including the
     * merchant-configured order total window.
     */
    protected function assertRedeemable(
        GiftCard $card,
        float $amount,
        ?Order $order = null,
        ?float $preCreditOrderTotal = null,
        string $channel = 'online',
    ): void {
        $this->assertUsable($card, $channel);

        if ($amount > (float) $card->current_balance) {
            throw ValidationException::withMessages(['gift_card' => 'Redemption amount exceeds the gift card balance.']);
        }

        $config = $this->configFor($card);
        if ($order) {
            $this->assertOrderAmount(
                $config,
                $preCreditOrderTotal ?? $this->preCreditTotalForOrder($order, $amount),
            );
        }
        if (! $config->allow_partial_redemption && $amount < (float) $card->current_balance) {
            throw ValidationException::withMessages(['gift_card' => 'Partial redemption is not allowed for this gift card.']);
        }
    }

    /**
     * Enforce the configured min/max order window for redemption.
     */
    protected function assertOrderAmount(ProductGiftCard $config, float $orderTotal): void
    {
        $min = $config->min_order_amount !== null ? (float) $config->min_order_amount : null;
        $max = $config->max_order_amount !== null ? (float) $config->max_order_amount : null;

        if ($min !== null && $orderTotal < $min) {
            throw ValidationException::withMessages([
                'gift_card' => 'This gift card can only be used on orders of at least '.number_format($min, 2).'.',
            ]);
        }
        if ($max !== null && $orderTotal > $max) {
            throw ValidationException::withMessages([
                'gift_card' => 'This gift card can only be used on orders up to '.number_format($max, 2).'.',
            ]);
        }
    }

    /**
     * Enforce product/category restrictions.
     *
     * Shape (all keys optional, ids are positive integers):
     *   category_ids          - cart may only contain products from these categories
     *   product_ids           - cart may only contain these products
     *   exclude_category_ids  - cart must not contain products from these categories
     *   exclude_product_ids   - cart must not contain these products
     *
     * An "include" list is permissive only when non-empty: every line must match
     * at least one allowed entry, otherwise the card is rejected.
     *
     * @param  list<array{product_id: int, category_ids: list<int>}>  $lines
     */
    protected function assertRestrictions(ProductGiftCard $config, array $lines): void
    {
        $restrictions = is_array($config->restrictions) ? $config->restrictions : [];
        if ($restrictions === []) {
            return;
        }

        $ids = fn (string $key) => array_map('intval', (array) ($restrictions[$key] ?? []));
        $allowCategories = $ids('category_ids');
        $allowProducts = $ids('product_ids');
        $denyCategories = $ids('exclude_category_ids');
        $denyProducts = $ids('exclude_product_ids');

        if ($allowCategories === [] && $allowProducts === [] && $denyCategories === [] && $denyProducts === []) {
            return;
        }

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $categoryIds = array_map('intval', $line['category_ids'] ?? []);

            if ($productId > 0 && in_array($productId, $denyProducts, true)) {
                throw ValidationException::withMessages([
                    'gift_card' => 'This gift card cannot be used one of the products in your cart.',
                ]);
            }
            if ($categoryIds !== [] && array_intersect($categoryIds, $denyCategories) !== []) {
                throw ValidationException::withMessages([
                    'gift_card' => 'This gift card cannot be used on one of the product categories in your cart.',
                ]);
            }

            $hasIncludes = $allowCategories !== [] || $allowProducts !== [];
            if (! $hasIncludes) {
                continue;
            }

            $productOk = $allowProducts !== [] && in_array($productId, $allowProducts, true);
            $categoryOk = $allowCategories !== [] && array_intersect($categoryIds, $allowCategories) !== [];
            if (! $productOk && ! $categoryOk) {
                throw ValidationException::withMessages([
                    'gift_card' => 'This gift card can only be used on eligible products.',
                ]);
            }
        }
    }

    /**
     * Restriction check for a card code without re-running the other gates.
     * Used by channels that have already validated the card (e.g. POS).
     *
     * @param  list<array{product_id: int, category_ids: list<int>}>  $lines
     */
    public function assertRestrictionsForCode(string $code, array $lines): void
    {
        $card = GiftCard::query()->where('code', $this->normalizeCode($code))->first();
        if (! $card) {
            throw ValidationException::withMessages(['gift_card_code' => 'Gift card not found.']);
        }

        $this->assertRestrictions($this->configFor($card), $this->loadRestrictionCategories($lines));
    }

    /**
     * Build the restriction context for the current cart lines so every gateway
     * path (cart, checkout, POS) enforces identical rules.
     *
     * @param  iterable<mixed>  $items  Cart items or order items carrying product_id
     * @return list<array{product_id: int, category_ids: list<int>}>
     */
    public function restrictionLines(iterable $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $product = method_exists($item, 'relationLoaded') && $item->relationLoaded('product')
                ? $item->product
                : null;
            $categoryIds = $product?->categories?->pluck('id')->all() ?? [];

            $lines[] = [
                'product_id' => $productId,
                'category_ids' => array_map('intval', $categoryIds),
            ];
        }

        return $lines;
    }

    /**
     * Hydrate category ids for restriction lines, loading any product/categories
     * that the caller has not eager-loaded yet.
     *
     * @param  list<array{product_id: int, category_ids: list<int>}>  $lines
     * @return list<array{product_id: int, category_ids: list<int>}>
     */
    public function loadRestrictionCategories(array $lines): array
    {
        $missing = [];
        foreach ($lines as $index => $line) {
            if ($line['category_ids'] === [] && $line['product_id'] > 0) {
                $missing[] = $index;
            }
        }
        if ($missing === []) {
            return $lines;
        }

        $productIds = array_unique(array_map(fn ($i) => $lines[$i]['product_id'], $missing));
        $map = Product::query()
            ->with('categories:id')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($missing as $index) {
            $product = $map->get($lines[$index]['product_id']);
            $lines[$index]['category_ids'] = $product
                ? array_map('intval', $product->categories->pluck('id')->all())
                : [];
        }

        return $lines;
    }

    protected function generateUniqueCode(ProductGiftCard $config): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->buildCode($config);
            if (! GiftCard::withTrashed()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique gift card code.');
    }

    protected function buildCode(ProductGiftCard $config): string
    {
        $length = max(8, min(32, (int) $config->code_length));
        $prefix = trim((string) ($config->code_prefix ?? ''));
        $bodyLength = max(6, $length - strlen($prefix));

        $pool = match ($config->code_charset) {
            'numeric' => '0123456789',
            'alpha' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            default => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        };

        // Cryptographically secure generation - these codes are bearer instruments.
        $segments = [];
        $remaining = $bodyLength;
        $max = strlen($pool) - 1;
        while ($remaining > 0) {
            $chunk = min(4, $remaining);
            $segment = '';
            for ($i = 0; $i < $chunk; $i++) {
                $segment .= $pool[random_int(0, $max)];
            }
            $segments[] = $segment;
            $remaining -= $chunk;
        }

        $code = $prefix.implode('-', $segments);

        return $config->code_uppercase ? Str::upper($code) : $code;
    }

    protected function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    protected function calculateExpiry(ProductGiftCard $config): ?\Illuminate\Support\Carbon
    {
        return match ($config->validity_type) {
            'days' => now()->addDays(max(1, (int) $config->validity_days)),
            'fixed_date' => $config->expiry_date?->endOfDay(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array<string, mixed>>  $amounts
     */
    protected function validateConfig(array $config, array $amounts): void
    {
        $type = $config['amount_type'] ?? 'fixed';
        if ($type === 'fixed') {
            if (count($amounts) === 0) {
                throw ValidationException::withMessages(['gift_card_amounts' => 'Add at least one fixed gift card amount.']);
            }
            $values = [];
            foreach ($amounts as $index => $row) {
                $value = round((float) ($row['value'] ?? 0), 2);
                if ($value <= 0) {
                    throw ValidationException::withMessages(["gift_card_amounts.$index.value" => 'Amount must be greater than zero.']);
                }
                if (isset($values[(string) $value])) {
                    throw ValidationException::withMessages(["gift_card_amounts.$index.value" => 'Duplicate gift card amounts are not allowed.']);
                }
                $values[(string) $value] = true;
                if (isset($row['selling_price']) && $row['selling_price'] !== '' && (float) $row['selling_price'] < 0) {
                    throw ValidationException::withMessages(["gift_card_amounts.$index.selling_price" => 'Selling price cannot be negative.']);
                }
            }
        } else {
            $min = round((float) ($config['min_amount'] ?? 0), 2);
            $max = round((float) ($config['max_amount'] ?? 0), 2);
            $default = isset($config['default_amount']) && $config['default_amount'] !== ''
                ? round((float) $config['default_amount'], 2)
                : null;
            $step = round((float) ($config['amount_step'] ?? 0), 2);

            if ($min <= 0) {
                throw ValidationException::withMessages(['gift_card.min_amount' => 'Minimum amount must be greater than zero.']);
            }
            if ($max < $min) {
                throw ValidationException::withMessages(['gift_card.max_amount' => 'Maximum amount must be greater than or equal to minimum.']);
            }
            if ($step <= 0) {
                throw ValidationException::withMessages(['gift_card.amount_step' => 'Amount step must be greater than zero.']);
            }
            if ($default !== null && ($default < $min || $default > $max)) {
                throw ValidationException::withMessages(['gift_card.default_amount' => 'Default amount must be within the allowed range.']);
            }
        }

        $validity = $config['validity_type'] ?? 'never';
        if ($validity === 'days' && (int) ($config['validity_days'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['gift_card.validity_days' => 'Validity days must be greater than zero.']);
        }
        if ($validity === 'fixed_date' && empty($config['expiry_date'])) {
            throw ValidationException::withMessages(['gift_card.expiry_date' => 'Expiry date is required.']);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function configAttributes(array $config): array
    {
        return [
            'amount_type' => $config['amount_type'] ?? 'fixed',
            'min_amount' => ($config['min_amount'] ?? '') === '' ? null : $config['min_amount'],
            'max_amount' => ($config['max_amount'] ?? '') === '' ? null : $config['max_amount'],
            'default_amount' => ($config['default_amount'] ?? '') === '' ? null : $config['default_amount'],
            'amount_step' => ($config['amount_step'] ?? '') === '' ? null : $config['amount_step'],
            'delivery_method' => $config['delivery_method'] ?? 'email',
            'recipient_name_enabled' => (bool) ($config['recipient_name_enabled'] ?? true),
            'recipient_name_required' => (bool) ($config['recipient_name_required'] ?? false),
            'recipient_email_enabled' => (bool) ($config['recipient_email_enabled'] ?? true),
            'recipient_email_required' => (bool) ($config['recipient_email_required'] ?? true),
            'sender_name_enabled' => (bool) ($config['sender_name_enabled'] ?? true),
            'sender_name_required' => (bool) ($config['sender_name_required'] ?? false),
            'gift_message_enabled' => (bool) ($config['gift_message_enabled'] ?? true),
            'gift_message_required' => (bool) ($config['gift_message_required'] ?? false),
            'validity_type' => $config['validity_type'] ?? 'never',
            'validity_days' => ($config['validity_days'] ?? '') === '' ? null : (int) $config['validity_days'],
            'expiry_date' => ($config['expiry_date'] ?? '') === '' ? null : $config['expiry_date'],
            'allow_online_redemption' => (bool) ($config['allow_online_redemption'] ?? true),
            'allow_offline_redemption' => (bool) ($config['allow_offline_redemption'] ?? false),
            'usage_type' => $config['usage_type'] ?? 'multiple',
            'allow_partial_redemption' => (bool) ($config['allow_partial_redemption'] ?? true),
            'code_length' => max(8, min(32, (int) ($config['code_length'] ?? 12))),
            'code_prefix' => ($config['code_prefix'] ?? '') !== '' ? $config['code_prefix'] : null,
            'code_charset' => $config['code_charset'] ?? 'alphanumeric',
            'code_uppercase' => (bool) ($config['code_uppercase'] ?? true),
            'min_order_amount' => ($config['min_order_amount'] ?? '') === '' ? null : $config['min_order_amount'],
            'max_order_amount' => ($config['max_order_amount'] ?? '') === '' ? null : $config['max_order_amount'],
            'restrictions' => $this->normalizeRestrictions($config['restrictions'] ?? []),
        ];
    }

    /**
     * Restrictions use a fixed, documented shape. Unknown keys are dropped and
     * every list is cast to unique positive integers so nothing else can be
     * persisted through the config payload.
     *
     * @param  mixed  $restrictions
     * @return array<string, list<int>>|null
     */
    protected function normalizeRestrictions(mixed $restrictions): ?array
    {
        if (! is_array($restrictions)) {
            return null;
        }

        $clean = [];
        foreach (['category_ids', 'product_ids', 'exclude_category_ids', 'exclude_product_ids'] as $key) {
            $ids = [];
            foreach ((array) ($restrictions[$key] ?? []) as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $clean[$key] = array_values($ids);
        }

        $empty = collect($clean)->every(fn (array $ids) => $ids === []);

        return $empty ? null : $clean;
    }

    /**
     * @param  array<int, array<string, mixed>>  $amounts
     */
    protected function syncAmounts(Product $product, array $amounts): void
    {
        $keep = [];
        foreach ($amounts as $index => $row) {
            $value = round((float) ($row['value'] ?? 0), 2);
            if ($value <= 0) {
                continue;
            }
            $payload = [
                'value' => $value,
                'selling_price' => ($row['selling_price'] ?? '') === '' ? null : $row['selling_price'],
                'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                'position' => (int) ($row['position'] ?? $index),
            ];
            if (! empty($row['id'])) {
                ProductGiftCardAmount::query()
                    ->where('product_id', $product->id)
                    ->where('id', $row['id'])
                    ->update($payload);
                $keep[] = (int) $row['id'];
            } else {
                $created = ProductGiftCardAmount::query()->create([
                    'product_id' => $product->id,
                    ...$payload,
                ]);
                $keep[] = $created->id;
            }
        }

        ProductGiftCardAmount::query()
            ->where('product_id', $product->id)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolveRecipientFields(ProductGiftCard $config, array $data): array
    {
        $out = [];
        $fields = [
            'recipient_name' => ['enabled' => 'recipient_name_enabled', 'required' => 'recipient_name_required'],
            'recipient_email' => ['enabled' => 'recipient_email_enabled', 'required' => 'recipient_email_required'],
            'sender_name' => ['enabled' => 'sender_name_enabled', 'required' => 'sender_name_required'],
            'gift_message' => ['enabled' => 'gift_message_enabled', 'required' => 'gift_message_required'],
        ];

        foreach ($fields as $key => $flags) {
            if (! $config->{$flags['enabled']}) {
                continue;
            }
            $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
            if ($config->{$flags['required']} && $value === '') {
                throw ValidationException::withMessages([$key => ucfirst(str_replace('_', ' ', $key)).' is required.']);
            }
            if ($key === 'recipient_email' && $value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([$key => 'Recipient email must be valid.']);
            }
            $out[$key] = $value !== '' ? $value : null;
        }

        return $out;
    }
}
