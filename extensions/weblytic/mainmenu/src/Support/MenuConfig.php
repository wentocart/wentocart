<?php

namespace Modules\Weblytic\Mainmenu\Support;

use App\Domain\Plugin\Support\PluginSettings;

/**
 * Ordered main-menu entries (CAT / CMS / LNK tokens), stored in plugin settings.
 */
class MenuConfig
{
    public function __construct(protected PluginSettings $settings) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $rows = $this->settings->get('items', []);
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($row) => $this->normalizeItem($row), $rows)));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function setItems(array $items): void
    {
        $normalized = [];
        foreach ($items as $row) {
            $item = $this->normalizeItem($row);
            if ($item !== null) {
                $normalized[] = $item;
            }
            if (count($normalized) >= 120) {
                break;
            }
        }
        $byId = [];
        foreach ($normalized as $item) {
            $byId[(string) $item['id']] = $item;
        }
        foreach ($normalized as &$item) {
            $parent = (string) ($item['parent_id'] ?? '');
            if ($parent === '' || ! isset($byId[$parent])) {
                $item['parent_id'] = '';
                continue;
            }
            if ($this->wouldCreateCycle((string) $item['id'], $parent, $byId)) {
                $item['parent_id'] = '';
                $byId[(string) $item['id']]['parent_id'] = '';
            }
        }
        unset($item);
        $this->settings->set('items', array_values($normalized));
    }

    /**
     * @param  array<string, array<string, mixed>>  $byId
     */
    public function wouldCreateCycle(string $id, string $parentId, array $byId): bool
    {
        if ($id === '' || $parentId === '') {
            return false;
        }
        if ($id === $parentId) {
            return true;
        }
        $seen = [$id => true];
        $cursor = $parentId;
        $guard = 0;
        while ($cursor !== '' && isset($byId[$cursor]) && $guard < 130) {
            if (isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $cursor = (string) ($byId[$cursor]['parent_id'] ?? '');
            $guard++;
        }

        return false;
    }

    public function migratedFromCore(): bool
    {
        return (bool) $this->settings->get('migrated_from_core', false);
    }

    public function markMigratedFromCore(): void
    {
        $this->settings->set('migrated_from_core', true);
    }

    /**
     * @param  mixed  $row
     * @return array<string, mixed>|null
     */
    public function normalizeItem(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        if (! in_array($type, ['category', 'cms', 'link'], true)) {
            $token = strtoupper(trim((string) ($row['token'] ?? $row['key'] ?? '')));
            if (preg_match('/^(CAT|CMS|LNK)[:\-]?(\d+|[a-zA-Z0-9_-]+)$/', $token, $m)) {
                $type = match ($m[1]) {
                    'CAT' => 'category',
                    'CMS' => 'cms',
                    default => 'link',
                };
                $row['ref'] = $m[2];
            } else {
                return null;
            }
        }

        $ref = trim((string) ($row['ref'] ?? $row['ref_id'] ?? $row['link_id'] ?? ''));
        if ($type === 'category' || $type === 'cms') {
            if (! ctype_digit($ref) || (int) $ref < 1) {
                return null;
            }
            $ref = (string) (int) $ref;
        } elseif ($ref === '' || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $ref)) {
            return null;
        }

        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id)) {
            $prefix = $type === 'category' ? 'CAT' : ($type === 'cms' ? 'CMS' : 'LNK');
            $id = $prefix.'-'.$ref;
        }

        $parent = trim((string) ($row['parent_id'] ?? ''));
        if ($parent !== '' && ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $parent)) {
            $parent = '';
        }

        return [
            'id' => $id,
            'type' => $type,
            'ref' => $ref,
            'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
            'new_window' => (bool) ($row['new_window'] ?? $row['open_in_new_window'] ?? false),
            'parent_id' => $parent === $id ? '' : $parent,
            'position' => max(0, (int) ($row['position'] ?? 0)),
            'label_override' => $this->plain($row['label_override'] ?? $row['label'] ?? '', 120),
            'labels' => $this->normalizeLabels($row['labels'] ?? []),
        ];
    }

    /**
     * @param  mixed  $labels
     * @return array<string, string>
     */
    public function normalizeLabels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }
        $out = [];
        foreach ($labels as $code => $label) {
            $locale = strtolower(trim((string) $code));
            if (! preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/', $locale)) {
                continue;
            }
            $text = $this->plain($label, 120);
            if ($text !== '') {
                $out[$locale] = $text;
            }
        }

        return $out;
    }

    protected function plain(mixed $value, int $max): string
    {
        $text = trim(strip_tags((string) $value));

        return mb_substr($text, 0, $max);
    }
}
