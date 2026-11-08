<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST GET /api/v1/store/customer-reviews (+ /{id}).
 */
class ShopCustomerReviewsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        $q = Review::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id');

        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status === 'approved') {
            $q->where(function ($inner) {
                $inner->where('is_approved', true)
                    ->orWhere('status', Review::STATUS_APPROVED);
            });
        } elseif ($status === 'pending') {
            $q->where(function ($inner) {
                $inner->where('status', Review::STATUS_PENDING)
                    ->orWhere(function ($p) {
                        $p->where('is_approved', false)->where(function ($s) {
                            $s->whereNull('status')->orWhere('status', Review::STATUS_PENDING);
                        });
                    });
            });
        } elseif (in_array($status, ['disapproved', 'rejected'], true)) {
            $q->where('status', Review::STATUS_REJECTED);
        }

        $rating = (int) $request->query('rating', 0);
        if ($rating >= 1 && $rating <= 5) {
            $q->where('rating', $rating);
        }

        $total = (clone $q)->count();
        $rows = $q->limit(10)->get();
        $data = $rows->map(fn (Review $r) => $this->map($r, $customer))->values()->all();

        return response()->json($data, 200, [
            'X-Total-Count' => (string) $total,
            'X-Page' => '1',
            'X-Per-Page' => '10',
            'X-Total-Pages' => (string) max(1, (int) ceil($total / 10)),
        ]);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        $review = Review::query()
            ->where('id', (int) $id)
            ->where('customer_id', $customer->id)
            ->first();
        if (! $review) {
            return response()->json([
                'detail' => 'Review not found',
                'message' => 'Review not found',
            ], 404);
        }

        return response()->json($this->map($review, $customer));
    }

    /**
     * @return array<string, mixed>
     */
    private function map(Review $r, Customer $customer): array
    {
        $status = (string) ($r->status ?: ($r->is_approved ? 'approved' : 'pending'));
        if ($status === Review::STATUS_REJECTED) {
            $status = 'disapproved';
        }
        $name = $r->guest_name
            ?: $customer->name
            ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
            ?: 'Customer';

        return [
            'id' => (int) $r->id,
            'name' => $name,
            'title' => (string) ($r->title ?? ''),
            'comment' => (string) ($r->body ?? ''),
            'rating' => (int) $r->rating,
            'status' => $status,
            'product' => '/api/v1/store/products/'.(int) $r->product_id,
            'customer' => '/api/v1/store/customers/'.(int) $customer->id,
            'createdAt' => optional($r->created_at)?->toIso8601String(),
            'updatedAt' => optional($r->updated_at)?->toIso8601String(),
        ];
    }
}
