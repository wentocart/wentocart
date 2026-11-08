<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 */
class ShopCustomerTransactionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $orders = $customer->orders()
            ->with('paymentMethod')
            ->latest('id')
            ->limit(50)
            ->get();

        $rows = $orders->map(fn (Order $order) => $this->row($order))->values()->all();

        return response()->json($rows);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $customer->orders()->with('paymentMethod')->whereKey($id)->first();
        if (! $order) {
            return response()->json([
                'detail' => 'Customer transaction with ID "'.$id.'" not found',
                'message' => 'Customer transaction with ID "'.$id.'" not found',
            ], 404);
        }

        return response()->json($this->row($order));
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

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        $amount = (float) ($order->grand_total ?? $order->total ?? 0);
        $method = $order->paymentMethod;
        $code = (string) ($method?->code ?? $order->payment_method ?? 'payment');

        return [
            'id' => (int) $order->id,
            'transactionId' => (string) ($order->number ?? $order->id),
            'orderId' => (int) $order->id,
            'orderIncrementId' => (string) ($order->number ?? ''),
            'paymentMethod' => $code,
            'paymentTitle' => (string) ($method?->name ?? $code),
            'amount' => $amount,
            'formattedAmount' => Money::format($amount),
            'status' => (string) ($order->payment_status ?: $order->status ?: 'pending'),
            'createdAt' => optional($order->created_at)?->toIso8601String(),
            'updatedAt' => optional($order->updated_at)?->toIso8601String(),
        ];
    }
}
