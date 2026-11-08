<?php

namespace App\Domain\Installer;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Checks the database the merchant typed in before any schema or migration runs.
 * Talks only through PDO. The mysql and mariadb command-line clients are not required.
 */
class DatabasePreflight
{
    /**
     * @param  array<string, mixed>  $input
     */
    public static function validateCredentials(array $input): void
    {
        $connection = (string) ($input['db_connection'] ?? 'mysql');
        if (! in_array($connection, ['mysql', 'mariadb', 'sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Database type must be MySQL, MariaDB, SQLite, or PostgreSQL.');
        }

        if ($connection === 'sqlite') {
            $database = trim((string) ($input['db_database'] ?? ''));
            if ($database === '') {
                throw new RuntimeException('SQLite database path is empty. Choose a file path, or use :memory: only for tests.');
            }

            return;
        }

        $host = trim((string) ($input['db_host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('Database host is empty. Enter the host where the database is running, usually 127.0.0.1.');
        }

        $port = (int) ($input['db_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $usual = $connection === 'pgsql' ? '5432' : '3306';
            throw new RuntimeException('Database port must be a number from 1 to 65535. The usual port is '.$usual.'. If the server does not start, check its configuration file for the real port and enter that number.');
        }

        $name = (string) ($input['db_database'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('Database name may contain only letters, numbers, and underscores. Example: wentocart.');
        }

        $user = trim((string) ($input['db_username'] ?? ''));
        if ($user === '') {
            throw new RuntimeException('Database username is empty. Use an account that can create tables. On a local XAMPP or LAMPP install that account is often root.');
        }
    }

    /**
     * Server version and CREATE/DROP rights on the connection already selected for install.
     */
    public static function assertReady(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            try {
                DB::selectOne('SELECT version()');
            } catch (Throwable $e) {
                throw new RuntimeException(self::explain($e), 0, $e);
            }

            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        try {
            $row = DB::selectOne('SELECT VERSION() AS version');
        } catch (Throwable $e) {
            throw new RuntimeException(self::explain($e), 0, $e);
        }

        self::assertServerVersion((string) ($row->version ?? ''));
        self::assertCanCreateTables();
    }

    public static function assertServerVersion(string $version): void
    {
        $isMaria = stripos($version, 'mariadb') !== false;
        if (! preg_match('/(\d+\.\d+\.\d+)/', $version, $match)) {
            throw new RuntimeException('Could not read the database server version ('.$version.'). Use MySQL 5.7 or newer, or MariaDB 10.4 or newer, then try the installation again.');
        }

        $numeric = $match[1];
        if ($isMaria && version_compare($numeric, '10.4.0', '<')) {
            throw new RuntimeException('MariaDB '.$numeric.' is too old. Install MariaDB 10.4 or newer, then try the installation again.');
        }

        if (! $isMaria && version_compare($numeric, '5.7.0', '<')) {
            throw new RuntimeException('MySQL '.$numeric.' is too old. Install MySQL 5.7 or newer, or MariaDB 10.4 or newer, then try the installation again.');
        }
    }

    public static function assertCanCreateTables(): void
    {
        $table = '_wento_install_probe';
        try {
            DB::unprepared('DROP TABLE IF EXISTS `'.$table.'`');
            DB::unprepared('CREATE TABLE `'.$table.'` (`id` int NOT NULL, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            DB::unprepared('INSERT INTO `'.$table.'` (`id`) VALUES (1)');
            DB::unprepared('DROP TABLE IF EXISTS `'.$table.'`');
        } catch (Throwable $e) {
            try {
                DB::unprepared('DROP TABLE IF EXISTS `'.$table.'`');
            } catch (Throwable) {
            }

            $hint = self::connectionHint($e);
            throw new RuntimeException(
                $hint ?? 'The database user cannot create, insert into, and drop tables. Grant CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE, and REFERENCES on this database, then try the installation again. ('.$e->getMessage().')',
                0,
                $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function explain(Throwable $e, array $input = []): string
    {
        return self::connectionHint($e, $input) ?? $e->getMessage();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function connectionHint(Throwable $e, array $input = []): ?string
    {
        $detail = $e->getMessage();
        $host = trim((string) ($input['db_host'] ?? '127.0.0.1'));
        $port = trim((string) ($input['db_port'] ?? '3306'));
        $name = trim((string) ($input['db_database'] ?? ''));
        $where = $host.':'.$port;

        if (preg_match('/could not find driver|pdo_mysql/i', $detail)) {
            return 'This PHP build cannot talk to MySQL. Enable the pdo_mysql extension in the php.ini used by this web server, then restart the server. ('.$detail.')';
        }

        if (preg_match('/\b1045\b|using password|Access denied for user/i', $detail)) {
            return 'The database rejected the username or password. Check both values and try again. An empty root password is normal on a fresh XAMPP or LAMPP install. ('.$detail.')';
        }

        if (preg_match('/\b1044\b|\b1142\b|command denied/i', $detail)) {
            $target = $name !== '' ? ' in '.$name : '';

            return 'The database user does not have permission to set up tables'.$target.'. Grant CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE, and REFERENCES, then try again. ('.$detail.')';
        }

        if (preg_match('/\b1049\b|Unknown database/i', $detail)) {
            $target = $name !== '' ? ' "'.$name.'"' : '';

            return 'Database'.$target.' does not exist and could not be created. Check the database name, and make sure the user is allowed to run CREATE DATABASE. ('.$detail.')';
        }

        if (preg_match('/\b1059\b|Identifier name .+ is too long/i', $detail)) {
            return 'MySQL rejected a table or index name because it is longer than 64 characters. Empty this database, or choose a new empty database, then run the installation again. ('.$detail.')';
        }

        if (preg_match('/\b2002\b|\b2003\b|Connection refused|timed out|getaddrinfo|No such file or directory|actively refused/i', $detail)) {
            return 'Cannot reach MySQL or MariaDB at '.$where.'. Start the database service, then check the host and port. XAMPP and LAMPP: start MySQL. Linux: start the mysql or mariadb service. Docker: start the database container. The usual port is 3306. ('.$detail.')';
        }

        return null;
    }
}
