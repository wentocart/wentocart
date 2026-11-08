<?php

namespace App\Console\Commands;

use App\Domain\Payment\PaymentSettings;
use App\Domain\Platform\Upgrade\PlatformMeta;
use Illuminate\Console\Command;
use Throwable;

class ReleaseCheckCommand extends Command
{
    protected $signature = 'wento:release-check {--production : Check as if this shop is already on the public internet}';

    protected $description = 'Fail if this shop is not ready to face the public internet.';

    public function handle(): int
    {
        $production = $this->option('production') || app()->environment('production');
        $errors = [];
        $warnings = [];

        if (! \App\Domain\Installer\InstallerService::appKeyIsValid((string) config('app.key'))) {
            $errors[] = 'APP_KEY is missing or invalid. Run php artisan key:generate --force.';
        }
        if ($production && config('app.debug')) {
            $errors[] = 'APP_DEBUG must be false in production.';
        }
        if ($production && app()->environment('local')) {
            $errors[] = 'APP_ENV is still local while --production was requested.';
        }
        if ($production && PaymentSettings::sandboxPay()) {
            $errors[] = 'payments.sandbox_pay is on. Turn it off before taking live money.';
        }
        if ($production && ! config('session.secure') && str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'SESSION_SECURE_COOKIE should be true behind HTTPS.';
        }
        if ($production && config('session.same_site') === 'none' && ! config('session.secure')) {
            $errors[] = 'SESSION_SAME_SITE=none requires SESSION_SECURE_COOKIE=true.';
        }
        if ($production && ! config('session.encrypt')) {
            $errors[] = 'SESSION_ENCRYPT must be true so a database copy does not reveal session contents.';
        }
        if ($production && ! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL must start with https:// on a public shop.';
        }
        foreach (['storage/app', 'storage/logs', 'bootstrap/cache'] as $dir) {
            if (! is_writable(base_path($dir))) {
                $errors[] = $dir.' is not writable.';
            }
        }
        $logFile = storage_path('logs/laravel.log');
        if (is_file($logFile) && ! is_writable($logFile)) {
            $errors[] = 'storage/logs/laravel.log is not writable (checkout/mail can return Server Error when MAIL_MAILER=log).';
        }
        if (! extension_loaded('openssl') || ! extension_loaded('pdo_mysql')) {
            $errors[] = 'Required PHP extensions openssl and pdo_mysql must be loaded.';
        }
        if (PHP_VERSION_ID < 80300) {
            $errors[] = 'PHP 8.3+ is required (this host is '.PHP_VERSION.').';
        }
        if (! $production && config('app.debug')) {
            $warnings[] = 'APP_DEBUG is on (fine for local). Turn it off before DNS goes public.';
        }
        try {
            if (config('wento.installed') || is_file((string) config('wento.lock_path'))) {
                $code = PlatformMeta::codeVersion();
                $installed = PlatformMeta::installedVersion();
                if (version_compare($installed, $code, '<')) {
                    $warnings[] = 'Platform DB version '.$installed.' is behind code '.$code.'. Run php artisan wento:upgrade.';
                }
            }
        } catch (Throwable) {
            // ignore when DB unavailable
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info($production
            ? 'Release check passed. Keep APP_DEBUG=false, HTTPS on, and sandbox pay off.'
            : 'Local release check passed. Re-run with --production on the live host.');

        return self::SUCCESS;
    }
}
