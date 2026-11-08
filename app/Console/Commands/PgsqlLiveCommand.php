<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Manage / verify the recommended PostgreSQL live runtime
 * (ephemeral host cluster via tools/pgsql-ephemeral.sh, or Compose overlay).
 */
class PgsqlLiveCommand extends Command
{
    protected $signature = 'wento:pgsql-live
        {action=status : start|stop|status|ping|env|destroy|doctor}
        {--compose : Print the recommended Docker Compose command instead of using the host cluster}';

    protected $description = 'Start/stop/ping the project PostgreSQL live runtime (host ephemeral or Compose).';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        if ($this->option('compose') || $action === 'compose') {
            $this->line('docker compose --profile postgres -f docker-compose.yml -f docker/compose.pgsql.yml up --build');

            return self::SUCCESS;
        }

        if ($action === 'doctor') {
            return $this->doctor();
        }

        if ($action === 'ping' && $this->canPingConfiguredPgsql()) {
            $this->info('Connected via current DB_* config.');
            $this->line((string) DB::selectOne('select version() as v')->v);

            return self::SUCCESS;
        }

        $script = base_path('tools/pgsql-ephemeral.sh');
        if (! is_file($script)) {
            $this->error('Missing tools/pgsql-ephemeral.sh');

            return self::FAILURE;
        }

        if (! in_array($action, ['start', 'stop', 'status', 'ping', 'env', 'destroy'], true)) {
            $this->error('Unknown action. Use start|stop|status|ping|env|destroy|doctor');

            return self::FAILURE;
        }

        $process = new Process(['bash', $script, $action], base_path());
        $process->setTimeout(120);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    protected function canPingConfiguredPgsql(): bool
    {
        try {
            if (DB::getDriverName() !== 'pgsql') {
                return false;
            }
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function doctor(): int
    {
        $ok = true;
        $paths = base_path('tools/pgsql-paths.sh');
        $this->line('pdo_pgsql: '.(extension_loaded('pdo_pgsql') ? 'loaded' : 'missing (use ./tools/php-with-pgsql)'));
        $this->line('php-with-pgsql: '.(is_file(base_path('tools/php-with-pgsql')) ? 'yes' : 'no'));
        $this->line('ephemeral script: '.(is_file(base_path('tools/pgsql-ephemeral.sh')) ? 'yes' : 'no'));
        $this->line('compose overlay: '.(is_file(base_path('docker/compose.pgsql.yml')) ? 'yes' : 'no'));

        $process = new Process(['bash', '-c', 'source '.escapeshellarg($paths).' && pgsql_resolve_bin initdb && pgsql_resolve_bin pg_ctl'], base_path());
        $process->run();
        if ($process->isSuccessful()) {
            $this->line("server binaries:\n".trim($process->getOutput()));
        } else {
            $ok = false;
            $this->warn('initdb/pg_ctl not found on PATH or under /usr/lib/postgresql/*/bin');
            $this->line('Fallback: php artisan wento:pgsql-live --compose');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
