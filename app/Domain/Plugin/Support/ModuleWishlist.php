<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allowlisted wishlist pivot access. Product ids only — no arbitrary tables.
 */
class ModuleWishlist
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * @return list<int>
     */
    public function idsFor(int $customerId): array
    {
        $this->assertCanRead();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return [];
        }

        return $customer->wishlist()->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function add(int $customerId, int $productId): void
    {
        $this->assertCanWrite();
        $customer = $this->requireCustomer($customerId);
        $product = Product::query()->where('is_enabled', true)->find($productId);
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => 'Product was not found or is disabled.']);
        }
        $customer->wishlist()->syncWithoutDetaching([(int) $product->id]);
    }

    public function remove(int $customerId, int $productId): void
    {
        $this->assertCanWrite();
        $customer = $this->requireCustomer($customerId);
        $customer->wishlist()->detach($productId);
    }

    public function clear(int $customerId): void
    {
        $this->assertCanWrite();
        $customer = $this->requireCustomer($customerId);
        $customer->wishlist()->detach();
    }

    protected function requireCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => 'Customer '.$customerId.' was not found.']);
        }

        return $customer;
    }

    protected function assertCanRead(): void
    {
        if (! $this->manifest->can(Capabilities::WISHLIST_READ) && ! $this->manifest->can(Capabilities::WISHLIST_WRITE)) {
            throw new InvalidArgumentException('This app did not declare wishlist.read or wishlist.write.');
        }
    }

    protected function assertCanWrite(): void
    {
        if (! $this->manifest->can(Capabilities::WISHLIST_WRITE)) {
            throw new InvalidArgumentException('This app did not declare wishlist.write.');
        }
    }
}
