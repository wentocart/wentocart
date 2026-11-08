<?php

namespace Modules\Weblytic\Mainmenu\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;
use Modules\Weblytic\Mainmenu\Support\CatalogData;
use Modules\Weblytic\Mainmenu\Support\CoreMenuMigrator;
use Modules\Weblytic\Mainmenu\Support\LinkStore;
use Modules\Weblytic\Mainmenu\Support\MenuConfig;
use Modules\Weblytic\Mainmenu\Support\MenuResolver;
use Symfony\Component\HttpFoundation\JsonResponse;

class MenuPayloadController extends ModuleController
{
    public function indexResponse(ModuleRequest $request): JsonResponse
    {
        $this->maybeMigrate();
        $locale = strtolower(trim((string) ($request->all()['locale'] ?? app()->getLocale() ?: 'en')));
        $items = (new MenuResolver())->resolve(
            $this->menuConfig()->items(),
            $this->linkStore()->keyed(),
            $this->catalogBundle($locale)
        );

        return response()->json(['items' => $items]);
    }

    public function index(ModuleRequest $request): array
    {
        $this->maybeMigrate();
        $items = (new MenuResolver())->resolve(
            $this->menuConfig()->items(),
            $this->linkStore()->keyed(),
            $this->catalogBundle(app()->getLocale() ?: 'en')
        );

        return $this->page([
            'title' => 'Main menu payload',
            'hint' => 'Resolved menu preview for the storefront header. Theme settings must use Custom menu (Main menu).',
            'blocks' => [
                [
                    'type' => 'card',
                    'title' => 'Top-level items',
                    'text' => (string) count($items).' root item(s) resolved.',
                ],
                [
                    'type' => 'section',
                    'title' => 'Fetch URL',
                    'text' => '/api/v1/modules/weblytic/mainmenu/menu',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogBundle(string $locale): array
    {
        return [
            'locale' => $locale,
            'categories' => $this->query()->rows('categories', ['id', 'parent_id', 'is_enabled', 'position'], 2000),
            'category_translations' => $this->query()->rows('category_translations', ['category_id', 'language_code', 'name', 'slug'], 2000),
            'cms_pages' => $this->query()->rows('cms_pages', ['id', 'is_enabled', 'is_visible', 'status'], 2000),
            'cms_page_translations' => $this->query()->rows('cms_page_translations', ['cms_page_id', 'language_code', 'title', 'slug'], 2000),
            'url_rewrites' => $this->query()->rows('url_rewrites', ['language_code', 'request_path', 'entity_type', 'entity_id'], 2000),
        ];
    }

    protected function maybeMigrate(): void
    {
        $config = $this->menuConfig();
        if ($config->migratedFromCore() || $config->items() !== []) {
            return;
        }
        $payload = (new CoreMenuMigrator())->fromCoreTables(
            $this->query()->rows('menus', ['id', 'code', 'name'], 50),
            $this->query()->rows('menu_items', ['id', 'menu_id', 'parent_id', 'label', 'url', 'position'], 2000),
            $this->query()->rows('menu_item_translations', ['menu_item_id', 'language_code', 'label'], 2000),
            'main'
        );
        if ($payload === null) {
            $config->markMigratedFromCore();

            return;
        }
        foreach ($payload['links'] as $link) {
            $this->linkStore()->save($link);
        }
        $config->setItems($payload['items']);
        $config->markMigratedFromCore();
    }

    protected function menuConfig(): MenuConfig
    {
        return new MenuConfig($this->settings());
    }

    protected function linkStore(): LinkStore
    {
        return new LinkStore($this->settings());
    }
}
