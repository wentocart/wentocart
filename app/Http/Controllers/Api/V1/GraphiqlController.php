<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\OpenApi\PlaygroundStorefrontKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Lightweight GraphiQL playground for the shop GraphQL endpoint (/api/graphiql parity).
 * Enabled when APP_DEBUG=true or GRAPHQL_PLAYGROUND=true.
 */
class GraphiqlController extends Controller
{
    public function __invoke(): Response
    {
        if (! config('app.debug') && ! filter_var(env('GRAPHQL_PLAYGROUND', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $endpoint = e(URL::to('/api/v1/graphql'));
        $injectKey = PlaygroundStorefrontKey::injectKey();
        $headersJs = '{}';
        $modeNote = 'Auto-inject OFF — set X-STOREFRONT-KEY in Headers when required.';
        if ($injectKey !== '') {
            $headersJs = json_encode(
                ['X-STOREFRONT-KEY' => $injectKey],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $modeNote = 'Auto-inject ON — STOREFRONT_PLAYGROUND_KEY is sent on every query (local/private only).';
        }
        $modeNoteEsc = e($modeNote);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wento GraphiQL</title>
  <link rel="stylesheet" href="https://unpkg.com/graphiql@3.7.1/graphiql.min.css">
  <style>body{margin:0;height:100vh;}#graphiql{height:calc(100vh - 40px);} .wento-bar{font:13px/1.4 system-ui,sans-serif;padding:8px 12px;background:#0f172a;color:#e2e8f0;} .wento-bar code{background:#1e293b;padding:1px 5px;border-radius:3px;}</style>
</head>
<body>
  <div class="wento-bar">Wento Shop GraphiQL · <code>{$endpoint}</code> · {$modeNoteEsc}</div>
  <div id="graphiql">Loading…</div>
  <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
  <script src="https://unpkg.com/graphiql@3.7.1/graphiql.min.js"></script>
  <script>
    const fetcher = GraphiQL.createFetcher({ url: '{$endpoint}', headers: {$headersJs} });
    ReactDOM.createRoot(document.getElementById('graphiql')).render(
      React.createElement(GraphiQL, { fetcher: fetcher, defaultQuery: '{ shop { name currency locale } }' })
    );
  </script>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
