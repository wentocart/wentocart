<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\CustomerNoteSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Customer\Models\Review;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;

/**
 */
final class GraphqlAdminGdprDownload
{
    /**
     * @return array{
     *   customerId: string,
     *   customerEmail: string|null,
     *   generatedAt: string,
     *   success: bool,
     *   message: string,
     *   data: array<string, mixed>
     * }
     */
    public static function export(int $customerId): array
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }

        $customer = Customer::query()
            ->with(['addresses', 'orders.items', 'reviews.product'])
            ->find($customerId);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }

        CustomerNoteSchema::ensure();
        $notes = CustomerNote::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerNote $n) => GraphqlAdminCustomerNotes::map($n))
            ->all();

        // Legacy single-field notes (pre-append-only) surface as one synthetic row when present.
        if (filled($customer->notes ?? null) && $notes === []) {
            $notes[] = [
                'id' => 'legacy',
                'note' => (string) $customer->notes,
                'customerId' => (string) $customer->id,
                'customerNotified' => false,
                'createdAt' => $customer->updated_at?->toIso8601String(),
            ];
        }

        $wishlist = $customer->wishlist()
            ->get(['products.id', 'products.sku'])
            ->map(fn ($p) => [
                'product_id' => (string) $p->id,
                'sku' => $p->sku,
            ])
            ->values()
            ->all();

        $profile = $customer->toArray();
        unset($profile['password'], $profile['remember_token'], $profile['totp_secret'], $profile['sandbox_payment_token']);

        $data = [
            'customer' => $profile,
            'addresses' => $customer->addresses->map(fn ($a) => $a->toArray())->values()->all(),
            'orders' => $customer->orders->map(function ($o) {
                $row = $o->toArray();
                $row['items'] = $o->items->map(fn ($i) => $i->toArray())->values()->all();

                return $row;
            })->values()->all(),
            'reviews' => $customer->reviews->map(fn (Review $r) => [
                'id' => $r->id,
                'product_id' => $r->product_id,
                'rating' => $r->rating,
                'title' => $r->title ?? null,
                'body' => $r->body ?? $r->comment ?? null,
                'status' => $r->status ?? null,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
            'wishlist' => $wishlist,
            'notes' => $notes,
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return [
            'customerId' => (string) $customer->id,
            'customerEmail' => $customer->email,
            'generatedAt' => now()->toIso8601String(),
            'success' => true,
            'message' => 'GDPR data export generated.',
            // REST uses structured array; GraphQL selects `data` / `dataJson` as string.
            'data' => $data,
            'dataJson' => $json,
        ];
    }
}
