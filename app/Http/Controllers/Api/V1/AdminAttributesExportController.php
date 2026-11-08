<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catalog attributes (features) CSV → /api/v1/console/attributes/export
 */
class AdminAttributesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('catalog.manage') && ! $admin->hasPermission('catalog.features')) {
            return response('Forbidden.', 403);
        }

        $csv = app(FeatureService::class)->exportCsv(Language::defaultCode(), $request);
        $filename = 'attributes-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
