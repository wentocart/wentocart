<?php

namespace App\Domain\Plugin;

/**
 * Lists, view slots, and storefront slots apps may extend.
 * Unknown ids are refused so a module cannot patch arbitrary SQL or HTML.
 */
final class ExtensionCatalog
{
    /** @return array<string, array{table: string, joins: array<string, array<string, string>>, selects: list<string>}> */
    public static function lists(): array
    {
        return [
            'catalog.products' => [
                'table' => 'products',
                'joins' => [
                    'suppliers' => [
                        'table' => 'supplier_translations as suppliers',
                        'first' => 'products.supplier_id',
                        'second' => 'suppliers.supplier_id',
                        'type' => 'left',
                        'wheres' => ['suppliers.language_code' => '@locale'],
                    ],
                    'brands' => [
                        'table' => 'brand_translations as brands',
                        'first' => 'products.brand_id',
                        'second' => 'brands.brand_id',
                        'type' => 'left',
                        'wheres' => ['brands.language_code' => '@locale'],
                    ],
                ],
                'selects' => ['suppliers.name', 'brands.name'],
            ],
            'orders.orders' => [
                'table' => 'orders',
                'joins' => [
                    'customers' => [
                        'table' => 'customers',
                        'first' => 'orders.customer_id',
                        'second' => 'customers.id',
                        'type' => 'left',
                    ],
                    'assignee' => [
                        'table' => 'admins as assignee',
                        'first' => 'orders.assigned_admin_id',
                        'second' => 'assignee.id',
                        'type' => 'left',
                    ],
                ],
                'selects' => ['customers.name', 'customers.email', 'assignee.name', 'assignee.email'],
            ],
            'customers.customers' => [
                'table' => 'customers',
                'joins' => [
                    'customer_groups' => [
                        'table' => 'customer_groups',
                        'first' => 'customers.customer_group_id',
                        'second' => 'customer_groups.id',
                        'type' => 'left',
                    ],
                ],
                'selects' => ['customers.notes', 'customer_groups.name'],
            ],
        ];
    }

    /** @return list<string> */
    public static function views(): array
    {
        return [
            'admin.product.edit',
            'admin.order.show',
            'admin.order.show.after_payment',
            'admin.order.actions',
            'admin.customer.show',
            'storefront.header.top',
            'storefront.header.notice',
            'storefront.home.body',
            'storefront.footer',
            'storefront.product.actions',
            'storefront.product.after',
            'storefront.product.tabs',
            'storefront.product.below_trust',
            'storefront.product.buybox',
            'storefront.category.after',
            'storefront.account.menu',
            'storefront.register.fields',
            'storefront.cart.sidebar',
            'storefront.cart.line',
            'storefront.order.detail',
            'storefront.checkout.payment',
            'storefront.checkout.shipping',
            'storefront.checkout.delivery',
            'storefront.checkout.sidebar',
        ];
    }

    public static function hasList(string $id): bool
    {
        return isset(self::lists()[$id]);
    }

    public static function hasView(string $id): bool
    {
        return in_array($id, self::views(), true);
    }
}
