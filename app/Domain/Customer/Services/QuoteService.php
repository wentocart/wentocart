<?php

namespace App\Domain\Customer\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\B2bQuote;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function requestFromCart(Customer $customer, CartService $carts, ?string $note = null): B2bQuote
    {
        $cart = $carts->current($customer);
        $cart->loadMissing('items.product');
        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['quote' => 'Add items to the cart before requesting a quote.']);
        }
        $totals = $carts->summary($cart, 0, null, null, $customer);

        return DB::transaction(function () use ($customer, $cart, $totals, $note) {
            $quote = B2bQuote::query()->create([
                'customer_id' => $customer->id,
                'number' => 'Q-'.str_pad((string) DocumentSequence::next('quote', 10001), 5, '0', STR_PAD_LEFT),
                'status' => B2bQuote::STATUS_REQUESTED,
                'note' => $note,
                'currency_code' => $totals['currency'] ?? null,
                'subtotal' => $totals['subtotal'] ?? 0,
                'total' => $totals['grand_total'] ?? 0,
                'valid_until' => now()->addDays(14),
            ]);
            foreach ($cart->items as $item) {
                $product = $item->product;
                $quote->items()->create([
                    'product_id' => $item->product_id,
                    'name' => $product?->translation()?->name ?: ($product?->sku ?: 'Item'),
                    'sku' => $product?->sku,
                    'qty' => (int) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => round((float) $item->unit_price * (int) $item->qty, 2),
                ]);
            }

            return $quote->fresh('items');
        });
    }

    /** @return array<string, mixed> */
    public function present(B2bQuote $quote): array
    {
        $quote->loadMissing('items');

        return [
            'id' => (int) $quote->id,
            'number' => $quote->number,
            'status' => $quote->status,
            'note' => $quote->note,
            'subtotal' => (float) $quote->subtotal,
            'total' => (float) $quote->total,
            'currency_code' => $quote->currency_code,
            'valid_until' => $quote->valid_until?->toIso8601String(),
            'items' => $quote->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'name' => $item->name,
                'sku' => $item->sku,
                'qty' => (int) $item->qty,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
            'converted_order_id' => $quote->converted_order_id ? (int) $quote->converted_order_id : null,
        ];
    }

    public function loadIntoCart(Customer $customer, B2bQuote $quote, CartService $carts): void
    {
        if ((int) $quote->customer_id !== (int) $customer->id) {
            abort(403, 'This quote does not belong to the current customer.');
        }
        if ($quote->status !== B2bQuote::STATUS_ACCEPTED) {
            throw ValidationException::withMessages(['quote' => 'Only accepted quotes can be checked out.']);
        }
        if ($quote->valid_until && $quote->valid_until->isPast()) {
            throw ValidationException::withMessages(['quote' => 'This quote has expired.']);
        }

        $quote->loadMissing('items');
        $cart = $carts->current($customer);
        $cart->items()->delete();
        $cart->unsetRelation('items');
        $cart->load('items');
        foreach ($quote->items as $line) {
            if (! $line->product_id) {
                continue;
            }
            $product = Product::query()->enabled()->find($line->product_id);
            if (! $product) {
                continue;
            }
            $item = $carts->add($cart, $product, max(1, (int) $line->qty));
            $item->update(['unit_price' => (float) $line->unit_price]);
        }
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $meta['quote_id'] = (int) $quote->id;
        $cart->update(['meta' => $meta]);
    }

    public function markConverted(B2bQuote $quote, int $orderId): void
    {
        $quote->update([
            'status' => B2bQuote::STATUS_CONVERTED,
            'converted_order_id' => $orderId,
        ]);
    }
}
