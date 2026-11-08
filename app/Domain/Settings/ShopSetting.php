<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;

class ShopSetting
{
    public static function get(string $name, mixed $default = null): mixed
    {
        if (app(StoreContext::class)->enabled()) {
            $context = app(StoreContext::class);
            if ($context->contextKind() === 'all') {
                return self::stored($name, $default);
            }
            if ($context->contextKind() === 'group') {
                $group = Setting::getValue(self::groupKey($context->contextGroupId(), $name), null);
                if ($group !== null && $group !== '') {
                    return self::decode($group);
                }

                return self::stored($name, $default);
            }
            $storeId = $context->id();
            if ($storeId > 0) {
                $hit = Setting::getValue(self::namespaced($name, $storeId), null);
                if ($hit !== null && $hit !== '') {
                    return self::decode($hit);
                }
                $groupId = (int) (Store::query()->find($storeId)?->store_group_id ?? 0);
                if ($groupId > 0) {
                    $group = Setting::getValue(self::groupKey($groupId, $name), null);
                    if ($group !== null && $group !== '') {
                        return self::decode($group);
                    }
                }
            }

            return self::stored($name, $default);
        }

        $storeId = self::storeId();
        if ($storeId > 0) {
            $hit = Setting::getValue(self::namespaced($name, $storeId), null);
            if ($hit !== null && $hit !== '') {
                return self::decode($hit);
            }
        }

        return self::stored($name, $default);
    }

    public static function put(string $name, mixed $value): void
    {
        if (app(StoreContext::class)->enabled()) {
            $context = app(StoreContext::class);
            if ($context->contextKind() === 'all') {
                Setting::setValue($name, self::encode($value));

                return;
            }
            if ($context->contextKind() === 'group') {
                Setting::setValue(self::groupKey($context->contextGroupId(), $name), self::encode($value));

                return;
            }
            $storeId = $context->id();
            $key = $storeId > 0 ? self::namespaced($name, $storeId) : $name;
            Setting::setValue($key, self::encode($value));

            return;
        }

        $storeId = self::storeId();
        $key = $storeId > 0 ? self::namespaced($name, $storeId) : $name;
        Setting::setValue($key, self::encode($value));
    }

    public static function groupKey(int $groupId, string $name): string
    {
        return 'group.'.$groupId.'.'.$name;
    }

    protected static function stored(string $name, mixed $default): mixed
    {
        $global = Setting::getValue($name, null);
        if ($global !== null && $global !== '') {
            return self::decode($global);
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>|list<mixed>
     */
    public static function json(string $name, array $default = []): array
    {
        $raw = self::get($name, null);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $default;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $raw = self::get($name, $default ? '1' : '0');

        return StoreContext::flag($raw);
    }

    public static function namespaced(string $name, int $storeId): string
    {
        return 'shop.'.$storeId.'.'.$name;
    }

    protected static function storeId(): int
    {
        try {
            return app(StoreContext::class)->id();
        } catch (\Throwable) {
            return 0;
        }
    }

    protected static function encode(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return (string) $value;
    }

    protected static function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trim = ltrim($value);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }
}
