<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\AdminApiAudit;
use App\Domain\Security\SecurityAuditLogger;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 */
final class AdminApiAuditLogger
{
    public static function maybeLog(Request $request, Response $response): void
    {
        if (! Schema::hasTable('admin_api_audits')) {
            return;
        }
        if (! \App\Domain\Settings\ShopSetting::bool('api.integration.audit_enabled', true)) {
            return;
        }
        if (! self::shouldAudit($request)) {
            return;
        }

        try {
            $admin = Auth::guard('admin')->user();
            $tokenId = null;
            if ($admin instanceof Admin && method_exists($admin, 'currentAccessToken')) {
                $token = $admin->currentAccessToken();
                $tokenId = $token?->id ? (int) $token->id : null;
            }

            $path = '/'.ltrim($request->path(), '/');
            $operation = self::operationName($request);
            $payload = self::requestPayload($request);
            $summary = [
                'status' => $response->getStatusCode(),
                'ok' => $response->getStatusCode() < 400,
            ];
            $contentType = (string) $response->headers->get('content-type', '');
            if (str_contains($contentType, 'json')) {
                $body = json_decode($response->getContent() ?: '', true);
                if (is_array($body)) {
                    foreach (['id', 'ok', 'message', 'error', 'entity'] as $key) {
                        if (array_key_exists($key, $body)) {
                            $summary[$key] = $body[$key];
                        }
                    }
                    if (isset($body['data']) && is_array($body['data'])) {
                        $summary['data_keys'] = array_slice(array_keys($body['data']), 0, 12);
                    }
                }
            }

            AdminApiAudit::query()->create([
                'admin_id' => $admin instanceof Admin ? (int) $admin->id : null,
                'token_id' => $tokenId,
                'method' => strtoupper($request->method()),
                'path' => mb_substr($path, 0, 255),
                'operation' => $operation !== null ? mb_substr($operation, 0, 120) : null,
                'status' => $response->getStatusCode(),
                'ip_address' => mb_substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
                'request_payload' => $payload,
                'response_summary' => $summary,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record admin API audit: '.$e->getMessage());
        }
    }

    protected static function shouldAudit(Request $request): bool
    {
        $method = strtoupper($request->method());
        $path = '/'.ltrim($request->path(), '/');
        if (! str_starts_with($path, '/api/v1/console')) {
            return false;
        }
        // Avoid recursive noise / read traffic.
        if (str_contains($path, '/console/settings/integrations/history')
            || str_contains($path, '/console/integrations/history')
            || str_contains($path, '/console/api-audits')) {
            return false;
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (str_ends_with($path, '/console/graphql') || str_ends_with($path, '/graphql')) {
                $query = (string) $request->input('query', '');

                return (bool) preg_match('/\bmutation\b/i', $query);
            }

            return true;
        }

        return false;
    }

    protected static function operationName(Request $request): ?string
    {
        $path = '/'.ltrim($request->path(), '/');
        if (str_ends_with($path, '/console/graphql') || str_ends_with($path, '/graphql')) {
            $query = (string) $request->input('query', '');
            if (preg_match('/\bmutation\s+([A-Za-z_][A-Za-z0-9_]*)/i', $query, $m)) {
                return $m[1];
            }
            if (preg_match('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[\(\{]/', $query, $m)) {
                return $m[1];
            }

            return 'graphql_mutation';
        }

        return strtoupper($request->method()).' '.$path;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function requestPayload(Request $request): array
    {
        $path = '/'.ltrim($request->path(), '/');
        if (str_ends_with($path, '/console/graphql') || str_ends_with($path, '/graphql')) {
            $query = (string) $request->input('query', '');
            $vars = $request->input('variables');
            $payload = [
                'query' => mb_substr(preg_replace('/\s+/', ' ', $query) ?? $query, 0, 500),
                'variables' => is_array($vars) ? $vars : null,
            ];

            return SecurityAuditLogger::sanitizePayload(self::extendSensitive($payload));
        }

        $all = $request->except(['password', 'password_confirmation']);

        return SecurityAuditLogger::sanitizePayload(self::extendSensitive($all));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function extendSensitive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lk = is_string($key) ? strtolower($key) : (string) $key;
            if (preg_match('/(password|secret|token|plain_key|plain_token|authorization|api_key|credit_card|cvv)/i', $lk)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::extendSensitive($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
