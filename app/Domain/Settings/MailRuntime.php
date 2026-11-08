<?php

namespace App\Domain\Settings;

use App\Domain\Installer\InstallerService;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

class MailRuntime
{
    public static function apply(): void
    {
        $mailer = (string) ShopSetting::get('mail.mailer', '');
        if ($mailer === '') {
            return;
        }

        $fromAddress = (string) ShopSetting::get('mail.from_address', config('mail.from.address'));
        $fromName = (string) ShopSetting::get('mail.from_name', config('mail.from.name'));

        config([
            'mail.default' => $mailer,
            'mail.from.address' => $fromAddress ?: config('mail.from.address'),
            'mail.from.name' => $fromName ?: config('mail.from.name'),
        ]);

        if ($mailer === 'sendmail') {
            $command = SendmailPath::resolve();
            if ($command !== null) {
                config(['mail.mailers.sendmail.path' => $command]);
            }
            self::forgetResolvedMailer();

            return;
        }

        if ($mailer !== 'smtp') {
            self::forgetResolvedMailer();

            return;
        }

        $encryption = (string) ShopSetting::get('mail.encryption', 'tls');
        if (! in_array($encryption, ['tls', 'ssl'], true)) {
            $encryption = null;
        }

        config([
            'mail.mailers.smtp.host' => ShopSetting::get('mail.host', config('mail.mailers.smtp.host')),
            'mail.mailers.smtp.port' => (int) ShopSetting::get('mail.port', config('mail.mailers.smtp.port')),
            'mail.mailers.smtp.encryption' => $encryption,
            'mail.mailers.smtp.username' => ShopSetting::get('mail.username', config('mail.mailers.smtp.username')),
            'mail.mailers.smtp.password' => self::password() ?: config('mail.mailers.smtp.password'),
        ]);
        self::forgetResolvedMailer();
    }

    public static function assertCanSend(): void
    {
        if ((string) config('mail.default') !== 'sendmail') {
            return;
        }

        if (SendmailPath::resolve() !== null) {
            return;
        }

        throw new RuntimeException(
            'Sendmail is not available on this server. PHP has no sendmail program configured. Use SMTP on this computer. On the live server, install sendmail or Postfix, or set sendmail_path in php.ini, then choose Sendmail again.'
        );
    }

    protected static function forgetResolvedMailer(): void
    {
        if (app()->bound('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
    }

    public static function password(): string
    {
        $raw = (string) ShopSetting::get('mail.password', '');
        if ($raw === '') {
            return '';
        }
        try {
            return Crypt::decryptString($raw);
        } catch (Throwable) {
            return $raw;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        ShopSetting::put('mail.mailer', $data['mailer']);
        ShopSetting::put('mail.host', $data['host'] ?? '');
        ShopSetting::put('mail.port', (string) ($data['port'] ?? 587));
        ShopSetting::put('mail.encryption', $data['encryption'] ?? '');
        ShopSetting::put('mail.username', $data['username'] ?? '');
        ShopSetting::put('mail.from_address', $data['from_address'] ?? '');
        ShopSetting::put('mail.from_name', $data['from_name'] ?? '');

        $password = trim((string) ($data['password'] ?? ''));
        if ($password !== '') {
            ShopSetting::put('mail.password', Crypt::encryptString($password));
        }

        self::apply();
        self::persistEnv($data, $password);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function persistEnv(array $data, string $password): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $store = app(StoreContext::class)->store();
        if (! $store?->is_default) {
            return;
        }

        $pairs = [
            'MAIL_MAILER' => (string) $data['mailer'],
            'MAIL_HOST' => (string) ($data['host'] ?? ''),
            'MAIL_PORT' => (string) ($data['port'] ?? 587),
            'MAIL_USERNAME' => (string) ($data['username'] ?? ''),
            'MAIL_ENCRYPTION' => (string) ($data['encryption'] ?? ''),
            'MAIL_FROM_ADDRESS' => (string) ($data['from_address'] ?? ''),
            'MAIL_FROM_NAME' => (string) ($data['from_name'] ?? ''),
        ];
        if ($password !== '') {
            $pairs['MAIL_PASSWORD'] = $password;
        }

        app(InstallerService::class)->writeEnvPairs($pairs);
    }
}
