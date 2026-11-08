<?php

namespace App\Domain\Ai;

use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

class AiSettings
{
    /**
     * Global toggles + connection list. Exactly one connection may be active.
     *
     * @return array{
     *   enabled: bool,
     *   content_enabled: bool,
     *   active_id: ?string,
     *   connections: list<array<string, mixed>>,
     *   active: ?array<string, mixed>
     * }
     */
    public static function forAdmin(): array
    {
        self::migrateLegacy();
        $connections = self::connectionsForAdmin();
        $activeId = self::activeId();

        return [
            'enabled' => self::enabled(),
            'content_enabled' => ShopSetting::bool('ai.content_enabled', false),
            'active_id' => $activeId,
            'connections' => $connections,
            'active' => collect($connections)->firstWhere('id', $activeId),
            'assistant' => AssistantSettings::forAdmin(),
            'storefront' => StorefrontAssistantSettings::forAdmin(),
        ];
    }

    /**
     * @return array{content_enabled: bool, configured: bool, assistant: array<string, mixed>}
     */
    public static function forInertia(): array
    {
        try {
            return [
                'content_enabled' => self::contentEnabled(),
                'configured' => self::enabled() && self::activeConnection() !== null,
                'assistant' => AssistantSettings::forInertia(),
            ];
        } catch (Throwable) {
            return [
                'content_enabled' => false,
                'configured' => false,
                'assistant' => ['assistant_enabled' => false],
            ];
        }
    }

    public static function enabled(): bool
    {
        return ShopSetting::bool('ai.enabled', false);
    }

    public static function contentEnabled(): bool
    {
        return self::enabled()
            && ShopSetting::bool('ai.content_enabled', false)
            && self::activeConnection() !== null;
    }

    public static function activeId(): ?string
    {
        self::migrateLegacy();
        $id = trim((string) ShopSetting::get('ai.active_id', ''));

        return $id !== '' ? $id : null;
    }

    /**
     * Decrypted active connection for runtime calls.
     *
     * @return array{id: string, driver: string, label: string, api_key: string, model: string, base_url?: string}|null
     */
    public static function activeConnection(): ?array
    {
        self::migrateLegacy();
        $id = self::activeId();
        if ($id === null) {
            return null;
        }

        foreach (self::rawConnections() as $row) {
            if (($row['id'] ?? '') === $id) {
                $key = self::decryptKey((string) ($row['api_key'] ?? ''));
                if ($key === null || $key === '') {
                    return null;
                }

                return [
                    'id' => (string) $row['id'],
                    'driver' => (string) $row['driver'],
                    'label' => (string) ($row['label'] ?? $row['driver']),
                    'api_key' => $key,
                    'model' => (string) ($row['model'] ?? ''),
                    'base_url' => (string) ($row['base_url'] ?? ''),
                ];
            }
        }

        return null;
    }

    /** @deprecated Use activeConnection() — kept for ContentWriter helpers */
    public static function provider(): string
    {
        return (string) (self::activeConnection()['driver'] ?? 'openai');
    }

    public static function model(): string
    {
        $model = trim((string) (self::activeConnection()['model'] ?? ''));

        return $model !== '' ? $model : 'gpt-4o-mini';
    }

    public static function hasApiKey(): bool
    {
        return self::activeConnection() !== null;
    }

    public static function apiKey(): ?string
    {
        return self::activeConnection()['api_key'] ?? null;
    }

