<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\Models\DiscountRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class SellerDiscountJoinRestController extends Controller
{
    public function index(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! MarketplaceSettings::enableProductDiscountJoin()) {
            return response()->json(['data' => [], 'meta' => ['enabled' => false]]);
        }
        SellerDiscountJoinSchema::ensure();
        $joins = MarketplaceDiscountJoin::query()
            ->where('seller_id', $seller->id)
            ->get()
            ->keyBy('discount_rule_id');

        $rows = DiscountRule::query()
            ->whereNull('seller_id')
            ->where('apply_as', 'catalog')
            ->where('allow_sellers_to_join', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (DiscountRule $rule) use ($joins) {
                $join = $joins->get($rule->id);

                return [
                    'id' => (int) $rule->id,
                    'name' => (string) $rule->name,
                    'action' => (string) $rule->action,
                    'value' => (float) $rule->value,
                    'priority' => (int) $rule->priority,
                    'isEnabled' => (bool) $rule->is_enabled,
                    'startsAt' => optional($rule->starts_at)?->toIso8601String(),
                    'expiresAt' => optional($rule->expires_at)?->toIso8601String(),
                    'participation' => $join
                        ? (string) $join->status
                        : 'not_enrolled',
                    'joinId' => $join ? (int) $join->id : null,
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'meta' => ['enabled' => true, 'total' => count($rows)]]);
    }

    public function enroll(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! MarketplaceSettings::enableProductDiscountJoin()) {
            return response()->json(['message' => 'Joining product discounts is disabled.', 'error' => 'disabled'], 422);
        }
        SellerDiscountJoinSchema::ensure();
        $rule = DiscountRule::query()
            ->whereNull('seller_id')
            ->where('apply_as', 'catalog')
            ->where('allow_sellers_to_join', true)
            ->find($id);
        if (! $rule) {
            return response()->json(['message' => 'Discount not found or not joinable.', 'error' => 'not_found'], 404);
        }
        $status = MarketplaceSettings::autoApproveDiscountJoin()
            ? MarketplaceDiscountJoin::STATUS_APPROVED
            : MarketplaceDiscountJoin::STATUS_PENDING;
        $join = MarketplaceDiscountJoin::query()->updateOrCreate(
            ['seller_id' => $seller->id, 'discount_rule_id' => $rule->id],
            ['status' => $status]
        );

        return response()->json([
            'message' => 'Enrolled in the product discount successfully.',
            'data' => [
                'id' => (int) $join->id,
                'discountRuleId' => (int) $rule->id,
                'status' => (string) $join->status,
            ],
        ], 201);
    }

    public function withdraw(int $id): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        SellerDiscountJoinSchema::ensure();
        $deleted = MarketplaceDiscountJoin::query()
            ->where('seller_id', $seller->id)
            ->where('discount_rule_id', $id)
            ->delete();
        if ($deleted < 1) {
            return response()->json(['message' => 'Join request not found.', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'message' => 'Withdrawn from the product discount.',
            'data' => ['discountRuleId' => $id, 'withdrawn' => true],
        ]);
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
