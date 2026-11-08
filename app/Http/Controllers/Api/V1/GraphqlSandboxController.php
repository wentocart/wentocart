<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Embedded Apollo Sandbox for shop GraphQL (/api/sandbox parity).
 * Enabled when APP_DEBUG=true or GRAPHQL_PLAYGROUND=true.
 */
class GraphqlSandboxController extends Controller
{
    public function __invoke(): Response
    {
        if (! config('app.debug') && ! filter_var(env('GRAPHQL_PLAYGROUND', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $endpoint = e(URL::to('/api/v1/graphql'));
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wento GraphQL Sandbox</title>
  <style>body{margin:0;height:100vh;}iframe{border:0;width:100%;height:100vh;}</style>
</head>
<body>
  <div style="padding:12px;font:14px/1.4 system-ui,sans-serif;border-bottom:1px solid #e5e5e5">
    Wento Apollo Sandbox · endpoint <code>{$endpoint}</code>
    · <a href="/api/v1/graphiql">GraphiQL</a>
  </div>
  <iframe
    src="https://sandbox.embed.apollographql.com/?endpoint={$endpoint}"
    title="Apollo Sandbox"
    allow="clipboard-read; clipboard-write"
  ></iframe>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
