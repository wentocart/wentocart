<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Security\HtmlSanitizer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CmsController extends Controller
{
    public function menu(string $code): JsonResponse
    {
        $menu = Menu::query()
            ->where('code', $code)
            ->with(['allItems.translations'])
            ->firstOrFail();

        return response()->json(['menu' => self::presentMenu($menu)]);
    }

    public function blocks(Request $request): JsonResponse
    {
        $location = trim($request->string('location')->toString()) ?: 'home';

        $blocks = CmsBlock::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->when($location !== 'all', fn ($query) => $query->where('location', $location))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (CmsBlock $block) => $this->presentBlock($block))
            ->values();

        return response()->json([
            'location' => $location,
            'blocks' => $blocks,
        ]);
    }

    /** @return array<string, mixed> */
    public static function presentMenu(Menu $menu): array
    {
        if (! $menu->relationLoaded('allItems')) {
            $menu->load(['allItems.translations']);
        }

        return [
            'id' => $menu->id,
            'code' => $menu->code,
            'name' => $menu->name,
            'items' => self::presentItemForest($menu->allItems),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentBlock(CmsBlock $block): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $block->id,
            'code' => $block->code,
            'title' => $block->localizedTitle($locale),
            'location' => $block->location,
            'content' => HtmlSanitizer::clean($block->localizedContent($locale)),
            'position' => $block->position,
        ];
    }

    /**
     * Build nested menu items from a flat collection (any depth).
     *
     * @param  Collection<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    protected static function presentItemForest(Collection $items): array
    {
        $locale = app()->getLocale();
        $byParent = $items->groupBy(fn (MenuItem $item) => $item->parent_id === null ? 0 : (int) $item->parent_id);

        $walk = function (int $parentId) use (&$walk, $byParent, $locale): array {
            return ($byParent->get($parentId) ?? collect())
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'label' => $item->localizedLabel($locale),
                    'url' => $item->url,
                    'children' => $walk((int) $item->id),
                ])
                ->all();
        };

        return $walk(0);
    }
}
