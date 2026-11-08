<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminSettingsTaxCategories
{
    public static function assertManage(): Admin
    {
        return GraphqlAdminSettingsTaxRates::assertManage();
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
        $q = TaxClass::query()->with('rules');
        $term = trim((string) ($filters['name'] ?? $filters['q'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where('name', $op, '%'.$term.'%');
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $column = in_array($sort, ['id', 'name', 'created_at'], true) ? $sort : 'id';
        $q->orderBy($column, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (TaxClass $row) => self::map($row))->values()->all(),
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
        $row = TaxClass::query()->with('rules')->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new UserError('name is required.');
        }
        $rateIds = self::rateIds($input);
        if ($rateIds === []) {
            throw new UserError('At least one tax rate id is required in taxrates.');
        }
        $existing = TaxRate::query()->whereIn('id', $rateIds)->pluck('id')->all();
        if (count($existing) !== count($rateIds)) {
            throw new UserError('One or more tax rate ids were not found.');
        }
        $rates = TaxRate::query()->whereIn('id', $rateIds)->get()->keyBy('id');
        $row = TaxClass::query()->create(['name' => $name]);
        foreach ($rateIds as $rateId) {
            $taxRate = $rates->get($rateId);
            TaxRule::query()->create([
                'tax_class_id' => $row->id,
                'tax_rate_id' => $rateId,
                'name' => $name.' · '.($taxRate?->name ?? ('rate '.$rateId)),
                'rate' => (float) ($taxRate?->rate ?? 0),
                'priority' => 0,
                'is_enabled' => true,
            ]);
        }

        return self::map($row->fresh(['rules']) ?? $row->load('rules'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = TaxClass::query()->with('rules')->find($id);
        if (! $row) {
            throw new UserError('Tax category not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->update(['name' => $name]);
        }
        if (
            array_key_exists('taxrates', $input)
            || array_key_exists('tax_rates', $input)
            || array_key_exists('rates', $input)
        ) {
            $rateIds = self::rateIds($input);
            if ($rateIds === []) {
                throw new UserError('taxrates cannot be empty when provided.');
            }
            $existing = TaxRate::query()->whereIn('id', $rateIds)->pluck('id')->all();
            if (count($existing) !== count($rateIds)) {
                throw new UserError('One or more tax rate ids were not found.');
            }
            $rates = TaxRate::query()->whereIn('id', $rateIds)->get()->keyBy('id');
            $row->rules()->delete();
            foreach ($rateIds as $rateId) {
                $taxRate = $rates->get($rateId);
                TaxRule::query()->create([
                    'tax_class_id' => $row->id,
                    'tax_rate_id' => $rateId,
                    'name' => $row->name.' · '.($taxRate?->name ?? ('rate '.$rateId)),
                    'rate' => (float) ($taxRate?->rate ?? 0),
                    'priority' => 0,
                    'is_enabled' => true,
                ]);
            }
        }

        return self::map($row->fresh(['rules']) ?? $row->load('rules'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = TaxClass::query()->with('rules')->find($id);
        if (! $row) {
            throw new UserError('Tax category not found.');
        }
        $snapshot = self::map($row);
        $row->rules()->delete();
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
            throw new UserError('Provide at least one tax category id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = TaxClass::query()->find($id);
            if (! $row) {
                $skipped[] = $id;
                continue;
            }
            $row->rules()->delete();
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
    public static function map(TaxClass $row): array
    {
        $rateIds = $row->relationLoaded('rules')
            ? $row->rules->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
            : $row->rules()->pluck('tax_rate_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return [
            'id' => (int) $row->id,

            'name' => (string) $row->name,
            'code' => Str::slug((string) $row->name) ?: ('tax-'.$row->id),
            'description' => null,
            'taxrates' => $rateIds,
            'tax_rates' => $rateIds,
            'rate_ids' => $rateIds,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    protected static function rateIds(array $input): array
    {
        $raw = $input['taxrates'] ?? $input['tax_rates'] ?? $input['rates'] ?? $input['rate_ids'] ?? [];

        return collect(is_array($raw) ? $raw : [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }
}
