<?php

namespace App\Domain\Api\OpenApi;

/**
 * OpenAPI 3 document for the shop REST API under /api/v1/store/*.
 */
final class ShopOpenApiDocument
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
                'title' => 'WentoCart Shop API',
                'description' => 'Wento storefront REST under `/api/v1/store/*`. Send `X-STOREFRONT-KEY` when required; customer routes need `Authorization: Bearer <token>` from `POST /store/customer/login`. Paths include curated docs plus live route discovery.',
                'version' => '1.1.140',
            ],
            'servers' => [
                ['url' => $base.'/api/v1', 'description' => 'Wento API v1'],
            ],
            'tags' => [
                ['name' => 'Auth', 'description' => 'Customer login / register / logout / profile'],
                ['name' => 'Catalog', 'description' => 'Products, categories, attributes'],
                ['name' => 'Cart', 'description' => 'shop cart'],
                ['name' => 'Checkout', 'description' => 'Addresses, shipping, payment, place order'],
                ['name' => 'Account', 'description' => 'Orders, invoices, shipments, addresses, wishlists'],
                ['name' => 'Content', 'description' => 'Theme, sections, contact, newsletters'],
                ['name' => 'Discovered Shop', 'description' => 'Auto-merged from live Laravel routes'],
            ],
            'components' => [
                'securitySchemes' => [
                    'storefrontKey' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-STOREFRONT-KEY',
                        'description' => 'Storefront API key (when required by shop settings)',
                    ],
                    'customerBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum id|token',
                        'description' => 'Customer token from login/register, or guest cart UUID',
                    ],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'success' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
            'paths' => self::paths(),
        ];

        return RouteOpenApiEnricher::enrich($doc, 'shop');
    }

    /**
     * @return array<string, mixed>
     */
    private static function paths(): array
    {
        $paths = [];

        self::add($paths, '/store/customer/login', 'post', 'Auth', 'Customer login', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['email', 'password'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'format' => 'password'],
                ],
            ]),
            'responses' => [
                '201' => ['description' => 'Logged in — returns `token`'],
                '401' => ['description' => 'Invalid credentials'],
            ],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/customers', 'post', 'Auth', 'Register customer', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['firstName', 'lastName', 'email', 'password', 'confirmPassword'],
                'properties' => [
                    'firstName' => ['type' => 'string'],
                    'lastName' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string'],
                    'confirmPassword' => ['type' => 'string'],
                    'subscribedToNewsLetter' => ['type' => 'boolean'],
                ],
            ]),
            'responses' => ['201' => ['description' => 'Created + token']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/customer/logout', 'post', 'Auth', 'Customer logout', [
            'responses' => ['200' => ['description' => 'Token revoked']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-profile', 'get', 'Auth', 'Get customer profile', [
            'responses' => ['200' => ['description' => 'Array of one customer object']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-profile-updates/{id}', 'put', 'Auth', 'Update customer profile', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Updated profile array'], '404' => ['description' => 'Not found']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-profile-deletes/{id}', 'post', 'Auth', 'Delete customer profile (anonymize + revoke tokens)', [
            'parameters' => [self::pathId()],
            'requestBody' => self::jsonBody(['type' => 'object']),
            'responses' => ['201' => ['description' => 'JSON null body'], '403' => ['description' => 'Disabled or unauthenticated'], '404' => ['description' => 'Wrong id']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/verify-tokens', 'post', 'Auth', 'Verify customer Bearer (always 201; use isValid)', [
            'responses' => ['201' => ['description' => 'isValid true|false + profile fields']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/unsubscribe-newsletters', 'post', 'Content', 'Unsubscribe newsletter (REST beat)', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string'],
                    'customerEmail' => ['type' => 'string'],
                ],
            ]),
            'responses' => ['200' => ['description' => 'Unsubscribed']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/forgot-passwords', 'post', 'Auth', 'Request password reset email', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['email'],
                'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
            ]),
            'responses' => ['200' => ['description' => 'Always success (anti-enumeration)']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/reset-passwords', 'post', 'Auth', 'Reset password with token (headless beat)', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['email', 'token', 'password'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'token' => ['type' => 'string'],
                    'password' => ['type' => 'string'],
                    'password_confirmation' => ['type' => 'string'],
                ],
            ]),
            'responses' => ['200' => ['description' => 'Password updated']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/cms-pages', 'get', 'CMS', 'List published CMS pages', [
            'parameters' => [
                self::query('page', 'Page', 'integer'),
                self::query('limit', 'Per page', 'integer'),
            ],
            'responses' => ['200' => ['description' => 'Bare array + X-Total-Count headers']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/cms-pages/{id}', 'get', 'CMS', 'Get CMS page by id or slug', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Page + translation'], '404' => ['description' => 'Not found']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/pages', 'get', 'CMS', 'List CMS pages (IRI alias)', [
            'responses' => ['200' => ['description' => 'Bare array']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/pages/{id}', 'get', 'CMS', 'Get CMS page (IRI alias)', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Page'], '404' => ['description' => 'Not found']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/cart-tokens', 'post', 'Cart', 'Create cart / cart token', [
            'requestBody' => self::jsonBody(['type' => 'object']),
            'responses' => ['201' => ['description' => 'Cart with cartToken']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/merge-carts', 'post', 'Cart', 'Merge guest cart into customer cart', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'properties' => [
                    'cartId' => ['type' => 'integer'],
                    'cartToken' => ['type' => 'string'],
                ],
            ]),
            'responses' => ['201' => ['description' => 'Merged cart'], '404' => ['description' => 'Guest cart missing']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/home-modules', 'get', 'Theme', 'List home modules', [
            'parameters' => [self::query('type', 'Block type filter')],
            'responses' => ['200' => ['description' => 'Published blocks']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/products', 'get', 'Catalog', 'List products', [
            'parameters' => [
                self::query('q', 'Search query'),
                self::query('category_id', 'Category id', 'integer'),
                self::query('page', 'Page', 'integer'),
            ],
            'responses' => ['200' => ['description' => 'Product list']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/products/{id}', 'get', 'Catalog', 'Get product', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Product'], '404' => ['description' => 'Not found']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/categories', 'get', 'Catalog', 'List categories', [
            'responses' => ['200' => ['description' => 'Categories']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/category-trees', 'get', 'Catalog', 'Category trees', [
            'parameters' => [self::query('parentId', 'Parent id', 'integer')],
            'responses' => ['200' => ['description' => 'Nested tree']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/attributes', 'get', 'Catalog', 'List attributes', [
            'responses' => ['200' => ['description' => 'Attributes']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/cart', 'post', 'Cart', 'Get cart', [
            'responses' => ['200' => ['description' => 'cart object'], '404' => ['description' => 'Empty']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/add-product-in-cart', 'post', 'Cart', 'Add product to cart', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['productId'],
                'properties' => [
                    'productId' => ['type' => 'integer'],
                    'quantity' => ['type' => 'number'],
                ],
            ]),
            'responses' => ['200' => ['description' => 'Updated cart']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/update-cart-item', 'post', 'Cart', 'Update cart line qty ', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['cartItemId', 'quantity'],
                'properties' => [
                    'cartItemId' => ['type' => 'integer'],
                    'quantity' => ['type' => 'number'],
                ],
            ]),
            'responses' => [
                '201' => ['description' => 'Updated cart (unknown id = silent no-op)'],
                '400' => ['description' => 'Missing cartItemId/quantity'],
            ],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/remove-cart-item', 'post', 'Cart', 'Remove one cart line ', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['cartItemId'],
                'properties' => ['cartItemId' => ['type' => 'integer']],
            ]),
            'responses' => [
                '201' => ['description' => 'Updated cart (empty cart allowed)'],
                '400' => ['description' => 'Missing cartItemId'],
                '404' => ['description' => 'Line not found'],
            ],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/remove-cart-items', 'post', 'Cart', 'Remove several cart lines ', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['itemIds'],
                'properties' => [
                    'itemIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
            ]),
            'responses' => [
                '201' => ['description' => 'Updated cart (empty cart allowed)'],
                '400' => ['description' => 'Missing itemIds'],
                '404' => ['description' => 'No matching lines'],
            ],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/apply-coupon', 'post', 'Cart', 'Apply coupon', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['couponCode'],
                'properties' => ['couponCode' => ['type' => 'string']],
            ]),
            'responses' => ['200' => ['description' => 'Cart + coupon'], '422' => ['description' => 'Invalid coupon']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/remove-coupon', 'delete', 'Cart', 'Remove coupon', [
            'responses' => ['200' => ['description' => 'Cart without coupon']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customizable-option-files', 'post', 'Cart', 'Stage customizable option file ', [
            'responses' => [
                '201' => ['description' => 'token + fileName + optionId'],
                '400' => ['description' => 'Missing fields'],
                '422' => ['description' => 'Validation / file type'],
            ],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/store_translations', 'get', 'Content', 'List store translations', [
            'responses' => ['200' => ['description' => 'Paginated translations']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/attribute_translations', 'get', 'Catalog', 'List attribute translations', [
            'responses' => ['200' => ['description' => 'Paginated translations']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/category_translations', 'get', 'Catalog', 'List category translations', [
            'responses' => ['200' => ['description' => 'Paginated translations']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/products/{productId}/images', 'get', 'Catalog', 'Product images (sub-resource)', [
            'responses' => ['200' => ['description' => 'Image array']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/products/{productId}/custom-options', 'get', 'Catalog', 'Product customizable options', [
            'responses' => ['200' => ['description' => 'Option array']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/checkout-addresses', 'get', 'Checkout', 'Get checkout addresses', [
            'responses' => ['200' => ['description' => 'Addresses or empty']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/checkout-addresses', 'post', 'Checkout', 'Set checkout addresses', [
            'responses' => ['200' => ['description' => 'Saved addresses'], '500' => ['description' => 'Prereq failure + detail']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/checkout-shipping-methods', 'get', 'Checkout', 'List shipping methods', [
            'responses' => ['200' => ['description' => 'Rate array (empty until addresses set)']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/checkout-shipping-methods', 'post', 'Checkout', 'Set shipping method', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['shippingMethod'],
                'properties' => ['shippingMethod' => ['type' => 'string']],
            ]),
            'responses' => ['200' => ['description' => 'Saved']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/payment-methods', 'get', 'Checkout', 'List payment methods', [
            'responses' => ['200' => ['description' => 'Payment methods']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/checkout-payment-methods', 'post', 'Checkout', 'Set payment method', [
            'requestBody' => self::jsonBody([
                'type' => 'object',
                'required' => ['paymentMethod'],
                'properties' => ['paymentMethod' => ['type' => 'string']],
            ]),
            'responses' => ['200' => ['description' => 'Saved']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/checkout-orders', 'post', 'Checkout', 'Place order', [
            'responses' => [
                '201' => ['description' => 'Order placed'],
                '500' => ['description' => 'Prereq / empty cart'],
            ],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-orders', 'get', 'Account', 'List customer orders', [
            'parameters' => [self::query('status', 'status filter')],
            'responses' => ['200' => ['description' => 'Bare array (max 10)']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-orders/{id}', 'get', 'Account', 'Get customer order', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'Order detail'], '404' => ['description' => 'Not found']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-orders/{id}/cancel', 'post', 'Account', 'Cancel customer order', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'success/message/orderId/status']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-orders/{id}/reorder', 'post', 'Account', 'Reorder into cart', [
            'parameters' => [self::pathId()],
            'responses' => ['201' => ['description' => 'Items added'], '400' => ['description' => 'Nothing added']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-invoices', 'get', 'Account', 'List invoices', [
            'parameters' => [
                self::query('order_id', 'Filter by order', 'integer'),
                self::query('state', 'Invoice state'),
            ],
            'responses' => ['200' => ['description' => 'Invoice array']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-invoices/{id}/pdf', 'get', 'Account', 'Download invoice PDF', [
            'parameters' => [self::pathId()],
            'responses' => ['200' => ['description' => 'application/pdf']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-shipments', 'get', 'Account', 'List shipments', [
            'parameters' => [self::query('order_id', 'Filter by order', 'integer')],
            'responses' => ['200' => ['description' => 'Shipment array']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-addresses', 'get', 'Account', 'List addresses', [
            'responses' => ['200' => ['description' => 'Address book']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/customer-addresses', 'post', 'Account', 'Create address', [
            'responses' => ['201' => ['description' => 'Created']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/wishlists', 'get', 'Account', 'List wishlists', [
            'responses' => ['200' => ['description' => 'Wishlist rows']],
            'security' => [['storefrontKey' => [], 'customerBearer' => []]],
        ]);

        self::add($paths, '/store/theme', 'get', 'Content', 'Shop theme', [
            'responses' => ['200' => ['description' => 'Theme payload']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/sections', 'get', 'Content', 'Published sections', [
            'responses' => ['200' => ['description' => 'Sections']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/contact-us', 'post', 'Content', 'Contact us', [
            'responses' => ['201' => ['description' => 'Submitted']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/newsletters', 'post', 'Content', 'Subscribe newsletter', [
            'responses' => ['200' => ['description' => 'Subscribed'], '400' => ['description' => 'Validation']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/store-config', 'get', 'Content', 'Store config', [
            'responses' => ['200' => ['description' => 'Public store settings']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/stores', 'get', 'Content', 'List stores', [
            'responses' => ['200' => ['description' => 'Stores']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/countries', 'get', 'Content', 'List countries', [
            'responses' => ['200' => ['description' => 'Countries']],
            'security' => [['storefrontKey' => []]],
        ]);

        self::add($paths, '/store/locales', 'get', 'Content', 'List locales', [
            'responses' => ['200' => ['description' => 'Locales']],
            'security' => [['storefrontKey' => []]],
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

    /** @param  array<string, mixed>  $schema */
    private static function jsonBody(array $schema): array
    {
        return [
            'required' => true,
            'content' => [
                'application/json' => ['schema' => $schema],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function pathId(): array
    {
        return [
            'name' => 'id',
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
