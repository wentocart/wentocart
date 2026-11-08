<?php

namespace Tests\Unit;

use App\Domain\Installer\DatabasePreflight;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabasePreflightTest extends TestCase
{
    public function test_empty_host_explains_what_to_enter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database host is empty');

        DatabasePreflight::validateCredentials([
            'db_connection' => 'mysql',
            'db_host' => '  ',
            'db_port' => '3306',
            'db_database' => 'shop',
            'db_username' => 'root',
        ]);
    }

    public function test_bad_port_names_the_usual_port(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3306');

        DatabasePreflight::validateCredentials([
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '0',
            'db_database' => 'shop',
            'db_username' => 'root',
        ]);
    }

    public function test_database_name_rejects_spaces_and_dashes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('letters, numbers, and underscores');

        DatabasePreflight::validateCredentials([
            'db_connection' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'my shop',
            'db_username' => 'root',
        ]);
    }

    public function test_empty_username_mentions_a_local_account(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('username is empty');

        DatabasePreflight::validateCredentials([
            'db_connection' => 'mariadb',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'shop',
            'db_username' => '',
        ]);
    }

    public function test_sqlite_allows_an_empty_password_and_requires_a_path(): void
    {
        DatabasePreflight::validateCredentials([
            'db_connection' => 'sqlite',
            'db_database' => ':memory:',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite database path is empty');
        DatabasePreflight::validateCredentials([
            'db_connection' => 'sqlite',
            'db_database' => '',
        ]);
    }

    public function test_connection_refused_tells_how_to_start_the_server(): void
    {
        $message = DatabasePreflight::explain(
            new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
            ['db_host' => '127.0.0.1', 'db_port' => '33306']
        );

        $this->assertStringContainsString('127.0.0.1:33306', $message);
        $this->assertStringContainsString('Start the database service', $message);
        $this->assertStringContainsString('3306', $message);
    }

    public function test_access_denied_points_at_the_password(): void
    {
        $message = DatabasePreflight::explain(
            new RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)")
        );

        $this->assertStringContainsString('username or password', $message);
        $this->assertStringContainsString('empty root password', $message);
    }

    public function test_missing_privilege_names_the_grants(): void
    {
        $message = DatabasePreflight::explain(
            new RuntimeException('SQLSTATE[42000]: Syntax error or access violation: 1142 CREATE command denied to user'),
            ['db_database' => 'shop']
        );

        $this->assertStringContainsString('CREATE', $message);
        $this->assertStringContainsString('shop', $message);
    }

    public function test_missing_pdo_driver_points_at_php_ini(): void
    {
        $message = DatabasePreflight::explain(new RuntimeException('could not find driver'));

        $this->assertStringContainsString('pdo_mysql', $message);
        $this->assertStringContainsString('php.ini', $message);
    }

    public function test_server_version_accepts_mysql_and_mariadb(): void
    {
        DatabasePreflight::assertServerVersion('8.0.36');
        DatabasePreflight::assertServerVersion('10.11.6-MariaDB-1');
        $this->addToAssertionCount(1);
    }

    public function test_old_mariadb_explains_the_minimum(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MariaDB 10.3.39 is too old');

        DatabasePreflight::assertServerVersion('10.3.39-MariaDB');
    }

    public function test_old_mysql_explains_the_minimum(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MySQL 5.6.51 is too old');

        DatabasePreflight::assertServerVersion('5.6.51');
    }
}
