<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Catalog\Support\WarehouseCsvSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/console/warehouses?format=csv → /api/v1/console/warehouses/export
 * Alias: /api/v1/console/warehouses/export
 */
class AdminWarehousesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('catalog.products') && ! $admin->hasPermission('catalog.manage')) {
            return response('Forbidden.', 403);
        }

        $csv = WarehouseCsvSchema::exportCsv();
        $filename = 'warehouses-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
