<?php

namespace App\Domain\Plugin;

/**
 * Permissions an app must declare in app.json. The merchant sees these
 * before enable. The kernel refuses APIs the app did not declare.
 */
final class Capabilities
{
    public const ADMIN_PAGE = 'admin.page';

    public const SETTINGS = 'settings';

    public const STOREFRONT_SLOT = 'storefront.slot';

    public const HOOK_LISTEN = 'hook.listen';

    public const ADMIN_MENU = 'admin.menu';

    public const ADMIN_PAGES = 'admin.pages';

    public const ADMIN_ASSETS = 'admin.assets';

    public const LIST_EXTEND = 'list.extend';

    public const VIEW_SLOT = 'view.slot';

    public const ACCOUNT_MENU = 'account.menu';

    public const STOREFRONT_PAGES = 'storefront.pages';

    public const CATALOG_READ = 'catalog.read';

    public const CMS_READ = 'cms.read';

    public const CATALOG_WRITE = 'catalog.write';

    public const CUSTOMERS_READ = 'customers.read';

    public const CUSTOMERS_WRITE = 'customers.write';

    public const MAIL_SEND = 'mail.send';

    public const CHECKOUT_ADJUST = 'checkout.adjust';

    public const CHECKOUT_PAYMENT_FILTER = 'checkout.payment.filter';

    public const CHECKOUT_SHIPPING_FILTER = 'checkout.shipping.filter';

    public const ORDERS_WRITE = 'orders.write';

    public const DOCUMENTS_PDF = 'documents.pdf';

    public const AUTH_LOGIN_RESOLVE = 'auth.login.resolve';

    public const AUTH_REGISTER = 'auth.register';

    public const CARTS_READ = 'carts.read';

    public const CARTS_WRITE = 'carts.write';

    public const SCHEDULE_TICK = 'schedule.tick';

    public const WISHLIST_READ = 'wishlist.read';

    public const WISHLIST_WRITE = 'wishlist.write';

    public const PAYMENT_DRIVER = 'payment.driver';

    public const SHIPPING_DRIVER = 'shipping.driver';

    public const HTTP_OUTBOUND = 'http.outbound';

    public const SCHEMA_TABLES = 'schema.tables';

    public const ORDERS_READ = 'orders.read';

    public const CHECKOUT_PAYMENT_UI = 'checkout.payment.ui';

    public const SECURITY_CSP = 'security.csp';

    /** Safe apps may only use these. */
    public const SAFE = [
        self::ADMIN_PAGE,
        self::SETTINGS,
        self::STOREFRONT_SLOT,
        self::HOOK_LISTEN,
        self::ADMIN_MENU,
        self::ADMIN_PAGES,
        self::ADMIN_ASSETS,
        self::LIST_EXTEND,
        self::VIEW_SLOT,
        self::ACCOUNT_MENU,
        self::STOREFRONT_PAGES,
        self::CATALOG_READ,
        self::CMS_READ,
        self::CATALOG_WRITE,
        self::CUSTOMERS_READ,
        self::CUSTOMERS_WRITE,
        self::MAIL_SEND,
        self::CHECKOUT_ADJUST,
        self::CHECKOUT_PAYMENT_FILTER,
        self::CHECKOUT_SHIPPING_FILTER,
        self::ORDERS_WRITE,
        self::DOCUMENTS_PDF,
        self::AUTH_LOGIN_RESOLVE,
        self::AUTH_REGISTER,
        self::CARTS_READ,
        self::CARTS_WRITE,
        self::SCHEDULE_TICK,
        self::WISHLIST_READ,
        self::WISHLIST_WRITE,
    ];

    /** Require marketplace review + type=certified. */
    public const CERTIFIED = [
        self::PAYMENT_DRIVER,
        self::SHIPPING_DRIVER,
        self::HTTP_OUTBOUND,
        self::SCHEMA_TABLES,
        self::ORDERS_READ,
        self::CHECKOUT_PAYMENT_UI,
        self::SECURITY_CSP,
    ];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::ADMIN_PAGE => 'Add an admin settings page (core renders the form)',
            self::SETTINGS => 'Store its own settings',
            self::STOREFRONT_SLOT => 'Show text in published storefront slots',
            self::HOOK_LISTEN => 'React to published shop events',
            self::ADMIN_MENU => 'Add a left-menu group for this app',
            self::ADMIN_PAGES => 'Host admin pages (core renders the page document)',
            self::ADMIN_ASSETS => 'Load CSS/JS on this app own admin pages only',
            self::LIST_EXTEND => 'Add columns to published admin lists',
            self::VIEW_SLOT => 'Add blocks on published admin view pages',
            self::ACCOUNT_MENU => 'Add items to the customer account menu',
            self::STOREFRONT_PAGES => 'Host storefront pages under /m/{vendor}/{name}',
            self::CATALOG_READ => 'Read catalog tables through the Module Query',
            self::CMS_READ => 'Read CMS pages, menus, URL rewrites, and languages through the Module Query',
            self::CATALOG_WRITE => 'Create, update, or delete simple products through core catalog APIs',
            self::CUSTOMERS_READ => 'Read customer tables through the Module Query',
            self::CUSTOMERS_WRITE => 'Assign customer groups, enable/disable accounts, and update profile fields',
            self::MAIL_SEND => 'Send email through the shop mailer using enabled templates',
            self::CHECKOUT_ADJUST => 'Add validated cart fees or due-today amounts at checkout',
            self::CHECKOUT_PAYMENT_FILTER => 'Hide payment methods at checkout by custom rules',
            self::CHECKOUT_SHIPPING_FILTER => 'Hide shipping methods at checkout by custom rules',
            self::ORDERS_WRITE => 'Record payments, fulfillment details, and assign orders to staff',
            self::DOCUMENTS_PDF => 'Generate PDF documents (and email them through the shop mailer)',
            self::AUTH_LOGIN_RESOLVE => 'Resolve username or alternate login to a customer email',
            self::AUTH_REGISTER => 'Configure registration (pending approval, extra handling after signup)',
            self::CARTS_READ => 'Read abandoned carts (customer email for reminders; never session ids)',
            self::CARTS_WRITE => 'Store small allowlisted key/value meta on a cart (delivery date, options)',
            self::SCHEDULE_TICK => 'Run timed work when the shop schedule tick fires (cron)',
            self::WISHLIST_READ => 'Read a customer wishlist product ids',
            self::WISHLIST_WRITE => 'Add or remove products on a customer wishlist',
            self::PAYMENT_DRIVER => 'Take payment (certified apps only)',
            self::SHIPPING_DRIVER => 'Quote shipping (certified apps only)',
            self::HTTP_OUTBOUND => 'Call listed remote URLs (certified apps only)',
            self::SCHEMA_TABLES => 'Create prefixed tables (certified apps only)',
            self::ORDERS_READ => 'Read order data (certified apps only)',
            self::CHECKOUT_PAYMENT_UI => 'Provide inline payment UI on checkout (certified apps only)',
            self::SECURITY_CSP => 'Declare browser CSP sources required by this extension (certified apps only)',
        ];
    }

    public static function isSafe(string $capability): bool
    {
        return in_array($capability, self::SAFE, true);
    }

    public static function isCertified(string $capability): bool
    {
        return in_array($capability, self::CERTIFIED, true);
    }

    public static function known(string $capability): bool
    {
        return self::isSafe($capability) || self::isCertified($capability);
    }
}
