<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Settings\ConfigurationCatalog;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 * Admin REST Configuration (REST + shared helpers).
 */
final class GraphqlAdminConfiguration
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('settings.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        self::assertManage();

        return ConfigurationCatalog::slugs();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function menu(array $args): array
    {
        self::assertManage();
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : null;
        if ($slug === '') {
            $slug = null;
        }
        if ($slug !== null && ! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('Unknown configuration slug.');
        }
        $include = filter_var($args['include_values'] ?? $args['includeValues'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $fields = ConfigurationCatalog::fieldsFor($slug);
        $values = $include && $slug !== null ? ConfigurationCatalog::valuesFor($slug) : null;

        return [
            'slug' => $slug,
            'fields' => $fields,
            'values' => $values,
            'valuesJson' => $values !== null ? json_encode($values, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * @return array{slug: string, values: array<string, mixed>}
     */
    public static function values(string $slug): array
    {
        self::assertManage();
        $slug = trim($slug);
        if ($slug === '') {
            throw new UserError('slug is required.');
        }
        if (! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('slug is required and must be a registered configuration slug.');
        }

        return [
            'slug' => $slug,
            'values' => ConfigurationCatalog::valuesFor($slug),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, slug: string, values: array<string, mixed>, message: string}
     */
    public static function update(array $input): array
    {
        self::assertManage();
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            throw new UserError('slug is required.');
        }
        if (! in_array($slug, ConfigurationCatalog::slugs(), true)) {
            throw new UserError('slug is required and must be a registered configuration slug.');
        }
        $values = $input['values'] ?? null;
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($values)) {
            throw new UserError('values must be an object of code → value.');
        }
        try {
            $resolved = ConfigurationCatalog::update($slug, $values);
        } catch (\InvalidArgumentException $e) {
            throw new UserError($e->getMessage());
        }

        return [
            'ok' => true,
            'slug' => $slug,
            'values' => $resolved,
            'message' => 'Configuration updated for '.$slug.'.',
        ];
    }
}
