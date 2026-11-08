<?php

namespace App\Domain\Plugin;

use App\Domain\Localization\Models\Language;
use App\Domain\Plugin\Support\ExtensionUpload;

final class PageDocument
{
    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function sanitize(array $doc): array
    {
        $title = self::plain((string) ($doc['title'] ?? 'App'));
        $hint = self::plain((string) ($doc['hint'] ?? ''));
        $assets = is_array($doc['assets'] ?? null) ? $doc['assets'] : [];
        $css = [];
        foreach ((array) ($assets['css'] ?? []) as $file) {
            if (is_string($file) && preg_match('#^assets/[a-z0-9._/-]+\.css$#', $file)) {
                $css[] = $file;
            }
        }
        $js = [];
        foreach ((array) ($assets['js'] ?? []) as $file) {
            if (is_string($file) && preg_match('#^assets/[a-z0-9._/-]+\.js$#', $file)) {
                $js[] = $file;
            }
        }

        $redirect = self::safeHref((string) ($doc['redirect'] ?? ''));
        $mount = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($doc['mount'] ?? ''))) ?? '';
        $fields = self::fields($doc['fields'] ?? []);
        $tabs = self::tabs($doc['tabs'] ?? []);
        $allFields = self::collectFields(['fields' => $fields, 'tabs' => $tabs]);

        $out = [
            'title' => $title !== '' ? $title : 'App',
            'hint' => $hint,
            'layout' => in_array((string) ($doc['layout'] ?? ''), ['config', 'stack', 'tabs'], true) ? (string) $doc['layout'] : 'stack',
            'assets' => ['css' => $css, 'js' => $js],
            'fields' => $fields,
            'blocks' => self::blocks($doc['blocks'] ?? []),
            'tabs' => $tabs,
            'nav' => self::nav($doc['nav'] ?? []),
            'sidebar_actions' => self::sidebarActions($doc['sidebar_actions'] ?? []),
            'redirect' => $redirect,
            'reload' => ($doc['reload'] ?? false) === true,
            'mount' => $mount !== '' ? $mount : null,
            'errors' => self::errorMessages($doc['errors'] ?? []),
            'field_errors' => self::fieldErrors($doc['field_errors'] ?? [], $allFields),
            'error_placement' => ((string) ($doc['error_placement'] ?? 'top')) === 'bottom' ? 'bottom' : 'top',
            'values' => self::overlayValues($allFields, is_array($doc['values'] ?? null) ? $doc['values'] : []),
            'list' => self::list($doc['list'] ?? null),
            'kpis' => self::kpis($doc['kpis'] ?? (is_array($doc['list'] ?? null) ? ($doc['list']['kpis'] ?? []) : [])),
            'back' => self::navLink($doc['back'] ?? null),
            'crumbs' => self::crumbs($doc['crumbs'] ?? []),
            'form_title' => self::plain((string) ($doc['form_title'] ?? '')),
            'save_label' => self::plain((string) ($doc['save_label'] ?? '')),
        ];
        if (($key = self::i18nKey($doc['form_title_key'] ?? null)) !== null) {
            $out['form_title_key'] = $key;
        }
        if (($key = self::i18nKey($doc['save_label_key'] ?? null)) !== null) {
            $out['save_label_key'] = $key;
        }
        if (($key = self::i18nKey($doc['title_key'] ?? null)) !== null) {
            $out['title_key'] = $key;
        }
        if (($key = self::i18nKey($doc['hint_key'] ?? null)) !== null) {
            $out['hint_key'] = $key;
        }

        return $out;
    }

    /**
     * Shared admin KPI cards (exactly four pastel cards — same look as Catalog lists).
     *
     * @param  mixed  $kpis
     * @return list<array{key: string, label: string, value: string, tone: string, trend: float}>
     */
    public static function kpis(mixed $kpis): array
    {
        $tones = ['sky', 'emerald', 'violet', 'amber'];
        $out = [];
        foreach (is_array($kpis) ? $kpis : [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = self::plain((string) ($item['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($item['key'] ?? ''))) ?? '';
            if ($key === '') {
                $key = 'kpi_'.$index;
            }
            $tone = (string) ($item['tone'] ?? $tones[$index] ?? 'sky');
            if (! in_array($tone, $tones, true)) {
                $tone = $tones[$index % 4];
            }
            $kpi = [
                'key' => $key,
                'label' => $label,
                'value' => self::plain((string) ($item['value'] ?? '0')),
                'tone' => $tone,
                'trend' => round((float) ($item['trend'] ?? 0), 1),
            ];
            if (($labelKey = self::i18nKey($item['label_key'] ?? null)) !== null) {
                $kpi['label_key'] = $labelKey;
            }
            $out[] = $kpi;
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    public static function collectFields(array $doc): array
    {
        $out = self::fields($doc['fields'] ?? []);
        foreach (self::tabs($doc['tabs'] ?? []) as $tab) {
            foreach ($tab['fields'] ?? [] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $tabs
     * @return list<array<string, mixed>>
     */
    public static function tabs(mixed $tabs): array
    {
        $out = [];
        foreach (is_array($tabs) ? $tabs : [] as $tab) {
            if (! is_array($tab)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($tab['id'] ?? ''))) ?? '';
            if ($id === '') {
                continue;
            }
            $item = [
                'id' => $id,
                'label' => self::plain((string) ($tab['label'] ?? $id)),
                'icon' => self::iconName($tab['icon'] ?? ''),
                'hint' => self::plain((string) ($tab['hint'] ?? '')),
                'fields' => self::fields($tab['fields'] ?? []),
                'blocks' => self::blocks($tab['blocks'] ?? []),
            ];
            if (($key = self::i18nKey($tab['label_key'] ?? null)) !== null) {
                $item['label_key'] = $key;
            }
            if (($key = self::i18nKey($tab['hint_key'] ?? null)) !== null) {
                $item['hint_key'] = $key;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Primary navigation tabs across extension screens (href + active).
     *
     * @param  mixed  $nav
     * @return list<array{id: string, label: string, href: string, active: bool, label_key?: string}>
     */
    public static function nav(mixed $nav): array
    {
        $out = [];
        foreach (is_array($nav) ? $nav : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($item['id'] ?? ''))) ?? '';
            $href = self::safeHref((string) ($item['href'] ?? ''));
            $label = self::plain((string) ($item['label'] ?? ''));
            if ($id === '' || $href === '' || $label === '') {
                continue;
            }
            $row = [
                'id' => $id,
                'label' => $label,
                'href' => $href,
                'active' => ($item['active'] ?? false) === true,
            ];
            if (($key = self::i18nKey($item['label_key'] ?? null)) !== null) {
                $row['label_key'] = $key;
            }
            $out[] = $row;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $actions
     * @return list<array<string, string>>
     */
    public static function sidebarActions(mixed $actions): array
    {
        $out = [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            $link = self::navLink($action);
            if ($link) {
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $link
     * @return array{label: string, href: string, icon: string}|null
     */
    public static function navLink(mixed $link): ?array
    {
        if (! is_array($link)) {
            return null;
        }
        $href = self::safeHref((string) ($link['href'] ?? ''));
        $label = self::plain((string) ($link['label'] ?? ''));
        if ($href === '' || $label === '') {
            return null;
        }

        $item = [
            'label' => $label,
            'href' => $href,
            'icon' => self::iconName($link['icon'] ?? 'download', 'download'),
        ];
        if (($key = self::i18nKey($link['label_key'] ?? null)) !== null) {
            $item['label_key'] = $key;
        }

        return $item;
    }

    /**
     * Hosted list: admin Catalog table, or Classic storefront account table.
     *
     * @param  mixed  $list
     * @return array<string, mixed>|null
     */
    public static function list(mixed $list): ?array
    {
        if (! is_array($list)) {
            return null;
        }
        $idKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($list['id_key'] ?? 'id'))) ?: 'id';
        $columns = [];
        $columnKeys = [];
        foreach ((array) ($list['columns'] ?? []) as $col) {
            if (! is_array($col)) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($col['key'] ?? ''))) ?? '';
            if ($key === '' || isset($columnKeys[$key])) {
                continue;
            }
            $columnKeys[$key] = true;
            $column = ['key' => $key, 'label' => self::plain((string) ($col['label'] ?? $key))];
            if (($labelKey = self::i18nKey($col['label_key'] ?? null)) !== null) {
                $column['label_key'] = $labelKey;
            }
            if (($col['sort'] ?? false) === true) {
                $column['sort'] = true;
            }
            if (($col['badge'] ?? false) === true) {
                $column['badge'] = true;
            }
            $filter = self::columnFilter($col['filter'] ?? null, $key);
            if ($filter !== null) {
                $column['filter'] = $filter;
            }
            $columns[] = $column;
        }
        if ($columns === []) {
            return null;
        }
        $rows = [];
        foreach ((array) ($list['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = self::listId($row[$idKey] ?? $row['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $clean = [$idKey => $id];
            foreach ($columns as $col) {
                if ($col['key'] === $idKey) {
                    continue;
                }
                $clean[$col['key']] = self::plain((string) ($row[$col['key']] ?? ''));
                if (! empty($col['badge'])) {
                    $tone = self::listTone($row[$col['key'].'_tone'] ?? $row['tone'] ?? $row['status_tone'] ?? '');
                    if ($tone !== '') {
                        $clean[$col['key'].'_tone'] = $tone;
                    }
                }
            }
            $href = self::safeHref((string) ($row['href'] ?? $row['edit_href'] ?? ''));
            if ($href !== '') {
                $clean['href'] = $href;
            }
            $viewHref = self::safeHref((string) ($row['view_href'] ?? ''));
            if ($viewHref !== '') {
                $clean['view_href'] = $viewHref;
            }
            $actions = self::listRowActions($row['actions'] ?? []);
            if ($actions !== []) {
                $clean['actions'] = $actions;
            }
            $clean['edit'] = ($row['edit'] ?? true) !== false;
            $clean['delete'] = ($row['delete'] ?? false) === true;
            $clean['duplicate'] = ($row['duplicate'] ?? false) === true;
            $rows[] = $clean;
            if (count($rows) >= 200) {
                break;
            }
        }
        $bulk = self::listBulk($list['bulk'] ?? []);
        $create = self::navLink($list['create'] ?? null);
        if ($create) {
            $create['icon'] = self::iconName($list['create']['icon'] ?? 'plus', 'plus');
        }

        $sort = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($list['sort'] ?? ''))) ?? '';
        if ($sort !== '' && ! isset($columnKeys[$sort])) {
            $sort = '';
        }
        $dir = strtolower((string) ($list['dir'] ?? ''));
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $perPageOptions = self::listPerPageOptions($list['per_page_options'] ?? $list['per_page'] ?? []);
        $perPage = (int) ($list['per_page'] ?? 0);
        if ($perPageOptions !== [] && ! in_array($perPage, $perPageOptions, true)) {
            $perPage = $perPageOptions[0];
        }
        $total = max(0, min(100000, (int) ($list['total'] ?? count($rows))));
        $page = max(1, (int) ($list['page'] ?? 1));
        $lastPage = max(1, (int) ($list['last_page'] ?? ($perPage > 0 ? (int) ceil(max(1, $total) / max(1, $perPage)) : 1)));

        $out = [
            'id_key' => $idKey,
            'columns' => $columns,
            'rows' => $rows,
            'bulk' => $bulk,
            'selectable' => $bulk !== [] && ($list['selectable'] ?? true) !== false,
            'empty' => self::plain((string) ($list['empty'] ?? '')),
            'delete_confirm' => self::plain((string) ($list['delete_confirm'] ?? '')),
            'create' => $create,
            'search' => ($list['search'] ?? false) === true,
            'filters' => self::listFilters($list['filters'] ?? [], $columns),
            'per_page_options' => $perPageOptions,
            'per_page' => $perPage > 0 ? $perPage : null,
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
            'sort' => $sort !== '' ? $sort : null,
            'dir' => $sort !== '' ? $dir : null,
        ];
        if (($key = self::i18nKey($list['empty_key'] ?? null)) !== null) {
            $out['empty_key'] = $key;
        }
        if (($key = self::i18nKey($list['delete_confirm_key'] ?? null)) !== null) {
            $out['delete_confirm_key'] = $key;
        }

        return $out;
    }

    /**
     * Per-column filter definition for Catalog-style hosted lists.
     *
     * @return array<string, mixed>|null
     */
    public static function columnFilter(mixed $filter, string $columnKey): ?array
    {
        if ($filter === true) {
            return [
                'type' => 'text',
                'param' => $columnKey,
                'placeholder' => '',
            ];
        }
        if (! is_array($filter)) {
            return null;
        }

        $type = strtolower((string) ($filter['type'] ?? 'text'));
        if (! in_array($type, ['text', 'select', 'date', 'range'], true)) {
            $type = 'text';
        }

        $param = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($filter['param'] ?? $columnKey))) ?? '';
        if ($param === '') {
            $param = $columnKey;
        }

        $out = [
            'type' => $type,
            'param' => $param,
            'placeholder' => self::plain((string) ($filter['placeholder'] ?? '')),
        ];

        if ($type === 'range') {
            $min = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($filter['min_param'] ?? $param.'_min'))) ?? '';
            $max = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($filter['max_param'] ?? $param.'_max'))) ?? '';
            $out['min_param'] = $min !== '' ? $min : $param.'_min';
            $out['max_param'] = $max !== '' ? $max : $param.'_max';
        }

        if ($type === 'select') {
            $options = [];
            foreach ((array) ($filter['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $value = self::plain((string) ($option['value'] ?? ''));
                $label = self::plain((string) ($option['label'] ?? $value));
                if ($value === '' || $label === '') {
                    continue;
                }
                $options[] = ['value' => $value, 'label' => $label];
                if (count($options) >= 30) {
                    break;
                }
            }
            $out['options'] = $options;
        }

        return $out;
    }

    /**
     * Echo active filter query values for the hosted list UI.
     *
     * @param  mixed  $filters
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, string>
     */
    public static function listFilters(mixed $filters, array $columns): array
    {
        $allowed = [];
        foreach ($columns as $column) {
            $filter = is_array($column['filter'] ?? null) ? $column['filter'] : null;
            if ($filter === null) {
                continue;
            }
            if (($filter['type'] ?? '') === 'range') {
                $allowed[(string) ($filter['min_param'] ?? '')] = true;
                $allowed[(string) ($filter['max_param'] ?? '')] = true;
            } else {
                $allowed[(string) ($filter['param'] ?? $column['key'] ?? '')] = true;
            }
        }

        $out = [];
        foreach (is_array($filters) ? $filters : [] as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key)) ?? '';
            if ($cleanKey === '' || ! isset($allowed[$cleanKey])) {
                continue;
            }
            $cleanValue = self::plain((string) $value);
            if ($cleanValue === '' || strlen($cleanValue) > 120) {
                continue;
            }
            $out[$cleanKey] = $cleanValue;
            if (count($out) >= 40) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $crumbs
     * @return list<array{name: string, href?: string}>
     */
    public static function crumbs(mixed $crumbs): array
    {
        $out = [];
        foreach (is_array($crumbs) ? $crumbs : [] as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }
            $name = self::plain((string) ($crumb['name'] ?? $crumb['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $item = ['name' => $name];
            $href = self::safeHref((string) ($crumb['href'] ?? $crumb['url'] ?? ''));
            if ($href !== '') {
                $item['href'] = $href;
            }
            $out[] = $item;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    public static function listTone(mixed $value): string
    {
        $tone = strtolower(trim((string) $value));

        return in_array($tone, ['success', 'warning', 'danger', 'info', 'primary', 'neutral'], true)
            ? $tone
            : '';
    }

    /**
     * @param  mixed  $actions
     * @return list<array<string, mixed>>
     */
    public static function listRowActions(mixed $actions): array
    {
        $out = [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            if (! is_array($action)) {
                continue;
            }
            $label = self::plain((string) ($action['label'] ?? ''));
            $href = self::safeHref((string) ($action['href'] ?? ''));
            if ($label === '' || $href === '') {
                continue;
            }
            $item = self::withIcon([
                'label' => $label,
                'href' => $href,
                'variant' => self::buttonVariant($action['variant'] ?? 'secondary'),
            ], $action['icon'] ?? null);
            if (($key = self::i18nKey($action['label_key'] ?? null)) !== null) {
                $item['label_key'] = $key;
            }
            $out[] = $item;
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $options
     * @return list<int>
     */
    public static function listPerPageOptions(mixed $options): array
    {
        $allowed = [10, 25, 50, 100];
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $value) {
            $n = (int) $value;
            if (! in_array($n, $allowed, true) || in_array($n, $out, true)) {
                continue;
            }
            $out[] = $n;
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $bulk
     * @return list<array{key: string, label?: string, icon?: string}>
     */
    public static function listBulk(mixed $bulk): array
    {
        $catalog = ['enable', 'disable', 'duplicate', 'delete'];
        if ($bulk === true || $bulk === 'catalog') {
            $bulk = $catalog;
        }
        $out = [];
        $seen = [];
        foreach (is_array($bulk) ? $bulk : [] as $item) {
            $key = '';
            $label = '';
            $icon = '';
            if (is_string($item)) {
                $key = strtolower($item);
            } elseif (is_array($item)) {
                $key = strtolower((string) ($item['key'] ?? ''));
                $label = self::plain((string) ($item['label'] ?? ''));
                $icon = self::iconName($item['icon'] ?? '');
            }
            $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
            if ($key === '' || isset($seen[$key]) || strlen($key) > 32) {
                continue;
            }
            if (! in_array($key, $catalog, true) && $label === '') {
                continue;
            }
            $seen[$key] = true;
            $row = ['key' => $key];
            if ($label !== '') {
                $row['label'] = $label;
            }
            if (is_array($item) && ($labelKey = self::i18nKey($item['label_key'] ?? null)) !== null) {
                $row['label_key'] = $labelKey;
            }
            if ($icon !== '') {
                $row['icon'] = $icon;
            }
            $out[] = $row;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    public static function listId(mixed $value): string
    {
        $id = trim((string) $value);
        if ($id === '' || strlen($id) > 64 || ! preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            return '';
        }

        return $id;
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    public static function selectedIds(mixed $ids): array
    {
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $clean = self::listId($id);
            if ($clean !== '' && ! in_array($clean, $out, true)) {
                $out[] = $clean;
            }
            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }

    public static function listActionKey(mixed $action): string
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $action))) ?? '';
        if ($key === '' || strlen($key) > 32) {
            return '';
        }

        return $key;
    }

    /**
     * @param  mixed  $blocks
     * @return list<array<string, mixed>>
     */
    public static function blocks(mixed $blocks): array
    {
        $out = [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            if ($type === 'card') {
                $card = [
                    'type' => 'card',
                    'title' => self::plain((string) ($block['title'] ?? '')),
                    'text' => self::plain((string) ($block['text'] ?? '')),
                ];
                if (($key = self::i18nKey($block['title_key'] ?? null)) !== null) {
                    $card['title_key'] = $key;
                }
                if (($key = self::i18nKey($block['text_key'] ?? null)) !== null) {
                    $card['text_key'] = $key;
                }
                $image = self::safeImageUrl((string) ($block['image'] ?? $block['image_url'] ?? ''));
                if ($image !== '') {
                    $card['image'] = $image;
                }
                $href = self::safeHref((string) ($block['href'] ?? ''), true);
                if ($href !== '') {
                    $card['href'] = $href;
                }
                if (! empty($block['variant'])) {
                    $card['variant'] = self::plain((string) $block['variant']);
                }
                if (! empty($block['style'])) {
                    $card['style'] = self::plain((string) $block['style']);
                }
                $out[] = $card;
            } elseif ($type === 'alert') {
                $alert = [
                    'type' => 'alert',
                    'title' => self::plain((string) ($block['title'] ?? '')),
                    'text' => self::plain((string) ($block['text'] ?? '')),
                ];
                if (($key = self::i18nKey($block['title_key'] ?? null)) !== null) {
                    $alert['title_key'] = $key;
                }
                if (($key = self::i18nKey($block['text_key'] ?? null)) !== null) {
                    $alert['text_key'] = $key;
                }
                $href = self::safeHref((string) ($block['href'] ?? ''), true);
                if ($href !== '') {
                    $alert['href'] = $href;
                }
                $out[] = $alert;
            } elseif ($type === 'section') {
                $section = [
                    'type' => 'section',
                    'title' => self::plain((string) ($block['title'] ?? '')),
                    'text' => self::plain((string) ($block['text'] ?? '')),
                ];
                if (($key = self::i18nKey($block['title_key'] ?? null)) !== null) {
                    $section['title_key'] = $key;
                }
                if (($key = self::i18nKey($block['text_key'] ?? null)) !== null) {
                    $section['text_key'] = $key;
                }
                $out[] = $section;
            } elseif ($type === 'table') {
                $columns = [];
                foreach ((array) ($block['columns'] ?? []) as $col) {
                    if (! is_array($col)) {
                        continue;
                    }
                    $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($col['key'] ?? ''))) ?? '';
                    if ($key === '') {
                        continue;
                    }
                    $column = ['key' => $key, 'label' => self::plain((string) ($col['label'] ?? $key))];
                    if (($labelKey = self::i18nKey($col['label_key'] ?? null)) !== null) {
                        $column['label_key'] = $labelKey;
                    }
                    $columns[] = $column;
                }
                $rows = [];
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $clean = [];
                    foreach ($columns as $col) {
                        $clean[$col['key']] = self::plain((string) ($row[$col['key']] ?? ''));
                    }
                    $rows[] = $clean;
                }
                $out[] = ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
            } elseif ($type === 'kpis') {
                $items = self::kpis($block['items'] ?? $block['kpis'] ?? []);
                if ($items === []) {
                    continue;
                }
                $out[] = ['type' => 'kpis', 'items' => $items];
            } elseif ($type === 'link') {
                $href = self::safeHref((string) ($block['href'] ?? ''), true);
                if ($href === '') {
                    continue;
                }
                $link = self::withIcon([
                    'type' => 'link',
                    'href' => $href,
                    'label' => self::plain((string) ($block['label'] ?? $href)),
                    'variant' => self::buttonVariant($block['variant'] ?? 'primary'),
                ], $block['icon'] ?? null);
                if (($key = self::i18nKey($block['label_key'] ?? null)) !== null) {
                    $link['label_key'] = $key;
                }
                $out[] = $link;
            } elseif ($type === 'button') {
                $label = self::plain((string) ($block['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $item = self::withIcon([
                    'type' => 'button',
                    'label' => $label,
                    'variant' => self::buttonVariant($block['variant'] ?? 'primary'),
                ], $block['icon'] ?? null);
                if (($key = self::i18nKey($block['label_key'] ?? null)) !== null) {
                    $item['label_key'] = $key;
                }
                $href = self::safeHref((string) ($block['href'] ?? ''), true);
                if ($href !== '') {
                    $item['href'] = $href;
                }
                if (is_array($block['modal'] ?? null)) {
                    $submit = self::safeModuleSubmit((string) ($block['modal']['submit'] ?? ''));
                    if ($submit !== '') {
                        $item['modal'] = [
                            'title' => self::plain((string) ($block['modal']['title'] ?? $label)),
                            'submit' => $submit,
                            'fields' => self::fields($block['modal']['fields'] ?? []),
                            'hidden' => self::hiddenFields($block['modal']['hidden'] ?? []),
                        ];
                    }
                }
                $out[] = $item;
            } elseif ($type === 'field') {
                $asField = $block;
                $asField['type'] = (string) ($block['input'] ?? $block['field_type'] ?? 'text');
                $fields = self::fields([$asField]);
                if ($fields === []) {
                    continue;
                }
                $item = $fields[0];
                $row = [
                    'type' => 'field',
                    'name' => $item['name'],
                    'input' => $item['type'],
                    'label' => $item['label'],
                    'hint' => $item['hint'] ?? '',
                    'required' => (bool) ($block['required'] ?? false),
                ];
                if (! empty($item['options'])) {
                    $row['options'] = $item['options'];
                }
                $out[] = $row;
            } elseif ($type === 'payment_ui') {
                $parsed = self::parsePaymentUiBlock($block);
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
            } elseif ($type === 'payment_notice') {
                $parsed = self::parsePaymentNoticeBlock($block);
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
            } else {
                $text = self::plain((string) ($block['text'] ?? $block['title'] ?? ''));
                if ($text !== '') {
                    $out[] = ['type' => 'text', 'text' => $text];
                }
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $fields
     * @return list<array<string, mixed>>
     */
    public static function fields(mixed $fields): array
    {
        $out = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($field['name'] ?? ''))) ?? '';
            if ($name === '') {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'localized') {
                $type = ((string) ($field['input'] ?? 'text')) === 'textarea' ? 'textarea' : 'text';
                $field['localized'] = true;
            }
            if ($type === 'upload') {
                $type = 'file';
            }
            if (! in_array($type, ['text', 'textarea', 'toggle', 'number', 'password', 'select', 'search', 'radio', 'checkboxes', 'readonly', 'image', 'file', 'color', 'date'], true)) {
                $type = 'text';
            }
            $item = [
                'name' => $name,
                'type' => $type,
                'label' => self::plain((string) ($field['label'] ?? $name)),
                'hint' => self::plain((string) ($field['hint'] ?? '')),
            ];
            if (($key = self::i18nKey($field['label_key'] ?? null)) !== null) {
                $item['label_key'] = $key;
            }
            if (($key = self::i18nKey($field['hint_key'] ?? null)) !== null) {
                $item['hint_key'] = $key;
            }
            if (($field['inline'] ?? false) === true) {
                $item['inline'] = true;
            }
            if (($field['required'] ?? false) === true) {
                $item['required'] = true;
            }
            if (in_array($type, ['text', 'textarea'], true) && ($field['localized'] ?? false) === true) {
                $item['localized'] = true;
            }
            if (in_array($type, ['image', 'file'], true)) {
                $item['accept'] = ExtensionUpload::acceptFor($type);
            }
            if (array_key_exists('default', $field) && $type !== 'checkboxes') {
                if (($item['localized'] ?? false) === true) {
                    $item['default'] = self::localizedMap($field['default']);
                } else {
                    $item['default'] = is_scalar($field['default']) ? $field['default'] : null;
                }
            }
            if (($field['copy'] ?? false) === true) {
                $item['copy'] = true;
            }
            if ($type === 'readonly' && is_scalar($field['value'] ?? null)) {
                $item['value'] = self::plain((string) $field['value']);
            }
            if (is_array($field['show_when'] ?? null)) {
                $item['show_when'] = $field['show_when'];
            }
            if (is_array($field['show_when_all'] ?? null)) {
                $item['show_when_all'] = $field['show_when_all'];
            }
            if (is_array($field['link'] ?? null)) {
                $link = $field['link'];
                if (($key = self::i18nKey($link['text_key'] ?? null)) !== null) {
                    $link['text_key'] = $key;
                } else {
                    unset($link['text_key']);
                }
                $item['link'] = $link;
            }
            if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                $options = [];
                foreach ((array) ($field['options'] ?? []) as $option) {
                    if (! is_array($option)) {
                        continue;
                    }
                    $opt = [
                        'value' => self::plain((string) ($option['value'] ?? '')),
                        'label' => self::plain((string) ($option['label'] ?? '')),
                    ];
                    if (($key = self::i18nKey($option['label_key'] ?? null)) !== null) {
                        $opt['label_key'] = $key;
                    }
                    $options[] = $opt;
                }
                $item['options'] = $options;
            }
            if ($type === 'checkboxes') {
                $item['default'] = self::checkboxValues($field['default'] ?? [], $item['options'] ?? []);
            }
            if ($type === 'search') {
                $source = strtolower((string) ($field['source'] ?? ''));
                if (! in_array($source, ['customers', 'products'], true)) {
                    continue; // refuse unknown lookup sources
                }
                $item['source'] = $source;
                $item['multiple'] = (bool) ($field['multiple'] ?? false);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  mixed  $errors
     * @return list<string>
     */
    public static function errorMessages(mixed $errors): array
    {
        $out = [];
        foreach (is_array($errors) ? $errors : [] as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $text = self::plain((string) $item);
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = mb_substr($text, 0, 500);
            }
        }

        return array_slice($out, 0, 20);
    }

    /**
     * @param  mixed  $errors
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    public static function fieldErrors(mixed $errors, array $fields): array
    {
        $allowed = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $allowed[$name] = true;
            }
        }
        $out = [];
        foreach (is_array($errors) ? $errors : [] as $name => $message) {
            $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $name)) ?? '';
            if ($name === '' || ! isset($allowed[$name])) {
                continue;
            }
            $text = is_array($message) ? (string) ($message[0] ?? '') : (string) $message;
            $text = mb_substr(self::plain($text), 0, 500);
            if ($text !== '') {
                $out[$name] = $text;
            }
        }

        return $out;
    }

    /**
     * Keep submitted scalars for known fields so a failed save does not blank the form.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function overlayValues(array $fields, array $incoming): array
    {
        $out = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || ! array_key_exists($name, $incoming)) {
                continue;
            }
            if (self::isLocalized($field)) {
                $out[$name] = self::localizedMap($incoming[$name]);

                continue;
            }
            if (ExtensionUpload::isField($field)) {
                $out[$name] = ExtensionUpload::sanitizeRecord($incoming[$name]);

                continue;
            }
            if (($field['type'] ?? '') === 'checkboxes') {
                $out[$name] = self::checkboxValues($incoming[$name], $field['options'] ?? []);

                continue;
            }
            $value = $incoming[$name];
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $out[$name] = $value;
            } elseif (is_string($value) || is_numeric($value)) {
                $out[$name] = self::plain((string) $value);
            } elseif (is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $items[] = self::plain((string) $item);
                    }
                }
                $out[$name] = $items;
            }
        }

        return $out;
    }

    /**
     * Keep only option values from a checkboxes field. Unknown ids are dropped.
     *
     * @param  mixed  $value
     * @param  list<array{value?: string, label?: string}>  $options
     * @return list<string>
     */
    public static function checkboxValues(mixed $value, array $options): array
    {
        $allowed = [];
        foreach ($options as $option) {
            $optionValue = self::plain((string) ($option['value'] ?? ''));
            if ($optionValue !== '') {
                $allowed[$optionValue] = true;
            }
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $item = self::plain((string) $item);
            if ($item !== '' && isset($allowed[$item]) && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function isLocalized(array $field): bool
    {
        if (($field['type'] ?? '') === 'localized') {
            return true;
        }

        return ($field['localized'] ?? false) === true
            && in_array((string) ($field['type'] ?? 'text'), ['text', 'textarea'], true);
    }

    /**
     * Per-language map keyed by enabled language codes (same switcher as Products).
     *
     * @return array<string, string>
     */
    public static function localizedMap(mixed $value): array
    {
        $allowed = [];
        foreach (self::enabledLanguageCodes() as $code) {
            $allowed[$code] = true;
        }
        $out = [];
        if (is_string($value) || is_numeric($value)) {
            $fallback = self::enabledLanguageCodes()[0] ?? (string) config('wento.default_language', 'en');
            $out[$fallback] = self::plain((string) $value);

            return $out;
        }
        foreach (is_array($value) ? $value : [] as $code => $text) {
            $code = strtolower(str_replace('_', '-', trim((string) $code)));
            $code = preg_replace('/[^a-z0-9-]/', '', $code) ?? '';
            if ($code === '' || ! preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code)) {
                continue;
            }
            if ($allowed !== [] && ! isset($allowed[$code])) {
                continue;
            }
            if (! is_scalar($text)) {
                continue;
            }
            $out[$code] = self::plain((string) $text);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function enabledLanguageCodes(): array
    {
        try {
            $codes = Language::enabledList()
                ->pluck('code')
                ->map(fn ($code) => strtolower((string) $code))
                ->filter()
                ->values()
                ->all();

            return array_values($codes);
        } catch (\Throwable) {
            return [];
        }
    }

    public static function pickLocalized(mixed $value, ?string $locale = null, string $default = ''): string
    {
        $locale = strtolower((string) ($locale ?: Language::currentCode()));
        if (is_array($value)) {
            $direct = $value[$locale] ?? $value[str_replace('-', '_', $locale)] ?? null;
            if (is_scalar($direct) && trim((string) $direct) !== '') {
                return (string) $direct;
            }
            $fallbackCode = strtolower((string) Language::defaultCode());
            $fallback = $value[$fallbackCode] ?? null;
            if (is_scalar($fallback) && trim((string) $fallback) !== '') {
                return (string) $fallback;
            }
            foreach ($value as $text) {
                if (is_scalar($text) && trim((string) $text) !== '') {
                    return (string) $text;
                }
            }

            return $default;
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function sanitizeSettingValue(array $field, mixed $value): mixed
    {
        if (($field['type'] ?? '') === 'checkboxes') {
            return self::checkboxValues($value, $field['options'] ?? []);
        }
        if (self::isLocalized($field)) {
            return self::localizedMap($value);
        }
        if (ExtensionUpload::isField($field)) {
            return ExtensionUpload::sanitizeRecord($value);
        }

        return $value;
    }

    /**
     * Keep declared setting keys only. Checkboxes always persist as an allowlisted string list
     * (including [] when nothing is checked).
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function sanitizeSettingsPayload(array $fields, array $values, bool $fillMissingCheckboxes = true): array
    {
        $out = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($name === '' || $type === 'readonly') {
                continue;
            }
            if ($type === 'checkboxes') {
                if (! $fillMissingCheckboxes && ! array_key_exists($name, $values)) {
                    continue;
                }
                $out[$name] = self::checkboxValues($values[$name] ?? [], $field['options'] ?? []);

                continue;
            }
            if (self::isLocalized($field)) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }
                $out[$name] = self::localizedMap($values[$name]);

                continue;
            }
            if (ExtensionUpload::isField($field)) {
                if (! array_key_exists($name, $values)) {
                    continue;
                }
                $out[$name] = ExtensionUpload::sanitizeRecord($values[$name]);

                continue;
            }
            if (! array_key_exists($name, $values)) {
                continue;
            }
            $out[$name] = $values[$name];
        }

        return $out;
    }

    /**
     * Parse an extension icon: kit id, emoji, Font Awesome class, image URL, or inline SVG.
     *
     * @return array{kind: string, value: string}|null
     */
    public static function parseIcon(mixed $icon): ?array
    {
        if (is_array($icon)) {
            $kind = strtolower(trim((string) ($icon['type'] ?? $icon['kind'] ?? '')));
            $value = $icon['value'] ?? $icon['name'] ?? $icon['class'] ?? $icon['src'] ?? $icon['svg'] ?? $icon['emoji'] ?? $icon['markup'] ?? '';

            return self::normalizeIcon($kind, $value) ?? self::parseIcon($value);
        }
        $raw = trim((string) $icon);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with(strtolower($raw), '<svg')) {
            return self::normalizeIcon('svg', $raw);
        }
        $image = self::safeImageUrl($raw);
        if ($image !== '') {
            return ['kind' => 'image', 'value' => $image];
        }
        $fa = self::fontAwesomeClass($raw);
        if ($fa !== '') {
            return ['kind' => 'fa', 'value' => $fa];
        }
        $kit = self::iconName($raw);
        if ($kit !== '') {
            return ['kind' => 'kit', 'value' => $kit];
        }
        if (self::isEmoji($raw)) {
            return ['kind' => 'emoji', 'value' => $raw];
        }

        return null;
    }

    /**
     * @return array{kind: string, value: string}|null
     */
    protected static function normalizeIcon(string $kind, mixed $value): ?array
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($kind === 'kit') {
            $name = self::iconName($value);

            return $name !== '' ? ['kind' => 'kit', 'value' => $name] : null;
        }
        if ($kind === 'emoji' && self::isEmoji($value)) {
            return ['kind' => 'emoji', 'value' => $value];
        }
        if ($kind === 'fa') {
            $class = self::fontAwesomeClass($value);

            return $class !== '' ? ['kind' => 'fa', 'value' => $class] : null;
        }
        if ($kind === 'image') {
            $src = self::safeImageUrl($value);

            return $src !== '' ? ['kind' => 'image', 'value' => $src] : null;
        }
        if ($kind === 'svg') {
            $svg = \App\Domain\Security\SvgSanitizer::clean($value);

            return $svg !== '' ? ['kind' => 'svg', 'value' => $svg] : null;
        }

        return null;
    }

    public static function fontAwesomeClass(mixed $value): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim((string) $value))) ?: [];
        $out = [];
        $hasIcon = false;
        foreach ($tokens as $token) {
            if ($token === '' || ! preg_match('/^(fa[srlbdt]?|fa-[a-z0-9-]+)$/', $token)) {
                return '';
            }
            if (str_starts_with($token, 'fa-') && ! in_array($token, ['fa-solid', 'fa-regular', 'fa-light', 'fa-thin', 'fa-brands', 'fa-duotone', 'fa-sharp', 'fa-fw', 'fa-lg', 'fa-xl', 'fa-xs', 'fa-sm'], true)) {
                $hasIcon = true;
            }
            $out[] = $token;
        }
        if ($out === [] || count($out) > 8 || ! $hasIcon) {
            return '';
        }

        return implode(' ', $out);
    }

    public static function isEmoji(string $value): bool
    {
        if ($value === '' || mb_strlen($value) > 16 || str_contains($value, '<') || str_contains($value, '/')) {
            return false;
        }
        if (preg_match('/[a-z0-9]/i', $value)) {
            return false;
        }

        return (bool) preg_match('/\p{Extended_Pictographic}/u', $value);
    }

    /**
     * Allowlisted kit icon id for admin tabs / menus.
     */
    public static function iconName(mixed $value, string $default = ''): string
    {
        $raw = strtolower(trim((string) $value));
        $raw = str_replace('_', '-', $raw);
        $compact = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
        $aliases = [
            'arrowleft' => 'arrow-left',
            'checkcircle' => 'check-circle',
            'chevrondown' => 'chevron-down',
            'chevronleft' => 'chevron-left',
            'chevronright' => 'chevron-right',
            'chevronup' => 'chevron-up',
            'eyeoff' => 'eye-off',
            'staroutline' => 'star',
            'usercircle' => 'user-circle',
            'pricetag' => 'price-tag',
            'cog' => 'settings',
            'gear' => 'settings',
            'fastar' => 'star',
            'facog' => 'settings',
            'fauser' => 'user',
            'faedit' => 'edit',
            'fatrash' => 'trash',
            'faplus' => 'plus',
            'fadownload' => 'download',
            'faupload' => 'upload',
            'fasearch' => 'search',
            'fahome' => 'home',
        ];
        $name = $aliases[$compact] ?? (preg_replace('/[^a-z0-9-]/', '', $raw) ?? '');
        $catalog = self::iconCatalog();
        if ($name !== '' && isset($catalog[$name])) {
            return $name;
        }
        if ($default !== '' && isset($catalog[$default])) {
            return $default;
        }

        return '';
    }

    public static function buttonVariant(mixed $variant): string
    {
        $value = strtolower(trim((string) $variant));
        if (in_array($value, ['primary', 'secondary', 'outline', 'ghost'], true)) {
            return $value;
        }

        return 'primary';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function withIcon(array $item, mixed $icon): array
    {
        $parsed = self::parseIcon($icon);
        if ($parsed) {
            $item['icon'] = $parsed;
        }

        return $item;
    }

    /**
     * @return array<string, true>
     */
    public static function iconCatalog(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }
        // Keep in lockstep with resources/js/admin/ui/Icon.jsx `paths` keys.
        $names = [
            'apple', 'ar', 'archive', 'alert-circle', 'alert-triangle', 'arrow-left',
            'arrow-right', 'bag', 'ban', 'bar-chart', 'bell', 'bookmark', 'bot', 'briefcase', 'building',
            'calendar', 'card', 'cart', 'check', 'check-circle', 'chevron', 'chevron-down',
            'chevron-left', 'chevron-right', 'chevron-up', 'clock', 'close', 'code',
            'compare', 'copy', 'database', 'download', 'edit', 'eye', 'eye-off',
            'facebook', 'file', 'filter', 'flame', 'folder', 'gauge', 'gift', 'globe',
            'google', 'grid', 'grip', 'heart', 'help', 'home', 'info', 'instagram',
            'layers', 'lightbulb', 'link', 'list', 'lock', 'logout', 'mail', 'menu',
            'message', 'monitor', 'more', 'orders', 'package', 'palette', 'pencil',
            'phone', 'photo', 'pin', 'play', 'plus', 'price-tag', 'printer', 'refresh', 'return',
            'rocket', 'save', 'search', 'send', 'settings', 'shield', 'smartphone',
            'sparkles', 'spinner', 'star', 'store', 'table', 'tag', 'trash', 'truck',
            'twitter', 'upload', 'user', 'user-circle', 'users', 'wand', 'x', 'youtube',
            'zap', 'zoom',
        ];
        $map = array_fill_keys($names, true);

        return $map;
    }

    /**
     * Optional UI translation key for extension/theme page chrome.
     * Keys must look like module.vendor.name.… or theme.code.…
     */
    public static function i18nKey(mixed $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }
        $key = trim($key);
        if ($key === '' || strlen($key) > 160) {
            return null;
        }
        if (! preg_match('/^(module\.[a-z0-9_-]+\.[a-z0-9_-]+|theme\.[a-z0-9_-]+)\.[a-z0-9_.-]+$/i', $key)) {
            return null;
        }

        return strtolower($key);
    }

    public static function plain(string $value): string
    {
        $value = trim(strip_tags($value));

        return mb_substr($value, 0, 2000);
    }

    public static function safeHref(string $href, bool $allowExternal = false): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if ($href === '/') {
            return '/';
        }
        if ($allowExternal && preg_match('#^https?://#i', $href)) {
            if (filter_var($href, FILTER_VALIDATE_URL)) {
                return $href;
            }
        }
        // Storefront checkout + order success are used by payment return/cancel landings.
        if (preg_match('#^(/admin/modules/|/admin/extensions/|/m/|/account/m/|/catalog|/account|/help|/checkout|/order)[a-zA-Z0-9/_?&=.\\-]*$#', $href)) {
            return $href;
        }

        return '';
    }

    public static function safeModuleSubmit(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^/api/v1/modules/[a-z0-9-]+/[a-z0-9-]+/[a-z0-9-]+$#', $path)) {
            return $path;
        }

        return '';
    }

    /**
     * @param  mixed  $hidden
     * @return array<string, string>
     */
    public static function hiddenFields(mixed $hidden): array
    {
        $out = [];
        foreach (is_array($hidden) ? $hidden : [] as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $name = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?? '';
            if ($name === '') {
                continue;
            }
            $out[$name] = self::plain((string) $value);
        }

        return $out;
    }

    public static function safeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https://[^\s<>\"\']{1,500}$#i', $url)) {
            return $url;
        }
        if (preg_match('#^/plugins/[a-z0-9/_.:\\-]+\\.(png|jpe?g|webp|gif|svg)$#i', $url)) {
            return $url;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected static function parsePaymentUiBlock(array $block): ?array
    {
        $extension = strtolower(trim((string) ($block['extension'] ?? '')));
        if (! preg_match('/^[a-z][a-z0-9-]{1,32}\/[a-z][a-z0-9-]{1,32}$/', $extension)) {
            return null;
        }
        [$vendor, $name] = explode('/', $extension, 2);
        $mount = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($block['mount'] ?? $name.'-payment-ui'))) ?? '';
        if ($mount === '') {
            return null;
        }
        $assets = self::parsePaymentAssets($block['assets'] ?? []);
        if ($assets['js'] === []) {
            return null;
        }

        return [
            'type' => 'payment_ui',
            'extension' => $extension,
            'vendor' => $vendor,
            'name' => $name,
            'mount' => $mount,
            'assets' => $assets,
            'when' => self::parsePaymentWhen($block['when'] ?? null),
            'config' => self::paymentUiConfig($block['config'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected static function parsePaymentNoticeBlock(array $block): ?array
    {
        $text = self::plain((string) ($block['text'] ?? ''));
        if ($text === '') {
            return null;
        }
        $extension = strtolower(trim((string) ($block['extension'] ?? '')));
        if ($extension !== '' && ! preg_match('/^[a-z][a-z0-9-]{1,32}\/[a-z][a-z0-9-]{1,32}$/', $extension)) {
            return null;
        }
        $vendor = '';
        $name = '';
        if ($extension !== '') {
            [$vendor, $name] = explode('/', $extension, 2);
        }
        $variant = strtolower(trim((string) ($block['variant'] ?? 'info')));
        if (! in_array($variant, ['info', 'warning'], true)) {
            $variant = 'info';
        }

        return [
            'type' => 'payment_notice',
            'extension' => $extension,
            'vendor' => $vendor,
            'name' => $name,
            'text' => $text,
            'variant' => $variant,
            'assets' => self::parsePaymentAssets($block['assets'] ?? []),
            'when' => self::parsePaymentWhen($block['when'] ?? null),
        ];
    }

    /**
     * @return array{css: list<string>, js: list<string>}
     */
    protected static function parsePaymentAssets(mixed $assets): array
    {
        $assets = is_array($assets) ? $assets : [];
        $css = [];
        foreach ((array) ($assets['css'] ?? []) as $file) {
            if (is_string($file) && preg_match('#^assets/[a-z0-9._/-]+\.css$#', $file)) {
                $css[] = $file;
            }
        }
        $js = [];
        foreach ((array) ($assets['js'] ?? []) as $file) {
            if (is_string($file) && preg_match('#^assets/[a-z0-9._/-]+\.js$#', $file)) {
                $js[] = $file;
            }
        }

        return ['css' => $css, 'js' => $js];
    }

    /**
     * @return array<string, int|string>
     */
    protected static function parsePaymentWhen(mixed $when): array
    {
        $out = [];
        if (! is_array($when)) {
            return $out;
        }
        $code = trim((string) ($when['payment_method_code'] ?? ''));
        if ($code !== '' && preg_match('/^[a-z0-9_-]{1,64}$/i', $code)) {
            $out['payment_method_code'] = strtolower($code);
        }
        $methodId = (int) ($when['payment_method_id'] ?? 0);
        if ($methodId > 0) {
            $out['payment_method_id'] = $methodId;
        }

        return $out;
    }

    /**
     * @return array<string, scalar|null>
     */
    protected static function paymentUiConfig(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $count = 0;
        foreach ($raw as $key => $value) {
            if ($count >= 20) {
                break;
            }
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value;
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $plain = self::plain($value);
                if ($plain !== '' && strlen($plain) <= 500) {
                    $out[$key] = $plain;
                }
            } elseif ($value === null) {
                $out[$key] = null;
            }
            $count++;
        }

        return $out;
    }
}
