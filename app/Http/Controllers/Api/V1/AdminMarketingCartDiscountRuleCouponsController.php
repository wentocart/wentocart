<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminMarketingCartDiscountRuleCoupons;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Marketing → Cart discount rule coupons.
 */
class AdminMarketingCartDiscountRuleCouponsController extends Controller
{
    public function index(Request $request, int $discountRuleId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminMarketingCartDiscountRuleCoupons::paginate($discountRuleId, $request->all()));
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function show(int $discountRuleId, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminMarketingCartDiscountRuleCoupons::find($discountRuleId, $id);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Coupon not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function store(Request $request, int $discountRuleId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminMarketingCartDiscountRuleCoupons::create($discountRuleId, $request->all()), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function destroy(int $discountRuleId, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminMarketingCartDiscountRuleCoupons::delete($discountRuleId, $id));
        } catch (UserError $e) {
            return $this->userError($e, 404);
        }
    }

    public function bulkDelete(Request $request, int $discountRuleId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            return response()->json(GraphqlAdminMarketingCartDiscountRuleCoupons::bulkDelete($discountRuleId, $ids));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function generate(Request $request, int $discountRuleId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminMarketingCartDiscountRuleCoupons::generate($discountRuleId, $request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    protected function guard(): ?JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }

    protected function userError(UserError $e, int $default = 403): JsonResponse
    {
        $msg = $e->getMessage();
        $status = match (true) {
            str_contains(strtolower($msg), 'unauthenticated') => 401,
            str_contains(strtolower($msg), 'not found') => 404,
            str_contains(strtolower($msg), 'forbidden') => 403,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
