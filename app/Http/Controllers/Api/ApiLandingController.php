<?php

namespace App\Http\Controllers\Api;

use App\Domain\Api\WentoApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Wento API landing — store + console surfaces (no foreign path aliases).
 */
class ApiLandingController extends Controller
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');
        $payload = [
            'name' => (string) config('wento.name', 'WentoCart'),
            'version' => WentoApiIdentity::VERSION,
            'identity' => 'wento',
            'surfaces' => [
                'store' => [
                    'rest_base' => $base.WentoApiIdentity::STORE_PREFIX,
                    'native_catalog' => $base.WentoApiIdentity::NATIVE_CATALOG_PREFIX,
                    'native_cart' => $base.'/api/v1/cart',
                    'native_checkout' => $base.'/api/v1/checkout',
                    'graphql' => $base.'/api/v1/graphql',
                    'graphiql' => $base.'/api/v1/graphiql',
                    'sandbox' => $base.'/api/v1/sandbox',
                    'store_config' => $base.WentoApiIdentity::storePath('store-config'),
                    'openapi' => $base.WentoApiIdentity::storePath('docs'),
                    'playground' => $base.WentoApiIdentity::storePath('playground'),
                    'auth' => [
                        'storefront_key_header' => 'X-STOREFRONT-KEY',
                        'customer_or_cart_bearer' => 'Authorization: Bearer <token>',
                    ],
                ],
                'console' => [
                    'rest_base' => $base.WentoApiIdentity::CONSOLE_PREFIX,
                    'graphql' => $base.WentoApiIdentity::consolePath('graphql'),
                    'graphiql' => $base.WentoApiIdentity::consolePath('graphiql'),
                    'reporting' => $base.WentoApiIdentity::consolePath('reporting/stats'),
                    'integrations' => $base.WentoApiIdentity::consolePath('settings/integrations'),
                    'storefront_keys' => $base.WentoApiIdentity::consolePath('settings/storefront-keys'),
                    'openapi' => $base.WentoApiIdentity::consolePath('docs'),
                    'playground' => $base.WentoApiIdentity::consolePath('playground'),
                    'auth' => [
                        'session' => 'admin guard session',
                        'integration_bearer' => 'Authorization: Bearer <id>|<token>',
                    ],
                ],
            ],
            'docs' => [
                'llms' => $base.'/api/v1/llms.txt',
                'llms_full' => $base.'/api/v1/llms-full.txt',
                'store_openapi' => $base.WentoApiIdentity::storePath('docs'),
                'store_playground' => $base.WentoApiIdentity::storePath('playground'),
                'console_openapi' => $base.WentoApiIdentity::consolePath('docs'),
                'console_playground' => $base.WentoApiIdentity::consolePath('playground'),
                'postman_collections' => $base.'/api/v1/collections',
                'postman_store' => $base.'/api/v1/collections/store',
                'postman_console' => $base.'/api/v1/collections/console',
                'postman_environment' => $base.'/api/v1/collections/environment',
            ],
            'conventions' => [
                'rest_json' => 'snake_case keys; { data, meta } via WentoApiEnvelope; bulk bodies use ids',
                'graphql' => 'camelCase; product.slug + product.listing; storeSettings/homeModules/defaultStore/productTemplate',
                'config_slugs' => 'shop | catalog | orders | seo | shipping — storeBased/localeBased metadata',
                'appearance_types' => 'Classic modules only (hero, featured, footer, …) — no foreign type aliases',
                'resources' => 'stores, warehouses, discount-rules, home-modules, product-templates, metrics, order-composer',
                'bulk_verbs' => 'bulk-delete | bulk-update',
                'payments' => 'cod | bank_transfer (order composer offline allowlist)',
            ],
            'highlights' => [
                'Flat console REST: products, orders, customers, warehouses, discount-rules',
                'Store REST /api/v1/store/* + first-party /api/v1/catalog|cart|checkout',
                'Native ShopSetting config codes + Classic appearance modules',
                'GraphQL: storeSettings, homeModules, defaultStore, productTemplate, listing, slug',
                'Order composer drafts (not Create-Order / draft-carts)',
                'Integration tokens + storefront keys over REST',
                'OpenAPI at /api/v1/store/docs and /api/v1/console/docs',
            ],
        ];

        $wantsJson = $request->expectsJson()
            || $request->boolean('json')
            || str_ends_with($request->path(), '.json')
            || str_contains((string) $request->header('Accept', ''), 'application/json');

        if ($wantsJson) {
            return response()->json($payload);
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($payload['name']).' API</title>'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<style>body{font-family:ui-sans-serif,system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.5;background:linear-gradient(160deg,#f7f4ef,#e8f0f2);min-height:100vh}'
            .'code,a{word-break:break-all}h1{font-size:1.75rem;letter-spacing:-0.02em}ul{padding-left:1.2rem}'
            .'.card{background:rgba(255,255,255,.72);border:1px solid rgba(0,0,0,.06);padding:1rem 1.25rem;margin:1rem 0}</style></head><body>'
            .'<h1>'.e($payload['name']).' API</h1>'
            .'<p class="card">Store GraphQL: <a href="'.e($payload['surfaces']['store']['graphiql']).'">GraphiQL</a> · '
            .'Store REST: <a href="'.e($payload['surfaces']['store']['playground']).'">Swagger</a> · '
            .'Console REST: <a href="'.e($payload['surfaces']['console']['playground']).'">Swagger</a><br>'
            .'Console base: <code>'.e($payload['surfaces']['console']['rest_base']).'</code></p>'
            .'<h2>Docs</h2><ul>'
            .'<li><a href="'.e($payload['docs']['llms']).'">llms.txt</a></li>'
            .'<li><a href="'.e($payload['docs']['store_openapi']).'">Store OpenAPI</a></li>'
            .'<li><a href="'.e($payload['docs']['console_openapi']).'">Console OpenAPI</a></li>'
            .'<li><a href="'.e($payload['docs']['postman_collections']).'">Postman collections</a></li>'
            .'</ul>'
            .'<h2>Wento identity</h2><ul>';
        foreach ($payload['highlights'] as $h) {
            $html .= '<li>'.e($h).'</li>';
        }
        $html .= '</ul><p><a href="'.e($base.'/api.json').'">JSON</a></p></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
