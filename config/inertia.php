<?php

use Inertia\Testing\AssertableInertia;

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    */

    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
    ],

    /*
    |--------------------------------------------------------------------------
    | Page component resolution
    |--------------------------------------------------------------------------
    |
    | The admin and storefront bundles are Vite SPAs that resolve their pages with
    | `import.meta.glob`, so every component under resources/js is addressable at
    | build time. Laravel has no reliable way to know that, so we do not ask it to
    | verify components on disk.
    |
    | `testing.ensure_pages_exist` is therefore false as well: leaving it on made
    | `assertInertia` fail for components that demonstrably render in the browser,
    | which turned valid pages into false negatives.
    |
    */

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/admin/Pages'),
            resource_path('js/pages'),
        ],
        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    */

    'testing' => [
        'ensure_pages_exist' => false,
        'page_paths' => [
            resource_path('js/admin/Pages'),
            resource_path('js/pages'),
        ],
        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | History Encryption
    |--------------------------------------------------------------------------
    */

    'history' => [
        'encrypt' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expose Shared Prop Keys
    |--------------------------------------------------------------------------
    */

    'expose_shared_prop_keys' => false,

    /*
    | Request dumps land in storage/inertia-devtools. Keep this off on a live shop.
    | A null value would turn the recorder on whenever APP_ENV is local.
    */
    'devtools' => [
        'enabled' => false,
    ],
];
