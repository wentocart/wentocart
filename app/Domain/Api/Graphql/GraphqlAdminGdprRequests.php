<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Services\GdprService;
use App\Domain\Settings\GdprSettings;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Admin REST GDPR Requests queue (list / detail / update / process / delete).
 */
final class GraphqlAdminGdprRequests
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function list(int $limit = 50, ?string $status = null): array
    {
        self::assertAdmin();
        GdprSchema::ensure();
        $limit = max(1, min(100, $limit));
        $q = GdprRequest::query()->with('customer')->orderByDesc('id');
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->limit($limit)->get()->map(fn (GdprRequest $r) => self::map($r))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertAdmin();
        GdprSchema::ensure();
        $row = GdprRequest::query()->with('customer')->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(int $id, string $status, ?string $message = null): array
    {
        self::assertAdmin();
        GdprSchema::ensure();
        $row = GdprRequest::query()->find($id);
        if (! $row) {
            throw new UserError('GDPR request not found.');
        }
        if ($message !== null) {
            $row->message = trim($message);
            $row->save();
        }
        try {
            $row = app(GdprService::class)->updateStatus($row, $status);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'GDPR request could not be updated.');
        }

        return self::map($row->loadMissing('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function process(int $requestId): array
    {
        self::assertAdmin();
        GdprSchema::ensure();
        $row = GdprRequest::query()->find($requestId);
        if (! $row) {
            throw new UserError('GDPR request not found.');
        }
        try {
            $result = app(GdprService::class)->process($row);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'GDPR request could not be processed.');
        }
        /** @var GdprRequest $fresh */
        $fresh = $result['request'];
        $mapped = self::map($fresh->loadMissing('customer'));

        return array_merge($mapped, [
            'requestId' => (string) $fresh->id,
            'customerDeleted' => (bool) $result['customerDeleted'],
            'processedAt' => now()->toIso8601String(),
            'success' => true,
            'message' => 'GDPR request processed.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertAdmin();
        GdprSchema::ensure();
        $row = GdprRequest::query()->with('customer')->find($id);
        if (! $row) {
            throw new UserError('GDPR request not found.');
        }
        $mapped = self::map($row);
        $row->delete();

        return array_merge($mapped, [
            'success' => true,
            'message' => 'GDPR request deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(GdprRequest $r): array
    {
        return [
            'id' => (string) $r->id,

            'requestId' => (string) $r->id,
            'reference' => $r->reference,
            'customerId' => $r->customer_id !== null ? (string) $r->customer_id : null,
            'customer_id' => $r->customer_id !== null ? (string) $r->customer_id : null,
            'customerEmail' => $r->customer?->email,
            'customerName' => $r->customer?->displayName(),
            'type' => (string) $r->type,
            'status' => (string) $r->status,
            'message' => $r->message,
            'createdAt' => $r->created_at?->toIso8601String(),
            'updatedAt' => $r->updated_at?->toIso8601String(),
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
