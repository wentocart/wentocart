<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\Support\ExtensionUpload;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ModuleHostController extends Controller
{
    public function show(Request $request, PluginKernel $kernel, string $vendor, string $name, string $page): InertiaResponse|HttpResponse
    {
        return $this->render($request, $kernel, $vendor, $name, $page);
    }

    public function downloadLog(Request $request, PluginKernel $kernel, string $vendor, string $name): InertiaResponse|HttpResponse
    {
        return $this->render($request, $kernel, $vendor, $name, 'download-log');
    }

    public function store(Request $request, PluginKernel $kernel, string $vendor, string $name, string $page): InertiaResponse|HttpResponse
    {
        return $this->render($request, $kernel, $vendor, $name, $page);
    }

    /**
     * Lookup for hosted module forms (customers / products) with search + pagination.
     * Only works if that extension declared the matching read/write capability.
     */
    public function lookup(Request $request, PluginKernel $kernel, string $vendor, string $name, string $source): JsonResponse
    {
        $kernel->boot();
        $plugin = $kernel->instance($vendor, $name);
        abort_unless($plugin, 404);
        $source = strtolower($source);
        $q = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(50, $request->integer('per_page', 25)));
        $ctx = $plugin->context();

        if ($source === 'customers') {
            abort_unless(
                $ctx->manifest()->can('customers.read') || $ctx->manifest()->can('customers.write'),
                403
            );
            // Lookups run outside the customers page, so enforce the same
            // domain permission here instead of leaking names to any admin.
            $admin = $request->user('admin');
            abort_unless($admin && ($admin->hasPermission('customers.customers') || $admin->hasPermission('customers.manage')), 403);
            $result = $ctx->customers()->paginate($q, $page, $perPage);

            return response()->json([
                'results' => collect($result['data'])->map(fn ($row) => [
                    'id' => $row['id'],
                    'name' => $row['label'] ?? $row['name'],
                ])->values(),
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
            ]);
        }

        if ($source === 'products') {
            abort_unless(
                $ctx->manifest()->can('catalog.read') || $ctx->manifest()->can('catalog.write'),
                403
            );
            $admin = $request->user('admin');
            abort_unless($admin && ($admin->hasPermission('catalog.products') || $admin->hasPermission('catalog.manage')), 403);
            $result = $ctx->catalog()->paginate($q, $page, $perPage);

            return response()->json([
                'results' => collect($result['data'])->map(fn ($row) => [
                    'id' => $row['id'],
                    'name' => $row['label'] ?? $row['name'],
                ])->values(),
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
            ]);
        }

        abort(404);
    }

    protected function render(Request $request, PluginKernel $kernel, string $vendor, string $name, string $page): InertiaResponse|HttpResponse
    {
        $kernel->boot();
        $page = strtolower($page);
        $entry = ModuleBuses::findAdminPage($vendor, $name, $page);
        abort_unless($entry, 404);
        $plugin = $kernel->instance($vendor, $name);
        abort_unless($plugin, 404);
        $class = $entry['class'];
        abort_unless(is_string($class) && class_exists($class), 404);
        $controller = new $class($plugin->context());
        abort_unless($controller instanceof ModuleController, 404);

        if ($request->isMethod('GET') && method_exists($controller, 'download')) {
            $response = $controller->download(ModuleRequest::capture($request->method(), $request->all()));
            if ($response instanceof HttpResponse) {
                return $response;
            }
        }

        $method = $request->isMethod('GET') ? 'index' : 'store';
        $payload = $request->all();
        if ($method === 'store') {
            $incoming = is_array($payload['values'] ?? null) ? $payload['values'] : [];
            $previewQuery = [];
            foreach (['id', 'new'] as $key) {
                if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== null) {
                    $previewQuery[$key] = $payload[$key];
                }
            }
            if (isset($incoming['id']) && (string) $incoming['id'] !== '') {
                $previewQuery['id'] = $incoming['id'];
            } elseif ($previewQuery === [] && $incoming !== []) {
                $previewQuery['new'] = '1';
            }
            $preview = $controller->index(ModuleRequest::capture('GET', $previewQuery));
            $preview = PageDocument::sanitize(is_array($preview) ? $preview : []);
            $previewFields = PageDocument::collectFields($preview);
            if ($previewFields !== []) {
                $current = [];
                foreach ($previewFields as $field) {
                    $key = (string) ($field['name'] ?? '');
                    if ($key !== '' && ExtensionUpload::isField($field)) {
                        $current[$key] = $plugin->context()->settings()->get($key, []);
                    }
                }
                $incoming = ExtensionUpload::mergeIncoming($vendor, $name, $previewFields, $incoming, $request, $current);
                $payload['values'] = PageDocument::sanitizeSettingsPayload($previewFields, $incoming);
            }
        }
        $document = $controller->{$method}(ModuleRequest::capture($request->method(), $payload));
        $document = PageDocument::sanitize(is_array($document) ? $document : []);
        $manifest = $plugin->context()->manifest();
        $allowAssets = $manifest->can('admin.assets');
        $css = $allowAssets
            ? array_map(fn ($file) => $manifest->publicAssetUrl((string) $file), $document['assets']['css'] ?? [])
            : [];
        $js = $allowAssets
            ? array_map(fn ($file) => $manifest->publicAssetUrl((string) $file), $document['assets']['js'] ?? [])
            : [];

        $fields = PageDocument::collectFields($document);
        $values = $plugin->context()->settings()->allForForm($fields);
        foreach ($document['values'] ?? [] as $key => $value) {
            $values[$key] = $value;
        }
        $masked = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'password') {
                continue;
            }
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $stored = $plugin->context()->settings()->get($key, '');
            $masked[$key] = is_string($stored) && trim($stored) !== '';
        }

        return Inertia::render('Apps/ModulePage', [
            'app' => [
                'vendor' => $vendor,
                'name' => $name,
                'display_name' => $plugin->context()->manifest()->displayName,
            ],
            'page' => $page,
            'document' => $document,
            'css' => $css,
            'js' => $js,
            'values' => $values,
            'masked' => $masked,
        ]);
    }
}
