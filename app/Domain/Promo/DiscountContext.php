<?php

namespace App\Domain\Promo;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;

class DiscountContext
{
    public function __construct(
        public ?Product $product = null,
        public float $qty = 1,
        public ?Customer $customer = null,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
        public ?string $currencyCode = null,
        public float $cartSubtotal = 0,
    ) {}

    public static function current(
        ?Customer $customer = null,
        ?string $countryCode = null,
        ?string $regionCode = null,
        ?string $currencyCode = null,
        float $cartSubtotal = 0,
    ): self {
        $resolved = $customer ?? auth('customer')->user();
        $resolved?->loadMissing('group');

        return new self(
            customer: $resolved,
            countryCode: $countryCode,
            regionCode: $regionCode,
            currencyCode: $currencyCode ?: Money::defaultCode(),
            cartSubtotal: $cartSubtotal,
        );
    }

    public static function guest(?string $currencyCode = null): self
    {
        return new self(currencyCode: $currencyCode ?: Money::defaultCode());
    }

    public function forProduct(Product $product, float $qty = 1): self
    {
        $copy = clone $this;
        $copy->product = $product;
        $copy->qty = max(0, $qty);

        return $copy;
    }
}
