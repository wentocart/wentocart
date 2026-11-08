<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Search\Models\SearchSynonym;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Cache;

/**
 */
final class GraphqlAdminMarketingSearchSynonyms
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
        $q = SearchSynonym::query();

        $needle = trim((string) ($filters['name'] ?? $filters['terms'] ?? $filters['q'] ?? ''));
        if ($needle !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($needle, $op) {
                $inner->where('term', $op, '%'.$needle.'%')
                    ->orWhere('aliases', $op, '%'.$needle.'%');
            });
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sortMap = ['id' => 'id', 'name' => 'term', 'term' => 'term', 'terms' => 'aliases', 'created_at' => 'created_at'];
        $column = $sortMap[$sort] ?? 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (SearchSynonym $row) => self::map($row))->values()->all(),
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
        $row = SearchSynonym::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $term = trim((string) ($input['name'] ?? $input['term'] ?? ''));
        $aliases = trim((string) ($input['terms'] ?? $input['aliases'] ?? ''));
        if ($term === '' || $aliases === '') {
            throw new UserError('name and terms are required.');
        }
        $row = SearchSynonym::query()->updateOrCreate(
            ['term' => $term],
            [
                'aliases' => $aliases,
                'is_enabled' => (bool) ($input['enabled'] ?? $input['status'] ?? true),
            ]
        );
        self::forgetCache();

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = SearchSynonym::query()->find($id);
        if (! $row) {
            throw new UserError('Search synonym not found.');
        }
        if (array_key_exists('name', $input) || array_key_exists('term', $input)) {
            $term = trim((string) ($input['name'] ?? $input['term'] ?? ''));
            if ($term === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->term = $term;
        }
        if (array_key_exists('terms', $input) || array_key_exists('aliases', $input)) {
            $aliases = trim((string) ($input['terms'] ?? $input['aliases'] ?? ''));
            if ($aliases === '') {
                throw new UserError('terms cannot be empty.');
            }
            $row->aliases = $aliases;
        }
        if (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $row->is_enabled = (bool) $input['enabled'];
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $row->is_enabled = (int) $input['status'] === 1;
        }
        $row->save();
        self::forgetCache();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = SearchSynonym::query()->find($id);
        if (! $row) {
            throw new UserError('Search synonym not found.');
        }
        $snapshot = self::map($row);
        $row->delete();
        self::forgetCache();

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
            throw new UserError('Provide at least one synonym id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = SearchSynonym::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }
        if ($deleted !== []) {
            self::forgetCache();
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
    public static function map(SearchSynonym $row): array
    {
        return [
            'id' => (int) $row->id,

            'name' => (string) $row->term,
            'term' => (string) $row->term,
            'terms' => (string) $row->aliases,
            'aliases' => (string) $row->aliases,
            'is_enabled' => (bool) $row->is_enabled,
            'status' => $row->is_enabled ? 1 : 0,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }

    protected static function forgetCache(): void
    {
        try {
            Cache::forget('search.synonyms');
        } catch (\Throwable) {
            // best-effort
        }
    }
}
