<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Tax\Support\TaxRuleCsvSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tax rules / categories assignment CSV → /api/v1/console/tax-rules/export
 * Also aliased as /api/v1/console/tax-categories/export for naming.
 */
class AdminTaxRulesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('taxes.manage') && ! $admin->hasPermission('settings.manage')) {
            return response('Forbidden.', 403);
        }

        $csv = TaxRuleCsvSchema::exportCsv();
        $filename = 'tax-rules-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
