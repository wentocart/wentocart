<?php

namespace App\Domain\Installer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Loads database/schema/*-schema.sql (token __DB_PREFIX__) so a fresh
 * install skips historical migration files when INFORMATION_SCHEMA is heavy.
 * PostgreSQL uses a translated MySQL dump via PostgresSchemaTranslator.
 */
class SchemaBootstrap
{
    public static function path(): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite' && is_file(database_path('schema/sqlite-schema.sql'))) {
            return database_path('schema/sqlite-schema.sql');
        }
        if ($driver === 'pgsql' && is_file(database_path('schema/pgsql-schema.sql'))) {
            return database_path('schema/pgsql-schema.sql');
        }

        return database_path('schema/mysql-schema.sql');
    }

    public static function stripAutoIncrementCounters(string $sql): string
    {
        return preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $sql) ?? $sql;
    }

    public static function available(): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            return is_file(database_path('schema/mysql-schema.sql'))
                && filesize(database_path('schema/mysql-schema.sql')) > 1000;
        }

        return is_file(self::path()) && filesize(self::path()) > 1000;
    }

    /**
     * True when the DB has no base tables yet (safe to import schema).
     */
    public static function shouldImport(): bool
    {
        if (! self::available()) {
            return false;
        }

        try {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

                return count($tables) === 0;
            }
            if ($driver === 'sqlite') {
                $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

                return count($tables) === 0;
            }
            $tables = DB::select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');

            return count($tables) === 0;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  (callable(string): void)|null  $progress
     */
    public static function import(string $prefix, ?callable $progress = null): void
    {
        if (DB::getDriverName() !== 'sqlite' && ($prefix === '' || ! str_ends_with($prefix, '_'))) {
            throw new RuntimeException('Database prefix must end with underscore before schema import.');
        }

        $raw = File::get(
            DB::getDriverName() === 'pgsql'
                ? database_path('schema/mysql-schema.sql')
                : self::path()
        );
        $raw = self::stripAutoIncrementCounters($raw);
        if (DB::getDriverName() === 'pgsql') {
            $raw = PostgresSchemaTranslator::translate($raw);
        }
        $sql = self::fitIdentifiers(str_replace('__DB_PREFIX__', $prefix, $raw));

        preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+[`"\']?([A-Za-z0-9_]+)/i', $sql, $tableMatches);
        $tableTotal = count($tableMatches[1] ?? []);
        $started = microtime(true);

        InstallProgress::say($progress, '  Creating '.$tableTotal.' tables…');
        InstallProgress::note($progress, 'Creating '.$tableTotal.' store tables…');
        InstallProgress::tick($progress, 'database', 0, max(1, $tableTotal), 'Creating store tables…');

        self::importStatements($sql, $progress, $tableTotal);
        InstallProgress::say($progress, '');
        InstallProgress::tick($progress, 'database', $tableTotal, max(1, $tableTotal), 'Store tables are ready');
        InstallProgress::stepDone($progress, 'Schema import', $started);
    }

    /**
     * Apply schema SQL with the current PDO connection. The mysql and mariadb clients are not used.
     *
     * @param  (callable(string): void)|null  $progress
     */
    public static function importStatements(string $sql, ?callable $progress = null, ?int $tableTotal = null): void
    {
        if ($tableTotal === null) {
            preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+[`"\']?([A-Za-z0-9_]+)/i', $sql, $tableMatches);
            $tableTotal = count($tableMatches[1] ?? []);
        }

        $tableIndex = 0;
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::statement('SET UNIQUE_CHECKS=0');
        }

        try {
            $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                $statement = trim(preg_replace('/^--.*$/m', '', $statement) ?? '');
                if ($statement === '') {
                    continue;
                }
                if ($pgsql && preg_match('/^DROP TABLE IF EXISTS/i', $statement) && ! preg_match('/\bCASCADE\b/i', $statement)) {
                    $statement .= ' CASCADE';
                }
                if (preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+[`"\']?([A-Za-z0-9_]+)/i', $statement, $match)) {
                    $tableIndex++;
                    InstallProgress::say($progress, InstallProgress::bar($tableIndex, max(1, $tableTotal), $match[1]));
                    InstallProgress::tick(
                        $progress,
                        'database',
                        $tableIndex,
                        max(1, $tableTotal),
                        'Tables '.$tableIndex.' / '.$tableTotal
                    );
                }
                try {
                    DB::unprepared($statement);
                } catch (Throwable $e) {
                    $preview = trim((string) preg_replace('/\s+/', ' ', substr($statement, 0, 140)));
                    throw new RuntimeException(self::explainImportFailure($e, $preview), 0, $e);
                }
            }
        } finally {
            if ($mysql) {
                DB::statement('SET UNIQUE_CHECKS=1');
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * MySQL identifiers are limited to 64 characters. A long table prefix makes
     * Laravel's index names overflow, which aborts install on Linux and Windows alike.
     */
    public static function fitIdentifiers(string $sql, int $max = 64): string
    {
        $quote = str_contains($sql, '"id"') || DB::getDriverName() === 'pgsql' ? '"' : '`';
        if (! preg_match_all('/'.$quote.'([^'.$quote.']+)'.$quote.'/', $sql, $matches)) {
            return $sql;
        }

        $names = array_unique($matches[1]);
        $taken = [];
        foreach ($names as $name) {
            if (strlen($name) <= $max) {
                $taken[$name] = true;
            }
        }

        $long = array_values(array_filter($names, static fn (string $name): bool => strlen($name) > $max));
        usort($long, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $map = [];
        foreach ($long as $name) {
            $salt = 0;
            $short = self::shortenIdentifier($name, $max);
            while (isset($taken[$short])) {
                $salt++;
                $short = self::shortenIdentifier($name.'#'.$salt, $max);
            }
            $taken[$short] = true;
            $map[$name] = $short;
        }

        foreach ($map as $from => $to) {
            $sql = str_replace($quote.$from.$quote, $quote.$to.$quote, $sql);
        }

        return $sql;
    }

    public static function shortenIdentifier(string $name, int $max = 64): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        $hash = substr(sha1($name), 0, 10);
        $keep = max(1, $max - strlen($hash) - 1);

        return rtrim(substr($name, 0, $keep), '_').'_'.$hash;
    }

    public static function explainImportFailure(Throwable $e, string $preview): string
    {
        $detail = $e->getMessage();
        $hint = DatabasePreflight::connectionHint($e);
        if ($hint !== null) {
            return $hint;
        }

        if (preg_match('/\b1059\b|Identifier name .+ is too long/i', $detail)) {
            return 'MySQL rejected a table or index name because it is longer than 64 characters. Empty this database (or choose a new empty one) and run the installation again. If some tables were created before the error, they must be removed first. ('.$detail.')';
        }

        return 'Could not create the store tables. '.$detail.($preview !== '' ? ' Statement: '.$preview.'.' : '');
    }
}
