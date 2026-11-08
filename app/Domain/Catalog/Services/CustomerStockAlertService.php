<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\CustomerStockAlert;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopMailer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerStockAlertService
{
    public function enabled(): bool
    {
        return ProductSettings::outOfStockNotification();
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'product' => 'Out of stock notifications are disabled.',
            ]);
        }
    }

    public function normalizeVariantId(?int $variantId): int
    {
        return max(0, (int) $variantId);
    }

    public function isSubscribed(Product $product, Customer $customer, ?int $variantId = null): bool
    {
        return CustomerStockAlert::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->where('product_variant_id', $this->normalizeVariantId($variantId))
            ->whereNull('notified_at')
            ->exists();
    }

    /**
     * Pending (not yet notified) variant keys for this customer on a product.
     * Uses 0 for the parent/simple product row.
     *
     * @return list<int>
     */
    public function pendingVariantIds(Product $product, Customer $customer): array
    {
        return CustomerStockAlert::query()
            ->where('product_id', $product->id)
            ->where('customer_id', $customer->id)
            ->whereNull('notified_at')
            ->pluck('product_variant_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function targetIsOutOfStock(Product $product, ?int $variantId = null): bool
    {
        $variantId = $this->normalizeVariantId($variantId);
        if ($variantId > 0) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereKey($variantId)
                ->first();
            if (! $variant) {
                return true;
            }

            return ! $this->variantIsInStock($variant);
        }

        return ! $this->productIsInStock($product);
    }

    public function targetIsInStock(Product $product, ?int $variantId = null): bool
    {
        return ! $this->targetIsOutOfStock($product, $variantId);
    }

    public function subscribe(Product $product, Customer $customer, ?int $variantId = null): CustomerStockAlert
    {
        $this->assertEnabled();
        $variantId = $this->normalizeVariantId($variantId);
        $this->assertVariantBelongsToProduct($product, $variantId);
        $this->assertTargetOutOfStock($product, $variantId);

        $email = $this->normalizeEmail((string) $customer->email);
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'A valid customer email is required for stock alerts.',
            ]);
        }

        $first = trim((string) ($customer->first_name ?: ''));
        $last = trim((string) ($customer->last_name ?: ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) ($customer->name ?: '')), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }

        return CustomerStockAlert::query()->updateOrCreate(
            [
                'email' => $email,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
            ],
            [
                'customer_id' => $customer->id,
                'first_name' => $first !== '' ? $first : null,
                'last_name' => $last !== '' ? $last : null,
                'notified_at' => null,
            ],
        );
    }

    /**
     * Guest / visitor stock-alert subscription (no customer account).
     */
    public function subscribeGuest(
        Product $product,
        string $firstName,
        string $lastName,
        string $email,
        ?int $variantId = null,
    ): CustomerStockAlert {
        $this->assertEnabled();
        $variantId = $this->normalizeVariantId($variantId);
        $this->assertVariantBelongsToProduct($product, $variantId);
        $this->assertTargetOutOfStock($product, $variantId);

        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = $this->normalizeEmail($email);

        if ($firstName === '') {
            throw ValidationException::withMessages([
                'first_name' => 'First name is required.',
            ]);
        }
        if ($lastName === '') {
            throw ValidationException::withMessages([
                'last_name' => 'Last name is required.',
            ]);
        }
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'A valid email address is required.',
            ]);
        }

        $existing = CustomerStockAlert::query()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variantId)
            ->where('email', $email)
            ->first();

        if ($existing && $existing->notified_at === null) {
            throw ValidationException::withMessages([
                'email' => 'This email is already subscribed for this product.',
            ]);
        }

        return CustomerStockAlert::query()->updateOrCreate(
            [
                'email' => $email,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
            ],
            [
                'customer_id' => null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'notified_at' => null,
            ],
        );
    }

    public function unsubscribe(Product $product, Customer $customer, ?int $variantId = null): void
    {
        CustomerStockAlert::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $this->normalizeVariantId($variantId))
            ->delete();
    }

    public function unsubscribeById(Customer $customer, int $alertId): void
    {
        $row = CustomerStockAlert::query()
            ->where('customer_id', $customer->id)
            ->whereKey($alertId)
            ->firstOrFail();
        $row->delete();
    }

    /**
     * @return LengthAwarePaginator<int, CustomerStockAlert>
     */
    public function listForCustomer(Customer $customer, int $perPage = 20): LengthAwarePaginator
    {
        return CustomerStockAlert::query()
            ->where('customer_id', $customer->id)
            ->with([
                'product.translations',
                'variant.attributeValues.translations',
                'variant.attributeValues.attribute',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, CustomerStockAlert>
     */
    public function listForAdmin(Request $request): LengthAwarePaginator
    {
        $query = CustomerStockAlert::query()
            ->with([
                'product.translations',
                'customer',
                'variant.attributeValues.translations',
                'variant.attributeValues.attribute',
            ]);

        $status = (string) $request->query('status', 'all');
        if ($status === 'pending') {
            $query->whereNull('notified_at');
        } elseif ($status === 'notified') {
            $query->whereNotNull('notified_at');
        }

        $product = trim((string) $request->query('product', ''));
        if ($product !== '') {
            $query->where(function ($q) use ($product) {
                if (ctype_digit($product)) {
                    $q->orWhere('product_id', (int) $product);
                }
                $q->orWhereHas('product.translations', function ($t) use ($product) {
                    $t->where('name', 'like', '%'.$product.'%')
                        ->orWhere('slug', 'like', '%'.$product.'%');
                })->orWhereHas('product', function ($p) use ($product) {
                    $p->where('sku', 'like', '%'.$product.'%');
                });
            });
        }

        $customer = trim((string) $request->query('customer', ''));
        if ($customer !== '') {
            $query->where(function ($q) use ($customer) {
                if (ctype_digit($customer)) {
                    $q->orWhere('customer_id', (int) $customer);
                }
                $q->orWhere('email', 'like', '%'.$customer.'%')
                    ->orWhere('first_name', 'like', '%'.$customer.'%')
                    ->orWhere('last_name', 'like', '%'.$customer.'%')
                    ->orWhereHas('customer', function ($c) use ($customer) {
                        $c->where('email', 'like', '%'.$customer.'%')
                            ->orWhere('name', 'like', '%'.$customer.'%')
                            ->orWhere('first_name', 'like', '%'.$customer.'%')
                            ->orWhere('last_name', 'like', '%'.$customer.'%');
                    });
            });
        }

        $sort = (string) $request->query('sort', 'id');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowed = ['id', 'created_at', 'notified_at', 'email', 'product_id', 'customer_id'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'id';
        }
        $query->orderBy($sort, $dir);

        return $query->paginate(max(5, min(100, (int) $request->query('per_page', 20))))->withQueryString();
    }

    /**
     * When availability flips unavailable → available, notify pending subscribers for that target.
     * Idempotent while already available (no emails). Safe under concurrent stock updates.
     */
    public function handleAvailabilityTransition(Product $product, bool $wasAvailable, ?int $variantId = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $variantKey = $this->normalizeVariantId($variantId);
        $isAvailable = $this->targetIsInStock($product, $variantKey > 0 ? $variantKey : null);
        if ($wasAvailable || ! $isAvailable) {
            return 0;
        }

        return $this->notifyPendingFor($product, $variantKey > 0 ? $variantKey : null);
    }

    /**
     * Send back-in-stock emails for pending subscriptions on one product/variant.
     * Each row is locked so concurrent stock updates cannot double-send.
     */
    public function notifyPendingFor(Product $product, ?int $variantId = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $variantKey = $this->normalizeVariantId($variantId);
        if (! $this->targetIsInStock($product, $variantKey > 0 ? $variantKey : null)) {
            return 0;
        }

        $ids = CustomerStockAlert::query()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variantKey)
            ->whereNull('notified_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sent = 0;
        foreach ($ids as $alertId) {
            if ($this->deliverOne($alertId)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Admin/manual resend for one subscription (requires product/variant available).
     */
    public function resend(int $alertId): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->deliverOne($alertId, true);
    }

    /**
     * @param  list<int>  $alertIds
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function resendMany(array $alertIds): array
    {
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($this->normalizeIds($alertIds) as $alertId) {
            $row = CustomerStockAlert::query()->with(['product', 'variant'])->find($alertId);
            if (! $row || ! $row->product) {
                $skipped++;

                continue;
            }
            $variantId = (int) $row->product_variant_id;
            if (! $this->targetIsInStock($row->product, $variantId > 0 ? $variantId : null)) {
                $skipped++;

                continue;
            }
            if ($this->deliverOne($alertId, true)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return compact('sent', 'skipped', 'failed');
    }

    /**
     * @param  list<int>  $alertIds
     */
    public function deleteMany(array $alertIds): int
    {
        $ids = $this->normalizeIds($alertIds);
        if ($ids === []) {
            return 0;
        }

        return (int) CustomerStockAlert::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Deliver a single alert under a row lock. Returns false if skipped or mail failed.
     * When $allowResend is false, only pending (notified_at null) rows are sent.
     */
    protected function deliverOne(int $alertId, bool $allowResend = false): bool
    {
        return (bool) DB::transaction(function () use ($alertId, $allowResend) {
            /** @var CustomerStockAlert|null $row */
            $row = CustomerStockAlert::query()
                ->whereKey($alertId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return false;
            }
            if (! $allowResend && $row->notified_at !== null) {
                return false;
            }

            $product = Product::query()->with('translations')->find($row->product_id);
            if (! $product) {
                return false;
            }

            $variantId = (int) $row->product_variant_id;
            if (! $this->targetIsInStock($product, $variantId > 0 ? $variantId : null)) {
                return false;
            }

            $row->loadMissing(['variant', 'customer']);
            $email = trim((string) ($row->email ?: $row->customer?->email));
            if ($email === '') {
                return false;
            }

            $name = $product->translation()?->name ?: $product->sku;
            if ($variantId > 0 && $row->variant) {
                $name .= ' ('.($row->variant->sku ?: '#'.$variantId).')';
            }
            $slug = (string) ($product->translation()?->slug ?: 'product/'.$product->id);
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($slug, '/');
            if ($variantId > 0) {
                $url .= (str_contains($url, '?') ? '&' : '?').'ipa='.$variantId;
            }
            $imageUrl = null;
            $cover = (string) ($product->cover_path ?: '');
            if ($cover !== '') {
                $imageUrl = str_starts_with($cover, 'http')
                    ? $cover
                    : rtrim((string) config('app.url'), '/').'/storage/'.ltrim($cover, '/');
            }

            try {
                app(ShopMailer::class)->backInStock($email, $name, $url, $imageUrl);
                $row->update(['notified_at' => now()]);

                return true;
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        });
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    protected function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRow(CustomerStockAlert $row, string $locale = 'en'): array
    {
        $product = $row->product;
        $variantId = (int) $row->product_variant_id;
        $inStock = $product ? $this->targetIsInStock($product, $variantId > 0 ? $variantId : null) : false;

        $guestName = trim(((string) $row->first_name).' '.((string) $row->last_name));
        $productUrl = null;
        if ($product) {
            $path = app(UrlRewriteService::class)->pathFor('product', (int) $product->id, $locale)
                ?: $product->translation($locale)?->slug;
            if ($path) {
                $productUrl = '/'.ltrim((string) $path, '/');
                if ($variantId > 0) {
                    $productUrl .= (str_contains($productUrl, '?') ? '&' : '?').'ipa='.$variantId;
                }
            }
        }

        $registered = $row->relationLoaded('customer') && $row->customer;
        $customerPayload = $registered ? [
            'id' => (int) $row->customer->id,
            'name' => $row->customer->displayName(),
            'email' => (string) ($row->email ?: $row->customer->email),
            'admin_url' => '/admin/customers/'.$row->customer->id,
            'is_guest' => false,
        ] : [
            'id' => null,
            'name' => $guestName !== '' ? $guestName : (string) $row->email,
            'email' => (string) $row->email,
            'admin_url' => null,
            'is_guest' => true,
        ];

        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'customer_id' => $row->customer_id,
            'product_variant_id' => $variantId > 0 ? $variantId : null,
            'email' => $row->email,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'is_guest' => $row->isGuest(),
            'status' => $row->statusLabel(),
            'subscribed_at' => $row->created_at?->toIso8601String(),
            'notified_at' => $row->notified_at?->toIso8601String(),
            'available' => $inStock,
            'product' => $product ? [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->translation($locale)?->name ?: $product->sku,
                'slug' => $product->translation($locale)?->slug,
                'url' => $productUrl,
                'cover_path' => $product->cover_path,
                'image' => $product->cover_path,
            ] : null,
            'variant' => $variantId > 0 && $row->variant ? [
                'id' => $row->variant->id,
                'sku' => $row->variant->sku,
                'label' => $row->variant->attributeLabel() ?: ($row->variant->sku ?: '#'.$variantId),
            ] : null,
            'customer' => $customerPayload,
        ];
    }

    protected function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        return $email;
    }

    protected function assertTargetOutOfStock(Product $product, int $variantId): void
    {
        if (! $this->targetIsOutOfStock($product, $variantId > 0 ? $variantId : null)) {
            throw ValidationException::withMessages([
                'product' => 'This product is already available for purchase.',
            ]);
        }
    }

    protected function assertVariantBelongsToProduct(Product $product, int $variantId): void
    {
        if ($variantId < 1) {
            return;
        }
        $exists = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereKey($variantId)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'The selected combination does not belong to this product.',
            ]);
        }
    }

    public function productIsInStock(Product $product): bool
    {
        if (! $product->manage_stock) {
            return $product->stock_status !== 'out_of_stock';
        }
        if ((float) $product->availableStock() > 0.009) {
            return true;
        }

        return in_array((string) $product->stock_status, ['in_stock', 'on_backorder'], true)
            && (bool) $product->allow_backorder;
    }

    public function variantIsInStock(ProductVariant $variant): bool
    {
        if (! $variant->manage_stock) {
            return $variant->stock_status !== 'out_of_stock';
        }
        if ((float) $variant->availableStock() > 0.009) {
            return true;
        }

        return in_array((string) $variant->stock_status, ['in_stock', 'on_backorder'], true)
            && (bool) $variant->allow_backorder;
    }
}
