<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Downloadable Postman collections for store + console surfaces.
 */
class ApiCollectionsController extends Controller
{
    public function index(): JsonResponse
    {
        $base = rtrim((string) request()->getSchemeAndHttpHost(), '/');

        return response()->json([
            'store' => $base.'/api/v1/collections/store',
            'console' => $base.'/api/v1/collections/console',
            'environment' => $base.'/api/v1/collections/environment',
            'disk_path' => 'api-collections/',
        ]);
    }

    public function shop(): Response
    {
        return $this->store();
    }

    public function admin(): Response
    {
        return $this->console();
    }

    public function store(): Response
    {
        return $this->file('wento-shop.postman_collection.json');
    }

    public function console(): Response
    {
        return $this->file('wento-admin.postman_collection.json');
    }

    public function environment(): Response
    {
        return $this->file('wento.postman_environment.json');
    }

    private function file(string $name): Response
    {
        $path = base_path('api-collections/'.$name);
        if (! is_file($path)) {
            abort(404, 'Collection file missing');
        }

        $body = (string) file_get_contents($path);

        return response($body, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
