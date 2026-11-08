<?php

namespace App\Domain\Api\Graphql;

use App\Models\Admin;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Encoded in Sanctum abilities alongside IP / rate-limit markers.
 */
final class IntegrationTokenPermissions
{
    public const MODE_ALL = 'acl:all';

    public const MODE_CUSTOM = 'acl:custom';

    public const MODE_SAME = 'acl:same';

    public const PERM_PREFIX = 'perm:';

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function strip(array $abilities): array
    {
        $kept = [];
        foreach ($abilities as $ability) {
            $ability = (string) $ability;
            if ($ability === self::MODE_ALL || $ability === self::MODE_CUSTOM || $ability === self::MODE_SAME) {
                continue;
            }
            if (str_starts_with($ability, self::PERM_PREFIX)) {
                continue;
            }
            $kept[] = $ability;
        }

        return array_values($kept);
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<string>  $permissionSlugs
     * @return list<string>
     */
    public static function merge(array $abilities, string $mode, array $permissionSlugs = []): array
    {
        $kept = self::strip($abilities);
        $mode = strtolower(trim($mode));
        if ($mode === 'all') {
            $kept[] = self::MODE_ALL;
            foreach ($permissionSlugs as $slug) {
                $slug = trim((string) $slug);
                if ($slug !== '') {
                    $kept[] = self::PERM_PREFIX.$slug;
                }
            }
        } elseif ($mode === 'custom') {
            $kept[] = self::MODE_CUSTOM;
            foreach ($permissionSlugs as $slug) {
                $slug = trim((string) $slug);
                if ($slug !== '') {
                    $kept[] = self::PERM_PREFIX.$slug;
                }
            }
        } else {
            $kept[] = self::MODE_SAME;
        }

        return array_values(array_unique($kept));
    }

    /**
     * Frozen permission slugs when mode is all/custom; null when same_as_web (follow role).
     *
     * @param  list<string>|null  $abilities
     * @return list<string>|null
     */
    public static function frozenSlugs(?array $abilities): ?array
    {
        $abilities = $abilities ?? [];
        $isAll = in_array(self::MODE_ALL, $abilities, true);
        $isCustom = in_array(self::MODE_CUSTOM, $abilities, true);
        if (! $isAll && ! $isCustom) {
            return null;
        }

        $out = [];
        foreach ($abilities as $ability) {
            $ability = (string) $ability;
            if (str_starts_with($ability, self::PERM_PREFIX)) {
                $out[] = substr($ability, strlen(self::PERM_PREFIX));
            }
        }

        return array_values(array_unique($out));
    }

    public static function mode(?array $abilities): string
    {
        $abilities = $abilities ?? [];
        if (in_array(self::MODE_ALL, $abilities, true)) {
            return 'all';
        }
        if (in_array(self::MODE_CUSTOM, $abilities, true)) {
            return 'custom';
        }

        return 'same_as_web';
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function intersectWithAdmin(Admin $admin, array $requested): array
    {
        $owned = $admin->permissionSlugs();
        if (in_array('*', $owned, true)) {
            return array_values(array_unique(array_filter(array_map('strval', $requested))));
        }

        return array_values(array_intersect($owned, array_map('strval', $requested)));
    }

    public static function allows(PersonalAccessToken $token, string $slug): bool
    {
        $frozen = self::frozenSlugs(is_array($token->abilities) ? $token->abilities : []);
        if ($frozen === null) {
            return true;
        }

        return in_array('*', $frozen, true) || in_array($slug, $frozen, true);
    }
}
