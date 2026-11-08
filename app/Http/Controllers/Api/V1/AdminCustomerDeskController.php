<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerDeskService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Wento Customer Desk REST:
 * GET /api/v1/console/customers/{id}/activity
 */
class AdminCustomerDeskController extends Controller
{
    public function show(Request $request, int $customerId, CustomerDeskService $desk): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }

        $limit = (int) ($request->input('limit') ?: 40);

        return response()->json($desk->timeline($customer, $limit));
    }

    protected function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('customers.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }
}
