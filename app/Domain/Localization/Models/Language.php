<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

#[Fillable(['code', 'name', 'is_enabled', 'is_default', 'position'])]
class Language extends Model
{
    protected static ?string $defaultCodeMemo = null;

    /** @var list<string>|null */
    protected static ?array $enabledCodesMemo = null;

    /** @var Collection<int, Language>|null */
    protected static ?Collection $enabledListMemo = null;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public static function forgetMemos(): void
    {
        self::$defaultCodeMemo = null;
        self::$enabledCodesMemo = null;
        self::$enabledListMemo = null;
    }

    public static function defaultCode(): string
    {
        if (self::$defaultCodeMemo !== null) {
            return self::$defaultCodeMemo;
        }
        try {
            return self::$defaultCodeMemo = static::query()->where('is_default', true)->value('code')
                ?: (string) config('wento.default_language', 'en');
        } catch (\Throwable) {
            return self::$defaultCodeMemo = (string) config('wento.default_language', 'en');
        }
    }

    /**
     * Language the admin or shopper is viewing. Never changes the default.
     */
    public static function currentCode(): string
    {
        $locale = (string) app()->getLocale();
        if ($locale === '') {
            return static::defaultCode();
        }

        try {
            if (self::$enabledCodesMemo === null) {
                self::$enabledCodesMemo = static::query()->enabled()->pluck('code')->all();
            }
            $enabled = self::$enabledCodesMemo;
            if ($enabled !== [] && ! in_array($locale, $enabled, true)) {
                return static::defaultCode();
            }
        } catch (\Throwable) {
            // Languages table may be missing during install.
        }

        return $locale;
    }

    /**
     * Current row, then default-language row, then any row.
     *
     * @param  iterable<mixed>  $translations
     */
    public static function pickTranslation(iterable $translations, ?string $locale = null): mixed
    {
        $rows = collect($translations);
        $locale ??= static::currentCode();
        $default = static::defaultCode();

        return $rows->firstWhere('language_code', $locale)
            ?? ($locale !== $default ? $rows->firstWhere('language_code', $default) : null)
            ?? $rows->first();
    }

    /** @return Collection<int, Language> */
    public static function enabledList(): Collection
    {
        if (self::$enabledListMemo !== null) {
            return self::$enabledListMemo;
        }

        try {
            return self::$enabledListMemo = static::query()->enabled()->orderBy('position')->orderBy('name')->get();
        } catch (\Throwable) {
            return self::$enabledListMemo = collect();
        }
    }
}
