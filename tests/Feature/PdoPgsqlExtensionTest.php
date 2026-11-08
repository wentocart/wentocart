<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Proves project-local pdo_pgsql binaries under tools/php-ext can load
 * (API CI has pdo_pgsql; host apt install may be blocked by sudo).
 */
class PdoPgsqlExtensionTest extends TestCase
{
    public function test_project_local_pdo_pgsql_extension_files_exist(): void
    {
        $dir = base_path('tools/php-ext');
        if (! is_dir($dir)) {
            $this->markTestSkipped('Local PostgreSQL PHP tooling is not part of this source tree.');
        }
        $this->assertFileExists($dir.'/pdo_pgsql.so');
        $this->assertFileExists($dir.'/pgsql.so');
        $this->assertFileExists(base_path('tools/php-with-pgsql'));
    }

    public function test_wrapper_loads_pdo_pgsql(): void
    {
        $wrapper = base_path('tools/php-with-pgsql');
        if (! is_file($wrapper)) {
            $this->markTestSkipped('Local PostgreSQL PHP tooling is not part of this source tree.');
        }
        $cmd = escapeshellarg($wrapper).' -r '.escapeshellarg(
            'echo extension_loaded("pdo_pgsql") ? "yes" : "no";'
        );
        $out = trim((string) shell_exec($cmd.' 2>/dev/null'));
        $this->assertSame('yes', $out, 'Expected tools/php-with-pgsql to load pdo_pgsql');
    }
}
