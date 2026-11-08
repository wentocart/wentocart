<?php

return [

    /*
    | Public product name. Shop title still comes from APP_NAME / shop settings.
    */
    'name' => env('APP_NAME', 'WentoCart'),

    'codename' => env('WENTO_CODENAME', 'wentocart'),

    'name_is_placeholder' => filter_var(env('WENTO_NAME_IS_PLACEHOLDER', false), FILTER_VALIDATE_BOOL),

    'installed' => filter_var(env('WENTO_INSTALLED', false), FILTER_VALIDATE_BOOL)
        || file_exists(storage_path('app/installed.lock')),

    'lock_path' => storage_path('app/installed.lock'),

    'default_language' => env('WENTO_LANGUAGE', 'en'),

    /*
    | Storefront locale URLs: cookie (default) or path (/en/..., /fr/...).
    | Merchants can also set ShopSetting seo.locale_url_mode = path.
    */
    'locale_url_mode' => env('WENTO_LOCALE_URL_MODE', 'cookie'),

    'default_currency' => env('WENTO_CURRENCY', 'USD'),

    /*
    | Public admin URL segment. Edit only config/admin-path.php (not this key).
    | App code reads AdminPath::segment() / config('wento.admin_prefix').
    */
    'admin_prefix' => (static function (): string {
        $file = __DIR__.'/admin-path.php';
        if (! is_file($file)) {
            return 'admin';
        }
        $data = require $file;
        $segment = is_array($data) ? (string) ($data['segment'] ?? '') : '';
        if (preg_match('/^[A-Za-z][A-Za-z0-9-]{2,39}$/', $segment) === 1) {
            return $segment;
        }

        return 'admin';
    })(),

    'table_prefix' => wento_db_prefix(),

    'version' => '1.0.0',

    'requires' => [
        'php' => '8.3.0',
        'node' => '20.0.0',
        'extensions' => ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'gd', 'zip'],
    ],

    /*
    | Product types describe how the shopper buys (one SKU, variants, rental…).
    | Selling by kg / metre / litre is NOT a type — it is `products.sale_unit`
    | (see App\Domain\Catalog\Enums\SaleUnit). Deprecated stubs dimension/ltr stay
    | out of this picker list.
    */
    'product_types' => [
        'simple',
        'combination',
        'virtual',
        'bundle',
        'external',
        'customizable',
        'rental',
        'subscription',
        'group',
        'gift_card',
        'service',
    ],

    'visibility' => ['catalog', 'search', 'both', 'hidden'],

    /*
    | Customization field types. Stored as strings + JSON config so a new type
    | can be added here without a schema change.
    */
    'customization' => [
        'field_types' => ['text', 'textarea', 'select', 'radio', 'checkbox', 'file', 'image'],
        'price_types' => ['none', 'fixed', 'percent'],
    ],

    'subscription' => [
        'frequencies' => ['hour', 'day', 'week', 'month', 'year'],
        'purchase_options' => ['one_time', 'subscription', 'both'],
    ],

    'upload' => [
        'images' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'attachments' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/zip',
            'text/plain',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'max_image_kb' => 8192,
        'max_attachment_kb' => 10240,
    ],

    'attachments' => [
        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'csv', 'gif', 'jpg', 'jpeg', 'png', 'webp'],
        'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'sh', 'cgi', 'js', 'html', 'htm', 'svg', 'htaccess'],
        'guest_download' => env('WENTO_ATTACHMENT_GUEST_DOWNLOAD', false),
    ],

    /*
    | Community Apps (zip modules). Safe apps are scanned and capability-locked.
    | Certified apps (payments, shipping, outbound HTTP) need marketplace review.
    */
    'apps' => [
        'allow_unsigned' => (bool) env('WENTO_ALLOW_UNSIGNED', false),
        'allow_certified_unsigned' => (bool) env('WENTO_ALLOW_CERTIFIED_UNSIGNED', false),
        'max_zip_kb' => 10240,
        'max_safe_php_files' => 16,
        'max_certified_php_files' => 48,
        /*
        | Default matrix for extensions/ on a fresh shop (Sam's first paint).
        | Keys are vendor/name. Values: available | installed | disabled | enabled
        | (installed and disabled both mean DB row, is_enabled=false).
        */
        'defaults' => [
            'weblytic/mainmenu' => 'enabled',
        ],
    ],

    /*
    | Official marketplace channel for extension update checks (optional).
    | When empty, zip Upgrade still works; badges use local catalog versions only.
    */
    'marketplace' => [
        'url' => rtrim((string) env('WENTO_MARKETPLACE_URL', ''), '/'),
        'connected' => filter_var(env('WENTO_MARKETPLACE_CONNECTED', false), FILTER_VALIDATE_BOOL),
    ],


    /*
    | Retention (days) for Admin API write audits.
    */
    'admin_api_audit_retention_days' => (int) env('WENTO_ADMIN_API_AUDIT_RETENTION_DAYS', 90),

    /*
    | Shop API playground (Swagger / GraphiQL).
    | Keep auto-inject OFF on public stores; it exposes the key to anyone who opens the playground.
    */
    'api_playground' => [
        'storefront_key' => (string) env('STOREFRONT_PLAYGROUND_KEY', ''),
        'auto_inject_storefront_key' => filter_var(
            env('API_PLAYGROUND_AUTO_INJECT_STOREFRONT_KEY', false),
            FILTER_VALIDATE_BOOL
        ),
    ],

];
