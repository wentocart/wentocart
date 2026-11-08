<?php

namespace App\Domain\Settings\Sms;

interface SmsDriver
{
    public static function key(): string;

    public static function label(): string;

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $to, string $body, string $purpose): array;
}
