<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16 — verified-purchase seller reviews.
 *
 * Eligibility: customer has a SellerOrder for this seller in shipped/completed.
 * Duplicates: one review per (seller_id, customer_id) — create only, never update-in-place.
 * Sellers cannot create/modify customer reviews. Admin moderates via ACL.
 */
class SellerReviewService
{
    public const ELIGIBLE_SELLER_ORDER_STATUSES = ['shipped', 'completed'];

    /**
     * @param  array{rating: int, title?: string|null, body?: string|null}  $data
     */
    public function create(Customer $customer, Seller $seller, array $data): SellerReview
    {
        if (! $seller->isPublic()) {
            abort(404);
        }

        if ((int) $seller->customer_id === (int) $customer->id) {
            throw ValidationException::withMessages([
                'seller_id' => 'You cannot review your own shop.',
            ]);
        }

        $eligible = $this->eligibleSellerOrder($customer, $seller);
        if (! $eligible) {
            throw ValidationException::withMessages([
                'seller_id' => 'Buy from this shop and wait until your order is shipped before leaving a seller review.',
            ]);
        }

        if (SellerReview::query()
            ->where('seller_id', $seller->id)
            ->where('customer_id', $customer->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'seller_id' => 'You have already reviewed this seller.',
            ]);
        }

        try {
            return DB::transaction(function () use ($customer, $seller, $eligible, $data) {
                // Re-check under lock for concurrent duplicate posts.
                $existing = SellerReview::query()
                    ->where('seller_id', $seller->id)
                    ->where('customer_id', $customer->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'seller_id' => 'You have already reviewed this seller.',
                    ]);
                }

                return SellerReview::query()->create([
                    'seller_id' => $seller->id,
                    'customer_id' => $customer->id,
                    'order_id' => $eligible->order_id,
                    'seller_order_id' => $eligible->id,
                    'rating' => (int) $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    'status' => SellerReview::STATUS_PENDING,
                    'is_verified_purchase' => true,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'seller_id' => 'You have already reviewed this seller.',
                ]);
            }
            throw $e;
        }
    }

    public function moderate(SellerReview $review, string $action, ?int $adminId = null, ?string $note = null): SellerReview
    {
        $status = match ($action) {
            'approve' => SellerReview::STATUS_APPROVED,
            'reject' => SellerReview::STATUS_REJECTED,
            default => throw ValidationException::withMessages(['action' => 'Unknown moderation action.']),
        };

        $review->update([
            'status' => $status,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'moderation_note' => $note,
        ]);

        return $review->fresh();
    }

    public function eligibleSellerOrder(Customer $customer, Seller $seller): ?SellerOrder
    {
        return SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereIn('status', self::ELIGIBLE_SELLER_ORDER_STATUSES)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderByDesc('id')
            ->first();
    }

    public function customerCanReview(Customer $customer, Seller $seller): bool
    {
        if ((int) $seller->customer_id === (int) $customer->id) {
            return false;
        }
        if (SellerReview::query()->where('seller_id', $seller->id)->where('customer_id', $customer->id)->exists()) {
            return false;
        }

        return $this->eligibleSellerOrder($customer, $seller) !== null;
    }

    /**
     * @return array{average: float|null, count: int, distribution: array<int, int>}
     */
    public function publicSummary(Seller $seller): array
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $rows = $seller->sellerReviews()
            ->where('status', SellerReview::STATUS_APPROVED)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $count = 0;
        $weighted = 0.0;
        foreach ($rows as $rating => $total) {
            $rating = (int) $rating;
            $total = (int) $total;
            if (isset($distribution[$rating])) {
                $distribution[$rating] = $total;
                $count += $total;
                $weighted += $rating * $total;
            }
        }

        return [
            'average' => $count > 0 ? round($weighted / $count, 1) : null,
            'count' => $count,
            'distribution' => $distribution,
        ];
    }

    /**
     * Public-safe review payload (no moderation internals / emails).
     *
     * @return array<string, mixed>
     */
    public function presentPublic(SellerReview $review): array
    {
        $review->loadMissing('customer');

        return [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'verified_purchase' => (bool) $review->is_verified_purchase,
            'customer' => $review->customer?->displayName() ?: 'Customer',
            'created_at' => $review->created_at?->toDateString(),
        ];
    }

    /**
     * Seller-portal / owner view — still no customer email.
     *
     * @return array<string, mixed>
     */
    public function presentForSeller(SellerReview $review): array
    {
        $review->loadMissing('customer');

        return [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => (string) $review->status,
            'verified_purchase' => (bool) $review->is_verified_purchase,
            'customer' => $review->customer?->displayName() ?: 'Customer',
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    protected function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = $e->getMessage();

        return $code === '1062'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'seller_reviews_seller_customer_unique');
    }
}
