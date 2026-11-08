<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminDashboardStats;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminDashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        try {
            $row = AdminDashboardStats::stats([
                'type' => $request->input('type', 'overview'),
                'start' => $request->input('start'),
                'end' => $request->input('end'),
                'channel' => $request->input('storeId'),
            ], false);
        } catch (UserError $e) {
            $msg = $e->getMessage();
            $status = str_contains(strtolower($msg), 'unauthenticated') ? 401 : 400;

            return response()->json([
                'message' => $msg,
                'error' => $status === 401 ? 'unauthenticated' : 'invalid-type',
            ], $status);
        }
        return response()->json([$row]);
    }
}
