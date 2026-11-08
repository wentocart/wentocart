<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use GraphQL\Error\UserError;

/**
 * Shop REST productReviews cursor connection + update/delete mutations.
 */
final class GraphqlProductReviews
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function connection(array $args): array
    {
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^rev:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = Review::query()->with('customer:id,name,first_name,last_name');
        $productId = (int) ($args['product_id'] ?? $args['productId'] ?? 0);
        if ($productId > 0) {
            $q->where('product_id', $productId);
        }

        $statusRaw = $args['status'] ?? 'approved';
        $status = strtolower(trim((string) $statusRaw));
        // Empty string = all statuses (product.reviews). Default approved for productReviews query.
        if ($status === 'approved') {
            $q->where(function ($inner) {
                $inner->where('is_approved', true)
                    ->orWhere('status', Review::STATUS_APPROVED);
            });
        } elseif ($status === '') {
            // no status filter
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

        $rating = isset($args['rating']) ? (int) $args['rating'] : 0;
        if ($rating >= 1 && $rating <= 5) {
            $q->where('rating', $rating);
        }
        $q->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (Review $r) => [
            'cursor' => base64_encode('rev:'.$r->id),
            'node' => self::map($r),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('rev:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(Review $r): array
    {
        $name = $r->guest_name
            ?: $r->customer?->name
            ?: trim(($r->customer?->first_name ?? '').' '.($r->customer?->last_name ?? ''))
            ?: 'Customer';
        $status = (string) ($r->status ?: ($r->is_approved ? 'approved' : 'pending'));
        if ($status === Review::STATUS_REJECTED) {
            $status = 'disapproved';
        }
        $attachments = null;
        if (is_array($r->images) && $r->images !== []) {
            $attachments = json_encode($r->images, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($r->photo_path) && $r->photo_path !== '') {
            $attachments = json_encode([$r->photo_path], JSON_UNESCAPED_SLASHES);
        }

        return [
            'id' => (string) $r->id,

            'name' => $name,
            'title' => (string) ($r->title ?? ''),
            'rating' => (int) $r->rating,
            'comment' => $r->body,
            'body' => $r->body,
            'status' => $status,
            'attachments' => $attachments,
            'customer_name' => $name,
            'productId' => $r->product_id ? (string) $r->product_id : null,
            'createdAt' => optional($r->created_at)?->toIso8601String(),
            'updatedAt' => optional($r->updated_at)?->toIso8601String(),
            'created_at' => optional($r->created_at)?->toIso8601String(),
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{productReview: array<string, mixed>, clientMutationId: ?string}
     */
    public static function updateMutation(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        $id = self::parseReviewId($input['id'] ?? null);
        if ($id < 1) {
            throw new UserError('Review not found.');
        }

        $review = Review::query()->find($id);
        if (! $review) {
            throw new UserError('Review not found.');
        }
        if ((int) $review->customer_id !== (int) $customer->id) {
            throw new UserError('Not your review.');
        }

        if (array_key_exists('productId', $input) || array_key_exists('product_id', $input)) {
            $productId = (int) ($input['productId'] ?? $input['product_id'] ?? 0);
            if ($productId < 1 || ! Product::query()->where('id', $productId)->exists()) {
                throw new UserError('Product not found.');
            }
            $review->product_id = $productId;
        }
        if (array_key_exists('title', $input) && $input['title'] !== null) {
            $title = trim((string) $input['title']);
            if ($title === '') {
                throw new UserError('Title cannot be empty.');
            }
            $review->title = $title;
        }
        if (array_key_exists('comment', $input) || array_key_exists('body', $input)) {
            $comment = trim((string) ($input['comment'] ?? $input['body'] ?? ''));
            if ($comment === '') {
                throw new UserError('Comment cannot be empty.');
            }
            $review->body = $comment;
        }
        if (array_key_exists('rating', $input) && $input['rating'] !== null) {
            $rating = (int) $input['rating'];
            if ($rating < 1 || $rating > 5) {
                throw new UserError('Rating must be between 1 and 5.');
            }
            $review->rating = $rating;
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name !== '') {
                $review->guest_name = $name;
            }
        }

        $review->save();
        $review->refresh();

        return [
            'productReview' => self::map($review),
            'clientMutationId' => isset($input['clientMutationId']) ? (string) $input['clientMutationId'] : null,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{productReview: array{id: string}, clientMutationId: ?string}
     */
    public static function deleteMutation(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        $id = self::parseReviewId($input['id'] ?? null);
        if ($id < 1) {
            throw new UserError('Review not found.');
        }

        $review = Review::query()->find($id);
        if (! $review) {
            throw new UserError('Review not found.');
        }
        if ((int) $review->customer_id !== (int) $customer->id) {
            throw new UserError('Not your review.');
        }

        $iri = '/api/v1/store/reviews/'.$review->id;
        $review->delete();

        return [
            'productReview' => ['id' => $iri],
            'clientMutationId' => isset($input['clientMutationId']) ? (string) $input['clientMutationId'] : null,
        ];
    }

    protected static function parseReviewId(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return (int) $raw;
        }
        $s = (string) $raw;
        if (preg_match('/(\d+)\s*$/', $s, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
