<?php

namespace App\Domain\Api\OpenApi;

/**
 * OpenAPI 3 document for the admin REST API under /api/v1/console/*.
 */
final class AdminOpenApiDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');

        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'WentoCart Admin API',
                'description' => 'Admin REST under `/api/v1/console/*`. Authenticate with Integration Bearer `Authorization: Bearer <id>|<token>` (no storefront key). Paths include curated docs plus live route discovery.',
                'version' => '1.1.140',
            ],
            'servers' => [
                ['url' => $base.'/api/v1', 'description' => 'Wento API v1'],
            ],
            'tags' => [
                ['name' => 'Auth & Settings', 'description' => 'Integrations, storefront keys, module'],
                ['name' => 'Dashboard', 'description' => 'Dashboard, menu, profile'],
                ['name' => 'Order Composer', 'description' => 'Composer drafts → place order'],
                ['name' => 'Catalog', 'description' => 'Products and catalog helpers'],
                ['name' => 'Sales', 'description' => 'Orders, invoices, shipments, refunds, RMA'],
                ['name' => 'Customers', 'description' => 'Customers, notes, impersonate, GDPR'],
                ['name' => 'Reporting', 'description' => 'Stats and CSV exports'],
                ['name' => 'Marketing', 'description' => 'Campaigns, cart/catalog discount rules'],
                ['name' => 'Discovered Admin', 'description' => 'Auto-merged from live Laravel routes'],
            ],
            'components' => [
                'securitySchemes' => [
                    'integrationBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'id|token',
                        'description' => 'Admin Integration token from Settings → Integrations',
                    ],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'paths' => self::paths(),
        ];

        return RouteOpenApiEnricher::enrich($doc, 'admin');
    }

    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        $paths = [];
        $sec = ['security' => [['integrationBearer' => []]]];

        self::add($paths, '/console/dashboard/stats', 'get', 'Dashboard', 'Dashboard stats', [
            'responses' => ['200' => ['description' => 'Stats payload']],
            ...$sec,
        ]);
        self::add($paths, '/console/menu', 'get', 'Dashboard', 'Admin navigation menu', [
            'responses' => ['200' => ['description' => 'Menu tree']],
            ...$sec,
        ]);
        self::add($paths, '/console/permissions', 'get', 'Dashboard', 'Admin permissions', [
            'responses' => ['200' => ['description' => 'Permission list']],
            ...$sec,
        ]);
        self::add($paths, '/console/profile', 'get', 'Dashboard', 'Admin profile', [
            'responses' => ['200' => ['description' => 'Current admin profile']],
            ...$sec,
        ]);

        self::add($paths, '/console/settings/integrations', 'get', 'Auth & Settings', 'List integration tokens', [
            'responses' => ['200' => ['description' => 'Integrations']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/integrations', 'post', 'Auth & Settings', 'Create integration token', [
            'responses' => ['201' => ['description' => 'Created (plaintext token once)']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/integrations/{id}', 'get', 'Auth & Settings', 'Get integration', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Integration']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/integrations/{id}/regenerate', 'post', 'Auth & Settings', 'Regenerate token', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'New plaintext token']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/integrations/history', 'get', 'Auth & Settings', 'Integration API audit history', [
            'responses' => ['200' => ['description' => 'Audit rows']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/storefront-keys', 'get', 'Auth & Settings', 'List storefront keys', [
            'responses' => ['200' => ['description' => 'Keys']],
            ...$sec,
        ]);
        self::add($paths, '/console/settings/storefront-keys', 'post', 'Auth & Settings', 'Create storefront key', [
            'responses' => ['201' => ['description' => 'Created']],
            ...$sec,
        ]);

        self::add($paths, '/console/customers/{customerId}/order-composer/drafts', 'post', 'Order Composer', 'Create composer draft', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['201' => ['description' => 'Composer draft cartId']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/items', 'post', 'Order Composer', 'Add composer draft item', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Updated cart']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/addresses', 'post', 'Order Composer', 'Set composer draft addresses', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Saved']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/shipping-methods', 'get', 'Order Composer', 'List shipping methods', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Rates']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/shipping-methods', 'post', 'Order Composer', 'Set shipping method', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Saved']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/payment-methods', 'get', 'Order Composer', 'List payment methods', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Methods']],
            ...$sec,
        ]);
        self::add($paths, '/console/carts/{cartId}/payment-methods', 'post', 'Order Composer', 'Set payment method', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['200' => ['description' => 'Saved']],
            ...$sec,
        ]);
        self::add($paths, '/console/orders/place/{cartId}', 'post', 'Order Composer', 'Place order from composer draft', [
            'parameters' => [self::pathParam('cartId')],
            'responses' => ['201' => ['description' => 'Order placed'], '422' => ['description' => 'Validation']],
            ...$sec,
        ]);
        self::add($paths, '/console/orders/{id}/reorder', 'post', 'Order Composer', 'Reorder into new composer draft', [
            'parameters' => [self::pathId()],
            'responses' => ['201' => ['description' => 'New composer draft cartId']],
            ...$sec,
        ]);
        self::add($paths, '/console/order-composer/products', 'get', 'Order Composer', 'Slim product search for order composer', [
            'parameters' => [
                self::query('query', 'Search'),
                self::query('sku', 'SKU filter'),
                self::query('per_page', 'Page size', 'integer'),
            ],
            'responses' => ['200' => ['description' => '{ data, meta }']],
            ...$sec,
        ]);

        self::add($paths, '/console/customers/{customerId}/impersonate', 'post', 'Customers', 'Login as customer (impersonate)', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['201' => ['description' => 'Customer Bearer once']],
            ...$sec,
        ]);
        self::add($paths, '/console/customers/{customerId}/notes', 'get', 'Customers', 'List customer notes', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['200' => ['description' => 'Notes']],
            ...$sec,
        ]);
        self::add($paths, '/console/customers/{customerId}/notes', 'post', 'Customers', 'Add customer note', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['201' => ['description' => 'Created']],
            ...$sec,
        ]);
        self::add($paths, '/console/customers/{customerId}/gdpr-download-data', 'post', 'Customers', 'GDPR data download', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['200' => ['description' => 'Export payload']],
            ...$sec,
        ]);
        self::add($paths, '/console/customers/{customerId}/addresses', 'get', 'Customers', 'Customer address book', [
            'parameters' => [self::pathParam('customerId')],
            'responses' => ['200' => ['description' => 'Addresses']],
            ...$sec,
        ]);
        self::add($paths, '/console/customers/export', 'get', 'Customers', 'Export customers CSV', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);

        self::add($paths, '/console/orders/export', 'get', 'Sales', 'Export orders CSV', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);
        self::add($paths, '/console/invoices/export', 'get', 'Sales', 'Export invoices CSV', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);
        self::add($paths, '/console/refunds/export', 'get', 'Sales', 'Export refunds CSV', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);
        self::add($paths, '/console/rma/requests', 'get', 'Sales', 'List RMA requests', [
            'responses' => ['200' => ['description' => 'RMA list']],
            ...$sec,
        ]);
        self::add($paths, '/console/rma/reasons', 'get', 'Sales', 'List RMA reasons', [
            'responses' => ['200' => ['description' => 'Reasons']],
            ...$sec,
        ]);

        self::add($paths, '/console/products/export', 'get', 'Catalog', 'Export catalog products CSV', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);

        self::add($paths, '/console/reporting/stats', 'get', 'Reporting', 'Reporting overview stats', [
            'responses' => ['200' => ['description' => 'Overview']],
            ...$sec,
        ]);
        self::add($paths, '/console/metrics/sales', 'get', 'Reporting', 'Sales reporting', [
            'responses' => ['200' => ['description' => 'Sales series']],
            ...$sec,
        ]);
        self::add($paths, '/console/metrics/customers', 'get', 'Reporting', 'Customers reporting', [
            'responses' => ['200' => ['description' => 'Customer series']],
            ...$sec,
        ]);
        self::add($paths, '/console/metrics/products', 'get', 'Reporting', 'Products reporting', [
            'responses' => ['200' => ['description' => 'Product series']],
            ...$sec,
        ]);
        self::add($paths, '/console/reporting/export', 'get', 'Reporting', 'Reporting CSV export', [
            'responses' => ['200' => ['description' => 'text/csv']],
            ...$sec,
        ]);

        self::add($paths, '/console/marketing/campaigns', 'get', 'Marketing', 'List campaigns', [
            'responses' => ['200' => ['description' => 'Campaigns']],
            ...$sec,
        ]);
        self::add($paths, '/console/marketing/discount-rules/cart', 'get', 'Marketing', 'List cart discount rules', [
            'responses' => ['200' => ['description' => 'Cart discount rules']],
            ...$sec,
        ]);
        self::add($paths, '/console/marketing/discount-rules/catalog', 'get', 'Marketing', 'List catalog discount rules', [
            'responses' => ['200' => ['description' => 'Catalog discount rules']],
            ...$sec,
        ]);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @param  array<string, mixed>  $operation
     */
    private static function add(array &$paths, string $path, string $method, string $tag, string $summary, array $operation): void
    {
        $paths[$path] ??= [];
        $paths[$path][$method] = array_merge([
            'tags' => [$tag],
            'summary' => $summary,
            'operationId' => str_replace(['/', '{', '}'], ['_', '', ''], $method.'_'.ltrim($path, '/')),
        ], $operation);
    }

    /** @return array<string, mixed> */
    private static function pathId(): array
    {
        return self::pathParam('id');
    }

    /** @return array<string, mixed> */
    private static function pathParam(string $name): array
    {
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ];
    }

    /** @return array<string, mixed> */
    private static function query(string $name, string $description, string $type = 'string'): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => ['type' => $type],
        ];
    }
}
