<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\SmsLog;
use App\Domain\Settings\Sms\LogSmsDriver;
use App\Domain\Settings\Sms\SmsDriver;
use App\Domain\Settings\Sms\TwilioSmsDriver;
use App\Domain\Settings\Sms\WebhookSmsDriver;

class SmsSender
{
    /**
     * @return array<string, string>
     */
    public static function drivers(): array
    {
        return [
            LogSmsDriver::key() => LogSmsDriver::label(),
            WebhookSmsDriver::key() => WebhookSmsDriver::label(),
            TwilioSmsDriver::key() => TwilioSmsDriver::label(),
        ];
    }

    public static function driverKey(): string
    {
        $key = trim((string) ShopSetting::get('auth.sms_driver', 'log'));

        return array_key_exists($key, self::drivers()) ? $key : 'log';
    }

    public function send(string $to, string $body, string $purpose = 'notice'): bool
    {
        $to = trim($to);
        $body = trim($body);
        if ($to === '' || $body === '') {
            return false;
        }

        $driver = match (self::driverKey()) {
            TwilioSmsDriver::key() => app(TwilioSmsDriver::class),
            WebhookSmsDriver::key() => app(WebhookSmsDriver::class),
            default => app(LogSmsDriver::class),
        };
        /** @var SmsDriver $driver */
        $result = $driver->send($to, $body, $purpose);
        $status = $result['ok'] ? (self::driverKey() === 'log' || (self::driverKey() === 'webhook' && trim((string) ShopSetting::get('auth.sms_webhook', '')) === '') ? 'logged' : 'sent') : 'failed';

        SmsLog::query()->create([
            'to_phone' => $to,
            'body' => $body,
            'status' => $status,
            'purpose' => $purpose,
            'error' => $result['error'],
        ]);

        return $status !== 'failed';
    }
}
