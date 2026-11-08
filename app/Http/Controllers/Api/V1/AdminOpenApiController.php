<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\OpenApi\AdminOpenApiDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 */
class AdminOpenApiController extends Controller
{
    public function docs(Request $request): JsonResponse
    {
        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');

        return response()->json(AdminOpenApiDocument::build($base));
    }

    public function playground(Request $request): Response
    {
        $base = rtrim((string) $request->getSchemeAndHttpHost(), '/');
        $specUrl = $base.'/api/v1/console/docs';
        $specUrlJs = json_encode($specUrl, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $title = e((string) config('wento.name', 'WentoCart')).' Admin API';
        $specEscaped = e($specUrl);

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
      font-family: system-ui, sans-serif; padding: 12px 20px; background: #111827; color: #e5e7eb;
      font-size: 14px; line-height: 1.4;
    }
    .wento-banner a { color: #93c5fd; }
    .wento-banner code { background: #1f2937; padding: 1px 6px; border-radius: 4px; }
  </style>
</head>
<body>
  <div class="wento-banner">
    <strong>{$title}</strong> — Swagger playground.
    Spec: <a href="{$specEscaped}"><code>/api/v1/console/docs</code></a>.
    Authorize with Integration Bearer <code>id|token</code> (no storefront key).
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