    /**
     * @param  array{enabled?: bool, content_enabled?: bool}  $data
     */
    public static function saveFlags(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            ShopSetting::put('ai.enabled', (bool) $data['enabled']);
        }
        if (array_key_exists('content_enabled', $data)) {
            ShopSetting::put('ai.content_enabled', (bool) $data['content_enabled']);
        }
    }

    /**
     * @param  array{driver: string, label: string, api_key?: ?string, model?: ?string, base_url?: ?string, clear_api_key?: bool}  $data
     * @return array<string, mixed>
     */
    public static function upsertConnection(?string $id, array $data): array
    {
        self::migrateLegacy();
        $driver = (string) $data['driver'];
        if (! AiProviderCatalog::find($driver)) {
            throw new \InvalidArgumentException('Unknown AI provider.');
        }

        $rows = self::rawConnections();
        $existing = null;
        $index = null;
        if ($id) {
            foreach ($rows as $i => $row) {
                if (($row['id'] ?? '') === $id) {
                    $existing = $row;
                    $index = $i;
                    break;
                }
            }
            if ($existing === null) {
                throw new \InvalidArgumentException('Connection not found.');
            }
        }

        $apiKey = (string) ($existing['api_key'] ?? '');
        if (! empty($data['clear_api_key'])) {
            $apiKey = '';
        } elseif (isset($data['api_key']) && is_string($data['api_key']) && trim($data['api_key']) !== '') {
            $apiKey = Crypt::encryptString(trim($data['api_key']));
        } elseif ($existing === null) {
            throw new \InvalidArgumentException('API key is required.');
        }

        if ($apiKey === '' && empty($data['clear_api_key'])) {
            // keep existing
        }

        if ($existing === null && $apiKey === '') {
            throw new \InvalidArgumentException('API key is required.');
        }

        $defaults = match ($driver) {
            'anthropic' => 'claude-sonnet-4-20250514',
            'gemini' => 'gemini-2.0-flash',
            'openai_compatible' => 'llama-3.3-70b-versatile',
            default => 'gpt-4o-mini',
        };

        $row = [
            'id' => $existing['id'] ?? (string) Str::ulid(),
            'driver' => $driver,
            'label' => trim((string) ($data['label'] ?? '')) ?: (AiProviderCatalog::find($driver)['label'] ?? $driver),
            'api_key' => $apiKey !== '' ? $apiKey : (string) ($existing['api_key'] ?? ''),
            'model' => trim((string) ($data['model'] ?? '')) ?: (string) ($existing['model'] ?? $defaults),
            'base_url' => trim((string) ($data['base_url'] ?? ($existing['base_url'] ?? ''))),
        ];

        if ($existing === null && ($row['api_key'] ?? '') === '') {
            throw new \InvalidArgumentException('API key is required.');
        }

        if ($driver === 'openai_compatible' && $row['base_url'] === '') {
            throw new \InvalidArgumentException('Base URL is required for OpenAI-compatible providers.');
        }

        if ($index === null) {
            $rows[] = $row;
        } else {
            $rows[$index] = $row;
        }

        self::putConnections($rows);

        if (self::activeId() === null) {
            ShopSetting::put('ai.active_id', $row['id']);
        }

        return self::maskConnection($row);
    }

    public static function deleteConnection(string $id): void
    {
        $rows = array_values(array_filter(self::rawConnections(), fn ($row) => ($row['id'] ?? '') !== $id));
        self::putConnections($rows);
        if (self::activeId() === $id) {
            $next = $rows[0]['id'] ?? '';
            ShopSetting::put('ai.active_id', $next);
        }
    }

    public static function activate(string $id): void
    {
        foreach (self::rawConnections() as $row) {
            if (($row['id'] ?? '') === $id) {
                if (self::decryptKey((string) ($row['api_key'] ?? '')) === null) {
                    throw new \InvalidArgumentException('This connection has no API key.');
                }
                ShopSetting::put('ai.active_id', $id);

                return;
            }
        }

        throw new \InvalidArgumentException('Connection not found.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function connectionsForAdmin(): array
    {
        return array_map(fn ($row) => self::maskConnection($row), self::rawConnections());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function maskConnection(array $row): array
    {
        $key = self::decryptKey((string) ($row['api_key'] ?? ''));
        $masked = null;
        if ($key !== null && $key !== '') {
            $len = strlen($key);
            $masked = $len <= 8
                ? str_repeat('•', $len)
                : substr($key, 0, 3).str_repeat('•', max(4, $len - 7)).substr($key, -4);
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'driver' => (string) ($row['driver'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'base_url' => (string) ($row['base_url'] ?? ''),
            'has_api_key' => $key !== null && $key !== '',
            'api_key_masked' => $masked,
            'is_active' => self::activeId() === ($row['id'] ?? null),
            'company' => AiProviderCatalog::find((string) ($row['driver'] ?? ''))['company'] ?? '',
            'driver_label' => AiProviderCatalog::find((string) ($row['driver'] ?? ''))['label'] ?? ($row['driver'] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function rawConnections(): array
    {
        $raw = ShopSetting::json('ai.connections', []);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($row) => is_array($row) && ! empty($row['id']) && ! empty($row['driver'])));
    }

    /** @param  list<array<string, mixed>>  $rows */
    protected static function putConnections(array $rows): void
    {
        ShopSetting::put('ai.connections', array_values($rows));
    }

    protected static function decryptKey(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        try {
            $plain = Crypt::decryptString($raw);

            return $plain !== '' ? $plain : null;
        } catch (Throwable) {
            return $raw !== '' ? $raw : null;
        }
    }

    /** One-time move from single-provider settings into connections[]. */
    protected static function migrateLegacy(): void
    {
        if (self::rawConnections() !== []) {
            return;
        }

        $legacyKey = (string) ShopSetting::get('ai.api_key', '');
        if ($legacyKey === '') {
            return;
        }

        $driver = strtolower(trim((string) ShopSetting::get('ai.provider', 'openai')));
        if (! AiProviderCatalog::find($driver)) {
            $driver = 'openai';
        }

        $id = (string) Str::ulid();
        self::putConnections([[
            'id' => $id,
            'driver' => $driver,
            'label' => AiProviderCatalog::find($driver)['label'] ?? $driver,
            'api_key' => $legacyKey,
            'model' => trim((string) ShopSetting::get('ai.model', 'gpt-4o-mini')) ?: 'gpt-4o-mini',
            'base_url' => '',
        ]]);
        ShopSetting::put('ai.active_id', $id);
    }
}
