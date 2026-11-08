<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\CustomerNoteSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Settings\ShopMailer;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Customer Notes — append-only timeline.
 */
final class GraphqlAdminCustomerNotes
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function list(int $customerId, int $limit = 50): array
    {
        self::assertAdmin();
        CustomerNoteSchema::ensure();
        if (! Customer::query()->whereKey($customerId)->exists()) {
            throw new UserError('Customer not found.');
        }
        $limit = max(1, min(100, $limit));

        return CustomerNote::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerNote $n) => self::map($n))
            ->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>}
     */
    public static function paginate(int $customerId, int $page = 1, int $perPage = 20): array
    {
        self::assertAdmin();
        CustomerNoteSchema::ensure();
        if (! Customer::query()->whereKey($customerId)->exists()) {
            throw new UserError('Customer not found.');
        }
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $paginator = CustomerNote::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()->map(fn (CustomerNote $n) => self::map($n))->values()->all(),
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
     * @return array<string, mixed>
     */
    public static function create(int $customerId, string $note, bool $customerNotified = false): array
    {
        $admin = self::assertAdmin();
        CustomerNoteSchema::ensure();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }
        $note = trim($note);
        if ($note === '') {
            throw new UserError('Note text is required.');
        }

        $row = CustomerNote::query()->create([
            'customer_id' => $customer->id,
            'admin_id' => $admin->id,
            'note' => $note,
            'customer_notified' => $customerNotified,
        ]);

        if ($customerNotified && filled($customer->email)) {
            try {
                app(ShopMailer::class)->sendHtml(
                    (string) $customer->email,
                    'A note from '.config('app.name', 'the store'),
                    '<p>'.e($note).'</p>',
                    'customer_note',
                );
            } catch (\Throwable) {
                // Note is still saved; email is best-effort (listener parity).
            }
        }

        return self::map($row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(CustomerNote $n): array
    {
        return [
            'id' => (string) $n->id,

            'note' => (string) $n->note,
            'customerId' => (string) $n->customer_id,
            'customer_id' => (string) $n->customer_id,
            'customerNotified' => (bool) $n->customer_notified,
            'customer_notified' => (bool) $n->customer_notified,
            'createdAt' => $n->created_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    protected static function assertAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }
}
