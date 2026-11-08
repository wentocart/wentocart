<?php

namespace App\Domain\Ops;

/**
 * Prebuilt connector API. Developers call these routes; they do not register their own.
 *
 * Extra relations are opt-in: ?include=images,categories
 * Sync: ?updated_since=2026-01-01T00:00:00Z&page=1&per_page=25
 */
final class WebserviceCatalog
{
    /**
     * @return list<array{path: string, methods: list<string>, scope: string, include: list<string>, filters: list<string>, hint: string, summary: string, group: string}>
     */
    public static function endpoints(): array
    {
        return [
            self::ep('GET', '/api/v1/ws', 'shop.read', [], [], 'Discovery: every endpoint, scope, and include key', 'Start here — lists everything the app is allowed to call'),
            self::ep('GET', '/api/v1/ws/shop', 'shop.read', ['countries'], [], 'Shop name, URL, languages, currencies', 'Shop name, languages, and currencies'),
            self::ep('GET', '/api/v1/ws/languages', 'shop.read', [], [], 'Enabled languages', 'Languages turned on for this shop'),
            self::ep('GET', '/api/v1/ws/currencies', 'shop.read', [], [], 'Enabled currencies', 'Currencies turned on for this shop'),
            self::ep('GET', '/api/v1/ws/countries', 'shop.read', ['regions'], ['code'], 'Countries for addresses', 'Countries used on addresses'),
            self::ep('GET', '/api/v1/ws/countries/{id}', 'shop.read', ['regions'], [], 'One country', 'One country — enter its ID below'),
            self::ep('GET', '/api/v1/ws/shipping-methods', 'shop.read', [], [], 'Public shipping methods (no carrier secrets)', 'Shipping methods customers can choose'),
            self::ep('GET', '/api/v1/ws/cms-pages', 'shop.read', ['body'], ['updated_since'], 'Published CMS pages', 'Published store pages'),
            self::ep('GET', '/api/v1/ws/cms-pages/{id}', 'shop.read', ['body'], [], 'One published CMS page', 'One store page — enter its ID below'),

            self::ep('GET', '/api/v1/ws/products', 'catalog.read', self::productIncludes(), ['sku', 'updated_since'], 'Products (no cost price)', 'Product list'),
            self::ep('POST', '/api/v1/ws/products', 'catalog.write', [], [], 'Create a simple product', 'Create a product'),
            self::ep('GET', '/api/v1/ws/products/{id}', 'catalog.read', self::productIncludes(), [], 'One product', 'One product — enter its ID below'),
            self::ep('PATCH', '/api/v1/ws/products/{id}', 'catalog.write', [], [], 'Update sku, name, price, stock', 'Update a product'),
            self::ep('DELETE', '/api/v1/ws/products/{id}', 'catalog.write', [], [], 'Delete a product', 'Delete a product'),
            self::ep('GET', '/api/v1/ws/stock', 'catalog.read', [], ['sku', 'updated_since'], 'Stock snapshot for ERP sync', 'Stock levels'),
            self::ep('GET', '/api/v1/ws/categories', 'catalog.read', ['children'], ['updated_since'], 'Categories', 'Categories'),
            self::ep('GET', '/api/v1/ws/categories/{id}', 'catalog.read', ['children'], [], 'One category', 'One category — enter its ID below'),
            self::ep('GET', '/api/v1/ws/brands', 'catalog.read', [], ['updated_since'], 'Brands', 'Brands'),
            self::ep('GET', '/api/v1/ws/brands/{id}', 'catalog.read', [], [], 'One brand', 'One brand — enter its ID below'),
            self::ep('GET', '/api/v1/ws/tags', 'catalog.read', [], [], 'Tags', 'Tags'),
            self::ep('GET', '/api/v1/ws/tags/{id}', 'catalog.read', [], [], 'One tag', 'One tag — enter its ID below'),
            self::ep('GET', '/api/v1/ws/suppliers', 'catalog.read', [], ['updated_since'], 'Suppliers (no wholesale prices)', 'Suppliers'),
            self::ep('GET', '/api/v1/ws/suppliers/{id}', 'catalog.read', [], [], 'One supplier', 'One supplier — enter its ID below'),
            self::ep('GET', '/api/v1/ws/features', 'catalog.read', ['values'], [], 'Features / attributes', 'Product features'),
            self::ep('GET', '/api/v1/ws/features/{id}', 'catalog.read', ['values'], [], 'One feature', 'One feature — enter its ID below'),

            self::ep('GET', '/api/v1/ws/orders', 'orders.read', ['customer'], ['status', 'number', 'updated_since'], 'Orders and line items', 'Order list'),
            self::ep('GET', '/api/v1/ws/orders/{id}', 'orders.read', ['customer'], [], 'One order', 'One order — enter its ID below'),
            self::ep('PATCH', '/api/v1/ws/orders/{id}/status', 'orders.write', [], [], 'Change order status through stages', 'Change an order status'),

            self::ep('GET', '/api/v1/ws/customers', 'customers.read', ['addresses'], ['email', 'updated_since'], 'Customers (no passwords)', 'Customer list'),
            self::ep('POST', '/api/v1/ws/customers', 'customers.write', [], [], 'Create a customer', 'Create a customer'),
            self::ep('GET', '/api/v1/ws/customers/{id}', 'customers.read', ['addresses'], [], 'One customer', 'One customer — enter their ID below'),
            self::ep('PATCH', '/api/v1/ws/customers/{id}', 'customers.write', [], [], 'Update a customer', 'Update a customer'),
            self::ep('GET', '/api/v1/ws/customers/{id}/addresses', 'customers.read', [], [], 'Addresses for one customer', 'Addresses for one customer — enter their ID below'),
            self::ep('GET', '/api/v1/ws/addresses', 'customers.read', [], ['customer_id'], 'All saved addresses', 'Saved addresses'),
            self::ep('GET', '/api/v1/ws/addresses/{id}', 'customers.read', [], [], 'One address', 'One address — enter its ID below'),
            self::ep('GET', '/api/v1/ws/customer-groups', 'customers.read', [], [], 'Customer groups', 'Customer groups'),

            self::ep('GET', '/api/v1/ws/webhooks', 'webhooks.manage', [], [], 'Outbound webhook endpoints', 'Webhook destinations you have saved'),
            self::ep('POST', '/api/v1/ws/webhooks', 'webhooks.manage', [], [], 'Register an HTTPS webhook', 'Add a webhook destination'),
            self::ep('DELETE', '/api/v1/ws/webhooks/{id}', 'webhooks.manage', [], [], 'Remove a webhook', 'Remove a webhook destination'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function productIncludes(): array
    {
        return ['images', 'categories', 'brand', 'tags', 'features', 'related', 'translations', 'suppliers'];
    }

    /**
     * @param  list<string>  $methods
     * @param  list<string>  $include
     * @param  list<string>  $filters
     * @return array{path: string, methods: list<string>, scope: string, include: list<string>, filters: list<string>, hint: string, summary: string, group: string}
     */
    protected static function ep(string $method, string $path, string $scope, array $include, array $filters, string $hint, string $summary = ''): array
    {
        return [
            'path' => $path,
            'methods' => [$method],
            'scope' => $scope,
            'include' => $include,
            'filters' => $filters,
            'hint' => $hint,
            'summary' => $summary !== '' ? $summary : $hint,
            'group' => self::groupForScope($scope),
        ];
    }

    public static function groupForScope(string $scope): string
    {
        return match (true) {
            str_starts_with($scope, 'catalog.') => 'Catalog',
            str_starts_with($scope, 'orders.') => 'Orders',
            str_starts_with($scope, 'customers.') => 'Customers',
            $scope === 'webhooks.manage' => 'Webhooks',
            default => 'Shop',
        };
    }
}
