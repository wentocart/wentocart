<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Security\SafeRedirect;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminMarketingUrlRewrites
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (
                ! $admin->hasPermission('catalog.products')
                && ! $admin->hasPermission('marketing.discounts')
                && ! $admin->hasPermission('marketing.newsletter')
                && ! $admin->hasPermission('settings.manage')
            )
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = SearchRedirect::query();

        $path = trim((string) ($filters['request_path'] ?? $filters['q'] ?? ''));
        if ($path !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($path, $op) {
                $inner->where('query', $op, '%'.$path.'%')
                    ->orWhere('target_path', $op, '%'.$path.'%');
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'query', 'target_path', 'created_at'], true)) {
            $sort = 'id';
        }
        $q->orderBy($sort, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (SearchRedirect $row) => self::map($row))->values()->all(),
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
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = SearchRedirect::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $query = mb_strtolower(trim((string) (
            $input['request_path'] ?? $input['requestPath'] ?? $input['query'] ?? $input['term'] ?? ''
        )));
        $targetRaw = trim((string) ($input['target_path'] ?? $input['targetPath'] ?? $input['redirect_url'] ?? ''));
        $target = SafeRedirect::sanitize($targetRaw);
        if ($query === '') {
            throw new UserError('request_path is required.');
        }
        if ($target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            throw new UserError('target_path must be a safe absolute path starting with /.');
        }

        $redirectType = trim((string) ($input['redirect_type'] ?? $input['redirectType'] ?? '301'));
        if (! in_array($redirectType, ['301', '302'], true)) {
            throw new UserError('redirect_type must be 301 or 302.');
        }

        $row = SearchRedirect::query()->updateOrCreate(
            ['query' => $query],
            [
                'target_path' => $target,
                'is_enabled' => (bool) ($input['enabled'] ?? true),
            ]
        );

        $mapped = self::map($row);
        $mapped['entity_type'] = (string) ($input['entity_type'] ?? $input['entityType'] ?? 'cms_page');
        $mapped['redirect_type'] = $redirectType;
        $mapped['redirectType'] = $redirectType;
        $mapped['locale'] = (string) ($input['locale'] ?? 'en');

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = SearchRedirect::query()->find($id);
        if (! $row) {
            throw new UserError('URL rewrite not found.');
        }
        if (array_key_exists('request_path', $input) || array_key_exists('requestPath', $input) || array_key_exists('query', $input) || array_key_exists('term', $input)) {
            $query = mb_strtolower(trim((string) (
                $input['request_path'] ?? $input['requestPath'] ?? $input['query'] ?? $input['term'] ?? ''
            )));
            if ($query === '') {
                throw new UserError('request_path cannot be empty.');
            }
            $row->query = $query;
        }
        if (array_key_exists('target_path', $input) || array_key_exists('targetPath', $input) || array_key_exists('redirect_url', $input)) {
            $target = SafeRedirect::sanitize(trim((string) ($input['target_path'] ?? $input['targetPath'] ?? $input['redirect_url'] ?? '')));
            if ($target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                throw new UserError('target_path must be a safe absolute path starting with /.');
            }
            $row->target_path = $target;
        }
        if (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $row->is_enabled = (bool) $input['enabled'];
        }
        $row->save();

        $mapped = self::map($row->fresh() ?? $row);
        if (isset($input['redirect_type']) || isset($input['redirectType'])) {
            $rt = trim((string) ($input['redirect_type'] ?? $input['redirectType']));
            if (in_array($rt, ['301', '302'], true)) {
                $mapped['redirect_type'] = $rt;
                $mapped['redirectType'] = $rt;
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = SearchRedirect::query()->find($id);
        if (! $row) {
            throw new UserError('URL rewrite not found.');
        }
        $snapshot = self::map($row);
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one rewrite id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = SearchRedirect::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(SearchRedirect $row): array
    {
        return [
            'id' => (int) $row->id,

            'query' => (string) $row->query,
            'request_path' => (string) $row->query,
            'requestPath' => (string) $row->query,
            'target_path' => (string) $row->target_path,
            'targetPath' => (string) $row->target_path,
            'redirect_url' => (string) $row->target_path,
            'redirect_type' => '301',
            'redirectType' => '301',
            'entity_type' => 'cms_page',
            'locale' => null,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
