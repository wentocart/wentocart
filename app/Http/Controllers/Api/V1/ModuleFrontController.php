<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ModuleFrontController extends Controller
{
    public function show(Request $request, PluginKernel $kernel, string $vendor, string $name, string $page): JsonResponse|HttpResponse
    {
        $kernel->boot();
        $page = strtolower($page);
        $entry = ModuleBuses::findFrontPage($vendor, $name, $page);
        abort_unless($entry, 404);
        if (! empty($entry['auth']) && ! $request->user('customer')) {
            abort(401);
        }
        $plugin = $kernel->instance($vendor, $name);
        abort_unless($plugin, 404);
        $class = $entry['class'];
        abort_unless(is_string($class) && class_exists($class), 404);
        $controller = new $class($plugin->context());
        abort_unless($controller instanceof ModuleController, 404);

        if ($request->isMethod('POST') && method_exists($controller, 'storeResponse')) {
            $response = $controller->storeResponse(ModuleRequest::capture($request->method(), $request->all()));
            if ($response instanceof HttpResponse) {
                return $response;
            }
        }

        if ($request->isMethod('GET') && method_exists($controller, 'indexResponse')) {
            $response = $controller->indexResponse(ModuleRequest::capture($request->method(), $request->all()));
            if ($response instanceof HttpResponse) {
                return $response;
            }
        }

        $method = $request->isMethod('GET') ? 'index' : 'store';
        $document = PageDocument::sanitize($controller->{$method}(ModuleRequest::capture($request->method(), $request->all())));
        $manifest = $plugin->context()->manifest();

        return response()->json([
            'app' => [
                'vendor' => $vendor,
                'name' => $name,
                'display_name' => $manifest->displayName,
            ],
            'page' => $page,
            'document' => $document,
            'css' => array_map(fn ($file) => $manifest->publicAssetUrl((string) $file), $document['assets']['css'] ?? []),
            'js' => $manifest->can('storefront.pages')
                ? array_map(fn ($file) => $manifest->publicAssetUrl((string) $file), $document['assets']['js'] ?? [])
                : [],
        ]);
    }
}
