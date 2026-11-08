<?php

namespace App\Console\Commands;

use App\Domain\Installer\ApacheHtaccess;
use App\Domain\Installer\InstallerService;
use App\Domain\Theme\ThemeInstaller;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'wento:doctor';

    protected $description = 'Check that this PHP binary and this folder can install the shop';

    public function handle(InstallerService $installer): int
    {
        $this->info('Using '.PHP_BINARY.' (PHP '.PHP_VERSION.')');
        $this->comment('This command only checks the PHP that is running it.');

        $failed = false;
        foreach ($installer->compatibility() as $check) {
            $mark = $check['ok'] ? 'OK' : 'FAIL';
            $this->line(sprintf('  [%s] %s  %s (%s)', $mark, $check['label'], $check['current'], $check['required']));
            if (! $check['ok']) {
                $failed = true;
                if (! empty($check['hint'])) {
                    $this->warn('        '.$check['hint']);
                }
            }
        }

        $port = 3306;
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if (is_resource($fp)) {
            fclose($fp);
            $this->line('  [OK] MySQL/MariaDB  127.0.0.1:'.$port.' is open');
        } else {
            $this->line('  [--] MySQL/MariaDB  127.0.0.1:'.$port.' not open (fine if you use another host, SQLite, or Docker)');
        }

        $this->newLine();
        $copied = app(ThemeInstaller::class)->publishBundled(false);
        $publicClassic = public_path('themes/classic/theme.json');
        $sourceClassic = base_path('themes/classic/theme.json');
        if (! is_file($sourceClassic)) {
            $this->error('  [FAIL] Classic theme source  themes/classic/theme.json is missing');
            $failed = true;
        } else {
            $this->line('  [OK] Classic theme source  themes/classic');
        }
        if (is_file($publicClassic)) {
            $msg = $copied > 0
                ? 'public/themes/classic (copied '.$copied.' updated file'.($copied === 1 ? '' : 's').')'
                : 'public/themes/classic (in sync with themes/classic)';
            $this->line('  [OK] Classic theme public  '.$msg);
        } else {
            $this->error('  [FAIL] Classic theme public  public/themes/classic missing — run php artisan wento:theme-publish');
            $failed = true;
        }

        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            $this->line('  [OK] Frontend build  public/build/manifest.json');
        } else {
            $this->line('  [--] Frontend build  public/build missing (run npm install && npm run build, or npm run dev)');
        }

        $rewroteHtaccess = ApacheHtaccess::ensure(base_path(), (string) config('app.url'));
        $htaccessBase = ApacheHtaccess::baseFromUrl((string) config('app.url'));
        $this->line($rewroteHtaccess
            ? '  [OK] Apache .htaccess  RewriteBase aligned to '.$htaccessBase.' (repaired)'
            : '  [OK] Apache .htaccess  RewriteBase '.$htaccessBase);

        if ($failed) {
            $this->newLine();
            $this->error('Fix the FAIL lines, then run: php artisan wento:doctor');
            $this->line('Or skip local PHP entirely: docker compose up --build');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ready. Next: php artisan wento:install');
        $this->comment('After git pull: php artisan wento:theme-publish && npm run build (or npm run dev)');

        return self::SUCCESS;
    }
}
