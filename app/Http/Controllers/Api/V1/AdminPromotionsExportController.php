<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Promo\Support\DiscountCsvSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Discount rules CSV → /api/v1/console/marketing/discount-rules/export
 */
class AdminPromotionsExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('marketing.discounts') && ! $admin->hasPermission('marketing.newsletter')) {
            return response('Forbidden.', 403);
        }

        $csv = DiscountCsvSchema::exportCsv();
        $filename = 'promotions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
