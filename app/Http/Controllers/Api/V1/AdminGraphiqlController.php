<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * GraphiQL playground for Admin GraphQL (/api/v1/console/graphiql parity).
 * Enabled when APP_DEBUG=true or GRAPHQL_PLAYGROUND=true.
 */
class AdminGraphiqlController extends Controller
{
    public function __invoke(): Response
    {
        if (! config('app.debug') && ! filter_var(env('GRAPHQL_PLAYGROUND', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $endpoint = e(URL::to('/api/v1/console/graphql'));
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wento Admin GraphiQL</title>
  <link rel="stylesheet" href="https://unpkg.com/graphiql@3.7.1/graphiql.min.css">
  <style>body{margin:0;height:100vh;}#graphiql{height:100vh;}</style>
</head>
<body>
  <div id="graphiql">Loading…</div>
  <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
  <script src="https://unpkg.com/graphiql@3.7.1/graphiql.min.js"></script>
  <script>
    const fetcher = GraphiQL.createFetcher({ url: '{$endpoint}' });
    ReactDOM.createRoot(document.getElementById('graphiql')).render(
      React.createElement(GraphiQL, {
        fetcher: fetcher,
        defaultQuery: '{ dashboard { type } }'
      })
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
