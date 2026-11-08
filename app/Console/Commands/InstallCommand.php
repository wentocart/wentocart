<?php

namespace App\Console\Commands;

use App\Domain\Installer\InstallerService;
use App\Domain\Security\AdminPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'wento:install
                            {--store-name= : Store display name}
                            {--store-email= : Public shop email}
                            {--store-address= : Optional shop address}
                            {--url= : Public URL}
                            {--db-connection=mysql : mysql, mariadb, sqlite, or pgsql}
                            {--db-host=127.0.0.1}
                            {--db-port=3306}
                            {--db-database= : Database name or sqlite path}
                            {--db-username=root}
                            {--db-password=}
                            {--prefix= : Table prefix, e.g. WBL}
                            {--admin-name=Administrator}
                            {--admin-email=}
                            {--admin-password=}
                            {--language=en}
                            {--currency=USD}
                            {--demo : Install demo catalog, customers, and orders}
                            {--no-demo : Skip demo data during install}';

    protected $description = 'Install the store from the command line';

    public function handle(InstallerService $installer): int
    {
        $this->banner();

        if ($installer->alreadyInstalled()) {
            $this->error('Already installed. Remove storage/app/installed.lock only if you intend to reinstall.');

            return self::FAILURE;
        }

        if (! $installer->ready()) {
            $this->error('This PHP does not meet requirements.');
            $this->call('wento:doctor');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive();
        $started = microtime(true);

        try {
            $this->step(1, 4, 'Store');
            $input = $this->collectStoreInput($interactive, $this->loadSavedAnswers());

            $this->step(2, 4, 'Database');
            $input = array_merge($input, $this->collectDatabaseInput($installer, $interactive, $input));

            $this->step(3, 4, 'Admin');
            $input = array_merge($input, $this->collectAdminInput($interactive, $input));

            $this->saveAnswers($input);
        } catch (Throwable $e) {
            $this->failSoft($e);

            return self::FAILURE;
        }

        if (! $interactive && (empty($input['admin_password']) || strlen((string) $input['admin_password']) < 8)) {
            $this->error('Non-interactive install needs --admin-password (min 8 characters).');

            return self::FAILURE;
        }

        if (! wento_db_prefix_valid((string) $input['db_prefix'])) {
            $this->error('Table prefix must start with a letter, then letters, numbers, or underscores (examples: WBL, tiger_, tia_2_, Lo_as_as_). Spaces and hyphens are not allowed.');

            return self::FAILURE;
        }

        if (! $interactive) {
            try {
                $installer->assertEmptyDatabase($input);
            } catch (Throwable $e) {
                $this->failSoft($e);

                return self::FAILURE;
            }
        }

        $this->step(4, 4, 'Install');
        $this->line('  Tables will be named <fg=cyan>'.wento_db_prefix($input['db_prefix']).'admins</>, …');
        $this->newLine();

        try {
            $installer->install($input, function (string $line): void {
                if ($line === '' || str_starts_with($line, "\r")) {
                    $this->output->write($line === '' ? PHP_EOL : $line);
                    if (method_exists($this->output, 'getOutput') && method_exists($this->output->getOutput(), 'isDecorated')) {
                        // keep moving
                    }
                    @ob_flush();
                    @flush();

                    return;
                }
                $this->line($line);
            });
        } catch (Throwable $e) {
            $this->failSoft($e);
            $this->warn('Answers were saved. Fix the issue, then run the same command again — you will not retype everything.');

            return self::FAILURE;
        }

        $this->clearAnswers();
        $seconds = (int) round(microtime(true) - $started);
        $shop = rtrim((string) $input['app_url'], '/');

        $this->newLine();
        $this->info(str_repeat('=', 56));
        $this->info('  Installation complete ('.$seconds.'s)');
        $this->info(str_repeat('=', 56));
        $this->line('  Shop:  <fg=green;options=bold>'.$shop.'</>');
        $this->line('  Admin: <fg=green;options=bold>'.$shop.'/'.AdminPath::segment().'/login</>');
        $this->newLine();
        $this->comment('  Open those URLs in your browser.');
        $this->comment('  Local helper: php artisan serve');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function banner(): void
    {
        $this->newLine();
        $this->info(str_repeat('=', 56));
        $this->info('  '.config('wento.name').' — command-line install');
        $this->info(str_repeat('=', 56));
        $this->comment('  Ctrl+C cancels. Errors print here; answers are saved for retry.');
        $this->newLine();
    }

    protected function step(int $n, int $total, string $title): void
    {
        $this->newLine();
        $this->info("[{$n}/{$total}] {$title}");
        $this->line(str_repeat('-', 40));
    }

    protected function failSoft(Throwable $e): void
    {
        $this->newLine();
        $this->error('Install stopped:');
        $this->line('  '.$e->getMessage());
        $this->newLine();
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadSavedAnswers(): array
    {
        $path = $this->answersPath();
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return [];
        }

        if ($this->input->isInteractive() && $this->confirm('Resume with answers from the last attempt?', true)) {
            $this->comment('  Reusing saved store / database / admin answers (password still asked if missing).');

            return $data;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function saveAnswers(array $input): void
    {
        $safe = $input;
        unset($safe['admin_password'], $safe['db_password']);
        File::ensureDirectoryExists(dirname($this->answersPath()));
        File::put($this->answersPath(), json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function clearAnswers(): void
    {
        File::delete($this->answersPath());
    }

    protected function answersPath(): string
    {
        return storage_path('app/install-answers.json');
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    protected function collectStoreInput(bool $interactive, array $saved): array
    {
        return [
            'store_name' => $this->option('store-name')
                ?: ($interactive ? $this->ask('Store name', $saved['store_name'] ?? config('wento.name')) : (string) ($saved['store_name'] ?? config('wento.name'))),
            'store_email' => $this->option('store-email')
                ?: ($interactive ? $this->ask('Store email', $saved['store_email'] ?? null) : (string) ($saved['store_email'] ?? $this->option('admin-email') ?: 'admin@wentocart.test')),
            'store_address' => $this->option('store-address')
                ?: ($interactive ? $this->ask('Store address (optional)', $saved['store_address'] ?? '') : (string) ($saved['store_address'] ?? '')),
            'app_url' => $this->option('url')
                ?: ($interactive ? $this->ask('App URL', $saved['app_url'] ?? config('app.url')) : (string) ($saved['app_url'] ?? config('app.url'))),
            'language' => $this->option('language') ?: ($saved['language'] ?? 'en'),
            'currency' => $this->option('currency') ?: ($saved['currency'] ?? 'USD'),
        ];
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    protected function collectDatabaseInput(InstallerService $installer, bool $interactive, array $saved): array
    {
        $input = [
            'db_connection' => $this->option('db-connection')
                ?: ($interactive
                    ? $this->choice(
                        'Database',
                        ['mysql', 'mariadb', 'sqlite', 'pgsql'],
                        match ($saved['db_connection'] ?? 'mysql') {
                            'sqlite' => 2,
                            'pgsql' => 3,
                            'mariadb' => 1,
                            default => 0,
                        }
                    )
                    : ($saved['db_connection'] ?? 'mysql')),
            'db_host' => $this->askOrOption('db-host', 'Database host', (string) ($saved['db_host'] ?? '127.0.0.1'), $interactive),
            'db_port' => $this->askOrOption(
                'db-port',
                'Database port',
                (string) ($saved['db_port'] ?? ((($this->option('db-connection') ?: ($saved['db_connection'] ?? 'mysql')) === 'pgsql') ? '5432' : '3306')),
                $interactive
            ),
            'db_username' => $this->askOrOption(
                'db-username',
                'Database username',
                (string) ($saved['db_username'] ?? ((($this->option('db-connection') ?: ($saved['db_connection'] ?? 'mysql')) === 'pgsql') ? 'wento' : 'root')),
                $interactive
            ),
            'db_password' => $this->option('db-password') ?? ($interactive ? $this->secret('Database password (empty allowed)') : ''),
        ];

        $defaultName = (string) ($saved['db_database'] ?? config('wento.codename'));
        $name = $this->option('db-database') ?: ($interactive ? $this->ask('Database name', $defaultName) : $defaultName);

        if ($interactive) {
            while (true) {
                $input['db_database'] = $name;
                try {
                    $installer->assertEmptyDatabase($input);
                    if (in_array($input['db_connection'], ['mysql', 'mariadb'], true)) {
                        $this->info('  Database "'.$name.'" is empty and ready.');
                    }
                    break;
                } catch (Throwable $e) {
                    $this->error('  '.$e->getMessage());
                    if (! str_contains($e->getMessage(), 'already been used')) {
                        throw $e;
                    }
                    $name = $this->ask('Database name', $defaultName);
                }
            }
        } else {
            $input['db_database'] = $name;
        }

        $input['db_prefix'] = $this->option('prefix')
            ?: ($interactive ? $this->ask('Table prefix (e.g. WBL)', $saved['db_prefix'] ?? 'wento') : ($saved['db_prefix'] ?? 'wento'));

        return $input;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    protected function collectAdminInput(bool $interactive, array $saved): array
    {
        return [
            'admin_name' => $this->option('admin-name') ?: ($saved['admin_name'] ?? 'Administrator'),
            'admin_email' => $this->option('admin-email')
                ?: ($interactive ? $this->ask('Admin email', $saved['admin_email'] ?? null) : (string) ($saved['admin_email'] ?? 'admin@wentocart.test')),
            'admin_password' => $this->option('admin-password')
                ?: ($interactive ? $this->secret('Admin password (min 8)') : null),
            'install_demo_data' => $this->resolveDemoOption(),
        ];
    }

    protected function askOrOption(string $option, string $question, string $default, bool $interactive): mixed
    {
        if ($this->input->hasParameterOption('--'.$option)) {
            return $this->option($option);
        }

        if (! $interactive) {
            return $this->option($option) ?: $default;
        }

        return $this->ask($question, $this->option($option) ?: $default);
    }

    protected function resolveDemoOption(): bool
    {
        if ($this->option('no-demo')) {
            return false;
        }
        if ($this->option('demo')) {
            return true;
        }
        if (! $this->input->isInteractive()) {
            return true;
        }

        return (bool) $this->confirm('Install demo data? (products, customers, orders)', true);
    }
}
