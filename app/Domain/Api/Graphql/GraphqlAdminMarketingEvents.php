<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Marketing\Models\NewsletterCampaign;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminMarketingEvents
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('marketing.newsletter') && ! $admin->hasPermission('marketing.discounts'))
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
        $q = MarketingEvent::query();

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $q->where('name', DbGrammar::likeOperator(), '%'.$name.'%');
        }
        $from = trim((string) ($filters['date_from'] ?? ''));
        if ($from !== '') {
            $q->whereDate('date', '>=', $from);
        }
        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($to !== '') {
            $q->whereDate('date', '<=', $to);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'name', 'date', 'created_at'], true)) {
            $sort = 'id';
        }
        $q->orderBy($sort, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (MarketingEvent $row) => self::map($row))->values()->all(),
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
        $row = MarketingEvent::query()->find($id);

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
        $description = trim((string) ($input['description'] ?? ''));
        $date = trim((string) ($input['date'] ?? ''));
        if ($name === '' || $date === '') {
            throw new UserError('name and date are required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new UserError('date must be YYYY-MM-DD.');
        }
        $row = MarketingEvent::query()->create([
            'name' => $name,
            'description' => $description,
            'date' => $date,
        ]);

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = MarketingEvent::query()->find($id);
        if (! $row) {
            throw new UserError('Marketing event not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('description', $input) && $input['description'] !== null) {
            $row->description = trim((string) $input['description']);
        }
        if (array_key_exists('date', $input) && $input['date'] !== null) {
            $date = trim((string) $input['date']);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new UserError('date must be YYYY-MM-DD.');
            }
            $row->date = $date;
        }
        $row->save();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = MarketingEvent::query()->find($id);
        if (! $row) {
            throw new UserError('Marketing event not found.');
        }
        NewsletterCampaign::query()->where('marketing_event_id', $row->id)->update(['marketing_event_id' => null]);
        $snapshot = self::map($row);
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one event id.');
        }
        $deleted = [];
        foreach ($ids as $id) {
            $row = MarketingEvent::query()->find($id);
            if (! $row) {
                continue;
            }
            NewsletterCampaign::query()->where('marketing_event_id', $row->id)->update(['marketing_event_id' => null]);
            $row->delete();
            $deleted[] = $id;
        }

        return ['ok' => true, 'deleted' => $deleted, 'updated_count' => count($deleted)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(MarketingEvent $row): array
    {
        return [
            'id' => (int) $row->id,

            'name' => (string) $row->name,
            'description' => $row->description,
            'date' => optional($row->date)?->format('Y-m-d') ?? (string) $row->date,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
