<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminCmsPagesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('cms.manage') && ! $admin->hasPermission('cms.pages')) {
            return response('Forbidden.', 403);
        }

        $locale = Language::defaultCode();
        $q = CmsPage::query()->with('translations')->orderByDesc('id');
        $title = trim((string) $request->query('page_title', $request->query('q', '')));
        $slug = trim((string) $request->query('url_key', $request->query('slug', '')));
        if ($title !== '' || $slug !== '') {
            $q->whereHas('translations', function ($tq) use ($title, $slug, $locale) {
                $tq->where('language_code', $locale);
                if ($title !== '') {
                    $tq->where('title', 'like', '%'.$title.'%');
                }
                if ($slug !== '') {
                    $tq->where('slug', 'like', '%'.$slug.'%');
                }
            });
        }

        $rows = $q->limit(5000)->get();
        $filename = 'cms-pages-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $locale) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'title', 'slug', 'status', 'is_enabled', 'is_visible', 'created_at']);
            foreach ($rows as $page) {
                $tr = $page->translation($locale);
                fputcsv($out, [
                    $page->id,
                    (string) ($tr?->title ?? $page->title ?? ''),
                    (string) ($tr?->slug ?? $page->slug ?? ''),
                    (string) ($page->status ?? ''),
                    $page->is_enabled ? 1 : 0,
                    $page->is_visible ? 1 : 0,
                    optional($page->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
