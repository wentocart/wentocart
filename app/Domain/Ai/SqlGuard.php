<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validate AI-generated SELECT queries against the allowlisted schema,
 * then apply the install table prefix (e.g. WENTO_) for raw DB::select.
 */
final class SqlGuard
{
    /** @var list<string>|null */
    protected static ?array $allowedTablesOverride = null;

    /**
     * @param  list<string>|null  $allowedTables  When set, only these tables may appear in FROM/JOIN.
     * @return list<string> sanitized SELECT statements ready to run (prefixed)
     */
    public static function sanitizeSelects(string $raw, int $limit, ?array $allowedTables = null): array
    {
        $previous = self::$allowedTablesOverride;
        self::$allowedTablesOverride = $allowedTables;
        try {
            $raw = trim($raw);
            $raw = preg_replace('/^```(?:sql)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
            $raw = trim($raw);

            if ($raw === '' || strcasecmp($raw, 'NOT_ALLOWED') === 0) {
                throw new InvalidArgumentException('That request is not allowed as a database query.');
            }

            if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|EXEC|INTO\s+OUTFILE|LOAD_FILE|SLEEP\s*\()/i', $raw)) {
                throw new InvalidArgumentException('Only read-only SELECT queries are allowed.');
            }

            preg_match_all('/SELECT[\s\S]*?(?:;|$)/i', $raw, $matches);
            $chunks = array_values(array_filter(array_map('trim', $matches[0] ?? [])));
            if ($chunks === []) {
                throw new InvalidArgumentException('No SELECT query was generated.');
            }

            $out = [];
            foreach ($chunks as $sql) {
                $sql = rtrim($sql, " \t\n\r\0\x0B;");
                $sql = self::validateOne($sql, $limit);
                $out[] = self::applyTablePrefix($sql);
            }

            return $out;
        } finally {
            self::$allowedTablesOverride = $previous;
        }
    }

    /** @return list<string> */
    protected static function allowedTableNames(): array
    {
        return self::$allowedTablesOverride ?? SchemaCatalog::tableNames();
    }

    protected static function validateOne(string $sql, int $limit): string
    {
        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new InvalidArgumentException('Query must start with SELECT.');
        }

        if (substr_count($sql, ';') > 0) {
            throw new InvalidArgumentException('Multiple statements are not allowed.');
        }

        // Normalize any install prefix the model may have invented.
        $sql = self::stripTablePrefix($sql);
        $sql = self::expandSelectStars($sql);

        $allowed = self::allowedTableNames();
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $tables);
        $found = array_unique(array_map('strtolower', $tables[1] ?? []));
        if ($found === []) {
            throw new InvalidArgumentException('Query must reference an allowlisted table.');
        }

        foreach ($found as $table) {
            if (! in_array($table, $allowed, true)) {
                throw new InvalidArgumentException('Table "'.$table.'" is not allowed for AI queries.');
            }
        }

        if (! preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql .= ' LIMIT '.max(1, $limit);
        } else {
            $sql = preg_replace_callback('/\bLIMIT\s+(\d+)/i', function ($m) use ($limit) {
                return 'LIMIT '.min((int) $m[1], max(1, $limit));
            }, $sql) ?? $sql;
        }

        return $sql;
    }

    /**
     * Rewrite SELECT * / alias.* to lean allowlisted columns (never dump every DB column).
     * COUNT(*) / SUM(*) etc. are left alone.
     */
    protected static function expandSelectStars(string $sql): string
    {
        if (! preg_match('/\bSELECT\b([\s\S]+?)\bFROM\b/i', $sql, $parts)) {
            return $sql;
        }

        $selectList = $parts[1];
        $withoutAggs = preg_replace('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(\s*\*\s*\)/i', '', $selectList) ?? $selectList;
        if (! preg_match('/(^|[\s,])\*(?=[\s,]|$)|([a-zA-Z0-9_]+)\s*\.\s*\*/i', $withoutAggs, $star)) {
            return $sql;
        }

        if (! preg_match('/\bFROM\s+`?([a-zA-Z0-9_]+)`?(?:\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i', $sql, $from)) {
            return $sql;
        }

        $table = strtolower($from[1]);
        $alias = isset($from[2]) ? $from[2] : $table;
        $reserved = ['where', 'inner', 'left', 'right', 'join', 'on', 'group', 'order', 'limit', 'having', 'union'];
        if (in_array(strtolower($alias), $reserved, true)) {
            $alias = $table;
        }

        // Prefer table.* alias when present.
        if (! empty($star[2])) {
            $alias = $star[2];
        }

        $cols = self::leanColumns($table);
        $list = implode(', ', array_map(static fn (string $col) => $alias.'.'.$col, $cols));

        return preg_replace('/\bSELECT\b[\s\S]+?\bFROM\b/i', 'SELECT '.$list.' FROM', $sql, 1) ?? $sql;
    }

    /**
     * @return list<string>
     */
    protected static function leanColumns(string $table): array
    {
        return match ($table) {
            'products' => ['id', 'sku', 'price', 'sale_price', 'stock_qty', 'stock_status', 'is_enabled'],
            'orders' => ['id', 'number', 'email', 'status', 'grand_total', 'currency_code', 'created_at', 'payment_status'],
            'customers' => ['id', 'name', 'email', 'phone', 'is_enabled', 'created_at'],
            'order_items' => ['id', 'order_id', 'product_id', 'sku', 'name', 'qty', 'unit_price', 'line_total'],
            default => StorefrontSchemaCatalog::tables()[$table]
                ?? SchemaCatalog::tables()[$table]
                ?? ['id'],
        };
    }

    protected static function tablePrefix(): string
    {
        try {
            return (string) DB::connection()->getTablePrefix();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Remove install prefix from FROM/JOIN table refs so allowlist checks use logical names.
     */
    protected static function stripTablePrefix(string $sql): string
    {
        $prefix = self::tablePrefix();
        if ($prefix === '') {
            return $sql;
        }

        $quoted = preg_quote($prefix, '/');

        return preg_replace_callback(
            '/\b(FROM|JOIN)\s+`?('.$quoted.')([a-zA-Z0-9_]+)`?/i',
            static fn (array $m) => $m[1].' '.$m[3],
            $sql
        ) ?? $sql;
    }

    /**
     * Apply Laravel/install prefix to allowlisted tables in FROM/JOIN.
     */
    protected static function applyTablePrefix(string $sql): string
    {
        $prefix = self::tablePrefix();
        if ($prefix === '') {
            return $sql;
        }

        $names = self::allowedTableNames();
        usort($names, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($names as $table) {
            $sql = preg_replace_callback(
                '/\b(FROM|JOIN)\s+`?('.preg_quote($table, '/').')`?(\s|$|,|\))/i',
                static function (array $m) use ($prefix, $table) {
                    // Avoid double-prefixing.
                    $name = $m[2];
                    if (strcasecmp($name, $prefix.$table) === 0) {
                        return $m[0];
                    }

                    return $m[1].' '.$prefix.$table.$m[3];
                },
                $sql
            ) ?? $sql;
        }

        return $sql;
    }
}
