<?php

namespace Tests\Feature;

use App\Domain\Installer\SchemaBootstrap;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallerDatabaseTest extends TestCase
{
    public function test_schema_import_works_when_mysql_cli_is_not_on_path(): void
    {
        $isolated = $this->withoutMysqlClient(function () {
            SchemaBootstrap::importStatements("CREATE TABLE wento_schema_probe (id INTEGER PRIMARY KEY);\n");
        });

        $this->assertTrue($isolated);
        $this->assertTrue(Schema::hasTable('wento_schema_probe'));
        Schema::dropIfExists('wento_schema_probe');
    }

    public function test_installer_source_does_not_shell_out_to_the_mysql_client(): void
    {
        $schema = (string) file_get_contents(app_path('Domain/Installer/SchemaBootstrap.php'));
        $service = (string) file_get_contents(app_path('Domain/Installer/InstallerService.php'));

        $this->assertStringNotContainsString('mysqlBinary', $schema);
        $this->assertStringNotContainsString('/opt/lampp/bin/mysql', $schema);
        $this->assertStringNotContainsString("artisanOrFail('migrate'", $service);
        $this->assertStringNotContainsString('PendingMigrations::run()', $service);
        $this->assertFileDoesNotExist(app_path('Domain/Installer/PendingMigrations.php'));
    }

    public function test_repo_does_not_keep_extra_laravel_migrations(): void
    {
        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getFilename())
            ->filter(fn ($name) => str_ends_with($name, '.php'))
            ->values()
            ->all();

        $this->assertSame([], $files, 'Put schema changes in mysql-schema.sql / sqlite-schema.sql. Do not keep extra Laravel migration files in git.');
    }

    public function test_schema_dumps_include_tables_that_used_to_live_in_extra_migrations(): void
    {
        $mysql = (string) file_get_contents(database_path('schema/mysql-schema.sql'));
        $sqlite = (string) file_get_contents(database_path('schema/sqlite-schema.sql'));

        foreach ([$mysql, $sqlite] as $dump) {
            $this->assertStringContainsString('checkout_groups', $dump);
            $this->assertStringContainsString('support_tickets', $dump);
            $this->assertStringContainsString('subscription_message', $dump);
            $this->assertStringContainsString('checkout_reference', $dump);
            $this->assertStringContainsString('storefront_api_keys', $dump);
            $this->assertStringContainsString('admin_api_audits', $dump);
        }
        $this->assertStringNotContainsString('KEY `__DB_PREFIX__orders_number_unique`', $mysql);
        $this->assertStringNotContainsString('CREATE UNIQUE INDEX "orders_number_unique"', $sqlite);
    }

    public function test_long_table_prefix_does_not_overflow_mysql_identifier_limit(): void
    {
        $raw = (string) file_get_contents(database_path('schema/mysql-schema.sql'));
        $prefix = str_repeat('A', 32).'_';
        $sql = SchemaBootstrap::fitIdentifiers(str_replace('__DB_PREFIX__', $prefix, $raw));

        preg_match_all('/`([^`]+)`/', $sql, $matches);
        foreach (array_unique($matches[1]) as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        $this->assertMatchesRegularExpression(
            '/UNIQUE KEY `[^`]{1,64}` \(`attachment_id`,`language_code`\)/',
            $sql
        );
        $this->assertStringNotContainsString('attachment_translations_attachment_id_language_code_unique`', $sql);
    }

    private function withoutMysqlClient(callable $callback): bool
    {
        $bin = sys_get_temp_dir().'/wento-no-mysql-'.bin2hex(random_bytes(4));
        mkdir($bin);
        $saved = [
            'PATH' => getenv('PATH'),
            'ENV' => $_ENV['PATH'] ?? null,
            'SERVER' => $_SERVER['PATH'] ?? null,
        ];
        putenv('PATH='.$bin);
        $_ENV['PATH'] = $bin;
        $_SERVER['PATH'] = $bin;

        try {
            $found = [];
            exec('command -v mysql 2>/dev/null', $found, $status);
            $this->assertNotSame(0, $status, 'mysql stayed on PATH, so this test would not prove the installer works without it.');
            $callback();

            return true;
        } finally {
            putenv('PATH='.(string) $saved['PATH']);
            if ($saved['ENV'] === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $saved['ENV'];
            }
            if ($saved['SERVER'] === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $saved['SERVER'];
            }
            @rmdir($bin);
        }
    }
}
