<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminIntegrationModule
{
    public static function assertWrite(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    public static function enabled(): bool
    {
        return ShopSetting::bool('api.integration.enabled', true);
    }

    public static function oneTokenPerAdmin(): bool
    {
        return ShopSetting::bool('api.integration.one_token_per_admin', false);
    }

    public static function auditEnabled(): bool
    {
        return ShopSetting::bool('api.integration.audit_enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        self::assertWrite();

        return self::payload();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(array $input): array
    {
        self::assertWrite();
        if (array_key_exists('enabled', $input)) {
            ShopSetting::put('api.integration.enabled', (bool) $input['enabled']);
        }
        if (array_key_exists('oneTokenPerAdmin', $input) || array_key_exists('one_token_per_admin', $input)) {
            $v = $input['oneTokenPerAdmin'] ?? $input['one_token_per_admin'];
            ShopSetting::put('api.integration.one_token_per_admin', (bool) $v);
        }
        if (array_key_exists('auditEnabled', $input) || array_key_exists('audit_enabled', $input)) {
            $v = $input['auditEnabled'] ?? $input['audit_enabled'];
            ShopSetting::put('api.integration.audit_enabled', (bool) $v);
        }

        return self::payload();
    }

    public static function assertModuleEnabled(): void
    {
        if (! self::enabled()) {
            throw new UserError('Integration module is disabled. Enable it under Configuration → API → Integration.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'oneTokenPerAdmin' => self::oneTokenPerAdmin(),
            'one_token_per_admin' => self::oneTokenPerAdmin(),
            'auditEnabled' => self::auditEnabled(),
            'audit_enabled' => self::auditEnabled(),
            'retentionDays' => (int) config('wento.admin_api_audit_retention_days', 90),
            'retention_days' => (int) config('wento.admin_api_audit_retention_days', 90),
        ];
    }
}
