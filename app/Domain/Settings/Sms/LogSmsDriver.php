<?php

namespace App\Domain\Settings\Sms;

class LogSmsDriver implements SmsDriver
{
    public static function key(): string
    {
        return 'log';
    }

    public static function label(): string
    {
        return 'Log only (development)';
    }

    public function send(string $to, string $body, string $purpose): array
    {
        return ['ok' => true, 'error' => null];
    }
}
