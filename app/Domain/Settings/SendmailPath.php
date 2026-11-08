<?php

namespace App\Domain\Settings;

/**
 * The command the server already uses to hand mail to sendmail or Postfix.
 * PHP uses the same sendmail_path setting.
 */
final class SendmailPath
{
    public static function resolve(?string $iniPath = null): ?string
    {
        $fromIni = self::normalize($iniPath ?? (string) ini_get('sendmail_path'));
        if ($fromIni !== null) {
            return $fromIni;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        foreach (['/usr/sbin/sendmail', '/usr/bin/sendmail'] as $binary) {
            if (@is_file($binary)) {
                return $binary.' -t -i';
            }
        }

        return null;
    }

    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, ' -bs') && ! str_contains($raw, ' -t')) {
            $raw .= ' -t -i';
        }

        return $raw;
    }
}
