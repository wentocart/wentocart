<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Database\DbGrammar;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 */
final class GraphqlAdminMarketingSubscribers
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
        $q = NewsletterSubscriber::query();

        $email = trim((string) ($filters['email'] ?? $filters['q'] ?? ''));
        if ($email !== '') {
            $q->where('email', DbGrammar::likeOperator(), '%'.$email.'%');
        }
        if (array_key_exists('is_subscribed', $filters) && $filters['is_subscribed'] !== null && $filters['is_subscribed'] !== '') {
            $q->where('is_subscribed', filter_var($filters['is_subscribed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? ((int) $filters['is_subscribed'] === 1));
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'email', 'created_at', 'is_subscribed'], true)) {
            $sort = 'id';
        }
        $q->orderBy($sort, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (NewsletterSubscriber $row) => self::map($row, false))->values()->all(),
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
        $row = NewsletterSubscriber::query()->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toggle(int $id, mixed $isSubscribed): array
    {
        self::assertManage();
        if ($isSubscribed === null || $isSubscribed === '') {
            throw new UserError('is_subscribed is required.');
        }
        $row = NewsletterSubscriber::query()->find($id);
        if (! $row) {
            throw new UserError('Subscriber not found.');
        }
        $flag = filter_var($isSubscribed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            $flag = ((int) $isSubscribed) === 1;
        }
        $row->is_subscribed = $flag;
        $row->save();

        // Mirror onto linked customer when present (parity).
        $customer = Customer::query()->where('email', $row->email)->first();
        if ($customer && \Illuminate\Support\Facades\Schema::hasColumn('customers', 'newsletter')) {
            $customer->newsletter = $flag;
            $customer->save();
        } elseif ($customer && \Illuminate\Support\Facades\Schema::hasColumn('customers', 'is_subscribed')) {
            $customer->is_subscribed = $flag;
            $customer->save();
        }

        return self::map($row->fresh() ?? $row, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = NewsletterSubscriber::query()->find($id);
        if (! $row) {
            throw new UserError('Subscriber not found.');
        }
        $snapshot = self::map($row, true);
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
            throw new UserError('Provide at least one subscriber id.');
        }
        $deleted = [];
        foreach ($ids as $id) {
            $row = NewsletterSubscriber::query()->find($id);
            if (! $row) {
                continue;
            }
            $deleted[] = $id;
            $row->delete();
        }

        return ['ok' => true, 'deleted' => $deleted, 'updated_count' => count($deleted)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(NewsletterSubscriber $row, bool $detail = false): array
    {
        $subscribed = (bool) ($row->is_subscribed ?? true);
        $customer = Customer::query()->where('email', $row->email)->first();

        $out = [
            'id' => (int) $row->id,

            'email' => (string) $row->email,
            'is_subscribed' => $subscribed,
            'isSubscribed' => $subscribed,
            'customerId' => $customer ? (int) $customer->id : null,
            'customerName' => $customer ? trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) ?: $customer->name : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'channel' => $detail ? null : null,
        ];

        return $out;
    }
}
