<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Search\Models\SearchRedirect;
use App\Domain\Security\SafeRedirect;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 * Storefront-recorded terms; no create endpoint (parity).
 */
final class GraphqlAdminMarketingSearchTerms
{
    public static function assertManage(): Admin
    {
        return GraphqlAdminMarketingUrlRewrites::assertManage();
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

        $term = trim((string) ($filters['term'] ?? $filters['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('query', $op, '%'.$term.'%')
                    ->orWhere('target_path', $op, '%'.$term.'%');
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sortMap = [
            'id' => 'id',
            'term' => 'query',
            'query' => 'query',
            'uses' => 'id',
            'results' => 'id',
            'created_at' => 'created_at',
        ];
        $column = $sortMap[$sort] ?? 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (SearchRedirect $row) => self::map($row, false))->values()->all(),
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

        return $row ? self::map($row, true) : null;
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
            throw new UserError('Search term not found.');
        }
        if (array_key_exists('term', $input) || array_key_exists('query', $input)) {
            $term = mb_strtolower(trim((string) ($input['term'] ?? $input['query'] ?? '')));
            if ($term === '') {
                throw new UserError('term cannot be empty.');
            }
            $row->query = $term;
        }
        if (
            array_key_exists('redirect_url', $input)
            || array_key_exists('redirectUrl', $input)
            || array_key_exists('target_path', $input)
            || array_key_exists('targetPath', $input)
        ) {
            $raw = trim((string) (
                $input['redirect_url'] ?? $input['redirectUrl'] ?? $input['target_path'] ?? $input['targetPath'] ?? ''
            ));
            if ($raw === '') {
                $row->target_path = '/';
            } else {
                $target = SafeRedirect::sanitize($raw);
                if ($target === null || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                    throw new UserError('redirect_url must be a safe absolute path starting with /.');
                }
                $row->target_path = $target;
            }
        }
        if (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $row->is_enabled = (bool) $input['enabled'];
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $row->is_enabled = (int) $input['status'] === 1;
        }
        $row->save();

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = SearchRedirect::query()->find($id);
        if (! $row) {
            throw new UserError('Search term not found.');
        }
        $snapshot = self::map($row, true);
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
            throw new UserError('Provide at least one search term id.');
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
    public static function map(SearchRedirect $row, bool $withChannel = false): array
    {
        $payload = [
            'id' => (int) $row->id,

            'term' => (string) $row->query,
            'query' => (string) $row->query,
            'redirect_url' => (string) $row->target_path,
            'redirectUrl' => (string) $row->target_path,
            'target_path' => (string) $row->target_path,
            'results' => 0,
            'uses' => 0,
            'store_id' => null,
            'locale' => null,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
        if ($withChannel) {
            $payload['channel'] = null;
        }

        return $payload;
    }
}
