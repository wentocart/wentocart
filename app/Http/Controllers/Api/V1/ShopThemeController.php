<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAppearanceSections;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST theme + sections REST.
 */
class ShopThemeController extends Controller
{
    public function theme(): JsonResponse
    {
        $store = app(StoreContext::class)->store()
            ?? Store::query()->where('is_default', true)->first();
        $code = (string) ($store?->theme ?: 'default');
        $name = (string) ($store?->name ?: $code);

        return response()->json([[
            'code' => $code !== '' ? $code : 'default',
            'name' => $name !== '' ? $name : $code,
            'sectionTypes' => GraphqlAdminAppearanceSections::sectionTypes(),
        ]]);
    }

    public function sections(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $all = GraphqlAdminAppearanceSections::shopPublished([
            'type' => (string) $request->query('type', ''),
        ]);
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));

        return response()->json(array_values($slice))->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }

    public function showSection(int $id): JsonResponse
    {
        foreach (GraphqlAdminAppearanceSections::shopPublished() as $row) {
            if ((int) $row['id'] === $id) {
                return response()->json($row);
            }
        }

        return response()->json(['message' => 'Section not found.', 'success' => false], 404);
    }
}
