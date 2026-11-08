<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\ReviewService;
use App\Domain\Settings\ReviewSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Shop REST product reviews REST: list / create / PATCH update / DELETE.
 */
class ShopReviewsController extends Controller
{
    /**
     */
    public function forProduct(Request $request, int|string $productId): JsonResponse
    {
        $pid = (int) $productId;
        if ($pid < 1 || ! Product::query()->whereKey($pid)->exists()) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(50, (int) ($request->query('per_page') ?? $request->query('limit') ?? 30)));
        $status = strtolower(trim((string) $request->query('status', 'approved')));
        if ($status === '') {
            $status = 'approved';
        }

        $q = Review::query()->with('customer:id,name,first_name,last_name')->where('product_id', $pid);

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
        $q->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get();
        $data = $rows->map(function (Review $r) {
            $status = (string) ($r->status ?: ($r->is_approved ? 'approved' : 'pending'));
            if ($status === Review::STATUS_REJECTED) {
                $status = 'disapproved';
            }
            $name = $r->guest_name
                ?: $r->customer?->name
                ?: trim(($r->customer?->first_name ?? '').' '.($r->customer?->last_name ?? ''))
                ?: 'Customer';

            return [
                'id' => (int) $r->id,
                'name' => $name,
                'title' => (string) ($r->title ?? ''),
                'rating' => (int) $r->rating,
                'comment' => (string) ($r->body ?? ''),
                'status' => $status,
                'createdAt' => optional($r->created_at)?->toIso8601String(),
                'updatedAt' => optional($r->updated_at)?->toIso8601String(),
            ];
        })->values()->all();

        return response()->json($data, 200, [
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function store(Request $request, ReviewService $reviews): JsonResponse
    {
        if (! ReviewSettings::enabled()) {
            return response()->json(['message' => 'Customer reviews are disabled.'], 403);
        }

        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            $customer = null;
            if (ReviewSettings::whoCanReview() !== 'everyone') {
                return response()->json(['message' => 'Authentication required to submit a review.'], 403);
            }
        }

        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        $title = trim((string) $request->input('title', ''));
        $comment = trim((string) ($request->input('comment') ?? $request->input('body') ?? ''));
        $rating = (int) $request->input('rating', 0);
        $name = trim((string) $request->input('name', ''));

        if ($productId < 1 || $title === '' || $comment === '' || $rating < 1 || $rating > 5) {
            return response()->json([
                'message' => 'productId, title, comment, and rating (1-5) are required.',
            ], 400);
        }

        $input = [
            'product_id' => $productId,
            'rating' => $rating,
            'title' => $title,
            'body' => $comment,
        ];
        if ($customer === null) {
            $input['guest_name'] = $name !== '' ? $name : 'Guest';
            $input['guest_email'] = trim((string) $request->input('email', $request->input('guest_email', '')));
            if ($input['guest_email'] === '') {
                return response()->json(['message' => 'email is required for guest reviews.'], 400);
            }
        } elseif ($name !== '') {
            $input['guest_name'] = $name;
        }

        try {
            $review = $reviews->submit($customer, $input);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Validation failed.';

            return response()->json(['message' => (string) $msg], 422);
        }

        return response()->json([
            'id' => (int) $review->id,
            'name' => $name !== ''
                ? $name
                : ($review->guest_name
                    ?: ($customer?->name ?? 'Customer')),
            'title' => (string) ($review->title ?? ''),
            'comment' => (string) ($review->body ?? ''),
            'rating' => (int) $review->rating,
            'status' => 'pending',
            'createdAt' => optional($review->created_at)?->toIso8601String(),
            'updatedAt' => optional($review->updated_at)?->toIso8601String(),
        ], 201);
    }

    /**
     */
    public function storeForProduct(Request $request, int $id, ReviewService $reviews): JsonResponse
    {
        $request->merge([
            'productId' => $id,
            'product_id' => $id,
        ]);

        return $this->store($request, $reviews);
    }

    /**
     * Returns review comment for the active locale; optional ?locale= override.
     */
    public function translate(Request $request, int $productId, int $reviewId): JsonResponse
    {
        if ($productId < 1 || ! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $review = Review::query()->where('product_id', $productId)->find($reviewId);
        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $approved = $review->is_approved
            || (string) ($review->status ?? '') === Review::STATUS_APPROVED;
        if (! $approved) {
            return response()->json(['message' => 'Review is not available for translation.'], 400);
        }

        $locale = trim((string) ($request->query('locale') ?? app()->getLocale()));
        $content = (string) ($review->body ?? '');

        return response()->json([
            'content' => $content,
            'locale' => $locale !== '' ? $locale : app()->getLocale(),
            'reviewId' => (int) $review->id,
            'productId' => (int) $productId,
            'success' => true,
        ]);
    }

    /**
     */
    public function update(Request $request, int|string $reviewId): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Authentication required.'], 403);
        }

        $ct = strtolower((string) $request->header('Content-Type', ''));
        if ($ct !== ''
            && ! str_contains($ct, 'application/json')
            && ! str_contains($ct, 'application/merge-patch+json')) {
            return response()->json(['message' => 'Unsupported Media Type'], 415);
        }

        $review = Review::query()->find((int) $reviewId);
        if (! $review || ! $review->customer_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ((int) $review->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($request->exists('title')) {
            $title = trim((string) $request->input('title'));
            if ($title === '') {
                return response()->json(['message' => 'Title cannot be empty.'], 400);
            }
            $review->title = $title;
        }
        if ($request->exists('comment') || $request->exists('body')) {
            $comment = trim((string) ($request->input('comment') ?? $request->input('body') ?? ''));
            if ($comment === '') {
                return response()->json(['message' => 'Comment cannot be empty.'], 400);
            }
            $review->body = $comment;
        }
        if ($request->exists('rating')) {
            $rating = (int) $request->input('rating');
            if ($rating < 1 || $rating > 5) {
                return response()->json(['message' => 'Rating must be between 1 and 5.'], 400);
            }
            $review->rating = $rating;
        }
        if ($request->exists('name')) {
            $name = trim((string) $request->input('name'));
            if ($name !== '') {
                $review->guest_name = $name;
            }
        }

        $review->save();
        $review->refresh();

        return response()->json($this->flatReview($review, $customer));
    }

    public function destroy(Request $request, int|string $reviewId): Response|JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json(['message' => 'Authentication required.'], 403);
        }

        $review = Review::query()->find((int) $reviewId);
        if (! $review || ! $review->customer_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ((int) $review->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $review->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function flatReview(Review $review, Customer $customer): array
    {
        $status = (string) ($review->status ?: ($review->is_approved ? 'approved' : 'pending'));
        if ($status === Review::STATUS_REJECTED) {
            $status = 'disapproved';
        }
        $name = $review->guest_name
            ?: $customer->name
            ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
            ?: 'Customer';

        return [
            'id' => (int) $review->id,
            'name' => $name,
            'title' => (string) ($review->title ?? ''),
            'comment' => (string) ($review->body ?? ''),
            'rating' => (int) $review->rating,
            'status' => $status,
            'createdAt' => optional($review->created_at)?->toIso8601String(),
            'updatedAt' => optional($review->updated_at)?->toIso8601String(),
        ];
    }
}
