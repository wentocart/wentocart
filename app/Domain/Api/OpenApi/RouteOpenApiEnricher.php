<?php

namespace App\Domain\Api\OpenApi;

use Illuminate\Support\Facades\Route;

/**
 * Merge live Laravel routes into OpenAPI paths so the playground lists every
 * store/console REST operation (beats hand-curated-only specs).
 */
final class RouteOpenApiEnricher
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function enrich(array $document, string $surface): array
    {
        $surface = strtolower($surface);
        $isConsole = in_array($surface, ['admin', 'console'], true);
        $prefix = $isConsole ? 'api/v1/console' : 'api/v1/store';
        $tag = $isConsole ? 'Discovered Console' : 'Discovered Store';
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        foreach (Route::getRoutes() as $route) {
            $uri = trim((string) $route->uri(), '/');
            if (! str_starts_with($uri, $prefix)) {
                continue;
            }
            // Skip docs/playground/openapi themselves
            if (preg_match('#/(docs|playground|openapi\\.json)$#', $uri) === 1) {
                continue;
            }

            $openPath = '/'.substr($uri, strlen('api/v1'));
            // Convert {param} Laravel style already matches OpenAPI
            $methods = array_values(array_filter(
                array_map('strtolower', $route->methods()),
                fn (string $m) => ! in_array($m, ['head', 'options'], true)
            ));
            if ($methods === []) {
                continue;
            }

            $paths[$openPath] ??= [];
            foreach ($methods as $method) {
                if (isset($paths[$openPath][$method])) {
                    continue;
                }
                $paths[$openPath][$method] = [
                    'tags' => [$tag],
                    'summary' => strtoupper($method).' '.$openPath,
                    'operationId' => str_replace(['/', '{', '}', '.'], ['_', '', '', '_'], $method.'_'.ltrim($openPath, '/')),
                    'responses' => [
                        '200' => ['description' => 'Success'],
                        '401' => ['description' => 'Unauthenticated'],
                        '403' => ['description' => 'Forbidden'],
                        '404' => ['description' => 'Not found'],
                        '422' => ['description' => 'Validation error'],
                    ],
                    'security' => $surface === 'admin'
                        ? [['integrationBearer' => []]]
                        : [['storefrontKey' => []]],
                    'x-wento-discovered' => true,
                ];
            }
        }

        ksort($paths);
        $document['paths'] = $paths;
        $document['x-wento-path-count'] = count($paths);

        return $document;
    }
}
