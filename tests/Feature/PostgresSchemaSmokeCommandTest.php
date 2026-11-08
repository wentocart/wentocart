<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PostgresSchemaSmokeCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('wento:postgres-schema-smoke', Artisan::all());
    }

    public function test_refuses_non_pgsql_without_psql_flag_when_pdo_pgsql_loaded(): void
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — CLI path is the default.');
        }

        if (config('database.default') === 'pgsql') {
            $this->markTestSkipped('Default connection is already pgsql.');
        }

        $code = Artisan::call('wento:postgres-schema-smoke');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('DB_CONNECTION must be pgsql', Artisan::output());
    }

    public function test_psql_path_fails_cleanly_when_psql_missing_or_unreachable(): void
    {
        // Force --psql so we exercise the CLI branch without needing a live cluster.
        $code = Artisan::call('wento:postgres-schema-smoke', [
            '--psql' => true,
            '--host' => '127.0.0.1',
            '--port' => '1',
            '--database' => 'wento_definitely_missing_'.uniqid(),
            '--username' => 'no_such_role',
            '--force' => true,
        ]);

        $this->assertSame(1, $code);
        $out = Artisan::output();
        $this->assertTrue(
            str_contains($out, 'psql not found')
            || str_contains($out, 'Could not reset schema')
            || str_contains($out, 'psql import failed')
            || str_contains($out, 'Connection refused')
            || str_contains($out, 'could not connect'),
            'Expected a clean psql failure message, got: '.$out
        );
    }
}
