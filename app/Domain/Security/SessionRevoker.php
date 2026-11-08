<?php

namespace App\Domain\Security;

use App\Domain\Customer\ShopSessionGuard;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kill every session of a user except the current one.
 *
 * File and database session payloads serialize the login as
 * `login_{guard}_{sha1(guard class)} => user id`, so ownership can be proven
 * without trusting filenames. Values may be encrypted (EncryptedStore) —
 * each candidate value is decrypted before comparison.
 *
 * Call after every password change so sessions stolen with the old password die.
 */
final class SessionRevoker
{
    public static function revokeOthers(string $guard, int|string $userId): int
    {
        try {
            return config('session.driver') === 'database'
                ? self::revokeDatabase($guard, $userId)
                : self::revokeFiles($guard, $userId);
        } catch (Throwable $e) {
            Log::warning('Session revocation failed.', ['guard' => $guard, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    protected static function loginKey(string $guard): string
    {
        $class = $guard === 'customer' ? ShopSessionGuard::class : SessionGuard::class;

        return 'login_'.$guard.'_'.sha1($class);
    }

    protected static function currentId(): ?string
    {
        try {
            $id = session()->getId();

            return $id !== '' ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function revokeFiles(string $guard, int|string $userId): int
    {
        $dir = (string) config('session.files', storage_path('framework/sessions'));
        if (! is_dir($dir)) {
            return 0;
        }
        $key = self::loginKey($guard);
        $current = self::currentId();
        $killed = 0;
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (! is_file($file) || ($current !== null && basename((string) $file) === $current)) {
                continue;
            }
            try {
                $contents = file_get_contents((string) $file);
                if (! is_string($contents)) {
                    continue;
                }
                $payload = self::decodePayload($contents);
                if ($payload !== null && self::payloadBelongsTo($payload, $key, $userId) && @unlink((string) $file)) {
                    $killed++;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $killed;
    }

    protected static function revokeDatabase(string $guard, int|string $userId): int
    {
        $table = (string) config('session.table', 'sessions');
        try {
            $rows = DB::table($table)->select(['id', 'payload'])->get();
        } catch (Throwable) {
            return 0;
        }
        $key = self::loginKey($guard);
        $current = self::currentId();
        $killed = 0;
        foreach ($rows as $row) {
            $id = (string) ($row->id ?? '');
            if ($id === '' || ($current !== null && $id === $current)) {
                continue;
            }
            try {
                $payload = self::decodePayload((string) ($row->payload ?? ''));
                if ($payload !== null
                    && self::payloadBelongsTo($payload, $key, $userId)
                    && DB::table($table)->where('id', $id)->delete() > 0) {
                    $killed++;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $killed;
    }

    /**
     * @return array<mixed>|null
     */
    protected static function decodePayload(string $raw): ?array
    {
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($data)) {
            $decoded = base64_decode($raw, true);
            if (is_string($decoded)) {
                $data = @unserialize($decoded, ['allowed_classes' => false]);
            }
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<mixed>  $payload
     */
    protected static function payloadBelongsTo(array $payload, string $key, int|string $userId): bool
    {
        if (! array_key_exists($key, $payload)) {
            return false;
        }
        $stored = $payload[$key];
        if (is_string($stored)) {
            try {
                $stored = app('encrypter')->decrypt($stored);
            } catch (Throwable) {
                // Plain (unencrypted) session value — compare as-is.
            }
        }

        return (string) $stored === (string) $userId;
    }
}
