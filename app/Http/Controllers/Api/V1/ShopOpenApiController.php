<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\OpenApi\PlaygroundStorefrontKey;
use App\Domain\Api\OpenApi\ShopOpenApiDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 */
class ShopOpenApiController extends Controller
{
    public function docs(Request $request): JsonResponse
    {
        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');

        return response()->json(ShopOpenApiDocument::build($base));
    }

    public function playground(Request $request): Response
    {
        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');
        $specUrl = $base.'/api/v1/store/docs';
        $specUrlJs = json_encode($specUrl, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $title = e((string) config('wento.name', 'WentoCart')).' Shop API';
        $specEscaped = e($specUrl);

        $injectKey = PlaygroundStorefrontKey::injectKey();
        $injectKeyJs = json_encode($injectKey, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $autoOn = $injectKey !== '';
        $modeNote = $autoOn
            ? 'Auto-inject <strong>ON</strong> — <code>X-STOREFRONT-KEY</code> is sent from <code>STOREFRONT_PLAYGROUND_KEY</code> (local/private only).'
            : 'Auto-inject <strong>OFF</strong> — paste <code>X-STOREFRONT-KEY</code> per operation, or set <code>API_PLAYGROUND_AUTO_INJECT_STOREFRONT_KEY=true</code>.';

        $interceptorJs = $autoOn
            ? "requestInterceptor: (req) => { req.headers = req.headers || {}; req.headers['X-STOREFRONT-KEY'] = {$injectKeyJs}; return req; },"
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>{$title} Playground</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css"/>
  <style>
    body { margin: 0; background: #fafafa; }
    .wento-banner {
      font-family: system-ui, sans-serif; padding: 12px 20px; background: #0f172a; color: #e2e8f0;
      font-size: 14px; line-height: 1.4;
    }
    .wento-banner a { color: #7dd3fc; }
    .wento-banner code { background: #1e293b; padding: 1px 6px; border-radius: 4px; }
  </style>
</head>
<body>
  <div class="wento-banner">
    <strong>{$title}</strong> — Swagger playground.
    Spec: <a href="{$specEscaped}"><code>/api/v1/store/docs</code></a>.
    Authorize with customer Bearer from <code>POST /shop/customer/login</code>.
    {$modeNote}
  </div>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: {$specUrlJs},
      dom_id: '#swagger-ui',
      deepLinking: true,
      persistAuthorization: true,
      tryItOutEnabled: true,
      displayRequestDuration: true,
      filter: true,
      {$interceptorJs}
    });
  </script>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
