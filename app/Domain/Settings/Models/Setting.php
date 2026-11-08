<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @var array<string, mixed>|null */
    protected static ?array $requestMemo = null;

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::allCached()[$key] ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : (string) $value]);
        self::$requestMemo = null;
        Cache::forget('wento.settings');
    }

    /**
     * Plain arrays survive database cache when serializable_classes is false.
     * A Collection stored there comes back as __PHP_Incomplete_Class and must
     * not be treated as a hit that returns the caller default.
     *
     * @return array<string, mixed>
     */
    protected static function allCached(): array
    {
        if (self::$requestMemo !== null) {
            return self::$requestMemo;
        }

        try {
            $normalized = static::normalize(Cache::get('wento.settings'));
            if ($normalized !== null) {
                return self::$requestMemo = $normalized;
            }
            Cache::forget('wento.settings');
        } catch (\Throwable) {
            Cache::forget('wento.settings');
        }

        try {
            $settings = static::query()->pluck('value', 'key')->all();
        } catch (\Throwable) {
            // Missing settings table during migrate / broken installs.
            return self::$requestMemo = [];
        }

        try {
            Cache::put('wento.settings', $settings, 60);
        } catch (\Throwable) {
            // Cache is optional.
        }

        return self::$requestMemo = $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function normalize(mixed $cached): ?array
    {
        if ($cached instanceof Collection) {
            return $cached->all();
        }
        if (is_array($cached)) {
            return $cached;
        }

        return null;
    }
}
