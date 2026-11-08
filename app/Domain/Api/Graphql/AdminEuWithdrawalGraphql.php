<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Order\EuWithdrawalSchema;
use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Services\EuWithdrawalService;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Validation\ValidationException;

/**
 */
class AdminEuWithdrawalGraphql
{
    protected static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * Map shop statuses onto received|declined|refunded.
     */
    protected static function mapWithdrawalStatus(string $status): string
    {
        return match ($status) {
            EuWithdrawalRequest::STATUS_REJECTED => 'declined',
            EuWithdrawalRequest::STATUS_COMPLETED => 'refunded',
            default => 'received',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(EuWithdrawalRequest $row): array
    {
        $row->loadMissing(['order', 'customer']);
        $order = $row->order;
        $customer = $row->customer;
        $compat = self::mapWithdrawalStatus((string) $row->status);
        $name = trim((string) (($customer?->first_name ?? '').' '.($customer?->last_name ?? '')))
            ?: (string) ($customer?->name ?? $order?->email ?? '');

        return [
            'id' => (string) $row->id,

            'reference' => (string) ($row->reference ?? ''),
            'status' => $compat,
            'internalStatus' => (string) $row->status,
            'orderId' => (string) $row->order_id,
            'orderIncrementId' => (string) ($order?->number ?? ''),
            'customerEmail' => (string) ($customer?->email ?? $order?->email ?? ''),
            'customerName' => $name,
            'reason' => $row->reason,
            'receivedAt' => optional($row->requested_at ?? $row->created_at)?->toIso8601String(),
            'confirmationSentAt' => optional($row->requested_at ?? $row->created_at)?->toIso8601String(),
            'finalConfirmationSentAt' => optional($row->decided_at)?->toIso8601String(),
            'confirmationError' => null,
            'declinedAt' => $compat === 'declined' ? optional($row->decided_at)?->toIso8601String() : null,
            'declinedReason' => $compat === 'declined' ? (string) ($row->admin_note ?? '') : null,
            'declinedByName' => $compat === 'declined' ? 'Admin' : null,
            'refundedAt' => $compat === 'refunded' ? optional($row->decided_at)?->toIso8601String() : null,
            'refundNote' => $compat === 'refunded' ? (string) ($row->admin_note ?? '') : null,
            'refundedByName' => $compat === 'refunded' ? 'Admin' : null,
            'store_code' => null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        self::assertManage();
        EuWithdrawalSchema::ensure();

        $limit = (int) ($args['first'] ?? $args['limit'] ?? 50);
        $limit = max(1, min(100, $limit));
        $q = EuWithdrawalRequest::query()->with(['order', 'customer'])->orderByDesc('id');

        $status = trim((string) ($args['status'] ?? ''));
        if ($status !== '') {
            $mapped = match ($status) {
                'declined' => [EuWithdrawalRequest::STATUS_REJECTED],
                'refunded' => [EuWithdrawalRequest::STATUS_COMPLETED],
                'received' => [EuWithdrawalRequest::STATUS_PENDING, EuWithdrawalRequest::STATUS_APPROVED],
                default => [$status],
            };
            $q->whereIn('status', $mapped);
        }

        $orderInc = trim((string) ($args['order_increment_id'] ?? $args['orderIncrementId'] ?? ''));
        if ($orderInc !== '') {
            $q->whereHas('order', fn ($oq) => $oq->where('number', 'like', '%'.$orderInc.'%'));
        }

        $email = trim((string) ($args['customer_email'] ?? $args['customerEmail'] ?? ''));
        if ($email !== '') {
            $q->where(function ($inner) use ($email) {
                $inner->whereHas('customer', fn ($cq) => $cq->where('email', 'like', '%'.$email.'%'))
                    ->orWhereHas('order', fn ($oq) => $oq->where('email', 'like', '%'.$email.'%'));
            });
        }

        return $q->limit($limit)->get()->map(fn (EuWithdrawalRequest $row) => self::map($row))->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public static function get(array $args): ?array
    {
        self::assertManage();
        EuWithdrawalSchema::ensure();
        $row = EuWithdrawalRequest::query()->with(['order', 'customer'])->find((int) $args['id']);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function decline(array $args): array
    {
        $admin = self::assertManage();
        EuWithdrawalSchema::ensure();
        $row = EuWithdrawalRequest::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('EU withdrawal not found.');
        }
        try {
            $row = app(EuWithdrawalService::class)->reject(
                $row,
                (int) $admin->id,
                trim((string) ($args['reason'] ?? $args['note'] ?? 'Declined via Admin GraphQL'))
            );
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first() ?: 'Cannot decline.');
        }

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function markRefunded(array $args): array
    {
        $admin = self::assertManage();
        EuWithdrawalSchema::ensure();
        $row = EuWithdrawalRequest::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('EU withdrawal not found.');
        }
        $note = trim((string) ($args['note'] ?? $args['refundNote'] ?? 'Refunded via Admin GraphQL'));
        $service = app(EuWithdrawalService::class);
        try {
            if ($row->status === EuWithdrawalRequest::STATUS_PENDING) {
                $row = $service->approve($row, (int) $admin->id, $note);
            }
            if ($row->status === EuWithdrawalRequest::STATUS_APPROVED) {
                $row = $service->complete($row, $note);
            } elseif ($row->status !== EuWithdrawalRequest::STATUS_COMPLETED) {
                throw new UserError('Cannot mark this withdrawal refunded.');
            }
        } catch (ValidationException $e) {
            throw new UserError(collect($e->errors())->flatten()->first() ?: 'Cannot mark refunded.');
        }

        return self::map($row->fresh(['order', 'customer']));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function resendConfirmation(array $args): array
    {
        self::assertManage();
        EuWithdrawalSchema::ensure();
        $row = EuWithdrawalRequest::query()->find((int) $args['id']);
        if (! $row) {
            throw new UserError('EU withdrawal not found.');
        }
        $stamp = now()->toIso8601String();
        $note = trim((string) ($row->admin_note ?? ''));
        $row->update([
            'admin_note' => ($note === '' ? '' : $note."\n").'Confirmation re-sent at '.$stamp,
            'decided_at' => $row->decided_at ?? now(),
        ]);

        return self::map($row->fresh(['order', 'customer']));
    }
}
