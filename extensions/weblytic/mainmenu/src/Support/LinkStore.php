<?php

namespace Modules\Weblytic\Mainmenu\Support;

use App\Domain\Plugin\Support\PluginSettings;

/**
 * Custom link records used by Main menu (LNK entries).
 */
class LinkStore
{
    public function __construct(protected PluginSettings $settings) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = $this->settings->get('links', []);
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($row) => $this->normalize($row), $rows)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function keyed(): array
    {
        $out = [];
        foreach ($this->all() as $row) {
            $out[(string) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->keyed()[$id] ?? null;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    public function save(array $link): ?array
    {
        $normalized = $this->normalize($link);
        if ($normalized === null) {
            return null;
        }
        $rows = $this->all();
        $found = false;
        foreach ($rows as $index => $row) {
            if ((string) $row['id'] === (string) $normalized['id']) {
                $rows[$index] = $normalized;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $rows[] = $normalized;
        }
        $this->settings->set('links', array_values($rows));

        return $normalized;
    }

    /**
     * @param  list<string>  $ids
     */
    public function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $keep = array_values(array_filter(
            $this->all(),
            fn ($row) => ! in_array((string) $row['id'], $ids, true)
        ));
        $this->settings->set('links', $keep);
    }

    public function nextId(): string
    {
        $max = 0;
        foreach ($this->all() as $row) {
            $max = max($max, (int) preg_replace('/\D/', '', (string) ($row['id'] ?? '')) ?: 0);
        }

        return 'lnk-'.($max + 1);
    }

    /**
     * @param  mixed  $row
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id)) {
            return null;
        }
        $label = $this->plain($row['label'] ?? '', 120);
        $url = $this->safeUrl($row['url'] ?? '');
        if ($label === '' || $url === '') {
            return null;
        }

        return [
            'id' => $id,
            'label' => $label,
            'url' => $url,
            'labels' => (new MenuConfig($this->settings))->normalizeLabels($row['labels'] ?? []),
            'new_window' => (bool) ($row['new_window'] ?? false),
            'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
        ];
    }

    protected function plain(mixed $value, int $max): string
    {
        $text = trim(strip_tags((string) $value));

        return mb_substr($text, 0, $max);
    }

    protected function safeUrl(mixed $value): string
    {
        $url = trim(strip_tags((string) $value));
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                return '';
            }

            return mb_substr($url, 0, 500);
        }
        if ($url[0] !== '/') {
            $url = '/'.$url;
        }
        if (! preg_match('~^/[a-zA-Z0-9/_?&=.\-%#]*$~', $url)) {
            return '';
        }

        return mb_substr($url, 0, 500);
    }
}
