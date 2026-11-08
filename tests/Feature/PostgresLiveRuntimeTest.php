<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Evidence for PostgreSQL live runtime lead: host binaries (even off PATH),
 * ephemeral cluster tooling, Compose overlay, and artisan doctor/ping.
 */
class PostgresLiveRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(base_path('tools/pgsql-paths.sh'))) {
            $this->markTestSkipped('PostgreSQL live tooling is not part of this source tree.');
        }
    }

    public function test_pgsql_tooling_files_exist(): void
    {
        $this->assertFileExists(base_path('tools/pgsql-paths.sh'));
        $this->assertFileExists(base_path('tools/pgsql-ephemeral.sh'));
        $this->assertFileExists(base_path('tools/php-with-pgsql'));
        $this->assertFileExists(base_path('docker/compose.pgsql.yml'));
        $overlay = (string) file_get_contents(base_path('docker/compose.pgsql.yml'));
        $this->assertStringContainsString('DB_CONNECTION: pgsql', $overlay);
        $this->assertStringContainsString('DB_HOST: postgres', $overlay);
    }

    public function test_pgsql_paths_resolve_initdb_and_pg_ctl(): void
    {
        $script = <<<'BASH'
set -euo pipefail
source tools/pgsql-paths.sh
initdb="$(pgsql_resolve_bin initdb)"
pgctl="$(pgsql_resolve_bin pg_ctl)"
test -x "$initdb"
test -x "$pgctl"
echo "ok:$initdb:$pgctl"
BASH;
        $cmd = 'bash -c '.escapeshellarg($script);
        $out = trim((string) shell_exec('cd '.escapeshellarg(base_path()).' && '.$cmd.' 2>/dev/null'));
        $this->assertStringStartsWith('ok:', $out, 'Expected initdb/pg_ctl via tools/pgsql-paths.sh (PATH or /usr/lib/postgresql/*/bin)');
    }

    public function test_artisan_pgsql_live_doctor_and_compose_hint(): void
    {
        $this->assertArrayHasKey('wento:pgsql-live', Artisan::all());

        $compose = Artisan::call('wento:pgsql-live', ['--compose' => true]);
        $this->assertSame(0, $compose);
        $this->assertStringContainsString('compose.pgsql.yml', Artisan::output());

        $doctor = Artisan::call('wento:pgsql-live', ['action' => 'doctor']);
        $output = Artisan::output();
        $this->assertSame(0, $doctor, $output);
        $this->assertStringContainsString('ephemeral script: yes', $output);
        $this->assertStringContainsString('compose overlay: yes', $output);
    }

    public function test_ephemeral_cluster_start_ping_stop_when_binaries_present(): void
    {
        $paths = base_path('tools/pgsql-paths.sh');
        $check = trim((string) shell_exec(
            'bash -c '.escapeshellarg('source '.escapeshellarg($paths).' && pgsql_resolve_bin initdb >/dev/null && echo yes || echo no')
        ));
        if ($check !== 'yes') {
            $this->markTestSkipped('initdb not available on this host');
        }

        $ephemeral = base_path('tools/pgsql-ephemeral.sh');
        $start = $this->runBash($ephemeral.' start');
        $this->assertSame(0, $start['code'], $start['out']);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $start['out']);

        $ping = $this->runBash($ephemeral.' ping');
        $this->assertSame(0, $ping['code'], $ping['out']);
        $this->assertStringContainsString('wento-pgsql-live:', $ping['out']);

        $status = Artisan::call('wento:pgsql-live', ['action' => 'status']);
        $this->assertSame(0, $status, Artisan::output());

        $stop = $this->runBash($ephemeral.' stop');
        $this->assertSame(0, $stop['code'], $stop['out']);
    }

    /**
     * @return array{code: int, out: string}
     */
    protected function runBash(string $command): array
    {
        $full = 'cd '.escapeshellarg(base_path()).' && bash '.$command.' 2>&1';
        $out = [];
        $code = 0;
        exec($full, $out, $code);

        return ['code' => $code, 'out' => implode("\n", $out)];
    }
}
