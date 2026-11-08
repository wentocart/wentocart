<?php

namespace Modules\Weblytic\Mainmenu\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;
use Modules\Weblytic\Mainmenu\Support\CatalogData;
use Modules\Weblytic\Mainmenu\Support\CoreMenuMigrator;
use Modules\Weblytic\Mainmenu\Support\LinkStore;
use Modules\Weblytic\Mainmenu\Support\MenuConfig;

class ConfigureController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        $this->maybeMigrate();
        $move = $request->string('move');
        $item = $request->string('item');
        if (in_array($move, ['up', 'down'], true) && $item !== '') {
            $this->moveItem($item, $move);

            return $this->page([
                'title' => 'Main menu',
                'redirect' => '/admin/modules/weblytic/mainmenu/configure',
            ]);
        }

        return $this->configurePage();
    }

    public function store(ModuleRequest $request): array
    {
        $this->maybeMigrate();
        $bulk = $this->selectedList($request);
        if ($bulk['action'] !== '') {
            if ($bulk['ids'] === []) {
                return $this->invalid($this->configurePage(), ['Select at least one menu item.']);
            }
            if ($bulk['action'] === 'delete') {
                $this->removeItems($bulk['ids']);
            }
            if ($bulk['action'] === 'enable') {
                $this->setEnabled($bulk['ids'], true);
            }
            if ($bulk['action'] === 'disable') {
                $this->setEnabled($bulk['ids'], false);
            }

            return $this->configurePage();
        }

        $values = $request->all()['values'] ?? [];
        if (! is_array($values)) {
            $values = [];
        }

        $wantsAdd = trim((string) ($values['category_ref'] ?? '')) !== ''
            || trim((string) ($values['cms_ref'] ?? '')) !== ''
            || trim((string) ($values['link_ref'] ?? '')) !== '';
        if ($wantsAdd) {
            $error = $this->addItem($values);
            if ($error !== null) {
                return $this->invalid($this->configurePage($values), $error, $values);
            }
        }

        return $this->configurePage();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function configurePage(array $values = []): array
    {
        $config = $this->menuConfig();
        $links = $this->linkStore()->keyed();
        $locale = $this->defaultLocale();
        $catalog = new CatalogData();
        $categories = $this->query()->rows('categories', ['id', 'parent_id', 'is_enabled', 'position'], 2000);
        $catTrans = $this->query()->rows('category_translations', ['category_id', 'language_code', 'name', 'slug'], 2000);
        $cmsPages = $this->query()->rows('cms_pages', ['id', 'is_enabled', 'is_visible', 'status'], 2000);
        $cmsTrans = $this->query()->rows('cms_page_translations', ['cms_page_id', 'language_code', 'title', 'slug'], 2000);

        $labelsById = [];
        foreach ($config->items() as $item) {
            $labelsById[(string) $item['id']] = $this->itemLabel($item, $links, $catTrans, $cmsTrans, $locale);
        }

        $ordered = $config->items();
        usort($ordered, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

        $rows = [];
        $last = count($ordered) - 1;
        foreach ($ordered as $index => $item) {
            $id = (string) $item['id'];
            $type = (string) $item['type'];
            $parentId = (string) ($item['parent_id'] ?? '');
            $enabled = ! empty($item['enabled']);
            $row = [
                'id' => $id,
                'order' => (string) ($index + 1),
                'type' => match ($type) {
                    'category' => 'Category',
                    'cms' => 'CMS page',
                    default => 'Custom link',
                },
                'label' => $labelsById[$id] ?? $id,
                'parent' => $parentId === '' ? 'Top level' : ($labelsById[$parentId] ?? 'Top level'),
                'visibility' => $enabled ? 'Visible' : 'Hidden',
                'visibility_tone' => $enabled ? 'success' : 'warning',
                'edit' => false,
                'actions' => [],
            ];
            if ($index > 0) {
                $row['actions'][] = [
                    'label' => 'Move up',
                    'label_key' => 'module.weblytic.mainmenu.configure.move_up',
                    'href' => '/admin/modules/weblytic/mainmenu/configure?move=up&item='.rawurlencode($id),
                    'icon' => 'chevron-up',
                ];
            }
            if ($index < $last) {
                $row['actions'][] = [
                    'label' => 'Move down',
                    'label_key' => 'module.weblytic.mainmenu.configure.move_down',
                    'href' => '/admin/modules/weblytic/mainmenu/configure?move=down&item='.rawurlencode($id),
                    'icon' => 'chevron-down',
                ];
            }
            if ($type === 'link') {
                $row['actions'][] = [
                    'label' => 'Edit link',
                    'label_key' => 'module.weblytic.mainmenu.configure.edit_link',
                    'href' => '/admin/modules/weblytic/mainmenu/links?id='.rawurlencode((string) $item['ref']),
                    'icon' => 'pencil',
                ];
            }
            $rows[] = $row;
        }

        $parentOptions = [['value' => '', 'label' => 'Top level']];
        foreach ($ordered as $item) {
            $parentOptions[] = [
                'value' => (string) $item['id'],
                'label' => $labelsById[(string) $item['id']] ?? (string) $item['id'],
            ];
        }

        $linkOptions = [['value' => '', 'label' => 'Select a custom link']];
        foreach ($this->linkStore()->all() as $link) {
            $linkOptions[] = [
                'value' => (string) $link['id'],
                'label' => (string) $link['label'],
            ];
        }

        return $this->page([
            'title' => 'Main menu',
            'title_key' => 'module.weblytic.mainmenu.configure.title',
            'hint' => '',
            'layout' => 'config',
            'form_title' => 'Add to menu',
            'form_title_key' => 'module.weblytic.mainmenu.configure.form_title',
            'save_label' => 'Add to menu',
            'save_label_key' => 'module.weblytic.mainmenu.configure.save',
            'assets' => ['css' => ['assets/admin.css']],
            'list' => [
                'selectable' => true,
                'columns' => [
                    ['key' => 'order', 'label' => '#', 'label_key' => 'module.weblytic.mainmenu.configure.col.order'],
                    ['key' => 'label', 'label' => 'Item', 'label_key' => 'module.weblytic.mainmenu.configure.col.label'],
                    ['key' => 'type', 'label' => 'Type', 'label_key' => 'module.weblytic.mainmenu.configure.col.type'],
                    ['key' => 'parent', 'label' => 'Placed under', 'label_key' => 'module.weblytic.mainmenu.configure.col.parent'],
                    ['key' => 'visibility', 'label' => 'Visibility', 'label_key' => 'module.weblytic.mainmenu.configure.col.visibility', 'badge' => true],
                ],
                'rows' => $rows,
                'bulk' => [
                    ['key' => 'enable', 'label' => 'Show', 'label_key' => 'module.weblytic.mainmenu.configure.bulk.enable'],
                    ['key' => 'disable', 'label' => 'Hide', 'label_key' => 'module.weblytic.mainmenu.configure.bulk.disable'],
                    ['key' => 'delete', 'label' => 'Remove', 'label_key' => 'module.weblytic.mainmenu.configure.bulk.delete', 'icon' => 'trash'],
                ],
                'empty' => 'Your menu is empty. Add a category, a page, or a custom link.',
                'empty_key' => 'module.weblytic.mainmenu.configure.empty',
                'delete_confirm' => 'Remove the selected items from the menu?',
                'delete_confirm_key' => 'module.weblytic.mainmenu.configure.delete_confirm',
                'create' => [
                    'href' => '/admin/modules/weblytic/mainmenu/links?new=1',
                    'label' => 'New custom link',
                    'label_key' => 'module.weblytic.mainmenu.configure.create',
                    'icon' => 'plus',
                ],
            ],
            'fields' => [
                [
                    'name' => 'add_type',
                    'type' => 'select',
                    'inline' => true,
                    'label' => 'What do you want to add?',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.add_type',
                    'options' => [
                        ['value' => 'category', 'label' => 'Category', 'label_key' => 'module.weblytic.mainmenu.configure.option.category'],
                        ['value' => 'cms', 'label' => 'CMS page', 'label_key' => 'module.weblytic.mainmenu.configure.option.cms'],
                        ['value' => 'link', 'label' => 'Custom link', 'label_key' => 'module.weblytic.mainmenu.configure.option.link'],
                    ],
                ],
                [
                    'name' => 'category_ref',
                    'type' => 'select',
                    'inline' => true,
                    'label' => 'Category',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.category',
                    'show_when' => ['field' => 'add_type', 'equals' => 'category'],
                    'options' => array_merge(
                        [['value' => '', 'label' => 'Select a category']],
                        $catalog->categoryOptions($categories, $catTrans, $locale)
                    ),
                ],
                [
                    'name' => 'cms_ref',
                    'type' => 'select',
                    'inline' => true,
                    'label' => 'CMS page',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.cms',
                    'show_when' => ['field' => 'add_type', 'equals' => 'cms'],
                    'options' => array_merge(
                        [['value' => '', 'label' => 'Select a page']],
                        $catalog->cmsOptions($cmsPages, $cmsTrans, $locale)
                    ),
                ],
                [
                    'name' => 'link_ref',
                    'type' => 'select',
                    'inline' => true,
                    'label' => 'Custom link',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.link',
                    'show_when' => ['field' => 'add_type', 'equals' => 'link'],
                    'options' => $linkOptions,
                ],
                [
                    'name' => 'parent_id',
                    'type' => 'select',
                    'inline' => true,
                    'label' => 'Place under',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.parent',
                    'hint' => 'Optional. Nest under another top-level menu entry.',
                    'hint_key' => 'module.weblytic.mainmenu.configure.field.parent_hint',
                    'options' => $parentOptions,
                ],
                [
                    'name' => 'new_window',
                    'type' => 'toggle',
                    'inline' => true,
                    'label' => 'Open in a new tab',
                    'label_key' => 'module.weblytic.mainmenu.configure.field.new_window',
                ],
            ],
            'values' => array_merge([
                'add_type' => 'category',
                'category_ref' => '',
                'cms_ref' => '',
                'link_ref' => '',
                'parent_id' => '',
                'new_window' => false,
            ], $values),
        ]);
    }

    protected function moveItem(string $id, string $direction): void
    {
        $items = $this->menuConfig()->items();
        usort($items, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
        $index = null;
        foreach ($items as $i => $item) {
            if ((string) $item['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($items[$swap])) {
            return;
        }
        $position = (int) ($items[$index]['position'] ?? $index);
        $items[$index]['position'] = (int) ($items[$swap]['position'] ?? $swap);
        $items[$swap]['position'] = $position;
        if ((int) $items[$index]['position'] === (int) $items[$swap]['position']) {
            $items[$index]['position'] = $swap;
            $items[$swap]['position'] = $index;
        }
        $this->menuConfig()->setItems($items);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>|null
     */
    protected function addItem(array $values): ?array
    {
        $type = strtolower(trim((string) ($values['add_type'] ?? 'category')));
        if (! in_array($type, ['category', 'cms', 'link'], true)) {
            return ['add_type' => 'Choose a valid item type.'];
        }
        $ref = match ($type) {
            'category' => trim((string) ($values['category_ref'] ?? '')),
            'cms' => trim((string) ($values['cms_ref'] ?? '')),
            default => trim((string) ($values['link_ref'] ?? '')),
        };
        if ($ref === '') {
            return match ($type) {
                'category' => ['category_ref' => 'Select a category.'],
                'cms' => ['cms_ref' => 'Select a CMS page.'],
                default => ['link_ref' => 'Select a custom link (create one under Links first).'],
            };
        }
        if ($type === 'link' && ! $this->linkStore()->find($ref)) {
            return ['link_ref' => 'Custom link was not found.'];
        }
        if ($type === 'category') {
            $found = false;
            foreach ($this->query()->rows('categories', ['id', 'is_enabled'], 2000) as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $ref) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return ['category_ref' => 'Category was not found.'];
            }
        }
        if ($type === 'cms') {
            $found = false;
            foreach ($this->query()->rows('cms_pages', ['id'], 2000) as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $ref) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return ['cms_ref' => 'CMS page was not found.'];
            }
        }

        $items = $this->menuConfig()->items();
        $prefix = $type === 'category' ? 'CAT' : ($type === 'cms' ? 'CMS' : 'LNK');
        $id = $prefix.'-'.$ref;
        foreach ($items as $existing) {
            if ((string) $existing['id'] === $id) {
                return ['add_type' => 'That item is already in the menu.'];
            }
        }
        $maxPos = -1;
        foreach ($items as $existing) {
            $maxPos = max($maxPos, (int) ($existing['position'] ?? 0));
        }
        $parentId = trim((string) ($values['parent_id'] ?? ''));
        $byId = [];
        foreach ($items as $existing) {
            $byId[(string) $existing['id']] = $existing;
        }
        $byId[$id] = ['id' => $id, 'parent_id' => $parentId];
        if ($parentId !== '' && $this->menuConfig()->wouldCreateCycle($id, $parentId, $byId)) {
            return ['parent_id' => 'That parent would create a circular menu relationship.'];
        }
        $items[] = [
            'id' => $id,
            'type' => $type,
            'ref' => $ref,
            'enabled' => true,
            'new_window' => ! empty($values['new_window']),
            'parent_id' => $parentId,
            'position' => $maxPos + 1,
            'label_override' => '',
            'labels' => [],
        ];
        $this->menuConfig()->setItems($items);

        return null;
    }

    protected function applyPositions(string $raw): ?string
    {
        $map = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$id, $pos] = array_map('trim', explode('=', $line, 2));
            if ($id === '' || ! preg_match('/^[a-zA-Z0-9_.:\-]+$/', $id)) {
                return 'Invalid id in order list.';
            }
            $map[$id] = max(0, (int) $pos);
        }
        $items = $this->menuConfig()->items();
        foreach ($items as &$item) {
            $id = (string) $item['id'];
            if (array_key_exists($id, $map)) {
                $item['position'] = $map[$id];
            }
        }
        unset($item);
        usort($items, fn ($a, $b) => ((int) $a['position']) <=> ((int) $b['position']));
        $this->menuConfig()->setItems($items);

        return null;
    }

    /**
     * @param  list<string>  $ids
     */
    protected function removeItems(array $ids): void
    {
        $idSet = array_fill_keys($ids, true);
        $keep = [];
        foreach ($this->menuConfig()->items() as $item) {
            if (isset($idSet[(string) $item['id']])) {
                continue;
            }
            $parent = (string) ($item['parent_id'] ?? '');
            if ($parent !== '' && isset($idSet[$parent])) {
                $item['parent_id'] = '';
            }
            $keep[] = $item;
        }
        $this->menuConfig()->setItems($keep);
    }

    /**
     * @param  list<string>  $ids
     */
    protected function setEnabled(array $ids, bool $enabled): void
    {
        $items = $this->menuConfig()->items();
        foreach ($items as &$item) {
            if (in_array((string) $item['id'], $ids, true)) {
                $item['enabled'] = $enabled;
            }
        }
        unset($item);
        $this->menuConfig()->setItems($items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $catTrans
     * @param  list<array<string, mixed>>  $cmsTrans
     */
    protected function itemLabel(array $item, array $links, array $catTrans, array $cmsTrans, string $locale): string
    {
        if (trim((string) ($item['label_override'] ?? '')) !== '') {
            return (string) $item['label_override'];
        }
        $type = (string) ($item['type'] ?? '');
        $ref = (string) ($item['ref'] ?? '');
        if ($type === 'link') {
            return (string) ($links[$ref]['label'] ?? $ref);
        }
        if ($type === 'category') {
            foreach ($catTrans as $row) {
                if ((int) ($row['category_id'] ?? 0) === (int) $ref) {
                    return (string) ($row['name'] ?? $ref);
                }
            }

            return 'Category '.$ref;
        }
        foreach ($cmsTrans as $row) {
            if ((int) ($row['cms_page_id'] ?? 0) === (int) $ref) {
                return (string) ($row['title'] ?? $ref);
            }
        }

        return 'CMS '.$ref;
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

    protected function defaultLocale(): string
    {
        foreach ($this->query()->rows('languages', ['code', 'name', 'is_enabled', 'is_default'], 50) as $row) {
            if ((int) ($row['is_default'] ?? 0) === 1) {
                return strtolower((string) $row['code']);
            }
        }

        return 'en';
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
