<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Marketing\Support\NewsletterCsvSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Newsletter subscribers CSV → /api/v1/console/newsletter-subscribers/export
 */
class AdminNewsletterSubscribersExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('marketing.newsletter') && ! $admin->hasPermission('marketing.discounts')) {
            return response('Forbidden.', 403);
        }

        $csv = NewsletterCsvSchema::exportCsv();
        $filename = 'newsletter-subscribers-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
