<?php

namespace Modules\Weblytic\Mainmenu\Support;

/**
 * One-time import of legacy core `menus` / `menu_items` into extension settings.
 * Pure PHP — rows are injected by the controller via ModuleQuery.
 */
class CoreMenuMigrator
{
    /**
     * @param  list<array<string, mixed>>  $menus
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $translations
     * @return array{items: list<array<string, mixed>>, links: list<array<string, mixed>>}|null
     */
    public function fromCoreTables(array $menus, array $items, array $translations, string $code = 'main'): ?array
    {
        $menuId = null;
        foreach ($menus as $menu) {
            if ((string) ($menu['code'] ?? '') === $code) {
                $menuId = (int) ($menu['id'] ?? 0);
                break;
            }
        }
        if (! $menuId) {
            return null;
        }

        $labelByItemLocale = [];
        foreach ($translations as $row) {
            $itemId = (int) ($row['menu_item_id'] ?? 0);
            $locale = strtolower((string) ($row['language_code'] ?? ''));
            $label = trim(strip_tags((string) ($row['label'] ?? '')));
            if ($itemId < 1 || $locale === '' || $label === '') {
                continue;
            }
            $labelByItemLocale[$itemId][$locale] = mb_substr($label, 0, 120);
        }

        $owned = array_values(array_filter(
            $items,
            fn ($row) => (int) ($row['menu_id'] ?? 0) === $menuId
        ));
        usort($owned, function ($a, $b) {
            $p = ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0));

            return $p !== 0 ? $p : ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $idMap = [];
        $links = [];
        $configItems = [];
        $position = 0;
        foreach ($owned as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            if ($oldId < 1) {
                continue;
            }
            $linkId = 'migrated-'.$oldId;
            $idMap[$oldId] = $linkId;
            $label = trim(strip_tags((string) ($row['label'] ?? '')));
            $url = trim((string) ($row['url'] ?? '/'));
            if ($label === '') {
                $label = 'Link '.$oldId;
            }
            if ($url === '') {
                $url = '/';
            }
            if ($url[0] !== '/' && ! preg_match('#^https?://#i', $url)) {
                $url = '/'.$url;
            }
            $links[] = [
                'id' => $linkId,
                'label' => mb_substr($label, 0, 120),
                'url' => mb_substr($url, 0, 500),
                'labels' => $labelByItemLocale[$oldId] ?? [],
                'new_window' => false,
                'enabled' => true,
            ];
            $configItems[] = [
                'id' => 'LNK-'.$linkId,
                'type' => 'link',
                'ref' => $linkId,
                'enabled' => true,
                'new_window' => false,
                'parent_id' => '',
                'position' => $position++,
                'label_override' => '',
                'labels' => [],
                '_old_parent' => (int) ($row['parent_id'] ?? 0),
            ];
        }

        foreach ($configItems as &$item) {
            $oldParent = (int) ($item['_old_parent'] ?? 0);
            unset($item['_old_parent']);
            if ($oldParent > 0 && isset($idMap[$oldParent])) {
                $item['parent_id'] = 'LNK-'.$idMap[$oldParent];
            }
        }
        unset($item);

        if ($configItems === []) {
            return null;
        }

        return ['items' => $configItems, 'links' => $links];
    }
}
