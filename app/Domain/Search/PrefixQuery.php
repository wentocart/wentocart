<?php

namespace App\Domain\Search;

class PrefixQuery
{
    public static function like(string $term): string
    {
        $term = trim($term);
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $term.'%';
    }

    public static function contains(string $term): string
    {
        $term = trim($term);
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return '%'.$term.'%';
    }

    public static function applySku($query, string $term, string $column = 'sku'): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $query->where(function ($inner) use ($term, $column) {
            $inner->where($column, $term)
                ->orWhere($column, 'like', self::like($term));
        });
    }

    public static function applySkuContains($query, string $term, string $column = 'sku'): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $query->where($column, 'like', self::contains($term));
    }
}
