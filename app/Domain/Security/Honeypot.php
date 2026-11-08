<?php

namespace App\Domain\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class Honeypot
{
    public const TRAP_FIELD = '_hp_sec_trap';
    public const TIME_FIELD = '_hp_sec_time';
    public const MINIMUM_SECONDS = 0.6;
    public const MAXIMUM_SECONDS = 86400; // 24 hours

    /**
     * Generate encrypted time token for form rendering.
     */
    public static function generateToken(): string
    {
        return Crypt::encryptString((string) microtime(true));
    }

    /**
     * Inspect the request. Returns true if clean, throws ValidationException if bot detected.
     *
     * @throws ValidationException
     */
    public static function assert(Request $request): void
    {
        if (self::isSpam($request)) {
            throw ValidationException::withMessages([
                'submission' => ['Unable to process request at this time.'],
            ]);
        }
    }

    /**
     * Check if request matches bot/spam signatures.
     */
    public static function isSpam(Request $request): bool
    {
        // 1. Trap field check: bots fill hidden fields
        if ($request->has(self::TRAP_FIELD)) {
            $val = $request->input(self::TRAP_FIELD);
            if (! empty($val)) {
                return true;
            }
        }

        // 2. Time field check: bots submit instantly without human reading delay
        if ($request->has(self::TIME_FIELD)) {
            $token = (string) $request->input(self::TIME_FIELD);
            if (! empty($token)) {
                try {
                    $decrypted = (float) Crypt::decryptString($token);
                    $elapsed = microtime(true) - $decrypted;
                    if ($elapsed < self::MINIMUM_SECONDS || $elapsed > self::MAXIMUM_SECONDS) {
                        return true;
                    }
                } catch (\Throwable) {
                    // Tampered or invalid token
                    return true;
                }
            }
        }

        return false;
    }
}
