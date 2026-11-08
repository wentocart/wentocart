<?php

namespace App\Domain\Customer\Services;

use App\Domain\Auth\Models\AdminImpersonation;
use App\Domain\Customer\CustomerNoteSchema;
use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Models\Review;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\Schema;

/**
 * Wento Customer Desk — unified activity for one shopper (not a notes clone).
 * Merges staff notes, orders, reviews, GDPR, and impersonation into one timeline.
 */
final class CustomerDeskService
{
    /**
     * @return array{
     *   customer_id: int,
     *   summary: array<string, int>,
     *   events: list<array<string, mixed>>
     * }
     */
    public function timeline(Customer $customer, int $limit = 40): array
    {
        $limit = max(5, min(100, $limit));
        $events = [];

        CustomerNoteSchema::ensure();
        if (Schema::hasTable('customer_notes')) {
            CustomerNote::query()
                ->with('admin:id,name')
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->each(function (CustomerNote $note) use (&$events) {
                    $events[] = [
                        'id' => 'note:'.$note->id,
                        'type' => 'note',
                        'at' => $note->created_at?->toIso8601String(),
                        'title' => 'Staff note',
                        'body' => (string) $note->note,
                        'href' => null,
                        'meta' => [
                            'admin' => $note->admin?->name,
                            'customer_notified' => (bool) $note->customer_notified,
                        ],
                    ];
                });
        }

        Order::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'number', 'status', 'payment_status', 'grand_total', 'currency_code', 'created_at'])
            ->each(function (Order $order) use (&$events) {
                $statusBits = array_filter([
                    (string) ($order->status ?: ''),
                    (string) ($order->payment_status ?: ''),
                ]);
                $events[] = [
                    'id' => 'order:'.$order->id,
                    'type' => 'order',
                    'at' => $order->created_at?->toIso8601String(),
                    'title' => 'Order '.($order->number ?: '#'.$order->id),
                    'body' => implode(' · ', $statusBits),
                    'href' => '/admin/orders/'.$order->id,
                    'meta' => [
                        'grand_total' => (float) $order->grand_total,
                        'currency' => (string) ($order->currency_code ?: ''),
                        'payment_status' => (string) ($order->payment_status ?: ''),
                    ],
                ];
            });

        if (Schema::hasTable('reviews')) {
            Review::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['id', 'rating', 'title', 'is_approved', 'created_at', 'product_id'])
                ->each(function (Review $review) use (&$events) {
                    $events[] = [
                        'id' => 'review:'.$review->id,
                        'type' => 'review',
                        'at' => $review->created_at?->toIso8601String(),
                        'title' => 'Review '.((int) $review->rating).'/5',
                        'body' => (string) ($review->title ?: ''),
                        'href' => '/admin/reviews',
                        'meta' => [
                            'approved' => (bool) $review->is_approved,
                            'product_id' => (int) $review->product_id,
                        ],
                    ];
                });
        }

        GdprSchema::ensure();
        if (Schema::hasTable('gdpr_requests')) {
            GdprRequest::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->each(function (GdprRequest $row) use (&$events) {
                    $events[] = [
                        'id' => 'gdpr:'.$row->id,
                        'type' => 'gdpr',
                        'at' => $row->created_at?->toIso8601String(),
                        'title' => 'GDPR '.$row->typeLabel(),
                        'body' => (string) ($row->status ?: ''),
                        'href' => '/admin/gdpr-requests/'.$row->id.'/edit',
                        'meta' => [
                            'reference' => (string) ($row->reference ?: ''),
                            'status' => (string) ($row->status ?: ''),
                        ],
                    ];
                });
        }

        if (Schema::hasTable('admin_impersonations')) {
            AdminImpersonation::query()
                ->with('admin:id,name')
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->each(function (AdminImpersonation $row) use (&$events) {
                    $events[] = [
                        'id' => 'impersonation:'.$row->id,
                        'type' => 'impersonation',
                        'at' => ($row->started_at ?? $row->created_at)?->toIso8601String(),
                        'title' => 'Storefront impersonation',
                        'body' => $row->admin?->name
                            ? 'By '.$row->admin->name
                            : 'Staff session',
                        'href' => null,
                        'meta' => [
                            'admin' => $row->admin?->name,
                            'ip' => (string) ($row->ip ?: ''),
                        ],
                    ];
                });
        }

        usort($events, function (array $a, array $b) {
            return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
        });
        $events = array_slice(array_values($events), 0, $limit);

        $summary = [
            'notes' => Schema::hasTable('customer_notes')
                ? (int) CustomerNote::query()->where('customer_id', $customer->id)->count()
                : 0,
            'orders' => (int) Order::query()->where('customer_id', $customer->id)->count(),
            'reviews' => Schema::hasTable('reviews')
                ? (int) Review::query()->where('customer_id', $customer->id)->count()
                : 0,
            'gdpr' => Schema::hasTable('gdpr_requests')
                ? (int) GdprRequest::query()->where('customer_id', $customer->id)->count()
                : 0,
            'impersonations' => Schema::hasTable('admin_impersonations')
                ? (int) AdminImpersonation::query()->where('customer_id', $customer->id)->count()
                : 0,
        ];

        return [
            'customer_id' => (int) $customer->id,
            'summary' => $summary,
            'events' => $events,
        ];
    }

    /**
     * Append a staff note through the existing notes pipeline (mail notify optional).
     *
     * @return array<string, mixed>
     */
    public function addNote(Customer $customer, string $note, bool $notify = false): array
    {
        return \App\Domain\Api\Graphql\GraphqlAdminCustomerNotes::create(
            (int) $customer->id,
            $note,
            $notify,
        );
    }
}
