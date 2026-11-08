<?php

namespace App\Domain\Api\Graphql;

use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 * Backed by Appearance sections / Classic homepage modules.
 */
final class GraphqlAdminSettingsThemes
{
    public static function assertManage(): Admin
    {
        return GraphqlAdminAppearanceSections::assertAppearance();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $all = self::filter(GraphqlAdminAppearanceSections::list($filters), $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? $filters['first'] ?? 10)));
        $total = count($all);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($all, $offset, $perPage);
        $data = array_map(fn (array $row) => self::listMap($row), $slice);

        return [
            'data' => array_values($data),
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'lastPage' => $lastPage,
                'total' => $total,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : $offset + count($slice),
            ],
        ];
    }

    /**
     * GraphQL list (no pagination wrapper).
     *
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        self::assertManage();
        $limit = max(1, min(100, (int) ($args['limit'] ?? $args['first'] ?? 50)));
        $all = self::filter(GraphqlAdminAppearanceSections::list($args), $args);

        return array_map(fn (array $row) => self::listMap($row), array_slice($all, 0, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id, array $args = []): ?array
    {
        self::assertManage();
        try {
            return self::detailMap(GraphqlAdminAppearanceSections::one(array_merge($args, ['id' => $id])));
        } catch (UserError $e) {
            if (str_contains(strtolower($e->getMessage()), 'not found')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        return self::detailMap(GraphqlAdminAppearanceSections::create($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        return self::detailMap(GraphqlAdminAppearanceSections::update(array_merge($input, ['id' => $id])));
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id, array $args = []): array
    {
        $row = self::find($id, $args);
        if ($row === null) {
            throw new UserError('Home module not found.');
        }
        $result = GraphqlAdminAppearanceSections::delete(array_merge($args, ['id' => $id]));
        $row['message'] = 'Home module deleted successfully.';
        $row['ok'] = (bool) ($result['ok'] ?? true);

        return $row;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int, message: string}
     */
    public static function bulkDelete(array $ids, array $args = []): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one theme id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            try {
                $row = GraphqlAdminAppearanceSections::one(array_merge($args, ['id' => $id]));
                if (($row['moduleCode'] ?? '') === 'footer' || ($row['type'] ?? '') === 'footer_links') {
                    $skipped[] = $id;
                    continue;
                }
                GraphqlAdminAppearanceSections::delete(array_merge($args, ['id' => $id]));
                $deleted[] = $id;
            } catch (UserError) {
                $skipped[] = $id;
            }
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
            'message' => count($deleted).' theme(s) deleted.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected static function filter(array $rows, array $filters): array
    {
        $name = strtolower(trim((string) ($filters['name'] ?? '')));
        $type = trim((string) ($filters['type'] ?? ''));
        $themeCode = trim((string) ($filters['themeCode'] ?? $filters['theme_code'] ?? ''));
        $storeId = trim((string) ($filters['storeId'] ?? $filters['store_id'] ?? $filters['storeId'] ?? ''));
        $status = array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== ''
            ? (int) $filters['status']
            : null;

        $filtered = array_values(array_filter($rows, function (array $row) use ($name, $type, $themeCode, $storeId, $status) {
            if ($name !== '' && ! str_contains(strtolower((string) ($row['name'] ?? '')), $name)) {
                return false;
            }
            if ($type !== '' && (string) ($row['type'] ?? '') !== $type) {
                return false;
            }
            if ($themeCode !== '' && (string) ($row['themeCode'] ?? '') !== $themeCode) {
                return false;
            }
            if ($storeId !== '' && (string) ($row['storeId'] ?? '') !== $storeId) {
                return false;
            }
            if ($status !== null && (int) ($row['status'] ?? 0) !== $status) {
                return false;
            }

            return true;
        }));

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        usort($filtered, function (array $a, array $b) use ($sort, $order) {
            $av = match ($sort) {
                'name' => (string) ($a['name'] ?? ''),
                'type' => (string) ($a['type'] ?? ''),
                'sort_order', 'sortOrder' => (int) ($a['sortOrder'] ?? 0),
                'theme_code', 'themeCode' => (string) ($a['themeCode'] ?? ''),
                'store_id', 'storeId' => (string) ($a['storeId'] ?? ''),
                'status' => (int) ($a['status'] ?? 0),
                default => (int) ($a['_id'] ?? 0),
            };
            $bv = match ($sort) {
                'name' => (string) ($b['name'] ?? ''),
                'type' => (string) ($b['type'] ?? ''),
                'sort_order', 'sortOrder' => (int) ($b['sortOrder'] ?? 0),
                'theme_code', 'themeCode' => (string) ($b['themeCode'] ?? ''),
                'store_id', 'storeId' => (string) ($b['storeId'] ?? ''),
                'status' => (int) ($b['status'] ?? 0),
                default => (int) ($b['_id'] ?? 0),
            };
            $cmp = $av <=> $bv;

            return $order === 'desc' ? -$cmp : $cmp;
        });

        return $filtered;
    }

    /**
     * Listing omits options (parity).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function listMap(array $row): array
    {
        return [
            'id' => (int) ($row['_id'] ?? $row['id'] ?? 0),

            'themeCode' => (string) ($row['themeCode'] ?? ''),
            'theme_code' => (string) ($row['themeCode'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'sortOrder' => (int) ($row['sortOrder'] ?? 0),
            'sort_order' => (int) ($row['sortOrder'] ?? 0),
            'status' => (int) ($row['status'] ?? 0),
            'storeId' => (string) ($row['storeId'] ?? ''),
            'store_id' => (string) ($row['storeId'] ?? ''),
            'moduleCode' => (string) ($row['moduleCode'] ?? ''),
            'hasDraft' => (bool) ($row['hasDraft'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function detailMap(array $row): array
    {
        $base = self::listMap($row);
        $base['options'] = $row['options'] ?? null;
        $base['translation'] = $row['translation'] ?? null;
        $base['translations'] = $row['translations'] ?? [];
        $base['message'] = $row['message'] ?? null;

        return $base;
    }
}
