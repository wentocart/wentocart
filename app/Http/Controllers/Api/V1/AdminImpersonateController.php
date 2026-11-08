<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminImpersonate;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminImpersonateController extends Controller
{
    public function __invoke(Request $request, int $customerId): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        try {
            $issued = GraphqlAdminImpersonate::issue($customerId);
        } catch (UserError $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 403;

            return response()->json(['message' => $message, 'error' => 'impersonate_error'], $status);
        }

        return response()->json([
            'token' => $issued['token'],
            'customerId' => (int) $issued['customerId'],
            'customerEmail' => $issued['customerEmail'],
            'customerName' => $issued['customerName'],
            'impersonatedByAdminId' => (int) $issued['impersonatedByAdminId'],
            'expiresAt' => $issued['expiresAt'],
            'success' => true,
            'message' => $issued['message'],
        ]);
    }
}
