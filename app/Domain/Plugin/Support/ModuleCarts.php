<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allowlisted cart reads for abandoned-cart style extensions.
 * Never exposes session_id. Email only when the cart has a customer.
 */
class ModuleCarts
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Carts with items that have not been updated recently.
     *
     * @return list<array{
     *   id: int,
     *   customer_id: ?int,
     *   email: ?string,
     *   name: ?string,
     *   updated_at: string,
     *   item_count: int,
     *   meta: array<string, string>,
     *   items: list<array{product_id: int, qty: int, unit_price: float}>
     * }>
     */
    public function abandoned(int $olderThanMinutes = 60, int $limit = 50): array
    {
        $this->assertCanRead();
        $minutes = max(15, min(60 * 24 * 30, $olderThanMinutes));
        $limit = max(1, min(100, $limit));
        $cutoff = now()->subMinutes($minutes);

        return Cart::query()
            ->where('updated_at', '<', $cutoff)
            ->whereHas('items')
            ->with([
                'customer:id,email,name',
                'items:id,cart_id,product_id,qty,unit_price',
            ])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Cart $cart) => $this->serialize($cart))
            ->all();
    }

    /**
     * @return array{
     *   id: int,
     *   customer_id: ?int,
     *   email: ?string,
     *   name: ?string,
     *   updated_at: string,
     *   item_count: int,
     *   meta: array<string, string>,
     *   items: list<array{product_id: int, qty: int, unit_price: float}>
     * }|null
     */
    public function find(int $id): ?array
    {
        $this->assertCanRead();
        $cart = Cart::query()
            ->with([
                'customer:id,email,name',
                'items:id,cart_id,product_id,qty,unit_price',
            ])
            ->find($id);

        return $cart ? $this->serialize($cart) : null;
    }

    /**
     * Merge allowlisted scalar meta onto a cart (delivery date, option codes, …).
     *
     * @param  array<string, mixed>  $kv
     * @return array<string, string>
     */
    public function setMeta(int $cartId, array $kv): array
    {
        $this->assertCanWrite();
        $cart = Cart::query()->find($cartId);
        if (! $cart) {
            throw ValidationException::withMessages(['id' => 'Cart '.$cartId.' was not found.']);
        }

        $clean = $this->sanitizeMeta($kv);
        $merged = array_merge($this->metaOf($cart), $clean);
        if (count($merged) > 20) {
            $merged = array_slice($merged, 0, 20, true);
        }
        $cart->update(['meta' => $merged]);

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    public function meta(int $cartId): array
    {
        if ($this->manifest->can(Capabilities::CARTS_WRITE) || $this->manifest->can(Capabilities::CARTS_READ)) {
            $cart = Cart::query()->find($cartId);

            return $cart ? $this->metaOf($cart) : [];
        }

        throw new InvalidArgumentException('This app did not declare carts.read or carts.write.');
    }

    /**
     * @return array{
     *   id: int,
     *   customer_id: ?int,
     *   email: ?string,
     *   name: ?string,
     *   updated_at: string,
     *   item_count: int,
     *   meta: array<string, string>,
     *   items: list<array{product_id: int, qty: int, unit_price: float}>
     * }
     */
    protected function serialize(Cart $cart): array
    {
        $items = $cart->items->map(static fn ($item) => [
            'product_id' => (int) $item->product_id,
            'qty' => (int) $item->qty,
            'unit_price' => round((float) $item->unit_price, 2),
        ])->values()->all();

        return [
            'id' => (int) $cart->id,
            'customer_id' => $cart->customer_id !== null ? (int) $cart->customer_id : null,
            'email' => $cart->customer?->email,
            'name' => $cart->customer?->name,
            'updated_at' => $cart->updated_at?->toIso8601String() ?? '',
            'item_count' => count($items),
            'meta' => $this->metaOf($cart),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function metaOf(Cart $cart): array
    {
        $raw = is_array($cart->meta) ? $cart->meta : [];
        $out = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key) || ! is_scalar($value)) {
                continue;
            }
            $out[$key] = Str::limit(trim(strip_tags((string) $value)), 500, '');
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $kv
     * @return array<string, string>
     */
    protected function sanitizeMeta(array $kv): array
    {
        $clean = [];
        foreach ($kv as $key => $value) {
            if (count($clean) >= 20 || ! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $clean[$key] = $value === null ? '' : Str::limit(trim(strip_tags((string) $value)), 500, '');
        }

        return $clean;
    }

    protected function assertCanRead(): void
    {
        if (! $this->manifest->can(Capabilities::CARTS_READ)) {
            throw new InvalidArgumentException('This app did not declare carts.read.');
        }
    }

    protected function assertCanWrite(): void
    {
        if (! $this->manifest->can(Capabilities::CARTS_WRITE)) {
            throw new InvalidArgumentException('This app did not declare carts.write.');
        }
    }
}
