<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST CMS pages — documents GraphQL only; Wento adds REST and beats.
 * Paths: GET /api/v1/store/cms-pages · GET /api/v1/store/pages · GET …/{id|slug}
 */
class ShopCmsPagesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $limit = max(1, min(100, (int) ($request->query('limit', $request->query('per_page', 50)))));
        $page = max(1, (int) ($request->query('page', 1)));

        $query = CmsPage::query()->public()->with('translations')->orderByDesc('id');
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get()
            ->map(fn (CmsPage $p) => $this->present($p, $locale, includeBody: false))
            ->values()
            ->all();

        return response()->json($rows, 200, [
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $limit,
            'X-Total-Pages' => (string) max(1, (int) ceil($total / $limit)),
        ]);
    }

    public function show(Request $request, string $idOrKey): JsonResponse
    {
        $locale = $this->locale($request);
        $page = $this->resolve($idOrKey, $locale);
        if (! $page) {
            return response()->json([
                'detail' => 'CMS page not found',
                'message' => 'CMS page not found',
            ], 404);
        }

        return response()->json($this->present($page, $locale, includeBody: true));
    }

    private function locale(Request $request): string
    {
        $header = trim((string) $request->header('X-Wento-Locale', ''));
        if ($header !== '') {
            return $header;
        }

        return app()->getLocale() ?: Language::defaultCode();
    }

    private function resolve(string $idOrKey, string $locale): ?CmsPage
    {
        $raw = trim($idOrKey);
        if (str_contains($raw, '/')) {
            $raw = (string) basename(rtrim($raw, '/'));
        }

        if (ctype_digit($raw)) {
            return CmsPage::query()->public()->with('translations')->find((int) $raw);
        }

        $slug = $raw;
        $page = CmsPage::query()
            ->public()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug)->where('language_code', $locale))
            ->with('translations')
            ->first();
        if ($page) {
            return $page;
        }

        return CmsPage::query()
            ->public()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->with('translations')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CmsPage $page, string $locale, bool $includeBody): array
    {
        $t = $page->translation($locale) ?? $page->translations->first();
        $slug = (string) ($t?->slug ?? '');
        $translation = null;
        if ($t) {
            $translation = [
                'id' => '/api/v1/store/page-translations/'.$t->id,
                '_id' => (int) $t->id,
                'pageTitle' => (string) ($t->title ?? ''),
                'slug' => $slug,
                'htmlContent' => $includeBody ? (string) ($t->body ?? '') : null,
                'metaTitle' => $t->meta_title,
                'metaDescription' => $t->meta_description,
                'metaKeywords' => $t->meta_keywords,
                'locale' => (string) ($t->language_code ?? $locale),
                'cmsPageId' => (string) $page->id,
                // Wento extras
                'shortDescription' => $t->short_description,
            ];
            if (! $includeBody) {
                unset($translation['htmlContent']);
            }
        }

        return [
            'id' => '/api/v1/store/pages/'.$page->id,
            '_id' => (int) $page->id,
            'layout' => $page->template ?: 'default',
            'createdAt' => optional($page->created_at)?->toIso8601String(),
            'updatedAt' => optional($page->updated_at)?->toIso8601String(),
            'translation' => $translation,
            // Flat aliases for headless clients (beat nesting-only)
            'pageTitle' => $translation['pageTitle'] ?? null,
            'slug' => $slug !== '' ? $slug : null,
            'htmlContent' => $includeBody ? ($translation['htmlContent'] ?? null) : null,
            'metaTitle' => $translation['metaTitle'] ?? null,
            'metaDescription' => $translation['metaDescription'] ?? null,
        ];
    }
}
