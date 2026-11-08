<?php

namespace App\Domain\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-driver SQL helpers (db_grammar() parity for MySQL / MariaDB / PostgreSQL).
 */
final class DbGrammar
{
    public static function driver(): string
    {
        return (string) DB::getDriverName();
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    /** Case-insensitive LIKE operator for the active connection. */
    public static function likeOperator(): string
    {
        return self::isPostgres() ? 'ilike' : 'like';
    }

    /**
     * Apply a case-insensitive contains match on a column.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function whereContains(Builder $query, string $column, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->where($column, self::likeOperator(), '%'.$term.'%');
    }

    /**
     * Apply an orWhere case-insensitive contains match on a column.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function orWhereContains(Builder $query, string $column, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->orWhere($column, self::likeOperator(), '%'.$term.'%');
    }

    /** SQL fragment that concatenates two expressions safely across drivers. */
    public static function concat(string $left, string $right): string
    {
        if (self::isPostgres()) {
            return "({$left} || {$right})";
        }

        return "CONCAT({$left}, {$right})";
    }
}
