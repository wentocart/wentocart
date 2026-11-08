<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreBootstrap;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminStoresExportController extends Controller
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

        StoreBootstrap::ensure();
        $rows = Store::query()->with('urls')->orderByDesc('id')->limit(5000)->get();
        $filename = 'stores-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'code', 'name', 'hostname', 'theme', 'email', 'is_default', 'is_enabled']);
            foreach ($rows as $store) {
                $primary = $store->urls->firstWhere('is_primary', true) ?? $store->urls->first();
                fputcsv($out, [
                    $store->id,
                    (string) $store->code,
                    (string) $store->name,
                    (string) ($primary?->domain ?? ''),
                    (string) ($store->theme ?? ''),
                    (string) ($store->email ?? ''),
                    $store->is_default ? '1' : '0',
                    $store->is_enabled ? '1' : '0',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
