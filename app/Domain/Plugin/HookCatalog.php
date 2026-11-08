<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\ActionCatalog;
use App\Domain\Plugin\ExtensionCatalog;

/**
 * Hooks community apps may listen to. Core may fire more internally.
 * Safe apps cannot subscribe to anything outside this list.
 */
final class HookCatalog
{
    public const STOREFRONT_HEADER_NOTICE = 'storefront.header.notice';

    public const STOREFRONT_PRODUCT_TABS = 'storefront.product.tabs';

    public const CATALOG_PRODUCT_SAVED = 'catalog.product.saved';

    public const CHECKOUT_CART_UPDATED = 'checkout.cart.updated';

    public const CHECKOUT_ORDER_PLACED = 'checkout.order.placed';

    public const PAYMENT_DRIVERS = 'payment.drivers';

    public const SHIPPING_CARRIERS = 'shipping.carriers';

    public const ADMIN_DASHBOARD_CARDS = 'admin.dashboard.cards';

    /** @return list<string> */
    public static function published(): array
    {
        return array_values(array_unique([
            self::STOREFRONT_HEADER_NOTICE,
            self::STOREFRONT_PRODUCT_TABS,
            self::CATALOG_PRODUCT_SAVED,
            self::CHECKOUT_CART_UPDATED,
            self::CHECKOUT_ORDER_PLACED,
            self::PAYMENT_DRIVERS,
            self::SHIPPING_CARRIERS,
            self::ADMIN_DASHBOARD_CARDS,
            ...array_map(fn (string $view) => 'view.'.$view, ExtensionCatalog::views()),
            ...ActionCatalog::hooks(),
        ]));
    }

    public static function allows(string $hook): bool
    {
        return in_array($hook, self::published(), true);
    }
}
