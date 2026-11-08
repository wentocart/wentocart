<?php

namespace App\Console\Commands;

use App\Domain\Installer\PostgresSchemaTranslator;
use App\Domain\Installer\SchemaBootstrap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Proves MySQL→Postgres translation by importing into an empty PostgreSQL database.
 * Prefer PDO when pdo_pgsql is loaded; otherwise shell out to `psql` (CI / local clients).
 */
class PostgresSchemaSmokeCommand extends Command
{
    protected $signature = 'wento:postgres-schema-smoke
        {--force : Allow running when tables already exist (drops public schema cascade)}
        {--psql : Force the psql CLI path even when pdo_pgsql is available}
        {--host= : Override DB host for psql}
        {--port= : Override DB port for psql}
        {--database= : Override DB name for psql}
        {--username= : Override DB user for psql}
        {--prefix= : Override table prefix for the import (recommended: wento_)}';

    protected $description = 'Import the translated MySQL schema into PostgreSQL (PDO or psql).';

    public function handle(): int
    {
        $usePsql = (bool) $this->option('psql') || ! extension_loaded('pdo_pgsql');

        if (! $usePsql && DB::getDriverName() !== 'pgsql') {
            $this->error('DB_CONNECTION must be pgsql (or pass --psql with host/user/database).');

            return self::FAILURE;
        }

        $prefix = (string) ($this->option('prefix') ?: config('database.connections.pgsql.prefix') ?: env('DB_PREFIX', 'wento_'));
        if ($prefix !== '' && ! str_ends_with($prefix, '_')) {
            $prefix .= '_';
        }
        if ($prefix === '') {
            $prefix = 'wento_';
        }
        // Match production normalization (PREFIX_) so quoted identifiers stay consistent.
        $prefix = wento_db_prefix(rtrim($prefix, '_'));
        if ($prefix === '') {
            $prefix = 'WENTO_';
        }

        try {
            $raw = File::get(database_path('schema/mysql-schema.sql'));
            $sql = PostgresSchemaTranslator::translate($raw);
            // fitIdentifiers needs a driver hint; force pgsql quoting for translated SQL.
            $prev = config('database.default');
            config(['database.default' => 'pgsql']);
            $sql = SchemaBootstrap::fitIdentifiers(str_replace('__DB_PREFIX__', $prefix, $sql));
            config(['database.default' => $prev]);

            // MySQL dumps create child tables before parents; Postgres rejects inline FKs.
            // Drop FK clauses for smoke — we only prove table creation counts.
            $sql = preg_replace(
                '/,\s*CONSTRAINT\s+"[^"]+"\s+FOREIGN KEY\s*\([^)]+\)\s+REFERENCES\s+"[^"]+"\s*\([^)]+\)(?:\s+ON DELETE\s+\w+)?(?:\s+ON UPDATE\s+\w+)?/i',
                '',
                $sql
            ) ?? $sql;

            preg_match_all('/CREATE TABLE/i', $sql, $m);
            $this->info('Importing '.count($m[0]).' tables with prefix '.$prefix.'…');

            if ($usePsql) {
                return $this->importViaPsql($sql);
            }

            return $this->importViaPdo($sql);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function importViaPdo(string $sql): int
    {
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        if ($tables !== [] && ! $this->option('force')) {
            $this->error('Database is not empty. Pass --force to DROP SCHEMA public CASCADE first.');

            return self::FAILURE;
        }
        if ($tables !== [] && $this->option('force')) {
            DB::statement('DROP SCHEMA public CASCADE');
            DB::statement('CREATE SCHEMA public');
            $this->warn('Dropped public schema.');
        }

        SchemaBootstrap::importStatements($sql, function (string $line): void {
            if (str_contains($line, 'Creating') || str_contains($line, '[')) {
                $this->line($line);
            }
        });

        $after = DB::select("SELECT count(*)::int AS c FROM pg_tables WHERE schemaname = 'public'");
        $count = (int) ($after[0]->c ?? 0);
        if ($count < 50) {
            $this->error("Expected ≥50 tables after import, got {$count}.");

            return self::FAILURE;
        }
        $this->info("PostgreSQL schema smoke OK (PDO) — {$count} tables in public.");

        return self::SUCCESS;
    }

    protected function importViaPsql(string $sql): int
    {
        $psql = trim((string) shell_exec('command -v psql 2>/dev/null'));
        if ($psql === '') {
            $this->error('psql not found and pdo_pgsql is unavailable.');

            return self::FAILURE;
        }

        $host = (string) ($this->option('host') ?: env('DB_HOST', '127.0.0.1'));
        $port = (string) ($this->option('port') ?: env('DB_PORT', '5432'));
        $database = (string) ($this->option('database') ?: env('DB_DATABASE', 'wento_test'));
        $username = (string) ($this->option('username') ?: env('DB_USERNAME', 'wento'));
        $password = (string) env('DB_PASSWORD', '');

        if ($this->option('force')) {
            $drop = new Process([
                $psql,
                '-h', $host,
                '-p', $port,
                '-U', $username,
                '-d', $database,
                '-v', 'ON_ERROR_STOP=1',
                '-c', 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;',
            ], null, ['PGPASSWORD' => $password]);
            $drop->setTimeout(120);
            $drop->run();
            if (! $drop->isSuccessful()) {
                $this->error('Could not reset schema: '.$drop->getErrorOutput());

                return self::FAILURE;
            }
            $this->warn('Dropped public schema via psql.');
        }

        $tmp = storage_path('app/pgsql-smoke-'.uniqid('', true).'.sql');
        File::ensureDirectoryExists(dirname($tmp));
        // Skip FK checks while creating tables out of dependency order (MySQL dump order).
        File::put($tmp, "SET session_replication_role = replica;\n".$sql."\nSET session_replication_role = DEFAULT;\n");

        try {
            $import = new Process([
                $psql,
                '-h', $host,
                '-p', $port,
                '-U', $username,
                '-d', $database,
                '-v', 'ON_ERROR_STOP=1',
                '-f', $tmp,
            ], null, ['PGPASSWORD' => $password]);
            $import->setTimeout(600);
            $import->run(function ($type, $buffer): void {
                $this->output->write($buffer);
            });
            if (! $import->isSuccessful()) {
                $this->error('psql import failed.');

                return self::FAILURE;
            }
        } finally {
            File::delete($tmp);
        }

        $countProc = new Process([
            $psql,
            '-h', $host,
            '-p', $port,
            '-U', $username,
            '-d', $database,
            '-t',
            '-A',
            '-c', "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'",
        ], null, ['PGPASSWORD' => $password]);
        $countProc->run();
        $count = (int) trim($countProc->getOutput());
        if ($count < 50) {
            $this->error("Expected ≥50 tables after import, got {$count}.");

            return self::FAILURE;
        }
        $this->info("PostgreSQL schema smoke OK (psql) — {$count} tables in public.");

        return self::SUCCESS;
    }
}
