<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Settings\OrderSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 */
class ShopCustomerOrderActionsController extends Controller
{
    public function cancel(Request $request, int $id, OrderStageService $stages): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $customer->orders()->whereKey($id)->first();
        if (! $order) {
            return response()->json([
                'detail' => 'Customer order with ID "'.$id.'" not found',
                'message' => 'Customer order with ID "'.$id.'" not found',
                'success' => false,
            ], 404);
        }

        if (! OrderSettings::buyerMayCancelStatus((string) $order->status)) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be canceled. It may have already been processed, shipped, or canceled',
                'orderId' => (int) $order->id,
                'status' => $this->mapStatus((string) $order->status),
            ]);
        }

        $stages->apply($order, 'cancelled', 'Cancelled by customer');
        $order->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Order has been canceled successfully',
            'orderId' => (int) $order->id,
            'status' => 'canceled',
        ]);
    }

    public function reorder(Request $request, int $id, CartService $carts): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $customer->orders()->with('items.product')->whereKey($id)->first();
        if (! $order) {
            return response()->json([
                'detail' => 'Customer order with ID "'.$id.'" not found',
                'message' => 'Customer order with ID "'.$id.'" not found',
                'success' => false,
            ], 404);
        }

        $cart = $carts->current($customer);
        $added = 0;
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->is_enabled) {
                continue;
            }
            try {
                $carts->add(
                    $cart,
                    $product,
                    max(1, (int) $item->qty),
                    is_array($item->customization) ? $item->customization : [],
                    [],
                    $item->subscription_plan_id ? (int) $item->subscription_plan_id : null,
                    is_array($item->gift_card_data) ? $item->gift_card_data : [],
                    $item->combination_id ? (int) $item->combination_id : null,
                    $item->booking_date?->toDateString(),
                    null,
                    null,
                    is_array($item->bundle_selection) ? $item->bundle_selection : null,
                    $item->booking_end_date?->toDateString(),
                    $item->rental_duration ? (int) $item->rental_duration : null,
                );
                $added++;
            } catch (\Throwable) {
                continue;
            }
        }

        return response()->json([
            'success' => $added > 0,
            'message' => $added > 0
                ? 'Items added to cart from order.'
                : 'No items could be added to the cart.',
            'orderId' => (int) $order->id,
            'itemsAdded' => $added,
            'cartId' => (int) $cart->id,
            'cartToken' => (string) ($cart->session_id ?: $cart->id),
        ], $added > 0 ? 201 : 400);
    }

    private function customer(Request $request): Customer
    {
        $user = $request->user('customer');
        if (! $user instanceof Customer) {
            abort(response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403));
        }

        return $user;
    }

    private function mapStatus(string $wento): string
    {
        return match (strtolower($wento)) {
            'cancelled' => 'canceled',
            'refunded' => 'closed',
            'delivered' => 'completed',
            default => strtolower($wento) ?: 'pending',
        };
    }
}
