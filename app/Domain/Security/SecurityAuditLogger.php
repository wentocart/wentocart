<?php

namespace App\Domain\Security;

use App\Domain\Security\Models\SecurityAuditLog;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SecurityAuditLogger
{
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'totp_secret',
        'token',
        'api_key',
        'card_number',
        'cvv',
        'credit_card',
        'authorization',
    ];

    /**
     * Log a security event.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function log(
        string $action,
        array $payload = [],
        ?Model $actor = null,
        ?Model $entity = null,
        ?Request $request = null,
    ): ?SecurityAuditLog {
        if (! Schema::hasTable('security_audit_logs')) {
            return null;
        }

        try {
            $req = $request ?? request();
            $storeId = null;
            if (app()->bound(StoreContext::class)) {
                $storeId = app(StoreContext::class)->id();
            }

            $actorType = $actor ? class_basename($actor) : 'system';
            $actorId = $actor?->getKey();
            $actorEmail = $actor && isset($actor->email) ? (string) $actor->email : null;

            $entityType = $entity ? class_basename($entity) : null;
            $entityId = $entity?->getKey();

            return SecurityAuditLog::create([
                'store_id' => $storeId,
                'action' => $action,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'ip_address' => $req ? mb_substr((string) $req->ip(), 0, 45) : null,
                'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => self::sanitizePayload($payload),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record security audit log: '.$e->getMessage());
            return null;
        }
    }

    public static function adminLogin(Model $user, Request $request): void
    {
        self::log('admin.auth.login', ['method' => 'password'], $user, null, $request);
    }

    public static function adminLoginFailed(string $email, Request $request, string $reason = 'invalid_credentials'): void
    {
        self::log('admin.auth.failed', ['email' => $email, 'reason' => $reason], null, null, $request);
    }

    public static function adminLogout(Model $user, Request $request): void
    {
        self::log('admin.auth.logout', [], $user, null, $request);
    }

    /**
     * Recursively mask sensitive fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitizePayload($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
