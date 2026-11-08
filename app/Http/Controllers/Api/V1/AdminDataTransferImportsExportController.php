<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Settings\Models\DataTransferImport;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data Transfer Imports datagrid CSV → /api/v1/console/data-transfer-imports/export
 */
class AdminDataTransferImportsExportController extends Controller
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

        $rows = DataTransferImport::query()->orderByDesc('id')->limit(5000)->get();
        $filename = 'data-transfer-imports-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'code', 'action', 'state', 'file_name',
                'processed_rows_count', 'invalid_rows_count', 'errors_count', 'created_at',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    (string) ($row->code ?? ''),
                    (string) ($row->action ?? ''),
                    (string) ($row->state ?? ''),
                    (string) ($row->file_name ?? ''),
                    (int) ($row->processed_rows_count ?? 0),
                    (int) ($row->invalid_rows_count ?? 0),
                    (int) ($row->errors_count ?? 0),
                    optional($row->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
