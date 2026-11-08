<?php

namespace Modules\Weblytic\Mainmenu\Support;

/**
 * Resolves CAT / CMS / LNK tokens into the storefront menu tree.
 * Pure PHP — catalog rows are injected by the controller (Safe Extension rule).
 */
class MenuResolver
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $links
     * @param  array{
     *   locale?: string,
     *   categories?: list<array<string, mixed>>,
     *   category_translations?: list<array<string, mixed>>,
     *   cms_pages?: list<array<string, mixed>>,
     *   cms_page_translations?: list<array<string, mixed>>,
     *   url_rewrites?: list<array<string, mixed>>
     * }  $catalog
     * @return list<array<string, mixed>>
     */
    public function resolve(array $items, array $links, array $catalog = []): array
    {
        $locale = strtolower((string) ($catalog['locale'] ?? 'en'));
        $paths = $this->pathIndex($catalog['url_rewrites'] ?? [], $locale);
        $catNames = $this->translationIndex($catalog['category_translations'] ?? [], 'category_id', 'name', $locale);
        $catSlugs = $this->translationIndex($catalog['category_translations'] ?? [], 'category_id', 'slug', $locale);
        $cmsTitles = $this->translationIndex($catalog['cms_page_translations'] ?? [], 'cms_page_id', 'title', $locale);
        $cmsSlugs = $this->translationIndex($catalog['cms_page_translations'] ?? [], 'cms_page_id', 'slug', $locale);
        $categories = $this->categoryIndex($catalog['categories'] ?? []);
        $cmsPages = $this->cmsIndex($catalog['cms_pages'] ?? []);

        $enabledItems = array_values(array_filter($items, fn ($row) => ($row['enabled'] ?? true) !== false));
        usort($enabledItems, fn ($a, $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

        $nodes = [];
        foreach ($enabledItems as $item) {
            $node = $this->buildNode($item, $links, $locale, $paths, $catNames, $catSlugs, $cmsTitles, $cmsSlugs, $categories, $cmsPages);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $this->nest($nodes);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $links
     * @param  array<string, string>  $paths
     * @param  array<int, string>  $catNames
     * @param  array<int, string>  $catSlugs
     * @param  array<int, string>  $cmsTitles
     * @param  array<int, string>  $cmsSlugs
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, array<string, mixed>>  $cmsPages
     * @return array<string, mixed>|null
     */
    protected function buildNode(
        array $item,
        array $links,
        string $locale,
        array $paths,
        array $catNames,
        array $catSlugs,
        array $cmsTitles,
        array $cmsSlugs,
        array $categories,
        array $cmsPages,
    ): ?array {
        $type = (string) ($item['type'] ?? '');
        $ref = (string) ($item['ref'] ?? '');
        $id = (string) ($item['id'] ?? '');
        $override = trim((string) ($item['label_override'] ?? ''));
        $labels = is_array($item['labels'] ?? null) ? $item['labels'] : [];
        $label = $this->pickLabel($labels, $locale, $override);
        $newWindow = (bool) ($item['new_window'] ?? false);
        $parentId = trim((string) ($item['parent_id'] ?? ''));

        if ($type === 'link') {
            $link = $links[$ref] ?? null;
            if (! $link || (($link['enabled'] ?? true) === false)) {
                return null;
            }
            $linkLabels = is_array($link['labels'] ?? null) ? $link['labels'] : [];
            $label = $this->pickLabel($linkLabels, $locale, $label !== '' ? $label : (string) ($link['label'] ?? ''));
            if ($label === '') {
                return null;
            }

            return [
                'id' => $id,
                'label' => $label,
                'url' => (string) ($link['url'] ?? '/'),
                'children' => [],
                'open_in_new_window' => $newWindow || (bool) ($link['new_window'] ?? false),
                'enabled' => true,
                'parent_id' => $parentId,
                '_type' => 'link',
            ];
        }

        if ($type === 'cms') {
            $pageId = (int) $ref;
            $page = $cmsPages[$pageId] ?? null;
            if (! $page || ! (int) ($page['is_enabled'] ?? 0) || ! (int) ($page['is_visible'] ?? 0)) {
                return null;
            }
            if (strtolower((string) ($page['status'] ?? '')) !== 'published') {
                return null;
            }
            $title = $label !== '' ? $label : ($cmsTitles[$pageId] ?? ('Page '.$pageId));
            $path = $paths['cms:'.$pageId] ?? ($cmsSlugs[$pageId] ?? '');
            if ($path === '') {
                return null;
            }

            return [
                'id' => $id,
                'label' => $title,
                'url' => $this->publicPath($path),
                'children' => [],
                'open_in_new_window' => $newWindow,
                'enabled' => true,
                'parent_id' => $parentId,
                '_type' => 'cms',
            ];
        }

        if ($type === 'category') {
            $categoryId = (int) $ref;
            $category = $categories[$categoryId] ?? null;
            if (! $category || ! (int) ($category['is_enabled'] ?? 0)) {
                return null;
            }
            $name = $label !== '' ? $label : ($catNames[$categoryId] ?? ('Category '.$categoryId));
            $path = $paths['category:'.$categoryId] ?? ($catSlugs[$categoryId] ?? '');
            if ($path === '') {
                return null;
            }
            $children = $this->categoryChildren($categoryId, $categories, $catNames, $catSlugs, $paths);

            return [
                'id' => $id,
                'label' => $name,
                'url' => $this->publicPath($path),
                'children' => $children,
                'open_in_new_window' => $newWindow,
                'enabled' => true,
                'parent_id' => $parentId,
                '_type' => 'category',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<int, string>  $catNames
     * @param  array<int, string>  $catSlugs
     * @param  array<string, string>  $paths
     * @return list<array<string, mixed>>
     */
    protected function categoryChildren(int $parentId, array $categories, array $catNames, array $catSlugs, array $paths): array
    {
        $kids = [];
        foreach ($categories as $category) {
            if ((int) ($category['parent_id'] ?? 0) !== $parentId) {
                continue;
            }
            if (! (int) ($category['is_enabled'] ?? 0)) {
                continue;
            }
            $id = (int) ($category['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $path = $paths['category:'.$id] ?? ($catSlugs[$id] ?? '');
            if ($path === '') {
                continue;
            }
            $kids[] = [
                'id' => 'CAT-'.$id,
                'label' => $catNames[$id] ?? ('Category '.$id),
                'url' => $this->publicPath($path),
                'children' => $this->categoryChildren($id, $categories, $catNames, $catSlugs, $paths),
                'open_in_new_window' => false,
                'enabled' => true,
                '_position' => (int) ($category['position'] ?? 0),
            ];
        }
        usort($kids, function ($a, $b) {
            $pos = ((int) ($a['_position'] ?? 0)) <=> ((int) ($b['_position'] ?? 0));
            if ($pos !== 0) {
                return $pos;
            }

            return strcmp((string) $a['label'], (string) $b['label']);
        });
        foreach ($kids as &$kid) {
            unset($kid['_position']);
        }
        unset($kid);

        return $kids;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    protected function nest(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $byId[(string) $node['id']] = true;
        }

        $childrenOf = [];
        foreach ($nodes as $node) {
            $parent = (string) ($node['parent_id'] ?? '');
            if ($parent !== '' && isset($byId[$parent]) && $parent !== (string) $node['id']) {
                $childrenOf[$parent][] = $node;
            }
        }

        $roots = [];
        foreach ($nodes as $node) {
            $parent = (string) ($node['parent_id'] ?? '');
            if ($parent === '' || ! isset($byId[$parent]) || $parent === (string) $node['id']) {
                $roots[] = $this->attachMenuChildren($node, $childrenOf);
            }
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, list<array<string, mixed>>>  $childrenOf
     * @return array<string, mixed>
     */
    protected function attachMenuChildren(array $node, array $childrenOf): array
    {
        $id = (string) $node['id'];
        $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($childrenOf[$id] ?? [] as $child) {
            $kids[] = $this->attachMenuChildren($child, $childrenOf);
        }
        unset($node['parent_id'], $node['_type']);
        $node['children'] = array_values($kids);

        return $node;
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function pickLabel(array $labels, string $locale, string $fallback): string
    {
        if (isset($labels[$locale]) && trim($labels[$locale]) !== '') {
            return trim($labels[$locale]);
        }
        $short = substr($locale, 0, 2);
        if (isset($labels[$short]) && trim($labels[$short]) !== '') {
            return trim($labels[$short]);
        }
        foreach ($labels as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return trim($fallback);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    protected function pathIndex(array $rows, string $locale): array
    {
        $out = [];
        $fallback = [];
        foreach ($rows as $row) {
            $type = (string) ($row['entity_type'] ?? '');
            $id = (int) ($row['entity_id'] ?? 0);
            $path = trim((string) ($row['request_path'] ?? ''), '/');
            $lang = strtolower((string) ($row['language_code'] ?? ''));
            if ($type === '' || $id < 1 || $path === '') {
                continue;
            }
            $key = $type.':'.$id;
            if ($lang === $locale || $lang === substr($locale, 0, 2)) {
                $out[$key] = $path;
            } elseif (! isset($fallback[$key])) {
                $fallback[$key] = $path;
            }
        }

        return $out + $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    protected function translationIndex(array $rows, string $fk, string $field, string $locale): array
    {
        $out = [];
        $fallback = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$fk] ?? 0);
            $lang = strtolower((string) ($row['language_code'] ?? ''));
            $value = trim((string) ($row[$field] ?? ''));
            if ($id < 1 || $value === '') {
                continue;
            }
            if ($lang === $locale || $lang === substr($locale, 0, 2)) {
                $out[$id] = $value;
            } elseif (! isset($fallback[$id])) {
                $fallback[$id] = $value;
            }
        }

        return $out + $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function categoryIndex(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out[$id] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function cmsIndex(array $rows): array
    {
        return $this->categoryIndex($rows);
    }

    protected function publicPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
