<?php

namespace App\Domain\Auth;

/**
 * Admin pages that can be granted through a role. Family slugs such as
 * catalog.manage still unlock every page in that group so existing roles keep working.
 */
final class PageCatalog
{
    /**
     * @return list<array{heading: string, pages: list<array{slug: string, label: string, href: string, group: string, aliases: list<string>}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'heading' => 'Catalog',
                'pages' => [
                    self::page('catalog.products', 'Products', '/admin/products', 'Catalog', ['catalog.manage', 'catalog']),
                    self::page('catalog.categories', 'Categories', '/admin/categories', 'Catalog', ['catalog.manage']),
                    self::page('catalog.brands', 'Brands', '/admin/brands', 'Catalog', ['catalog.manage']),
                    self::page('catalog.suppliers', 'Suppliers', '/admin/suppliers', 'Catalog', ['catalog.manage']),
                    self::page('catalog.warehouses', 'Warehouses', '/admin/warehouses', 'Catalog', ['catalog.manage', 'catalog.products']),
                    self::page('catalog.inventory', 'Inventory', '/admin/inventory', 'Catalog', ['catalog.manage', 'catalog.products']),
                    self::page('catalog.purchase-orders', 'Purchase orders', '/admin/purchase-orders', 'Catalog', ['catalog.manage', 'catalog.products']),
                    self::page('catalog.gift-cards', 'Gift cards', '/admin/gift-cards', 'Catalog', ['catalog.manage']),
                    self::page('catalog.features', 'Features', '/admin/features', 'Catalog', ['catalog.manage']),
                    self::page('catalog.files', 'Files', '/admin/files', 'Catalog', ['catalog.manage']),
                ],
            ],
            [
                'heading' => 'Orders',
                'pages' => [
                    self::page('orders.manage', 'Orders', '/admin/orders', 'Orders'),
                    self::page('orders.stages', 'Order statuses', '/admin/order-stages', 'Orders', ['orders.manage']),
                    self::page('orders.documents', 'Invoices and slips', '/admin/invoices', 'Orders'),
                ],
            ],
            [
                'heading' => 'Customers',
                'pages' => [
                    self::page('customers.customers', 'Customers', '/admin/customers', 'Customers', ['customers.manage']),
                    self::page('customers.groups', 'Customer groups', '/admin/customer-groups', 'Customers', ['customers.manage']),
                    self::page('customers.addresses', 'Addresses', '/admin/addresses', 'Customers', ['customers.manage']),
                    self::page('customers.reviews', 'Reviews', '/admin/reviews', 'Customers', ['customers.manage']),
                    self::page('customers.questions', 'Questions', '/admin/questions', 'Customers', ['customers.manage']),
                    self::page('customers.support', 'Support', '/admin/support', 'Customers', ['customers.manage']),
                    self::page('customers.gdpr', 'GDPR Data Requests', '/admin/gdpr-requests', 'Customers', ['customers.manage']),
                ],
            ],
            [
                'heading' => 'Multivendor',
                'pages' => [
                    self::page('multivendor.dashboard', 'Marketplace dashboard', '/admin/multivendor/dashboard', 'Multivendor', ['multivendor.manage', 'multivendor.seller-requests']),
                    self::page('multivendor.seller-requests', 'Seller Request', '/admin/multivendor/seller-requests', 'Multivendor', ['multivendor.manage', 'customers.sellers', 'customers.manage']),
                    self::page('multivendor.sellers', 'Sellers', '/admin/multivendor/sellers', 'Multivendor', ['multivendor.manage', 'multivendor.seller-requests', 'customers.sellers']),
                    self::page('multivendor.seller-profiles', 'Seller Profile', '/admin/multivendor/seller-profiles', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.seller-shops', 'Seller Shop', '/admin/multivendor/seller-shops', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.products', 'Products', '/admin/multivendor/products', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.discounts', 'Discounts', '/admin/multivendor/discounts', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.categories', 'Category', '/admin/multivendor/categories', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.features', 'Features', '/admin/multivendor/features', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.brands', 'Brands', '/admin/multivendor/brands', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.suppliers', 'Suppliers', '/admin/multivendor/suppliers', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.orders', 'Seller Orders', '/admin/multivendor/orders', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.returns', 'Seller Returns', '/admin/multivendor/returns', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.earnings', 'Seller Earnings', '/admin/multivendor/earnings', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.ledger', 'Seller Ledger', '/admin/multivendor/ledger', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.commissions', 'Seller Commission', '/admin/multivendor/commissions', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.shipping', 'Seller Shipping', '/admin/multivendor/shipping', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.payments', 'Seller Payout Methods', '/admin/multivendor/payments', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.payouts', 'Seller Payouts', '/admin/multivendor/payouts', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.reviews', 'Seller Reviews', '/admin/multivendor/reviews', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.messages', 'Seller Messages', '/admin/multivendor/messages', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.settings', 'Marketplace settings', '/admin/multivendor/settings', 'Multivendor', ['multivendor.manage']),
                    self::page('multivendor.audits', 'Marketplace audit trail', '/admin/multivendor/audits', 'Multivendor', ['multivendor.manage']),
                ],
            ],
            [
                'heading' => 'Marketing',
                'pages' => [
                    self::page('marketing.discounts', 'Discounts', '/admin/discounts', 'Marketing'),
                    self::page('marketing.newsletter', 'Newsletter', '/admin/newsletter', 'Marketing'),
                ],
            ],
            [
                'heading' => 'Shipping',
                'pages' => [
                    self::page('shipping.manage', 'Shipping methods', '/admin/shipping', 'Shipping'),
                ],
            ],
            [
                'heading' => 'Payment',
                'pages' => [
                    self::page('payments.manage', 'Payment methods', '/admin/payments', 'Payments'),
                ],
            ],
            [
                'heading' => 'Design',
                'pages' => [
                    self::page('cms.pages', 'Pages', '/admin/cms', 'Design', ['cms.manage']),
                    self::page('cms.blocks', 'Blocks', '/admin/blocks', 'Design', ['cms.manage']),
                    self::page('design.themes', 'Themes', '/admin/themes', 'Design', ['cms.manage']),
                    self::page('apps.manage', 'Apps', '/admin/apps', 'Design', ['cms.manage']),
                ],
            ],
            [
                'heading' => 'International',
                'pages' => [
                    self::page('localization.manage', 'Localization', '/admin/localization', 'International', ['settings.manage']),
                    self::page('localization.countries', 'Countries', '/admin/localization/countries', 'International', ['settings.manage']),
                    self::page('localization.currencies', 'Currencies', '/admin/localization/currencies', 'International', ['settings.manage']),
                    self::page('localization.languages', 'Languages', '/admin/localization/languages', 'International', ['settings.manage']),
                    self::page('localization.translations', 'Translations', '/admin/localization/translations', 'International', ['settings.manage']),
                    self::page('taxes.manage', 'Taxes', '/admin/tax', 'International', ['settings.manage']),
                ],
            ],
            [
                'heading' => 'AI',
                'pages' => [
                    self::page('settings.ai', 'AI settings', '/admin/ai', 'AI', ['settings.manage']),
                    self::page('settings.ai.admin', 'Admin chats', '/admin/ai/logs', 'AI', ['settings.manage', 'settings.ai']),
                    self::page('settings.ai.customer', 'Customer chats', '/admin/ai/customer-chats', 'AI', ['settings.manage', 'settings.ai']),
                ],
            ],
            [
                'heading' => 'Configure',
                'pages' => [
                    self::page('settings.general', 'General configuration', '/admin/general', 'Configure', ['settings.manage', 'settings.store']),
                    self::page('settings.store', 'Store settings', '/admin/store', 'Configure', ['settings.manage']),
                    self::page('settings.shops', 'Multishop', '/admin/shops', 'Configure', ['settings.manage', 'settings.store']),
                    self::page('settings.products', 'Product settings', '/admin/product-settings', 'Configure', ['settings.manage']),
                    self::page('settings.orders', 'Order settings', '/admin/order-settings', 'Configure', ['settings.manage']),
                    self::page('settings.customers', 'Customers', '/admin/customer-settings', 'Configure', ['settings.manage']),
                    self::page('settings.cart', 'Cart and checkout', '/admin/order-settings?tab=cart', 'Configure', ['settings.manage', 'settings.orders']),
                    self::page('settings.inventory', 'Inventory settings', '/admin/product-settings?tab=inventory', 'Configure', ['settings.manage', 'settings.products']),
                    self::page('settings.pricing', 'Pricing', '/admin/product-settings?tab=pricing', 'Configure', ['settings.manage', 'settings.products']),
                    self::page('settings.returns', 'Returns and refunds', '/admin/order-settings?tab=returns', 'Configure', ['settings.manage', 'settings.orders']),
                    self::page('settings.search', 'Search and catalog', '/admin/product-settings?tab=search', 'Configure', ['settings.manage', 'settings.products']),
                    self::page('settings.reviews', 'Review settings', '/admin/customer-settings?tab=reviews', 'Configure', ['settings.manage', 'settings.customers']),
                    self::page('settings.document-rules', 'Invoices and documents', '/admin/order-settings?tab=invoices', 'Configure', ['settings.manage', 'settings.documents', 'settings.orders']),
                    self::page('settings.urls', 'URLs', '/admin/urls', 'Configure', ['settings.manage']),
                    self::page('settings.seo', 'SEO', '/admin/seo', 'Configure', ['settings.manage']),
                    self::page('settings.gdpr', 'GDPR', '/admin/gdpr', 'Configure', ['settings.manage']),
                    self::page('settings.images', 'Images', '/admin/images', 'Configure', ['settings.manage']),
                    self::page('settings.performance', 'Performance', '/admin/performance', 'Configure', ['settings.manage']),
                    self::page('settings.email', 'Email', '/admin/email', 'Configure', ['settings.manage']),
                    self::page('settings.documents', 'Document designs', '/admin/documents', 'Configure', ['settings.manage']),
                    self::page('settings.advanced', 'Advanced', '/admin/advanced', 'Configure', ['settings.manage']),
                ],
            ],
            [
                'heading' => 'Operate',
                'pages' => [
                    self::page('ops.activity', 'Activity', '/admin/activity', 'Operate', ['settings.manage']),
                    self::page('ops.operator', 'AI operator', '/admin/operator', 'Operate', ['settings.manage', 'settings.ai'], true),
                    self::page('ops.health', 'Store health', '/admin/health', 'Operate', ['settings.manage']),
                    self::page('ops.automations', 'Automations', '/admin/automations', 'Operate', ['settings.manage'], true),
                    self::page('ops.import', 'Import studio', '/admin/import-studio', 'Operate', ['settings.manage', 'catalog.manage'], true),
                    self::page('ops.tools', 'Webhooks', '/admin/ops-tools', 'Operate', ['settings.manage']),
                ],
            ],
            [
                'heading' => 'Dashboard',
                'pages' => [
                    self::page('dashboards.manage', 'Manage dashboards', '/admin/dashboards', 'Dashboard'),
                ],
            ],
        ];
    }

    /**
     * @return list<array{slug: string, label: string, href: string, group: string, aliases: list<string>}>
     */
    public static function pages(): array
    {
        return collect(self::groups())->flatMap(fn (array $group) => $group['pages'])->values()->all();
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::pages(), 'slug');
    }

    /**
     * Expand coarse family permissions into the page slugs they unlock.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function expand(array $granted): array
    {
        $set = [];
        foreach ($granted as $slug) {
            $set[$slug] = true;
        }

        do {
            $changed = false;
            foreach (self::pages() as $page) {
                if (isset($set[$page['slug']])) {
                    foreach ($page['aliases'] as $alias) {
                        if (! str_ends_with($alias, '.manage') && ! isset($set[$alias])) {
                            $set[$alias] = true;
                            $changed = true;
                        }
                    }
                }
                foreach ($page['aliases'] as $alias) {
                    if (isset($set[$alias]) && ! isset($set[$page['slug']])) {
                        $set[$page['slug']] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return array_keys($set);
    }

    /**
     * @param  list<string>  $granted
     */
    public static function grants(array $granted, string $slug): bool
    {
        return in_array($slug, self::expand($granted), true);
    }

    /**
     * @return list<string>
     */
    public static function familySlugs(): array
    {
        return ['catalog.manage', 'customers.manage', 'cms.manage', 'settings.manage', 'multivendor.manage'];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function seedPermissions(): array
    {
        $rows = [
            'catalog.manage' => ['Catalog', 'All catalog pages'],
            'customers.manage' => ['Customers', 'All customer pages'],
            'cms.manage' => ['Design', 'All CMS pages'],
            'settings.manage' => ['Configure', 'All settings pages'],
            'multivendor.manage' => ['Multivendor', 'All marketplace pages'],
            'reports.view' => ['Reports', 'View reports'],
        ];

        foreach (self::pages() as $page) {
            $rows[$page['slug']] = [$page['group'], $page['label']];
        }

        return $rows;
    }

    /**
     * Groups shown in nav, command palette, and staff page pickers.
     * Hidden pages stay in pages() so existing ACL and direct URLs still work.
     *
     * @return list<array{heading: string, pages: list<array{slug: string, label: string, href: string, group: string, aliases: list<string>}>}>
     */
    public static function navGroups(): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            $pages = array_values(array_filter($group['pages'], fn (array $page) => empty($page['hidden'])));
            if ($pages === []) {
                continue;
            }
            $out[] = [
                'heading' => $group['heading'],
                'heading_key' => self::headingKey($group['heading']),
                'pages' => $pages,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $aliases
     * @return array{slug: string, label: string, label_key: string, href: string, group: string, group_key: string, aliases: list<string>, hidden: bool}
     */
    private static function page(string $slug, string $label, string $href, string $group, array $aliases = [], bool $hidden = false): array
    {
        return [
            'slug' => $slug,
            'label' => $label,
            'label_key' => 'admin.pages.'.$slug,
            'href' => $href,
            'group' => $group,
            'group_key' => self::groupKey($group),
            'aliases' => $aliases,
            'hidden' => $hidden,
        ];
    }

    public static function groupKey(string $group): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($group)) ?: 'group');

        return 'admin.pages.group.'.$slug;
    }

    public static function headingKey(string $heading): string
    {
        return self::groupKey($heading);
    }
}

