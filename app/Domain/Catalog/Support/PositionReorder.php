<?php

namespace App\Domain\Catalog\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PositionReorder
{
    /**
     * Next append position for a model (max existing + 1, or 0 when empty).
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function next(string $modelClass, ?string $scopeColumn = null, mixed $scopeId = null): int
    {
        $query = $modelClass::query();
        if ($scopeColumn !== null) {
            if ($scopeId === null) {
                $query->whereNull($scopeColumn);
            } else {
                $query->where($scopeColumn, $scopeId);
            }
        }

        $max = $query->max('position');

        return $max === null ? 0 : ((int) $max + 1);
    }

    /**
     * Resolve position for create/update:
     * - explicit value → use it
     * - update with no value → keep existing
     * - create with no value → append (next)
     *
     * @param  class-string<Model>|null  $modelClass  unused when $model is provided; kept for clarity at call sites
     */
    public static function resolve(
        mixed $provided,
        Model $model,
        ?string $scopeColumn = null,
        mixed $scopeId = null,
    ): int {
        if ($provided !== null && $provided !== '') {
            return (int) $provided;
        }

        if ($model->exists) {
            return (int) $model->getAttribute('position');
        }

        return self::next($model::class, $scopeColumn, $scopeId);
    }

    /**
     * Reorder a subset of rows while preserving the relative positions of
     * siblings that were not included in $orderedIds (e.g. other pages).
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int|string>  $orderedIds
     */
    public static function among(string $modelClass, array $orderedIds, ?string $scopeColumn = null, mixed ...$scope): void
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));
        if (count($ids) < 2) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $rows */
        $rows = $modelClass::query()->whereIn('id', $ids)->get();
        if ($rows->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => 'One or more items were not found.',
            ]);
        }

        $scopeValue = null;
        if ($scopeColumn !== null) {
            $scopeValue = array_key_exists(0, $scope)
                ? $scope[0]
                : $rows->first()->getAttribute($scopeColumn);

            if ($rows->contains(fn (Model $row) => $row->getAttribute($scopeColumn) != $scopeValue)) {
                throw ValidationException::withMessages([
                    'ids' => 'Items must belong to the same group.',
                ]);
            }
        }

        $siblingsQuery = $modelClass::query()->orderBy('position')->orderBy('id');
        if ($scopeColumn !== null) {
            if ($scopeValue === null) {
                $siblingsQuery->whereNull($scopeColumn);
            } else {
                $siblingsQuery->where($scopeColumn, $scopeValue);
            }
        }

        $siblings = $siblingsQuery->get();
        $orderedSet = collect($ids);
        $queue = $ids;
        $queueIndex = 0;
        $reordered = [];

        foreach ($siblings as $sibling) {
            if ($orderedSet->contains((int) $sibling->getKey())) {
                $reordered[] = (int) $queue[$queueIndex++];
            } else {
                $reordered[] = (int) $sibling->getKey();
            }
        }

        DB::transaction(function () use ($modelClass, $reordered) {
            foreach ($reordered as $position => $id) {
                $modelClass::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
