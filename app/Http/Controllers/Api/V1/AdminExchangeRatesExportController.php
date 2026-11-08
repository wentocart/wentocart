<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Localization\Models\Currency;
use App\Domain\Settings\AdminCsv;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exchange rates CSV → /api/v1/console/exchange-rates/export
 */
class AdminExchangeRatesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('settings.manage')) {
            return response('Forbidden.', 403);
        }

        $columns = ['id', 'currency_code', 'currency_name', 'rate', 'is_default'];
        $rows = Currency::query()->orderByDesc('is_default')->orderBy('code')->limit(10000)->get()->map(fn (Currency $row) => [
            'id' => $row->id,
            'currency_code' => $row->code,
            'currency_name' => $row->name,
            'rate' => $row->exchange_rate,
            'is_default' => $row->is_default ? '1' : '0',
        ])->all();
        $csv = AdminCsv::stringify($columns, $rows);
        $filename = 'exchange-rates-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
