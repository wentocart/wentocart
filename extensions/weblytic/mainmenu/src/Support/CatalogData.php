<?php

namespace Modules\Weblytic\Mainmenu\Support;

/**
 * Formats ModuleQuery rows for admin pickers (no I/O).
 */
class CatalogData
{
    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $translations
     * @return list<array{value: string, label: string}>
     */
    public function categoryOptions(array $categories, array $translations, string $locale = 'en'): array
    {
        $names = [];
        foreach ($translations as $row) {
            $id = (int) ($row['category_id'] ?? 0);
            $lang = strtolower((string) ($row['language_code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($id < 1 || $name === '') {
                continue;
            }
            if ($lang === $locale || $lang === substr($locale, 0, 2) || ! isset($names[$id])) {
                $names[$id] = $name;
            }
        }
        $options = [];
        foreach ($categories as $row) {
            if (! (int) ($row['is_enabled'] ?? 0)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $options[] = [
                'value' => (string) $id,
                'label' => ($names[$id] ?? ('Category '.$id)).' (#'.$id.')',
            ];
        }
        usort($options, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $translations
     * @return list<array{value: string, label: string}>
     */
    public function cmsOptions(array $pages, array $translations, string $locale = 'en'): array
    {
        $titles = [];
        foreach ($translations as $row) {
            $id = (int) ($row['cms_page_id'] ?? 0);
            $lang = strtolower((string) ($row['language_code'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($id < 1 || $title === '') {
                continue;
            }
            if ($lang === $locale || $lang === substr($locale, 0, 2) || ! isset($titles[$id])) {
                $titles[$id] = $title;
            }
        }
        $options = [];
        foreach ($pages as $row) {
            if (! (int) ($row['is_enabled'] ?? 0) || ! (int) ($row['is_visible'] ?? 0)) {
                continue;
            }
            if (strtolower((string) ($row['status'] ?? '')) !== 'published') {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $options[] = [
                'value' => (string) $id,
                'label' => ($titles[$id] ?? ('Page '.$id)).' (#'.$id.')',
            ];
        }
        usort($options, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $languages
     * @return list<array{code: string, name: string}>
     */
    public function languages(array $languages): array
    {
        $out = [];
        foreach ($languages as $row) {
            if (isset($row['is_enabled']) && ! (int) $row['is_enabled']) {
                continue;
            }
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'name' => trim((string) ($row['name'] ?? $code)) ?: $code,
            ];
        }

        return $out;
    }
}
