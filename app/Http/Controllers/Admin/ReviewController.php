<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\ReviewService;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Customer\Support\ReviewCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function __construct(protected ReviewService $reviewService) {}

    public function index(Request $request): Response
    {
        $sort = (string) $request->query('sort', 'id');
        if (! in_array($sort, ['id', 'product', 'customer', 'rating', 'review', 'status', 'created_at'], true)) {
            $sort = 'id';
        }
        $filters = [
            'product' => trim((string) $request->query('product', '')),
            'customer' => trim((string) $request->query('customer', '')),
            'rating' => trim((string) $request->query('rating', '')),
            'review' => trim((string) $request->query('review', '')),
            'status' => (string) $request->query('status', 'all'),
            'id_min' => trim((string) $request->query('id_min', '')),
            'id_max' => trim((string) $request->query('id_max', '')),
            'sort' => $sort,
            'dir' => strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];

        $query = Review::query()->with(['product.translations', 'customer']);
        if (in_array($filters['status'], [Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['id_min'] !== '') {
            $query->where((new Review)->qualifyColumn('id'), '>=', (int) $filters['id_min']);
        }
        if ($filters['id_max'] !== '') {
            $query->where((new Review)->qualifyColumn('id'), '<=', (int) $filters['id_max']);
        }
        if ($filters['product'] !== '') {
            $needle = $filters['product'];
            $query->whereHas('product.translations', fn ($t) => $t->where('name', 'like', '%'.$needle.'%'));
        }
        if ($filters['customer'] !== '') {
            $needle = $filters['customer'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('guest_name', 'like', '%'.$needle.'%')
                    ->orWhere('guest_email', 'like', '%'.$needle.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('email', 'like', '%'.$needle.'%')->orWhere('name', 'like', '%'.$needle.'%'));
            });
        }
        if ($filters['rating'] !== '') {
            $query->where('rating', (int) $filters['rating']);
        }
        if ($filters['review'] !== '') {
            $needle = $filters['review'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('body', 'like', '%'.$needle.'%');
            });
        }

        $dir = $filters['dir'];
        if ($filters['sort'] === 'product') {
            $locale = app()->getLocale();
            $prefix = \Illuminate\Support\Facades\DB::getTablePrefix();
            $productsTable = (new Product)->getTable();
            $translationsTable = (new ProductTranslation)->getTable();
            $rawTransName = "`{$prefix}{$translationsTable}`.`name`";
            $rawProdSku = "`{$prefix}{$productsTable}`.`sku`";
            $query
                ->leftJoin($productsTable, (new Product)->qualifyColumn('id'), '=', (new Review)->qualifyColumn('product_id'))
                ->leftJoin($translationsTable, function ($join) use ($locale) {
                    $join->on((new ProductTranslation)->qualifyColumn('product_id'), '=', (new Product)->qualifyColumn('id'))
                        ->where((new ProductTranslation)->qualifyColumn('language_code'), '=', $locale);
                })
                ->orderByRaw("COALESCE({$rawTransName}, {$rawProdSku}) {$dir}")
                ->orderBy((new Review)->qualifyColumn('id'), $dir)
                ->select((new Review)->qualifyColumn('*'));
        } elseif ($filters['sort'] === 'customer') {
            $customersTable = (new Customer)->getTable();
            $query
                ->leftJoin($customersTable, (new Customer)->qualifyColumn('id'), '=', (new Review)->qualifyColumn('customer_id'))
                ->orderBy((new Customer)->qualifyColumn('name'), $dir)
                ->orderBy((new Customer)->qualifyColumn('email'), $dir)
                ->orderBy((new Review)->qualifyColumn('id'), $dir)
                ->select((new Review)->qualifyColumn('*'));
        } elseif ($filters['sort'] === 'rating') {
            $query->orderBy('rating', $dir)->orderBy('id', $dir);
        } elseif ($filters['sort'] === 'review') {
            $query->orderBy('title', $dir)->orderBy('body', $dir)->orderBy('id', $dir);
        } elseif ($filters['sort'] === 'status') {
            $query->orderBy('status', $dir)->orderBy('id', $dir);
        } elseif ($filters['sort'] === 'created_at') {
            $query->orderBy('created_at', $dir)->orderBy('id', $dir);
        } else {
            $query->orderBy('id', $dir);
        }

        $counts = [
            'all' => Review::query()->count(),
            Review::STATUS_PENDING => Review::query()->where('status', Review::STATUS_PENDING)->count(),
            Review::STATUS_APPROVED => Review::query()->where('status', Review::STATUS_APPROVED)->count(),
            Review::STATUS_REJECTED => Review::query()->where('status', Review::STATUS_REJECTED)->count(),
        ];

        $locale = app()->getLocale();

        $mapReview = function (Review $review) use ($locale) {
            return array_merge([
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => $review->status ?: ($review->is_approved ? Review::STATUS_APPROVED : Review::STATUS_PENDING),
                'is_approved' => $review->is_approved,
                'rejected_reason' => $review->rejected_reason,
                'admin_reply' => $review->admin_reply,
                'is_verified_purchase' => $review->is_verified_purchase,
                'helpful_count' => (int) $review->helpful_count,
                'not_helpful_count' => (int) $review->not_helpful_count,
                'created_at' => $review->created_at?->toDateString(),
                'customer' => [
                    'id' => $review->customer?->id,
                    'email' => $review->customer?->email ?: $review->guest_email,
                    'name' => $review->authorName(),
                    'guest' => $review->customer_id === null,
                ],
                'product' => [
                    'id' => $review->product?->id,
                    'name' => $review->product?->translation($locale)?->name ?: $review->product?->sku,
                ],
            ], $review->mediaPayload());
        };

        $panel = null;
        if ($replyId = $request->integer('reply')) {
            $replying = Review::query()
                ->with(['product.translations', 'customer'])
                ->find($replyId);
            if ($replying) {
                $panel = [
                    'mode' => 'reply',
                    'review' => $mapReview($replying),
                ];
            }
        }

        return Inertia::render('Customers/Reviews', [
            'filters' => $filters,
            'counts' => $counts,
            'panel' => ReviewSettings::merchantReplies() ? $panel : null,
            'merchant_replies' => ReviewSettings::merchantReplies(),
            'reviews' => $query->adminPaginate()->through($mapReview),
        ]);
    }

    public function moderate(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,pending,unapprove,delete,reply,update'],
            'rejected_reason' => ['nullable', 'string', 'max:255'],
            'admin_reply' => ['nullable', 'string', 'max:5000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:5000'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['nullable', 'string', 'max:255'],
            'remove_video' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'array', 'max:'.Review::MAX_IMAGES],
            'video' => ['nullable', 'file'],
        ]);

        if ($data['action'] === 'delete') {
            $review->delete();

            return back()->with('success', 'Review deleted.');
        }

        if ($data['action'] === 'update') {
            $review->forceFill([
                'rating' => $data['rating'] ?? $review->rating,
                'title' => array_key_exists('title', $data) ? $data['title'] : $review->title,
                'body' => array_key_exists('body', $data) ? $data['body'] : $review->body,
            ])->save();

            $photos = $request->file('photos', []);
            if (! is_array($photos)) {
                $photos = $photos ? [$photos] : [];
            }
            $legacy = $request->file('photo');
            if ($legacy) {
                $photos[] = $legacy;
            }
            $this->reviewService->adminUpdateMedia(
                $review->fresh(),
                [
                    'remove_images' => $request->input('remove_images', []),
                    'remove_video' => $request->boolean('remove_video'),
                ],
                array_values(array_filter($photos)),
                $request->file('video')
            );

            return back()->with('success', 'Review content updated.');
        }

        if ($data['action'] === 'approve') {
            $review->approve();
        } elseif ($data['action'] === 'reject') {
            $review->reject($data['rejected_reason'] ?? null);
        } elseif ($data['action'] === 'pending' || $data['action'] === 'unapprove') {
            $review->pending();
        } elseif ($data['action'] === 'reply') {
            if (! ReviewSettings::merchantReplies()) {
                return back()->with('error', 'Merchant replies are turned off. Turn them on under Configure → Customers → Reviews. A customer cannot reply as staff.');
            }
            $reply = trim((string) ($data['admin_reply'] ?? ''));
            if ($reply === '') {
                return back()->with('error', 'Write a reply first.');
            }
            $review->reply($reply);
            $this->reviewService->notifyReply($review->fresh(['product', 'customer']));
        }

        return back()->with('success', 'Review updated.');
    }

    public function export()
    {
        return $this->csvDownload('reviews.csv', ReviewCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('reviews-sample.csv', ReviewCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, ReviewCsvSchema::import(...), 'reviews');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,pending,unapprove,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $reviews = Review::query()->whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($reviews as $review) {
            if ($data['action'] === 'delete') {
                $review->delete();
                $count++;
                continue;
            }
            if ($data['action'] === 'approve') {
                $review->approve();
                $count++;
            } elseif ($data['action'] === 'reject') {
                $review->reject(null);
                $count++;
            } elseif ($data['action'] === 'pending' || $data['action'] === 'unapprove') {
                $review->pending();
                $count++;
            }
        }

        return back()->with('success', "Updated {$count} reviews.");
    }
}
