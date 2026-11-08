<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerDiscount;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 */
class SellerCheckoutDealsRestController extends Controller
{
    public function __construct(protected SellerService $sellers) {}

    public function index(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $rows = SellerDiscount::query()
            ->where('seller_id', $seller->id)
            ->whereIn('apply_as', ['cart', 'coupon'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (SellerDiscount $d) => $this->row($d))
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $data = $this->validated($request);
        $row = $this->sellers->saveDiscount($seller, $data);

        return response()->json([
            'message' => 'Checkout deal created successfully.',
            'data' => $this->row($row),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $row = SellerDiscount::query()->where('seller_id', $seller->id)->whereKey($id)->first();
        if (! $row || ! in_array($row->apply_as, ['cart', 'coupon'], true)) {
            return response()->json(['message' => 'Checkout deal not found.', 'error' => 'not_found'], 404);
        }
        $row = $this->sellers->saveDiscount($seller, $this->validated($request), $row);

        return response()->json([
            'message' => 'Checkout deal updated successfully.',
            'data' => $this->row($row),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $row = SellerDiscount::query()->where('seller_id', $seller->id)->whereKey($id)->first();
        if (! $row || ! in_array($row->apply_as, ['cart', 'coupon'], true)) {
            return response()->json(['message' => 'Checkout deal not found.', 'error' => 'not_found'], 404);
        }
        $this->sellers->deleteDiscount($seller, $row);

        return response()->json(['message' => 'Checkout deal deleted.', 'data' => ['id' => $id]]);
    }

    public function copy(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        $row = SellerDiscount::query()->where('seller_id', $seller->id)->whereKey($id)->first();
        if (! $row || ! in_array($row->apply_as, ['cart', 'coupon'], true)) {
            return response()->json(['message' => 'Checkout deal not found.', 'error' => 'not_found'], 404);
        }
        $copy = $this->sellers->saveDiscount($seller, [
            'name' => 'Copy of '.$row->name,
            'code' => null,
            'apply_as' => $row->apply_as,
            'action' => $row->action,
            'value' => $row->value,
            'min_cart' => $row->min_cart,
            'max_uses' => $row->max_uses,
            'starts_at' => $row->starts_at,
            'expires_at' => $row->expires_at,
            'is_enabled' => false,
        ]);

        return response()->json([
            'message' => 'Checkout deal copied.',
            'data' => $this->row($copy),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:64'],
            'apply_as' => ['nullable', 'string', Rule::in(['cart', 'coupon'])],
            'coupon_type' => ['nullable', 'string', Rule::in(['none', 'specific', 'no_coupon', 'specific_coupon'])],
            'action' => ['nullable', 'string', Rule::in(['percent', 'amount', 'buy_x_get_y'])],
            'value' => ['required', 'numeric', 'min:0'],
            'min_cart' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_enabled' => ['nullable', 'boolean'],
            'status' => ['nullable', 'boolean'],
        ]);
        $couponType = (string) ($data['coupon_type'] ?? '');
        if (in_array($couponType, ['specific', 'specific_coupon'], true) || ! empty($data['code'])) {
            $data['apply_as'] = 'coupon';
        } else {
            $data['apply_as'] = $data['apply_as'] ?? 'cart';
        }
        if (array_key_exists('status', $data) && ! array_key_exists('is_enabled', $data)) {
            $data['is_enabled'] = (bool) $data['status'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerDiscount $d): array
    {
        return [
            'id' => (int) $d->id,
            'name' => (string) $d->name,
            'code' => (string) ($d->code ?? ''),
            'applyAs' => (string) $d->apply_as,
            'action' => (string) $d->action,
            'value' => (float) $d->value,
            'minCart' => $d->min_cart !== null ? (float) $d->min_cart : null,
            'priority' => 80,
            'isEnabled' => (bool) $d->is_enabled,
            'startsAt' => optional($d->starts_at)?->toIso8601String(),
            'expiresAt' => optional($d->expires_at)?->toIso8601String(),
        ];
    }

    /**
     * @return Seller|JsonResponse
     */
    private function owner(): Seller|JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $seller = Seller::query()->where('customer_id', $customer->id)->first();
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Seller shop not found.', 'error' => 'not_found'], 404);
        }

        return $seller;
    }
}
