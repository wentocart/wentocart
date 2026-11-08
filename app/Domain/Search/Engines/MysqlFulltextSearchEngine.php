<?php

namespace App\Domain\Search\Engines;

use App\Domain\Localization\Models\Language;
use App\Domain\Search\Contracts\SearchEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL/MariaDB BOOLEAN MODE fulltext over product_translations.
 */
final class MysqlFulltextSearchEngine implements SearchEngine
{
    public function name(): string
    {
        return 'mysql_fulltext';
    }

    public function available(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    public function searchIds(string $q, int $limit = 48): array
    {
        if (! $this->available() || trim($q) === '') {
            return [];
        }

        $boolean = $this->booleanQuery($q);
        if ($boolean === '') {
            return [];
        }

        $locale = app()->getLocale();
        $default = Language::defaultCode();
        $ids = DB::table('product_translations')
            ->where('language_code', $locale)
            ->whereRaw('MATCH(name, description, meta_keywords) AGAINST (? IN BOOLEAN MODE)', [$boolean])
            ->limit($limit)
            ->pluck('product_id');

        if ($ids->isEmpty() && $default !== $locale) {
            $ids = DB::table('product_translations')
                ->where('language_code', $default)
                ->whereRaw('MATCH(name, description, meta_keywords) AGAINST (? IN BOOLEAN MODE)', [$boolean])
                ->limit($limit)
                ->pluck('product_id');
        }

        return $ids->map(fn ($id) => (int) $id)->filter()->values()->all();
    }

    protected function booleanQuery(string $q): string
    {
        $tokens = preg_split('/\s+/u', trim($q)) ?: [];
        $parts = [];
        foreach ($tokens as $token) {
            $clean = preg_replace('/[+\-><()~*\"\\\\@]+/', '', $token) ?? '';
            if ($clean === '') {
                continue;
            }
            $parts[] = '+'.$clean.'*';
        }

        return implode(' ', $parts);
    }
}
