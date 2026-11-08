<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceSettingsRestController extends Controller
{
    public function show(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->present(MarketplaceSettings::all())]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $payload = [];
        $map = [
            'enabled' => 'enabled',
            'status' => 'enabled',
            'default_commission' => 'default_commission',
            'defaultCommission' => 'default_commission',
            'adminCommissionPercentage' => 'default_commission',
            'auto_approve_products' => 'auto_approve_products',
            'autoApproveProducts' => 'auto_approve_products',
            'earning_release_mode' => 'earning_release_mode',
            'earningReleaseMode' => 'earning_release_mode',
            'return_window_days' => 'return_window_days',
            'returnWindowDays' => 'return_window_days',
            'cancel_warning_rate' => 'cancel_warning_rate',
            'cancelWarningRate' => 'cancel_warning_rate',
            'cancel_critical_rate' => 'cancel_critical_rate',
            'cancelCriticalRate' => 'cancel_critical_rate',
            'red_flag_limit' => 'red_flag_limit',
            'redFlagLimit' => 'red_flag_limit',
            'seller_flags_enabled' => 'seller_flags_enabled',
            'sellerFlagsEnabled' => 'seller_flags_enabled',
            'subscription_plans_enabled' => 'subscription_plans_enabled',
            'subscriptionPlansEnabled' => 'subscription_plans_enabled',
            'can_create_invoice' => 'can_create_invoice',
            'canCreateInvoice' => 'can_create_invoice',
            'can_create_shipment' => 'can_create_shipment',
            'canCreateShipment' => 'can_create_shipment',
            'can_cancel_order' => 'can_cancel_order',
            'canCancelOrder' => 'can_cancel_order',
            'can_view_customers' => 'can_view_customers',
            'canViewCustomers' => 'can_view_customers',
            'enable_minimum_order_amount' => 'enable_minimum_order_amount',
            'enableMinimumOrderAmount' => 'enable_minimum_order_amount',
            'approval_required' => 'approval_required',
            'approvalRequired' => 'approval_required',
            'show_publicly' => 'show_publicly',
            'showPublicly' => 'show_publicly',
            'max_upload_mb' => 'max_upload_mb',
            'maxUploadMb' => 'max_upload_mb',
            'maxFileUploadSize' => 'max_upload_mb',
            'seller_can_create_product' => 'seller_can_create_product',
            'sellerCanCreateProduct' => 'seller_can_create_product',
            'seller_can_assign_product' => 'seller_can_assign_product',
            'sellerCanAssignProduct' => 'seller_can_assign_product',
            'ai_allow_reference_images' => 'ai_allow_reference_images',
            'aiAllowReferenceImages' => 'ai_allow_reference_images',
            'ai_max_reference_images' => 'ai_max_reference_images',
            'aiMaxReferenceImages' => 'ai_max_reference_images',
            'ai_let_sellers_choose_fields' => 'ai_let_sellers_choose_fields',
            'aiLetSellersChooseFields' => 'ai_let_sellers_choose_fields',
            'enable_product_discount_join' => 'enable_product_discount_join',
            'enableProductDiscountJoin' => 'enable_product_discount_join',
            'auto_approve_discount_join' => 'auto_approve_discount_join',
            'autoApproveDiscountJoin' => 'auto_approve_discount_join',
            'low_stock_threshold' => 'low_stock_threshold',
            'lowStockThreshold' => 'low_stock_threshold',
            'enable_stepped_product_form' => 'enable_stepped_product_form',
            'enableSteppedProductForm' => 'enable_stepped_product_form',
            'stepped_form_navigation' => 'stepped_form_navigation',
            'steppedFormNavigation' => 'stepped_form_navigation',
            'show_progress_bar' => 'show_progress_bar',
            'showProgressBar' => 'show_progress_bar',
        ];
        foreach ($map as $input => $key) {
            if ($request->exists($input)) {
                $payload[$key] = $request->input($input);
            }
        }
        if ($payload === []) {
            return response()->json(['message' => 'No settings provided.', 'error' => 'validation'], 422);
        }
        if (isset($payload['default_commission'])) {
            $rate = (float) $payload['default_commission'];
            if ($rate < 0 || $rate > 100) {
                return response()->json(['message' => 'default_commission must be 0–100.', 'error' => 'validation'], 422);
            }
        }

        MarketplaceSettings::save($payload);

        return response()->json([
            'message' => 'Marketplace settings saved.',
            'data' => $this->present(MarketplaceSettings::all()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $all
     * @return array<string, mixed>
     */
    private function present(array $all): array
    {
        return [
            'enabled' => (bool) $all['enabled'],
            'defaultCommission' => (float) $all['default_commission'],
            'autoApproveProducts' => (bool) $all['auto_approve_products'],
            'earningReleaseMode' => (string) $all['earning_release_mode'],
            'returnWindowDays' => (int) $all['return_window_days'],
            'cancelWarningRate' => (float) $all['cancel_warning_rate'],
            'cancelCriticalRate' => (float) $all['cancel_critical_rate'],
            'redFlagLimit' => (int) $all['red_flag_limit'],
            'sellerFlagsEnabled' => (bool) $all['seller_flags_enabled'],
            'subscriptionPlansEnabled' => (bool) $all['subscription_plans_enabled'],
            'canCreateInvoice' => (bool) $all['can_create_invoice'],
            'canCreateShipment' => (bool) $all['can_create_shipment'],
            'canCancelOrder' => (bool) $all['can_cancel_order'],
            'canViewCustomers' => (bool) $all['can_view_customers'],
            'enableMinimumOrderAmount' => (bool) $all['enable_minimum_order_amount'],
            'approvalRequired' => (bool) $all['approval_required'],
            'showPublicly' => (bool) $all['show_publicly'],
            'maxUploadMb' => (int) $all['max_upload_mb'],
            'sellerCanCreateProduct' => (bool) $all['seller_can_create_product'],
            'sellerCanAssignProduct' => (bool) $all['seller_can_assign_product'],
            'aiAllowReferenceImages' => (bool) $all['ai_allow_reference_images'],
            'aiMaxReferenceImages' => (int) $all['ai_max_reference_images'],
            'aiLetSellersChooseFields' => (bool) $all['ai_let_sellers_choose_fields'],
            'enableProductDiscountJoin' => (bool) $all['enable_product_discount_join'],
            'autoApproveDiscountJoin' => (bool) $all['auto_approve_discount_join'],
            'lowStockThreshold' => (int) $all['low_stock_threshold'],
            'enableSteppedProductForm' => (bool) $all['enable_stepped_product_form'],
            'steppedFormNavigation' => (string) $all['stepped_form_navigation'],
            'showProgressBar' => (bool) $all['show_progress_bar'],
        ];
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
