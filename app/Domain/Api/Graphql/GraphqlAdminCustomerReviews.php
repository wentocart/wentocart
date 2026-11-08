<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use GraphQL\Error\UserError;

/**
 * Admin REST Customer Reviews moderation (shared by GraphQL + REST).
 */
final class GraphqlAdminCustomerReviews
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
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
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $q = Review::query()->with(['product.translations', 'customer'])->orderByDesc('id');

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $q->where('status', self::normalizeStatus($status));
        }
        if (isset($filters['rating']) && $filters['rating'] !== '' && $filters['rating'] !== null) {
            $q->where('rating', (int) $filters['rating']);
        }
        $productId = (int) ($filters['product_id'] ?? $filters['productId'] ?? 0);
        if ($productId > 0) {
            $q->where('product_id', $productId);
        }
        $customerId = (int) ($filters['customer_id'] ?? $filters['customerId'] ?? 0);
        if ($customerId > 0) {
            $q->where('customer_id', $customerId);
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['id', 'rating', 'created_at', 'status'], true)) {
            $q->reorder($sort, $order);
        }

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (Review $row) => self::map($row, true))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id, bool $withImages = true): ?array
    {
        self::assertManage();
        $row = Review::query()->with(['product.translations', 'customer'])->find($id);

        return $row ? self::map($row, true, $withImages) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateStatus(int $id, string $status, ?string $rejectedReason = null): array
    {
        self::assertManage();
        $row = Review::query()->with(['product.translations', 'customer'])->find($id);
        if (! $row) {
            throw new UserError('Review not found.');
        }
        $status = self::normalizeStatus($status);
        if ($status === Review::STATUS_APPROVED) {
            $row->approve();
        } elseif ($status === Review::STATUS_REJECTED) {
            $row->reject($rejectedReason);
        } else {
            $row->pending();
        }

        return self::map($row->fresh(['product.translations', 'customer']) ?? $row, true, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = Review::query()->with(['product.translations', 'customer'])->find($id);
        if (! $row) {
            throw new UserError('Review not found.');
        }
        $snapshot = self::map($row, true, true);
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
        $deleted = [];
        foreach ($ids as $id) {
            $row = Review::query()->find($id);
            if (! $row) {
                continue;
            }
            $deleted[] = $id;
            $row->delete();
        }

        return ['ok' => true, 'deleted' => $deleted, 'updated_count' => count($deleted)];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, updated_count: int}
     */
    public static function bulkUpdateStatus(array $ids, string $status): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $status = self::normalizeStatus($status);
        $updated = 0;
        foreach (Review::query()->whereIn('id', $ids)->get() as $row) {
            if ($status === Review::STATUS_APPROVED) {
                $row->approve();
            } elseif ($status === Review::STATUS_REJECTED) {
                $row->reject();
            } else {
                $row->pending();
            }
            $updated++;
        }

        return ['ok' => true, 'updated_count' => $updated];
    }

    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'disapproved') {
            $status = Review::STATUS_REJECTED;
        }
        if (! in_array($status, [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            throw new UserError('Status must be pending, approved, or disapproved.');
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Review $row, bool $nested = false, bool $withImages = false): array
    {
        $locale = Language::defaultCode();
        $status = (string) ($row->status ?: ($row->is_approved ? Review::STATUS_APPROVED : Review::STATUS_PENDING));
        $compatStatus = $status === Review::STATUS_REJECTED ? 'disapproved' : $status;

        $out = [
            'id' => (int) $row->id,

            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'rating' => (int) $row->rating,
            'title' => $row->title,
            'body' => $row->body,
            'comment' => $row->body,
            'status' => $compatStatus,
            'is_approved' => (bool) $row->is_approved,
            'product_name' => $row->product?->translation($locale)?->name ?: $row->product?->sku,
            'customer_name' => $row->authorName(),
            'customer_email' => $row->customer?->email ?: $row->guest_email,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];

        if ($nested) {
            $out['product'] = $row->product ? [
                'id' => (int) $row->product->id,
                'name' => $row->product->translation($locale)?->name ?: $row->product->sku,
                'sku' => (string) ($row->product->sku ?? ''),
            ] : null;
            $out['customer'] = $row->customer_id ? [
                'id' => (int) $row->customer_id,
                'name' => $row->authorName(),
                'email' => $row->customer?->email,
            ] : null;
        }

        if ($withImages) {
            $images = [];
            $raw = $row->images;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            if (is_array($raw)) {
                foreach ($raw as $i => $img) {
                    if (is_string($img)) {
                        $images[] = ['id' => $i + 1, 'path' => $img, 'url' => $img];
                    } elseif (is_array($img)) {
                        $images[] = [
                            'id' => (int) ($img['id'] ?? $i + 1),
                            'path' => (string) ($img['path'] ?? $img['url'] ?? ''),
                            'url' => (string) ($img['url'] ?? $img['path'] ?? ''),
                        ];
                    }
                }
            }
            if ($row->photo_path) {
                array_unshift($images, [
                    'id' => 0,
                    'path' => (string) $row->photo_path,
                    'url' => (string) $row->photo_path,
                ]);
            }
            $out['images'] = $images;
        }

        return $out;
    }
}
