<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Laravel\Sanctum\PersonalAccessToken;

/**
 */
final class GraphqlAdminIntegrations
{
    public static function assertWrite(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters = []): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $q = $admin->tokens()->orderByDesc('id');
        $name = trim((string) ($filters['name'] ?? $filters['q'] ?? ''));
        if ($name !== '') {
            $q->where('name', DbGrammar::likeOperator(), '%'.$name.'%');
        }
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn (PersonalAccessToken $t) => self::map($t, null))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAll(): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();

        return $admin->tokens()->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $t) => self::map($t, null))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $token = $admin->tokens()->whereKey($id)->first();

        return $token ? self::map($token, null) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        if (GraphqlAdminIntegrationModule::oneTokenPerAdmin() && $admin->tokens()->exists()) {
            throw new UserError('This admin already has an Integration token. Revoke or regenerate it first.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Name is required.');
        }
        $ips = IntegrationTokenAllowlist::parse($input['allowedIps'] ?? $input['allowed_ips'] ?? null);
        [$perMinute, $perDay] = IntegrationTokenRateLimit::normalizeCreateArgs(
            array_key_exists('rateLimitPerMinute', $input)
                ? $input['rateLimitPerMinute']
                : ($input['rate_limit_per_minute'] ?? null),
            array_key_exists('rateLimitPerDay', $input)
                ? $input['rateLimitPerDay']
                : ($input['rate_limit_per_day'] ?? null),
        );
        $abilities = IntegrationTokenAllowlist::mergeAbilities(['admin:graphql'], $ips);
        $abilities = IntegrationTokenRateLimit::mergeAbilities($abilities, $perMinute, $perDay);

        $mode = strtolower(trim((string) ($input['permissionMode'] ?? $input['permission_mode'] ?? 'same_as_web')));
        if (! in_array($mode, ['all', 'custom', 'same_as_web'], true)) {
            throw new UserError('permissionMode must be all, custom, or same_as_web.');
        }
        $permSlugs = [];
        if ($mode === 'all') {
            $permSlugs = $admin->permissionSlugs();
        } elseif ($mode === 'custom') {
            $requested = is_array($input['permissions'] ?? null) ? $input['permissions'] : [];
            $permSlugs = IntegrationTokenPermissions::intersectWithAdmin($admin, $requested);
            if ($permSlugs === []) {
                throw new UserError('Custom permissionMode requires at least one permission the admin holds.');
            }
        }
        $abilities = IntegrationTokenPermissions::merge($abilities, $mode, $permSlugs);

        $expiresAt = null;
        $neverExpires = ! empty($input['neverExpires']) || ! empty($input['never_expires']);
        if ($neverExpires) {
            $expiresAt = null;
        } elseif (array_key_exists('expiresAt', $input) || array_key_exists('expires_at', $input)) {
            $raw = $input['expiresAt'] ?? $input['expires_at'] ?? null;
            if ($raw !== null && trim((string) $raw) !== '') {
                try {
                    $expiresAt = \Illuminate\Support\Carbon::parse((string) $raw);
                } catch (\Throwable) {
                    throw new UserError('Invalid expiresAt.');
                }
            }
        } else {
            $expiresAt = now()->addYear();
        }

        $new = $admin->createToken(mb_substr($name, 0, 120), $abilities, $expiresAt);
        IntegrationTokenLifecycleMailer::generated($admin, $new->accessToken);

        return self::map($new->accessToken, $new->plainTextToken);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function updateAllowlist(int $id, array $input): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $token = self::tokenOrFail($admin, $id);
        $ips = IntegrationTokenAllowlist::parse($input['allowedIps'] ?? $input['allowed_ips'] ?? null);
        $abilities = is_array($token->abilities) ? $token->abilities : ['admin:graphql'];
        $token->abilities = IntegrationTokenAllowlist::mergeAbilities($abilities, $ips);
        $token->save();

        return self::map($token, null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function updateRateLimits(int $id, array $input): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $token = self::tokenOrFail($admin, $id);
        $abilities = is_array($token->abilities) ? $token->abilities : ['admin:graphql'];
        $current = IntegrationTokenRateLimit::extract($abilities);
        $hasMinute = array_key_exists('rateLimitPerMinute', $input) || array_key_exists('rate_limit_per_minute', $input);
        $hasDay = array_key_exists('rateLimitPerDay', $input) || array_key_exists('rate_limit_per_day', $input);
        $minuteRaw = $input['rateLimitPerMinute'] ?? $input['rate_limit_per_minute'] ?? null;
        $dayRaw = $input['rateLimitPerDay'] ?? $input['rate_limit_per_day'] ?? null;
        $perMinute = $hasMinute
            ? ((int) $minuteRaw > 0 ? (int) $minuteRaw : null)
            : $current['per_minute'];
        $perDay = $hasDay
            ? ((int) $dayRaw > 0 ? (int) $dayRaw : null)
            : $current['per_day'];
        $token->abilities = IntegrationTokenRateLimit::mergeAbilities($abilities, $perMinute, $perDay);
        $token->save();

        return self::map($token, null);
    }

    /**
     * @return array{ok: bool, id: string}
     */
    public static function revoke(int $id): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $token = self::tokenOrFail($admin, $id);
        $sid = (string) $token->id;
        $name = (string) $token->name;
        $token->delete();
        IntegrationTokenLifecycleMailer::revoked($admin, $name, $sid);

        return ['ok' => true, 'id' => $sid];
    }

    /**
     * @return array<string, mixed>
     */
    public static function regenerate(int $id): array
    {
        GraphqlAdminIntegrationModule::assertModuleEnabled();
        $admin = self::assertWrite();
        $token = self::tokenOrFail($admin, $id);
        $abilities = is_array($token->abilities) ? $token->abilities : ['admin:graphql'];
        $expiresAt = $token->expires_at;
        $name = (string) $token->name;
        $retiredName = mb_substr($name.' (regenerated '.now()->format('Y-m-d').')', 0, 120);
        $token->name = $retiredName;
        $token->save();
        $token->delete();

        $new = $admin->createToken(mb_substr($name, 0, 120), $abilities, $expiresAt);
        IntegrationTokenLifecycleMailer::regenerated($admin, $new->accessToken);

        return self::map($new->accessToken, $new->plainTextToken);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(PersonalAccessToken $token, ?string $plain): array
    {
        $abilities = is_array($token->abilities) ? $token->abilities : ['admin:graphql'];
        $limits = IntegrationTokenRateLimit::extract($abilities);

        return [
            'id' => (string) $token->id,
            'name' => $token->name,
            'abilities' => $abilities,
            'allowed_ips' => IntegrationTokenAllowlist::extract($abilities),
            'rate_limit_per_minute' => $limits['per_minute'],
            'rate_limit_per_day' => $limits['per_day'],
            'rateLimitPerMinute' => $limits['per_minute'],
            'rateLimitPerDay' => $limits['per_day'],
            'expires_at' => optional($token->expires_at)?->toIso8601String(),
            'expiresAt' => optional($token->expires_at)?->toIso8601String(),
            'permission_mode' => IntegrationTokenPermissions::mode($abilities),
            'permissionMode' => IntegrationTokenPermissions::mode($abilities),
            'permissions' => IntegrationTokenPermissions::frozenSlugs($abilities) ?? [],
            'last_used_at' => optional($token->last_used_at)?->toIso8601String(),
            'created_at' => optional($token->created_at)?->toIso8601String(),
            'plain_token' => $plain,
        ];
    }

    protected static function tokenOrFail(Admin $admin, int $id): PersonalAccessToken
    {
        $token = $admin->tokens()->whereKey($id)->first();
        if (! $token) {
            throw new UserError('Integration token not found.');
        }

        return $token;
    }
}
