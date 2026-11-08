<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Catalog\Models\Product;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminCatalogProductsExportController extends Controller
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

        $locale = Language::defaultCode();
        $q = Product::query()->with('translations')->orderByDesc('id');
        if ($request->has('status') || $request->has('enabled')) {
            $enabled = (int) $request->query('status', $request->query('enabled', 1)) === 1;
            $q->where('is_enabled', $enabled);
        }
        $sku = trim((string) $request->query('sku', ''));
        if ($sku !== '') {
            $q->where('sku', 'like', '%'.$sku.'%');
        }

        $rows = $q->limit(5000)->get();
        $filename = 'catalog-products-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $locale) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'sku', 'name', 'type', 'price', 'stock_qty', 'is_enabled', 'created_at',
            ]);
            foreach ($rows as $p) {
                $tr = $p->translation($locale);
                fputcsv($out, [
                    $p->id,
                    (string) $p->sku,
                    (string) ($tr?->name ?? ''),
                    (string) $p->type,
                    $p->price,
                    $p->stock_qty,
                    $p->is_enabled ? 1 : 0,
                    optional($p->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
