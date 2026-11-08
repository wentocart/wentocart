<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\Models\PluginSetting;
use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginManifest;
use InvalidArgumentException;

class PluginSettings
{
    public function __construct(protected PluginManifest $manifest) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assert();
        $row = $this->query($key);

        if (! $row) {
            return $default;
        }
        $decoded = json_decode((string) $row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function localized(string $key, ?string $locale = null, string $default = ''): string
    {
        return PageDocument::pickLocalized($this->get($key, $default), $locale, $default);
    }

    public function file(string $key): array
    {
        return ExtensionUpload::present($this->get($key, []), $this->manifest->vendor, $this->manifest->name);
    }

    public function fileUrl(string $key, string $default = ''): string
    {
        $url = (string) ($this->file($key)['url'] ?? '');

        return $url !== '' ? $url : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assert();
        $plugin = InstalledPlugin::query()
            ->where('vendor', $this->manifest->vendor)
            ->where('name', $this->manifest->name)
            ->first();
        if (! $plugin) {
            return;
        }
        PluginSetting::query()->updateOrCreate(
            ['plugin_id' => $plugin->id, 'key' => $key],
            ['value' => json_encode($value)],
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function fill(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== '') {
                $this->set($key, $value);
            }
        }
    }

    protected function assert(): void
    {
        if (! $this->manifest->can(Capabilities::SETTINGS)
            && ! $this->manifest->can(Capabilities::ADMIN_PAGE)
            && ! $this->manifest->can(Capabilities::ADMIN_PAGES)) {
            throw new InvalidArgumentException('This app did not declare settings.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function allForForm(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $default = $type === 'toggle'
                ? true
                : (array_key_exists('default', $field) ? $field['default'] : '');
            if ($type === 'checkboxes') {
                $default = $field['default'] ?? [];
            }
            if (PageDocument::isLocalized($field) && is_string($default)) {
                $default = PageDocument::localizedMap($default);
            }
            $stored = $this->get($key, $default);
            if ($type === 'password') {
                $values[$key] = is_string($stored) && trim($stored) !== '' ? '' : '';
            } elseif ($type === 'checkboxes') {
                $values[$key] = PageDocument::checkboxValues($stored, $field['options'] ?? []);
            } elseif (PageDocument::isLocalized($field)) {
                $values[$key] = PageDocument::localizedMap($stored);
            } elseif (ExtensionUpload::isField($field)) {
                $values[$key] = ExtensionUpload::present($stored, $this->manifest->vendor, $this->manifest->name);
            } else {
                $values[$key] = $stored;
            }
        }

        return $values;
    }

    protected function query(string $key): ?PluginSetting
    {
        $plugin = InstalledPlugin::query()
            ->where('vendor', $this->manifest->vendor)
            ->where('name', $this->manifest->name)
            ->first();
        if (! $plugin) {
            return null;
        }

        return PluginSetting::query()->where('plugin_id', $plugin->id)->where('key', $key)->first();
    }
}
