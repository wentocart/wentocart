<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\PreorderNotifySubscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPreorderSetting;
use App\Domain\Catalog\Support\ProductModeConflict;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Search\PrefixQuery;
use App\Domain\Settings\ShopMailer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreorderService
{
    /** @var list<string> */
    public const SUPPORTED_TYPES = ['simple', 'combination'];

    public function supportsProductType(string $type): bool
    {
        $normalized = $this->normalizeProductType($type);

        return in_array($normalized, self::SUPPORTED_TYPES, true);
    }

    public function normalizeProductType(string $type): string
    {
        return $type === 'preorder' ? 'simple' : $type;
    }

    public function setting(Product $product): ?ProductPreorderSetting
    {
        if (! $product->preorder_enabled) {
            return null;
        }
        if ($product->relationLoaded('preorderSetting')) {
            return $product->preorderSetting;
        }

        return ProductPreorderSetting::query()->where('product_id', $product->id)->first();
    }

    public function isActive(Product $product, ?Carbon $at = null): bool
    {
        if (! $product->preorder_enabled || ! $this->supportsProductType($product->type)) {
            return false;
        }
        $config = $this->setting($product);
        if (! $config) {
            return false;
        }
        $at ??= now();
        if ($config->start_at && $at->lt($config->start_at)) {
            return false;
        }
        if ($config->end_at && $at->gt($config->end_at)) {
            return false;
        }
        $remaining = $config->ordersRemaining();
        if ($remaining !== null && $remaining <= 0) {
            return false;
        }

        return true;
    }

    public function skipsStockGate(Product $product): bool
    {
        return $this->isActive($product);
    }

    public function status(Product $product, ?Carbon $at = null): string
    {
        if (! $product->preorder_enabled) {
            return 'disabled';
        }
        $config = $this->setting($product);
        if (! $config) {
            return 'incomplete';
        }
        $at ??= now();
        if ($config->start_at && $at->lt($config->start_at)) {
            return 'scheduled';
        }
        if ($config->end_at && $at->gt($config->end_at)) {
            return 'ended';
        }
        $remaining = $config->ordersRemaining();
        if ($remaining !== null && $remaining <= 0) {
            return 'sold_out';
        }

        return 'open';
    }

    public function fullUnitPrice(Product $product, ?float $fallbackPrice = null): float
    {
        $config = $this->setting($product);
        $base = $fallbackPrice !== null ? (float) $fallbackPrice : (float) $product->price;
        if (! $config || $config->preorder_price === null) {
            return round(max(0, $base), 2);
        }
        if ($config->preorder_price_valid_until && now()->gt($config->preorder_price_valid_until)) {
            return round(max(0, $base), 2);
        }

        return round(max(0, (float) $config->preorder_price), 2);
    }

    /**
     * Amount charged now per unit (deposit share or full).
     */
    public function chargeUnitPrice(Product $product, ?float $fallbackPrice = null): float
    {
        $full = $this->fullUnitPrice($product, $fallbackPrice);
        $config = $this->setting($product);
        if (! $config || ($config->payment_type ?? 'full') !== 'deposit') {
            return $full;
        }
        $depositPerUnit = $this->depositPerUnit($product, $full);

        return round(min($full, max(0, $depositPerUnit)), 2);
    }

    public function depositPerUnit(Product $product, ?float $fullUnit = null): float
    {
        $config = $this->setting($product);
        if (! $config || ($config->payment_type ?? 'full') !== 'deposit') {
            return 0.0;
        }
        $full = $fullUnit ?? $this->fullUnitPrice($product);
        $value = (float) ($config->deposit_value ?? 0);
        if (($config->deposit_type ?? 'percent') === 'percent') {
            return round($full * ($value / 100), 2);
        }

        return round(min($full, max(0, $value)), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPreorderData(Product $product, float $qty, ?float $fallbackPrice = null): array
    {
        $config = $this->setting($product);
        $full = $this->fullUnitPrice($product, $fallbackPrice);
        $charge = $this->chargeUnitPrice($product, $fallbackPrice);
        $deposit = round($charge * $qty, 2);
        $remaining = round(max(0, ($full - $charge) * $qty), 2);
        $status = 'awaiting_release';
        if ($config && ($config->fulfillment_rule ?? 'auto') === 'auto') {
            if ($config->release_at && now()->gte($config->release_at)) {
                $status = 'ready';
            }
        }

        return [
            'enabled' => true,
            'payment_type' => $config?->payment_type ?? 'full',
            'deposit_type' => $config?->deposit_type,
            'deposit_value' => $config?->deposit_value !== null ? (float) $config->deposit_value : null,
            'full_unit_price' => $full,
            'charge_unit_price' => $charge,
            'deposit' => $deposit,
            'remaining_balance' => $remaining,
            'release_at' => $config?->release_at?->toIso8601String(),
            'fulfillment_at' => $config?->fulfillment_at?->toIso8601String(),
            'expected_delivery_at' => $config?->expected_delivery_at?->toIso8601String(),
            'fulfillment_rule' => $config?->fulfillment_rule ?? 'auto',
            'status' => $status,
            'message' => $config?->message,
            'qty_reserved' => (float) $qty,
        ];
    }

    /**
     * Units of this product that currently consume a customer's pre-order allowance
     * (enabled pre-order lines that have not been cancelled/released).
     */
    public function customerActivePreorderQty(Product $product, int $customerId): float
    {
        if ($customerId < 1) {
            return 0.0;
        }

        $items = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', static fn ($query) => $query->where('customer_id', $customerId))
            ->whereNotNull('preorder_data')
            ->get(['id', 'qty', 'preorder_data']);

        $sum = 0.0;
        foreach ($items as $item) {
            $data = is_array($item->preorder_data) ? $item->preorder_data : [];
            if (! $this->lineHasPreorderData($data) || $this->isFinalPaymentData($data)) {
                continue;
            }
            if (($data['status'] ?? '') === 'cancelled') {
                continue;
            }
            $sum += (float) ($data['qty_reserved'] ?? $item->qty);
        }

        return $sum;
    }

    public function customerRemainingAllowance(Product $product, Customer $customer): ?float
    {
        $config = $this->setting($product);
        if ($config?->max_quantity === null) {
            return null;
        }
        $max = (int) $config->max_quantity;
        $used = $this->customerActivePreorderQty($product, (int) $customer->id);

        return max(0.0, (float) $max - $used);
    }

    /**
     * @param  float  $alreadyInCart  Other matching pre-order qty already in the cart (not including $qty)
     * @param  bool  $lockCustomer  When true (checkout), lock the customer row for concurrency safety
     */
    public function assertCanPurchase(
        Product $product,
        float $qty,
        float $alreadyInCart = 0,
        ?Customer $customer = null,
        bool $lockCustomer = false,
    ): void {
        if (! $this->isActive($product)) {
            $status = $this->status($product);
            $message = match ($status) {
                'scheduled' => 'This pre-order has not started yet.',
                'ended' => 'This pre-order window has ended.',
                'sold_out' => 'This pre-order is sold out.',
                default => 'This product is not available for pre-order.',
            };
            throw ValidationException::withMessages(['product' => $message]);
        }

        $config = $this->setting($product);
        $min = max(1, (int) ($config?->min_quantity ?: 1));
        $max = $config?->max_quantity !== null ? (int) $config->max_quantity : null;
        $lineQty = $qty + $alreadyInCart;
        if ($lineQty < $min) {
            throw ValidationException::withMessages([
                'qty' => 'Minimum pre-order quantity is '.$min.'.',
            ]);
        }

        if ($max !== null) {
            if (! $customer) {
                throw ValidationException::withMessages([
                    'product' => 'Sign in is required to pre-order this product (maximum per customer applies).',
                ]);
            }
            if ($lockCustomer) {
                Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            }
            $used = $this->customerActivePreorderQty($product, (int) $customer->id);
            if ($used + $lineQty > $max) {
                $remaining = max(0.0, (float) $max - $used);
                throw ValidationException::withMessages([
                    'qty' => $remaining > 0.009
                        ? 'Maximum per customer is '.$max.'. You can pre-order '.$this->formatQty($remaining).' more.'
                        : 'You have reached the maximum of '.$max.' pre-order unit(s) for this product.',
                ]);
            }
        }

        $remaining = $config?->ordersRemaining();
        if ($remaining !== null && ($qty) > $remaining) {
            throw ValidationException::withMessages([
                'qty' => 'Only '.$remaining.' pre-order unit(s) remaining.',
            ]);
        }
    }

    protected function formatQty(float $qty): string
    {
        return fmod($qty, 1.0) < 0.001 ? (string) (int) round($qty) : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    public function reserveOrders(Product $product, float $qty): void
    {
        if ($qty <= 0) {
            return;
        }
        DB::transaction(function () use ($product, $qty) {
            $row = ProductPreorderSetting::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                throw ValidationException::withMessages(['product' => 'Pre-order configuration is missing.']);
            }
            if ($row->total_limit !== null) {
                $left = max(0, (int) $row->total_limit - (int) $row->orders_placed);
                if ($qty > $left) {
                    throw ValidationException::withMessages([
                        'qty' => 'Only '.$left.' pre-order unit(s) remaining.',
                    ]);
                }
            }
            $row->orders_placed = (int) $row->orders_placed + (int) ceil($qty);
            $row->save();
        });
    }

    public function releaseOrders(Product $product, float $qty): void
    {
        if ($qty <= 0) {
            return;
        }
        DB::transaction(function () use ($product, $qty) {
            $row = ProductPreorderSetting::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                return;
            }
            $row->orders_placed = max(0, (int) $row->orders_placed - (int) ceil($qty));
            $row->save();
        });
    }

    public function releaseOrderItem(OrderItem $item): void
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : null;
        if (! $data || empty($data['enabled'])) {
            return;
        }
        if (($data['status'] ?? '') === 'cancelled') {
            return;
        }
        $qty = (float) ($data['qty_reserved'] ?? $item->qty);
        if ($item->product) {
            $this->releaseOrders($item->product, $qty);
        }
        $data['status'] = 'cancelled';
        $data['deposit_status'] = 'released';
        $item->preorder_data = $data;
        $item->save();
    }

    public function markItemReady(OrderItem $item): void
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : null;
        if (! $data || empty($data['enabled'])) {
            return;
        }
        $data['status'] = 'ready';
        $item->preorder_data = $data;
        $item->save();
    }

    public function markItemFulfilled(OrderItem $item): void
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : null;
        if (! $data || empty($data['enabled'])) {
            return;
        }
        $data['status'] = 'fulfilled';
        $item->preorder_data = $data;
        $item->save();
    }

    public function autoAdvanceItem(OrderItem $item): void
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : null;
        if (! $data || empty($data['enabled'])) {
            return;
        }
        if ($this->fulfillmentRuleForItem($item, $data) !== 'auto') {
            return;
        }
        if (($data['status'] ?? '') !== 'awaiting_release') {
            return;
        }
        $release = ! empty($data['release_at']) ? Carbon::parse($data['release_at']) : null;
        $released = $release && now()->gte($release);
        $item->loadMissing('product');
        if ($released || $this->productHasAvailableStock($item->product)) {
            $this->markItemReady($item);
        }
    }

    /**
     * Storefront payload.
     *
     * @return array<string, mixed>|null
     */
    public function presentForStorefront(Product $product, ?Customer $customer = null): ?array
    {
        if (! $product->preorder_enabled) {
            return null;
        }
        $config = $this->setting($product);
        if (! $config) {
            return null;
        }

        // Before start / after end / sold out: omit pre-order payload so FO uses normal PDP/cart.
        // Active window is evaluated with app timezone via status()/isActive().
        if ($this->status($product) !== 'open') {
            return null;
        }

        $full = $this->fullUnitPrice($product);
        $charge = $this->chargeUnitPrice($product);
        $maxPerCustomer = $config->max_quantity !== null ? (int) $config->max_quantity : null;
        $requiresLogin = $maxPerCustomer !== null;
        $used = null;
        $customerRemaining = null;
        if ($customer && $maxPerCustomer !== null) {
            $used = $this->customerActivePreorderQty($product, (int) $customer->id);
            $customerRemaining = max(0.0, (float) $maxPerCustomer - $used);
        }

        return array_merge($this->presentRow($config), [
            'enabled' => true,
            'active' => true,
            'status' => 'open',
            'orders_remaining' => $config->ordersRemaining(),
            'full_unit_price' => $full,
            'charge_unit_price' => $charge,
            'deposit_per_unit' => $this->depositPerUnit($product, $full),
            'remaining_per_unit' => round(max(0, $full - $charge), 2),
            'cta_label' => 'Pre-order now',
            'can_purchase' => ! $requiresLogin || ($customer && ($customerRemaining === null || $customerRemaining > 0.009)),
            'requires_login' => $requiresLogin && ! $customer,
            'max_per_customer' => $maxPerCustomer,
            'customer_qty_used' => $used,
            'customer_qty_remaining' => $customerRemaining,
            'notify_me_enabled' => (bool) $config->notify_me_enabled,
            'notify_me_subscribed' => $customer ? $this->isNotifySubscribed($product, $customer) : false,
        ]);
    }

    public function isNotifyMeEnabled(Product $product): bool
    {
        $config = $this->setting($product);

        return (bool) ($config?->notify_me_enabled);
    }

    public function isNotifySubscribed(Product $product, Customer $customer): bool
    {
        return PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->whereNull('notified_at')
            ->exists();
    }

    /**
     * Product is available for normal purchase (pre-order window closed, stock present).
     */
    public function isAvailableForPurchaseNotify(Product $product): bool
    {
        if ($this->isActive($product)) {
            return false;
        }

        return $this->productHasAvailableStock($product);
    }

    public function subscribeNotify(Product $product, Customer $customer): PreorderNotifySubscription
    {
        if (! $product->preorder_enabled || ! $this->supportsProductType($product->type)) {
            throw ValidationException::withMessages([
                'product' => 'This product is not available for pre-order notifications.',
            ]);
        }
        if (! $this->isNotifyMeEnabled($product)) {
            throw ValidationException::withMessages([
                'product' => 'Notify Me is not enabled for this product.',
            ]);
        }
        if (! $this->isActive($product)) {
            throw ValidationException::withMessages([
                'product' => 'Notify Me is only available while the pre-order window is open.',
            ]);
        }

        return PreorderNotifySubscription::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
            ],
            ['notified_at' => null],
        );
    }

    public function unsubscribeNotify(Product $product, Customer $customer): void
    {
        PreorderNotifySubscription::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->delete();
    }

    public function dispatchAvailabilityNotifications(int $limit = 100): int
    {
        $limit = max(1, $limit);
        $rows = PreorderNotifySubscription::query()
            ->whereNull('notified_at')
            ->with(['product.translations', 'customer'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $mailer = app(ShopMailer::class);
        $sent = 0;
        foreach ($rows as $row) {
            $product = $row->product;
            $customer = $row->customer;
            if (! $product || ! $customer) {
                continue;
            }
            $email = trim((string) $customer->email);
            if ($email === '') {
                continue;
            }
            if (! $this->isAvailableForPurchaseNotify($product)) {
                continue;
            }
            $name = $product->translation()?->name ?: $product->sku;
            $slug = (string) ($product->translation()?->slug ?: 'product/'.$product->id);
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($slug, '/');
            $mailer->preorderAvailable($email, $name, $url);
            $row->update(['notified_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    public function syncProduct(Product $product, bool $enabled, array $config = []): ?ProductPreorderSetting
    {
        $type = $this->normalizeProductType($product->type);
        if ($enabled && ! $this->supportsProductType($type)) {
            throw ValidationException::withMessages([
                'preorder_enabled' => 'Pre-order is not supported for this product type.',
            ]);
        }
        if ($enabled) {
            ProductModeConflict::assertExclusive((bool) $product->subscription_enabled, true);
        }

        $product->update(['preorder_enabled' => $enabled]);

        if (! $enabled) {
            ProductPreorderSetting::query()->where('product_id', $product->id)->delete();

            return null;
        }

        $this->validateConfig($config, $product);

        return DB::transaction(function () use ($product, $config) {
            return ProductPreorderSetting::query()->updateOrCreate(
                ['product_id' => $product->id],
                $this->configAttributes($config),
            );
        });
    }

    public function presentConfig(Product $product): ?array
    {
        if (! $product->preorder_enabled) {
            return null;
        }

        $config = $this->setting($product);
        if (! $config) {
            return [
                'preorder_enabled' => true,
            ];
        }

        return array_merge($this->presentRow($config), [
            'preorder_enabled' => true,
            'orders_remaining' => $config->ordersRemaining(),
        ]);
    }

    public function presentRow(ProductPreorderSetting $config): array
    {
        return [
            'start_at' => $config->start_at?->format('Y-m-d\TH:i'),
            'end_at' => $config->end_at?->format('Y-m-d\TH:i'),
            'release_at' => $config->release_at?->format('Y-m-d\TH:i'),
            'fulfillment_at' => $config->fulfillment_at?->format('Y-m-d\TH:i'),
            'expected_delivery_at' => $config->expected_delivery_at?->format('Y-m-d\TH:i'),
            'payment_type' => $config->payment_type,
            'deposit_type' => $config->deposit_type,
            'deposit_value' => $config->deposit_value !== null ? (float) $config->deposit_value : null,
            'min_quantity' => (int) $config->min_quantity,
            'max_quantity' => $config->max_quantity,
            'total_limit' => $config->total_limit,
            'orders_placed' => (int) $config->orders_placed,
            'message' => $config->message,
            'show_badge' => (bool) $config->show_badge,
            'show_release_date' => (bool) $config->show_release_date,
            'show_delivery_date' => (bool) $config->show_delivery_date,
            'show_countdown_pdp' => (bool) $config->show_countdown_pdp,
            'show_countdown_listing' => (bool) $config->show_countdown_listing,
            'show_image_label' => (bool) $config->show_image_label,
            'notify_me_enabled' => (bool) $config->notify_me_enabled,
            ...$this->countdownFields($config),
            'fulfillment_rule' => $config->fulfillment_rule,
            'preorder_price' => $config->preorder_price !== null ? (float) $config->preorder_price : null,
            'preorder_price_valid_until' => $config->preorder_price_valid_until?->format('Y-m-d\TH:i'),
        ];
    }

    protected function validateConfig(array $config, Product $product): void
    {
        $start = $this->parseDate($config['start_at'] ?? null);
        $end = $this->parseDate($config['end_at'] ?? null);
        $release = $this->parseDate($config['release_at'] ?? null);
        $fulfillment = $this->parseDate($config['fulfillment_at'] ?? null);
        $delivery = $this->parseDate($config['expected_delivery_at'] ?? null);

        if ($end && $start && $end->lt($start)) {
            throw ValidationException::withMessages(['preorder.end_at' => 'Pre-order end date must be after the start date.']);
        }
        if ($release && $start && $release->lt($start)) {
            throw ValidationException::withMessages(['preorder.release_at' => 'Release date cannot be before the pre-order start date.']);
        }
        if ($fulfillment && $release && $fulfillment->lt($release)) {
            throw ValidationException::withMessages(['preorder.fulfillment_at' => 'Fulfillment date cannot be before the release date.']);
        }
        if ($delivery && $release && $delivery->lt($release)) {
            throw ValidationException::withMessages(['preorder.expected_delivery_at' => 'Expected delivery date cannot be before the release date.']);
        }

        $min = (int) ($config['min_quantity'] ?? 1);
        $max = isset($config['max_quantity']) && $config['max_quantity'] !== '' ? (int) $config['max_quantity'] : null;
        if ($min <= 0) {
            throw ValidationException::withMessages(['preorder.min_quantity' => 'Minimum pre-order quantity must be greater than zero.']);
        }
        if ($max !== null && $max < $min) {
            throw ValidationException::withMessages(['preorder.max_quantity' => 'Maximum per customer must be greater than or equal to minimum quantity.']);
        }

        $totalLimit = isset($config['total_limit']) && $config['total_limit'] !== '' ? (int) $config['total_limit'] : null;
        if ($totalLimit !== null && $totalLimit <= 0) {
            throw ValidationException::withMessages(['preorder.total_limit' => 'Total pre-order limit must be greater than zero or unlimited.']);
        }

        $paymentType = $config['payment_type'] ?? 'full';
        if ($paymentType === 'deposit') {
            $depositType = $config['deposit_type'] ?? null;
            $depositValue = $config['deposit_value'] ?? null;
            if (! in_array($depositType, ['fixed', 'percent'], true)) {
                throw ValidationException::withMessages(['preorder.deposit_type' => 'Deposit type is required when deposit payment is selected.']);
            }
            if ($depositValue === null || $depositValue === '') {
                throw ValidationException::withMessages(['preorder.deposit_value' => 'Deposit value is required.']);
            }
            $amount = (float) $depositValue;
            if ($amount < 0) {
                throw ValidationException::withMessages(['preorder.deposit_value' => 'Deposit value cannot be negative.']);
            }
            if ($depositType === 'percent' && $amount > 100) {
                throw ValidationException::withMessages(['preorder.deposit_value' => 'Deposit percentage cannot exceed 100%.']);
            }
            if ($depositType === 'fixed' && $amount > (float) $product->price) {
                throw ValidationException::withMessages(['preorder.deposit_value' => 'Fixed deposit cannot exceed the product price.']);
            }
        }

        if (($config['preorder_price'] ?? '') !== '' && (float) $config['preorder_price'] < 0) {
            throw ValidationException::withMessages(['preorder.preorder_price' => 'Pre-order price cannot be negative.']);
        }
    }

    protected function configAttributes(array $config): array
    {
        return [
            'start_at' => ($config['start_at'] ?? '') === '' ? null : $config['start_at'],
            'end_at' => ($config['end_at'] ?? '') === '' ? null : $config['end_at'],
            'release_at' => ($config['release_at'] ?? '') === '' ? null : $config['release_at'],
            'fulfillment_at' => ($config['fulfillment_at'] ?? '') === '' ? null : $config['fulfillment_at'],
            'expected_delivery_at' => ($config['expected_delivery_at'] ?? '') === '' ? null : $config['expected_delivery_at'],
            'payment_type' => $config['payment_type'] ?? 'full',
            'deposit_type' => ($config['payment_type'] ?? 'full') === 'deposit' ? ($config['deposit_type'] ?? null) : null,
            'deposit_value' => ($config['payment_type'] ?? 'full') === 'deposit' && ($config['deposit_value'] ?? '') !== ''
                ? $config['deposit_value']
                : null,
            'min_quantity' => max(1, (int) ($config['min_quantity'] ?? 1)),
            'max_quantity' => ($config['max_quantity'] ?? '') === '' ? null : (int) $config['max_quantity'],
            'total_limit' => ($config['total_limit'] ?? '') === '' ? null : (int) $config['total_limit'],
            'message' => ($config['message'] ?? '') !== '' ? $config['message'] : null,
            'show_badge' => (bool) ($config['show_badge'] ?? true),
            'show_release_date' => (bool) ($config['show_release_date'] ?? true),
            'show_delivery_date' => (bool) ($config['show_delivery_date'] ?? false),
            'show_countdown_pdp' => (bool) ($config['show_countdown_pdp'] ?? false),
            'show_countdown_listing' => (bool) ($config['show_countdown_listing'] ?? false),
            'show_image_label' => (bool) ($config['show_image_label'] ?? false),
            'notify_me_enabled' => (bool) ($config['notify_me_enabled'] ?? false),
            'fulfillment_rule' => $config['fulfillment_rule'] ?? 'auto',
            'preorder_price' => ($config['preorder_price'] ?? '') === '' ? null : $config['preorder_price'],
            'preorder_price_valid_until' => ($config['preorder_price_valid_until'] ?? '') === '' ? null : $config['preorder_price_valid_until'],
        ];
    }

    /**
     * Count down to the next configured pre-order instant (start, then end, else release).
     *
     * @return array{countdown_kind: ?string, countdown_at: ?string, server_now: string}
     */
    public function countdownFields(ProductPreorderSetting $config, ?Carbon $at = null): array
    {
        $at ??= now();
        $kind = null;
        $target = null;

        if ($config->start_at && $at->lt($config->start_at)) {
            $kind = 'start';
            $target = $config->start_at;
        } elseif ($config->end_at) {
            $kind = 'end';
            $target = $config->end_at;
        } elseif ($config->release_at) {
            $kind = 'release';
            $target = $config->release_at;
        }

        return [
            'countdown_kind' => $kind,
            'countdown_at' => $target?->toIso8601String(),
            'server_now' => $at->toIso8601String(),
        ];
    }

    /**
     * Customer My Account pre-order lines (one row per purchased order item).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateForCustomer(Customer $customer, int $perPage = 10): LengthAwarePaginator
    {
        $locale = app()->getLocale();

        return OrderItem::query()
            ->whereHas('order', static fn ($query) => $query->where('customer_id', $customer->id))
            ->where(function ($query) {
                $query->where(function ($json) {
                    $json->where('preorder_data->enabled', true)
                        ->orWhere('preorder_data->enabled', 1)
                        ->orWhereNotNull('preorder_data->full_unit_price')
                        ->orWhereNotNull('preorder_data->payment_type');
                })->orWhereHas('product', static fn ($product) => $product->where('preorder_enabled', true));
            })
            ->where(function ($query) {
                $query->whereNull('preorder_data->kind')
                    ->orWhere('preorder_data->kind', '!=', 'balance_payment');
            })
            ->with([
                'order',
                'product.preorderSetting',
                'product.translations',
                'product.brand.translations',
            ])
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (OrderItem $item) => $this->presentAccountItem($item, $locale));
    }

    /**
     * Backoffice pre-order lines (one row per pre-order item; mixed orders stay line-scoped).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateForAdmin(Request $request, int $perPage = 10): LengthAwarePaginator
    {
        $locale = app()->getLocale();
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $sort = $this->normalizeAdminSort($requestedSort);

        $query = $this->adminPreorderItemQuery($request)
            ->with([
                'order:id,number,checkout_reference,customer_id,email,status,payment_status,currency_code,grand_total,payment_method_id,billing_address,created_at',
                'order.customer:id,name,email',
                'order.paymentMethod:id,name',
                'order.items:id,order_id,preorder_data,product_id',
                'order.items.product:id,preorder_enabled',
                'product.preorderSetting',
                'product.translations',
            ])
            ->orderBy($sort, $dir)
            ->orderByDesc('order_items.id');

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (OrderItem $item) => $this->presentAdminItem($item, $locale));
    }

    /**
     * @return Builder<OrderItem>
     */
    public function adminPreorderItemQuery(Request $request): Builder
    {
        $query = OrderItem::query()
            ->where(function ($inner) {
                $inner->where(function ($json) {
                    $json->where('preorder_data->enabled', true)
                        ->orWhere('preorder_data->enabled', 1)
                        ->orWhereNotNull('preorder_data->full_unit_price')
                        ->orWhereNotNull('preorder_data->payment_type');
                })->orWhereHas('product', static fn ($product) => $product->where('preorder_enabled', true));
            })
            ->where(function ($inner) {
                $inner->whereNull('preorder_data->kind')
                    ->orWhere('preorder_data->kind', '!=', 'balance_payment');
            });

        $number = trim(ltrim($request->string('number')->toString(), '#'));
        $customer = trim($request->string('customer')->toString());
        $product = trim($request->string('product')->toString());
        $paymentStatus = trim($request->string('payment_status')->toString());
        $preorderStatus = trim($request->string('preorder_status')->toString());
        $q = trim($request->string('q')->toString());

        $query->when($number !== '', function ($builder) use ($number) {
            $like = PrefixQuery::like($number);
            $builder->whereHas('order', fn ($order) => $order
                ->where('number', 'like', $like)
                ->orWhere('checkout_reference', 'like', $like));
        });

        $query->when($customer !== '', function ($builder) use ($customer) {
            $like = PrefixQuery::like($customer);
            $builder->whereHas('order', function ($order) use ($like) {
                $order->where('email', 'like', $like)
                    ->orWhereHas('customer', fn ($row) => $row->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhere('billing_address->first_name', 'like', $like)
                    ->orWhere('billing_address->last_name', 'like', $like);
            });
        });

        $query->when($product !== '', function ($builder) use ($product) {
            $like = PrefixQuery::contains($product);
            $builder->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhereHas('product.translations', fn ($translation) => $translation->where('name', 'like', $like));
            });
        });

        $query->when($paymentStatus !== '' && $paymentStatus !== 'all', function ($builder) use ($paymentStatus) {
            $builder->whereHas('order', fn ($order) => $order->where('payment_status', $paymentStatus));
        });

        $query->when($preorderStatus !== '' && $preorderStatus !== 'all', function ($builder) use ($preorderStatus) {
            $status = strtolower($preorderStatus);
            if ($status === 'delivered') {
                $status = 'fulfilled';
            }
            $builder->where(function ($inner) use ($status) {
                $inner->where('preorder_data->status', $status);
                if ($status === 'fulfilled') {
                    $inner->orWhereHas('order', fn ($order) => $order->whereIn('status', ['completed', 'delivered', 'shipped']));
                } elseif ($status === 'cancelled') {
                    $inner->orWhereHas('order', fn ($order) => $order->whereIn('status', ['cancelled', 'canceled', 'refunded', 'void']));
                } elseif ($status === 'ready') {
                    $inner->orWhereHas('order', fn ($order) => $order->where('status', 'processing'));
                }
            });
        });

        $query->when($request->filled('id_min'), fn ($builder) => $builder->whereHas(
            'order',
            fn ($order) => $order->where('orders.id', '>=', (int) $request->input('id_min')),
        ));
        $query->when($request->filled('id_max'), fn ($builder) => $builder->whereHas(
            'order',
            fn ($order) => $order->where('orders.id', '<=', (int) $request->input('id_max')),
        ));
        $query->when($request->filled('date'), fn ($builder) => $builder->whereHas(
            'order',
            fn ($order) => $order->whereDate('orders.created_at', $request->input('date')),
        ));
        $query->when($request->filled('from') && ! $request->filled('date'), fn ($builder) => $builder->whereHas(
            'order',
            fn ($order) => $order->where('orders.created_at', '>=', $request->date('from')->startOfDay()),
        ));
        $query->when($request->filled('to'), fn ($builder) => $builder->whereHas(
            'order',
            fn ($order) => $order->where('orders.created_at', '<=', $request->date('to')->endOfDay()),
        ));

        $query->when($q !== '', function ($builder) use ($q) {
            $term = ltrim($q, '#');
            $prefix = PrefixQuery::like($term);
            $contains = PrefixQuery::contains($term);
            $builder->where(function ($inner) use ($prefix, $contains, $term) {
                $inner->where('name', 'like', $contains)
                    ->orWhere('sku', 'like', $prefix)
                    ->orWhereHas('order', function ($order) use ($prefix, $contains, $term) {
                        $order->where('number', 'like', $prefix)
                            ->orWhere('checkout_reference', 'like', $prefix)
                            ->orWhere('email', 'like', $contains)
                            ->orWhereHas('customer', fn ($customer) => $customer
                                ->where('name', 'like', $contains)
                                ->orWhere('email', 'like', $contains));
                        if (ctype_digit($term)) {
                            $order->orWhere('orders.id', (int) $term);
                        }
                    });
            });
        });

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAdminItem(OrderItem $item, string $locale): array
    {
        $base = $this->presentAccountItem($item, $locale);
        $order = $item->order;
        $customer = $order?->customer;
        $billing = is_array($order?->billing_address) ? $order->billing_address : [];
        $customerName = $customer?->name
            ?: trim(implode(' ', array_filter([(string) ($billing['first_name'] ?? ''), (string) ($billing['last_name'] ?? '')])))
            ?: (string) ($order?->email ?? '');

        $siblings = $order?->relationLoaded('items') ? $order->items : collect([$item]);
        $siblingCount = $siblings->count();
        $preorderSiblingCount = $siblings->filter(fn (OrderItem $row) => $this->isAdminPreorderLine($row))->count();

        return array_merge($base, [
            'order_reference' => $order?->checkout_reference,
            'customer' => [
                'id' => $customer?->id,
                'name' => $customerName,
                'email' => $order?->email ?? $customer?->email,
            ],
            'order_payment_status' => $order?->payment_status,
            'order_status' => $order?->status,
            'order_grand_total' => $order ? (float) $order->grand_total : null,
            'payment_method' => $order?->paymentMethod?->name,
            'created_at' => $order?->created_at?->toIso8601String(),
            'is_mixed_order' => $siblingCount > $preorderSiblingCount,
            'preorder_item_count' => $preorderSiblingCount,
            'order_item_count' => $siblingCount,
            'line_total' => (float) ($base['price'] ?? $item->total),
        ]);
    }

    public function isAdminPreorderLine(OrderItem $item): bool
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : [];
        if (($data['kind'] ?? null) === 'balance_payment') {
            return false;
        }

        if (! empty($data['enabled'])
            || isset($data['full_unit_price'])
            || isset($data['payment_type'])) {
            return true;
        }

        return (bool) ($item->product?->preorder_enabled);
    }

    protected function normalizeAdminSort(string $sort): string|\Illuminate\Database\Query\Expression
    {
        return match ($sort) {
            'number', 'order' => DB::raw('(SELECT number FROM orders WHERE orders.id = order_items.order_id LIMIT 1)'),
            'customer' => DB::raw('(SELECT COALESCE(customers.name, orders.email) FROM orders LEFT JOIN customers ON customers.id = orders.customer_id WHERE orders.id = order_items.order_id LIMIT 1)'),
            'product' => 'order_items.name',
            'quantity', 'qty' => 'order_items.qty',
            'total', 'line_total' => 'order_items.total',
            'payment_status' => DB::raw('(SELECT payment_status FROM orders WHERE orders.id = order_items.order_id LIMIT 1)'),
            'preorder_status' => 'order_items.preorder_data->status',
            'created_at', 'date' => DB::raw('(SELECT created_at FROM orders WHERE orders.id = order_items.order_id LIMIT 1)'),
            'order_id' => 'order_items.order_id',
            default => 'order_items.id',
        };
    }

    /**
     * Authoritative pre-order money for one purchased line.
     *
     * Price = full unit × qty. Paid = this line's share of successfully captured
     * order payment (never shipping/tax extras). Remaining = price − paid.
     *
     * @return array<string, mixed>
     */
    public function settleLine(OrderItem $item): array
    {
        $this->hydrateMissingPreorderData($item);
        $this->autoAdvanceItem($item);
        $data = is_array($item->preorder_data) ? $item->preorder_data : [];
        $order = $item->order;
        $qty = (float) $item->qty;
        $fullUnit = (float) ($data['full_unit_price'] ?? $item->unit_price);
        $chargeUnit = (float) ($data['charge_unit_price'] ?? $item->unit_price);
        $requiredNow = round((float) ($data['deposit'] ?? ($chargeUnit * $qty)), 2);
        $totalValue = round($fullUnit * $qty, 2);
        $paymentStatus = strtolower((string) ($order?->payment_status ?? ''));
        $orderStatus = strtolower((string) ($order?->status ?? ''));
        $lineStatus = strtolower((string) ($data['status'] ?? ''));
        $paid = $this->capturedTowardLine($item, $totalValue);
        $remaining = round(max(0, $totalValue - $paid), 2);
        $paymentLabel = $this->paymentAccountStatus($orderStatus, $paymentStatus, $lineStatus, $remaining);
        $preorderLabel = $this->preorderAccountStatus($orderStatus, $lineStatus);
        $cancelled = $paymentLabel === 'cancelled' || $preorderLabel === 'cancelled';

        return [
            'unit_price' => round($fullUnit, 2),
            'quantity' => $qty,
            'price' => $totalValue,
            'required_now' => $requiredNow,
            'paid' => $paid,
            'remaining' => $remaining,
            'status' => $paymentLabel,
            'line_status' => $lineStatus,
            'payment_status' => $paymentLabel,
            'preorder_status' => $preorderLabel,
            'payment_type' => $data['payment_type'] ?? 'full',
            'currency_code' => $order?->currency_code,
            'can_complete_payment' => ! $cancelled && $remaining > 0.009 && $this->isAvailableForBalancePayment($item, $data, $preorderLabel),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAccountItem(OrderItem $item, string $locale): array
    {
        $settled = $this->settleLine($item);
        $product = null;
        if ($item->product) {
            $presented = app(CatalogPresenter::class)->product($item->product, $locale, false);
            $product = [
                'id' => $presented['id'] ?? $item->product_id,
                'name' => $item->name ?: ($presented['name'] ?? null),
                'url' => $presented['url'] ?? null,
                'slug' => $presented['slug'] ?? null,
                'cover' => $presented['cover'] ?? $item->product->cover_path,
                'cover_url' => $presented['cover_url'] ?? null,
            ];
        } else {
            $product = [
                'id' => $item->product_id,
                'name' => $item->name,
                'url' => null,
                'slug' => null,
                'cover' => null,
                'cover_url' => null,
            ];
        }

        return array_merge($settled, [
            'id' => (int) $item->id,
            'order_id' => (int) $item->order_id,
            'order_number' => $item->order?->number,
            'product' => $product,
        ]);
    }

    /**
     * Queue the remaining pre-order balance on the customer cart for checkout.
     *
     * @return array<string, mixed>
     */
    public function beginRemainingPayment(Customer $customer, int $itemId): array
    {
        $prepared = DB::transaction(function () use ($customer, $itemId) {
            $item = $this->lockedOwnedPreorderItem($customer, $itemId);
            $settled = $this->assertCanStartFinalPayment($item);
            if ($this->hasOpenBalancePaymentOrder((int) $item->id)) {
                throw ValidationException::withMessages([
                    'payment' => 'A final payment for this pre-order is already in progress.',
                ]);
            }

            $carts = app(\App\Domain\Checkout\Services\CartService::class);
            $cart = $carts->current($customer);
            $cartItem = $carts->addPreorderFinalPayment($cart, $item, $settled);

            return [
                'item' => $item->fresh(['order', 'product.preorderSetting', 'product.translations']),
                'cart_item_id' => (int) $cartItem->id,
                'remaining' => (float) $settled['remaining'],
            ];
        });

        return [
            'preorder' => $this->presentAccountItem($prepared['item'], app()->getLocale()),
            'redirect_url' => '/cart',
            'remaining' => $prepared['remaining'],
            'cart_item_id' => $prepared['cart_item_id'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function isFinalPaymentData(?array $data): bool
    {
        if (! is_array($data) || $data === []) {
            return false;
        }

        return ! empty($data['final_payment']) || ($data['kind'] ?? null) === 'balance_payment';
    }

    /**
     * Recheck eligibility using live remaining + stock/release (not frontend flags).
     *
     * @return array<string, mixed>
     */
    public function assertCanStartFinalPayment(OrderItem $item): array
    {
        $item->loadMissing(['order', 'product.preorderSetting']);
        $this->hydrateMissingPreorderData($item);
        $this->autoAdvanceItem($item);
        $item->refresh();
        $item->loadMissing(['order', 'product.preorderSetting']);

        $data = is_array($item->preorder_data) ? $item->preorder_data : [];
        if (! $this->lineHasPreorderData($data) || $this->isFinalPaymentData($data)) {
            abort(404);
        }

        $settled = $this->settleLine($item);
        if ($settled['remaining'] <= 0.009) {
            throw ValidationException::withMessages([
                'payment' => 'This pre-order is already fully paid.',
            ]);
        }
        if (! $settled['can_complete_payment']) {
            throw ValidationException::withMessages([
                'payment' => 'This pre-order is not available for the remaining payment yet.',
            ]);
        }

        return $settled;
    }

    /**
     * Apply a paid balance-payment order onto the original pre-order line.
     */
    public function applyCapturedBalanceFromPaymentOrder(Order $paymentOrder): void
    {
        $paymentOrder->loadMissing('items.order');
        foreach ($paymentOrder->items as $line) {
            $data = is_array($line->preorder_data) ? $line->preorder_data : [];
            if (! $this->isFinalPaymentData($data)) {
                continue;
            }
            $sourceId = (int) ($data['source_order_item_id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            DB::transaction(function () use ($paymentOrder, $line, $sourceId) {
                $source = OrderItem::query()->lockForUpdate()->find($sourceId);
                if (! $source) {
                    return;
                }
                $original = Order::query()->whereKey($source->order_id)->lockForUpdate()->first();
                if (! $original) {
                    return;
                }
                $source->setRelation('order', $original);
                $source->loadMissing('product.preorderSetting');
                $settled = $this->settleLine($source);
                $remaining = (float) ($settled['remaining'] ?? 0);
                if ($remaining <= 0.009) {
                    return;
                }

                $credit = round(min($remaining, (float) $line->total, (float) ($paymentOrder->amount_paid ?? $paymentOrder->grand_total ?? 0)), 2);
                if ($credit <= 0.009) {
                    return;
                }

                $toInvoice = $this->uninvoicedRemainder($source, $settled);
                $sourceData = is_array($source->preorder_data) ? $source->preorder_data : [];
                $appliedOrders = is_array($sourceData['final_payment_order_ids'] ?? null)
                    ? $sourceData['final_payment_order_ids']
                    : [];
                if (in_array((int) $paymentOrder->id, array_map('intval', $appliedOrders), true)) {
                    return;
                }

                if ($toInvoice > 0.009) {
                    $invoice = round(min($toInvoice, $credit), 2);
                    $sourceData['enabled'] = true;
                    $sourceData['balance_invoiced'] = round((float) ($sourceData['balance_invoiced'] ?? 0) + $invoice, 2);
                    $original->updateTrusted([
                        'grand_total' => round((float) $original->grand_total + $invoice, 2),
                        'amount_due' => round((float) $original->amount_due + $invoice, 2),
                        'payment_status' => 'partial',
                    ]);
                    $original->refresh();
                }

                $paid = round((float) ($original->amount_paid ?? 0) + $credit, 2);
                $due = round(max(0, (float) $original->grand_total - $paid), 2);
                $original->updateTrusted([
                    'amount_paid' => $paid,
                    'amount_due' => $due,
                    'payment_status' => $due <= 0.009 ? 'paid' : 'partial',
                ]);

                $appliedOrders[] = (int) $paymentOrder->id;
                $sourceData['final_payment_order_ids'] = array_values(array_unique($appliedOrders));
                $sourceData['final_payment_order_id'] = (int) $paymentOrder->id;
                $sourceData['final_payment_order_number'] = $paymentOrder->number;
                unset($sourceData['pending_final_payment_order_id']);
                $source->preorder_data = $sourceData;
                $source->save();
            });
        }
    }

    public function linkPendingBalancePayment(Order $paymentOrder, OrderItem $source): void
    {
        $data = is_array($source->preorder_data) ? $source->preorder_data : [];
        $data['pending_final_payment_order_id'] = (int) $paymentOrder->id;
        $source->preorder_data = $data;
        $source->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFinalPaymentCartData(OrderItem $item, array $settled): array
    {
        $remaining = round((float) $settled['remaining'], 2);
        $data = is_array($item->preorder_data) ? $item->preorder_data : [];

        return [
            'enabled' => true,
            'final_payment' => true,
            'kind' => 'balance_payment',
            'source_order_item_id' => (int) $item->id,
            'source_order_id' => (int) $item->order_id,
            'source_order_number' => $item->order?->number,
            'payment_type' => $data['payment_type'] ?? 'deposit',
            'full_unit_price' => $remaining,
            'charge_unit_price' => $remaining,
            'deposit' => $remaining,
            'remaining_balance' => $remaining,
            'already_paid' => (float) ($settled['paid'] ?? 0),
            'original_total' => (float) ($settled['price'] ?? 0),
            'original_qty' => (float) ($settled['quantity'] ?? $item->qty),
            'status' => 'final_payment',
            'release_at' => $data['release_at'] ?? null,
            'message' => $data['message'] ?? null,
        ];
    }

    protected function lockedOwnedPreorderItem(Customer $customer, int $itemId): OrderItem
    {
        $owned = Order::query()
            ->where('customer_id', $customer->id)
            ->whereHas('items', static fn ($query) => $query->whereKey($itemId))
            ->lockForUpdate()
            ->first();
        if (! $owned) {
            abort(404);
        }
        $owned->load('items');
        $item = $owned->items->firstWhere('id', $itemId);
        $data = is_array($item?->preorder_data) ? $item->preorder_data : [];
        if (! $item || ! $this->lineHasPreorderData($data) || $this->isFinalPaymentData($data)) {
            abort(404);
        }
        $item->setRelation('order', $owned);
        $item->loadMissing('product.preorderSetting');

        return $item;
    }

    protected function hasOpenBalancePaymentOrder(int $sourceItemId): bool
    {
        return OrderItem::query()
            ->where('preorder_data->source_order_item_id', $sourceItemId)
            ->where(function ($query) {
                $query->where('preorder_data->final_payment', true)
                    ->orWhere('preorder_data->kind', 'balance_payment');
            })
            ->whereHas('order', static function ($query) {
                $query->whereNotIn('status', ['cancelled', 'canceled'])
                    ->whereNotIn('payment_status', ['failed', 'refunded', 'paid']);
            })
            ->exists();
    }

    /**
     * Share of successfully captured order payment that belongs to this line.
     * Pending/failed captures are ignored. Shipping/tax are excluded from paid.
     * Paid is capped at the full pre-order value, not the deposit.
     */
    protected function capturedTowardLine(OrderItem $item, float $totalValue): float
    {
        $order = $item->order;
        $paymentStatus = strtolower((string) ($order?->payment_status ?? ''));
        if (in_array($paymentStatus, ['failed', 'refunded'], true)) {
            return 0.0;
        }
        if (! in_array($paymentStatus, ['paid', 'partial'], true)) {
            return 0.0;
        }

        $captured = (float) ($order->amount_paid ?? 0);
        if ($captured <= 0.009) {
            $captured = max(0, (float) ($order->grand_total ?? 0) - (float) ($order->amount_due ?? 0));
            if ($captured <= 0.009 && $paymentStatus === 'paid' && (float) ($order->amount_due ?? 0) <= 0.009) {
                $captured = (float) ($order->grand_total ?? 0);
            }
        }
        $captured = max(0, $captured - (float) ($order->refunded_total ?? 0));
        if ($captured <= 0.009) {
            return 0.0;
        }

        $invoiced = $this->invoicedMerchandise($order);
        $fees = max(0, round((float) ($order->grand_total ?? 0) - $invoiced, 2));
        $merchandise = max(0, round($captured - $fees, 2));
        $share = $this->merchandiseShareForLine($item, $merchandise);

        return round(min($totalValue, max(0, $share)), 2);
    }

    protected function invoicedMerchandise(?\App\Domain\Order\Models\Order $order): float
    {
        if (! $order) {
            return 0.0;
        }
        $order->loadMissing('items');
        $sum = 0.0;
        foreach ($order->items as $line) {
            $data = is_array($line->preorder_data) ? $line->preorder_data : [];
            if ($this->lineHasPreorderData($data)) {
                $qty = (float) $line->qty;
                $chargeUnit = (float) ($data['charge_unit_price'] ?? $line->unit_price);
                $requiredNow = round((float) ($data['deposit'] ?? ($chargeUnit * $qty)), 2);
                $sum += $requiredNow + (float) ($data['balance_invoiced'] ?? 0);
            } else {
                $sum += (float) $line->total;
            }
        }

        return round($sum, 2);
    }

    protected function merchandiseShareForLine(OrderItem $item, float $merchandise): float
    {
        $order = $item->order;
        if (! $order) {
            return $merchandise;
        }
        $order->loadMissing('items');
        $weights = [];
        $sum = 0.0;
        foreach ($order->items as $line) {
            $data = is_array($line->preorder_data) ? $line->preorder_data : [];
            if ($this->lineHasPreorderData($data)) {
                $weight = round(((float) ($data['full_unit_price'] ?? $line->unit_price)) * (float) $line->qty, 2);
            } else {
                $weight = (float) $line->total;
            }
            $weights[(int) $line->id] = $weight;
            $sum += $weight;
        }
        if ($sum <= 0.009) {
            return 0.0;
        }

        $mine = $weights[(int) $item->id] ?? 0.0;

        return round($merchandise * ($mine / $sum), 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function lineHasPreorderData(array $data): bool
    {
        if ($data === []) {
            return false;
        }
        if (! empty($data['enabled'])) {
            return true;
        }

        return array_key_exists('full_unit_price', $data) || array_key_exists('payment_type', $data);
    }

    /**
     * @param  array<string, mixed>  $settled
     */
    protected function uninvoicedRemainder(OrderItem $item, array $settled): float
    {
        if (($settled['paid'] ?? 0) + 0.009 < (float) ($settled['required_now'] ?? 0)) {
            return 0.0;
        }
        $data = is_array($item->preorder_data) ? $item->preorder_data : [];
        $already = round((float) ($settled['required_now'] ?? 0) + (float) ($data['balance_invoiced'] ?? 0), 2);

        return round(max(0, (float) ($settled['price'] ?? 0) - $already), 2);
    }

    /**
     * Rebuild a missing line snapshot from the product's current pre-order settings.
     * Admin order edits can drop preorder_data; the purchase is still a pre-order.
     */
    public function hydrateMissingPreorderData(OrderItem $item): void
    {
        $data = is_array($item->preorder_data) ? $item->preorder_data : [];
        if ($this->lineHasPreorderData($data)) {
            return;
        }
        $item->loadMissing('product.preorderSetting');
        $product = $item->product;
        if (! $product || ! $product->preorder_enabled) {
            return;
        }
        $item->preorder_data = $this->buildPreorderData($product, (float) $item->qty);
        $item->save();
    }

    /**
     * Remaining balance can be charged once the pre-order is released/ready
     * or the product is back in stock.
     *
     * @param  array<string, mixed>  $data
     */
    public function isAvailableForBalancePayment(OrderItem $item, ?array $data = null, ?string $preorderStatus = null): bool
    {
        $data ??= is_array($item->preorder_data) ? $item->preorder_data : [];
        $preorderStatus ??= $this->preorderAccountStatus(
            strtolower((string) ($item->order?->status ?? '')),
            strtolower((string) ($data['status'] ?? '')),
        );
        if (in_array($preorderStatus, ['ready', 'fulfilled'], true)) {
            return true;
        }
        $item->loadMissing('product');

        return $this->productHasAvailableStock($item->product);
    }

    /**
     * Current product order-handling rule, so a later Catalog change is not stuck on the checkout snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    protected function fulfillmentRuleForItem(OrderItem $item, array $data): string
    {
        $item->loadMissing('product.preorderSetting');
        $live = $item->product?->preorderSetting?->fulfillment_rule;
        if (is_string($live) && in_array($live, ['auto', 'manual'], true)) {
            return $live;
        }

        return ($data['fulfillment_rule'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    }

    protected function productHasAvailableStock(?Product $product): bool
    {
        if (! $product) {
            return false;
        }
        if (! $product->manage_stock) {
            return $product->stock_status !== 'out_of_stock';
        }
        if ((float) $product->availableStock() > 0.009) {
            return true;
        }

        return in_array((string) $product->stock_status, ['in_stock', 'on_backorder'], true);
    }

    protected function paymentAccountStatus(
        string $orderStatus,
        string $paymentStatus,
        string $lineStatus,
        float $remaining,
    ): string {
        if ($lineStatus === 'cancelled' || in_array($orderStatus, ['cancelled', 'canceled', 'refunded', 'void'], true)) {
            return 'cancelled';
        }
        if ($paymentStatus === 'failed') {
            return 'payment_failed';
        }
        if (! in_array($paymentStatus, ['paid', 'partial'], true)) {
            return 'payment_pending';
        }
        if ($remaining > 0.009) {
            return 'partially_paid';
        }

        return 'fully_paid';
    }

    protected function preorderAccountStatus(string $orderStatus, string $lineStatus): string
    {
        if ($lineStatus === 'cancelled' || in_array($orderStatus, ['cancelled', 'canceled', 'refunded', 'void'], true)) {
            return 'cancelled';
        }
        if ($lineStatus === 'fulfilled' || in_array($orderStatus, ['completed', 'delivered', 'shipped'], true)) {
            return 'fulfilled';
        }
        if ($lineStatus === 'ready' || $orderStatus === 'processing') {
            return 'ready';
        }

        return $lineStatus !== '' ? $lineStatus : 'awaiting_release';
    }

    protected function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
