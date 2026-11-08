<?php

namespace App\Domain\Settings;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdminSelectQuery
{
    public const MAX_ROWS = 100;

    public const MAX_SQL = 4000;

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        $driver = DB::getDriverName();
        $names = [];

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($rows as $row) {
                $names[] = (string) $row->name;
            }
        } else {
            foreach (DB::select('SHOW TABLES') as $row) {
                $value = array_values((array) $row)[0] ?? null;
                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                }
            }
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return array_values(array_filter($names, fn (string $name) => $name !== ''));
    }

    public static function sampleSelect(string $table): string
    {
        return 'SELECT * FROM '.self::quoteIdent($table).' LIMIT 50';
    }

    /**
     * @return array{sql: string, columns: list<string>, rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public static function run(string $sql): array
    {
        $safe = self::prepare($sql);

        try {
            $rows = DB::select($safe);
        } catch (QueryException $e) {
            throw new InvalidArgumentException(self::publicError($e->getMessage()));
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        $columns = [];
        $out = [];

        foreach ($rows as $row) {
            $assoc = [];
            foreach ((array) $row as $key => $value) {
                if (! in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
                $assoc[$key] = self::cell($value);
            }
            $out[] = $assoc;
        }

        return [
            'sql' => $safe,
            'columns' => $columns,
            'rows' => $out,
            'row_count' => count($out),
            'truncated' => $truncated,
        ];
    }

    public static function prepare(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new InvalidArgumentException('Write a SELECT statement.');
        }
        if (strlen($sql) > self::MAX_SQL) {
            throw new InvalidArgumentException('The query is too long.');
        }

        $stripped = self::stripComments($sql);
        $stripped = trim($stripped);
        if ($stripped === '') {
            throw new InvalidArgumentException('Write a SELECT statement.');
        }

        $body = rtrim($stripped, "; \t\n\r");
        if (str_contains($body, ';')) {
            throw new InvalidArgumentException('Only a single SELECT statement is allowed.');
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $body) ?? $body);
        $normalized = ltrim($normalized, '( ');

        if (! str_starts_with($normalized, 'select ') && ! str_starts_with($normalized, 'with ')) {
            throw new InvalidArgumentException('Only SELECT queries are allowed.');
        }

        foreach (self::forbiddenTokens() as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/', $normalized)) {
                throw new InvalidArgumentException('Write statements and schema changes are blocked.');
            }
        }

        if (str_contains($normalized, ' into ') || str_contains($normalized, 'outfile') || str_contains($normalized, 'dumpfile') || str_contains($normalized, 'for update') || str_contains($normalized, 'for share')) {
            throw new InvalidArgumentException('Write statements and schema changes are blocked.');
        }

        foreach (self::sensitiveIdentifiers() as $name) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/', $normalized)) {
                throw new InvalidArgumentException('Queries cannot read credentials or secrets.');
            }
        }

        return self::withLimit($body);
    }

    public static function quoteIdent(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('That table name is not allowed.');
        }

        $quote = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? '`' : '"';

        return $quote.$name.$quote;
    }

    /**
     * @return list<string>
     */
    protected static function forbiddenTokens(): array
    {
        return [
            'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
            'grant', 'revoke', 'call', 'exec', 'execute', 'merge',
            'pragma', 'attach', 'detach', 'vacuum', 'reindex', 'handler',
            'unlock', 'rename', 'commit', 'rollback', 'savepoint',
        ];
    }

    /**
     * @return list<string>
     */
    protected static function sensitiveIdentifiers(): array
    {
        return [
            'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
            'mfa_secret', 'api_token', 'webhook_secret', 'secret_key', 'client_secret',
            'information_schema', 'mysql.user', 'performance_schema',
        ];
    }

    protected static function stripComments(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;

        return preg_replace('/#[^\n]*/', ' ', $sql) ?? $sql;
    }

    protected static function withLimit(string $sql): string
    {
        if (preg_match('/\blimit\s+\d+/i', $sql)) {
            return preg_replace_callback(
                '/\blimit\s+(\d+)(?:\s*,\s*(\d+))?(?:\s+offset\s+(\d+))?\s*$/i',
                function (array $match): string {
                    if (isset($match[2]) && $match[2] !== '') {
                        $count = min((int) $match[2], self::MAX_ROWS);

                        return 'LIMIT '.(int) $match[1].', '.$count;
                    }
                    $count = min((int) $match[1], self::MAX_ROWS);
                    $offset = isset($match[3]) && $match[3] !== '' ? ' OFFSET '.(int) $match[3] : '';

                    return 'LIMIT '.$count.$offset;
                },
                $sql
            ) ?? $sql;
        }

        return $sql.' LIMIT '.self::MAX_ROWS;
    }

    protected static function cell(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strlen($value) > 400 ? substr($value, 0, 400).'…' : $value;
        }

        return substr(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '', 0, 400);
    }

    protected static function publicError(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/', '', $message) ?? $message;
        $message = preg_replace('/\(Connection:\s*[^)]+\)\s*/', '', $message) ?? $message;

        return $message !== '' ? $message : 'The query failed.';
    }
}
