<?php

namespace App\Domain\Catalog\Enums;

enum ProductType: string
{
    case Simple = 'simple';
    case Combination = 'combination';
    case Virtual = 'virtual';
    case Bundle = 'bundle';
    case External = 'external';
    /** @deprecated Unused stub. Sell by length/area via SaleUnit, not a type. */
    case Dimension = 'dimension';
    /** @deprecated Unused stub. Sell by litre via SaleUnit, not a type. */
    case Ltr = 'ltr';
    case Customizable = 'customizable';
    case Rental = 'rental';
    case Subscription = 'subscription';
    case Group = 'group';
    case GiftCard = 'gift_card';
    case Service = 'service';

    public function hasShipping(): bool
    {
        return ! in_array($this, [self::Virtual, self::GiftCard, self::Service], true);
    }

    public function defaultManageStock(): bool
    {
        return ! in_array($this, [self::Virtual, self::Group, self::Bundle, self::GiftCard, self::Service], true);
    }

    public function usesCatalogLayout(): bool
    {
        return match ($this) {
            self::Simple, self::Virtual, self::Group, self::Bundle, self::GiftCard, self::Service, self::Rental => true,
            default => false,
        };
    }

    public function isComposite(): bool
    {
        return $this === self::Group || $this === self::Bundle;
    }
}
