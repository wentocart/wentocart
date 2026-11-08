<?php

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\Models\PluginSetting;
use App\Domain\Plugin\PageDocument;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Plugin\Support\BasePlugin;

final class PaymentMethodExtension
{
    public function __construct(protected PluginKernel $kernel) {}

    /**
     * @return array<string, mixed>|null
     */
    public function panel(PaymentMethod $method): ?array
    {
        $row = $this->installedRow($method);
        if (! $row) {
            return null;
        }

        $plugin = $this->kernel->instance($row->vendor, $row->name);
        $schema = $plugin?->adminSchema() ?? ['title' => $row->display_name, 'fields' => []];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        if ($fields === []) {
            return null;
        }

        $values = $this->valuesFor($row, $fields, $plugin);
        $masked = $this->maskedFlags($row, $fields);

        return [
            'vendor' => $row->vendor,
            'name' => $row->name,
            'display_name' => $row->display_name,
            'enabled' => (bool) $row->is_enabled,
            'configure_href' => '/admin/extensions/'.$row->vendor.'/'.$row->name,
            'schema' => $schema,
            'values' => $values,
            'masked' => $masked,
            'complete' => $this->isComplete($fields, $values, $masked),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(PaymentMethod $method): ?array
    {
        $row = $this->installedRow($method);
        if (! $row) {
            return null;
        }

        $plugin = $this->kernel->instance($row->vendor, $row->name);
        $schema = $plugin?->adminSchema() ?? ['fields' => []];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $values = $this->valuesFor($row, $fields, $plugin);
        $masked = $this->maskedFlags($row, $fields);

        return [
            'display_name' => $row->display_name,
            'enabled' => (bool) $row->is_enabled,
            'configure_href' => '/admin/extensions/'.$row->vendor.'/'.$row->name,
            'complete' => $this->isComplete($fields, $values, $masked),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(PaymentMethod $method, array $values): void
    {
        $row = $this->installedRow($method);
        if (! $row) {
            return;
        }

        $plugin = $this->kernel->instance($row->vendor, $row->name);
        if (! $plugin instanceof BasePlugin) {
            return;
        }

        $schema = $plugin->adminSchema();
        $fieldsByName = [];
        $passwordKeys = [];
        foreach (PageDocument::collectFields($schema) as $field) {
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '' || ($field['type'] ?? '') === 'readonly') {
                continue;
            }
            $fieldsByName[$key] = $field;
            if (($field['type'] ?? '') === 'password') {
                $passwordKeys[] = $key;
            }
        }

        $incoming = [];
        foreach ($values as $key => $value) {
            if (! isset($fieldsByName[$key])) {
                continue;
            }
            if (in_array($key, $passwordKeys, true) && trim((string) $value) === '') {
                continue;
            }
            $incoming[$key] = $value;
        }

        $filtered = PageDocument::sanitizeSettingsPayload(array_values($fieldsByName), $incoming, false);

        if ($filtered !== []) {
            $plugin->saveSettings($filtered);
        }
    }

    protected function installedRow(PaymentMethod $method): ?InstalledPlugin
    {
        $extensionId = data_get($method->config, 'extension');
        if (! is_string($extensionId) || ! str_contains($extensionId, '/')) {
            return null;
        }

        [$vendor, $name] = array_pad(explode('/', strtolower(trim($extensionId)), 2), 2, null);
        if (! is_string($vendor) || $vendor === '' || ! is_string($name) || $name === '') {
            return null;
        }

        $this->kernel->boot();

        return InstalledPlugin::query()
            ->with('settings')
            ->where('vendor', $vendor)
            ->where('name', $name)
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function valuesFor(InstalledPlugin $row, array $fields, ?BasePlugin $plugin): array
    {
        $values = [];
        foreach ($row->settings as $setting) {
            $decoded = json_decode((string) $setting->value, true);
            $values[$setting->key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
        }
        foreach ($fields as $field) {
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (! array_key_exists($key, $values) && ($field['type'] ?? '') === 'toggle') {
                $values[$key] = true;
            }
            if (! array_key_exists($key, $values) && array_key_exists('default', $field)) {
                $values[$key] = $field['default'];
            }
            if (($field['type'] ?? '') === 'checkboxes') {
                $values[$key] = PageDocument::sanitizeSettingValue($field, $values[$key] ?? []);
            }
            if (PageDocument::isLocalized($field)) {
                $values[$key] = PageDocument::localizedMap($values[$key] ?? ($field['default'] ?? []));
            }
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, bool>
     */
    protected function maskedFlags(InstalledPlugin $row, array $fields): array
    {
        $flags = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'password') {
                continue;
            }
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $stored = PluginSetting::query()
                ->where('plugin_id', $row->id)
                ->where('key', $key)
                ->value('value');
            $flags[$key] = is_string($stored) && trim($stored) !== '' && trim($stored) !== '""';
        }

        return $flags;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, bool>  $masked
     */
    protected function isComplete(array $fields, array $values, array $masked): bool
    {
        foreach ($fields as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }
            $key = $field['name'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (($field['type'] ?? '') === 'password') {
                if (! ($masked[$key] ?? false) && trim((string) ($values[$key] ?? '')) === '') {
                    return false;
                }

                continue;
            }
            if (($field['type'] ?? '') === 'checkboxes') {
                if (PageDocument::checkboxValues($values[$key] ?? [], $field['options'] ?? []) === []) {
                    return false;
                }

                continue;
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                return false;
            }
        }

        if ($this->looksLikeStripe($fields)) {
            $mode = trim((string) ($values['stripe_mode'] ?? 'sandbox'));
            if ($mode !== 'production') {
                $mode = 'sandbox';
            }
            $publishableKey = $mode === 'production' ? 'live_publishable_key' : 'test_publishable_key';
            $secretKey = $mode === 'production' ? 'live_secret_key' : 'test_secret_key';

            $hasPublishable = trim((string) ($values[$publishableKey] ?? '')) !== '';
            $hasSecret = ($masked[$secretKey] ?? false) || trim((string) ($values[$secretKey] ?? '')) !== ''
                || trim((string) ($values['secret_key'] ?? '')) !== '';

            return $hasPublishable && $hasSecret;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    protected function looksLikeStripe(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['name'] ?? '') === 'stripe_mode') {
                return true;
            }
        }

        return false;
    }
}
