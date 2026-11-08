<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminGdprRequests;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST GDPR Requests REST surface.
 */
class AdminGdprRequestsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $rows = GraphqlAdminGdprRequests::list(
            (int) ($request->input('limit') ?: $request->input('per_page') ?: 50),
            $request->filled('status') ? (string) $request->input('status') : null,
        );

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $row = GraphqlAdminGdprRequests::find($id);
        if ($row === null) {
            return response()->json(['message' => 'GDPR request not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminGdprRequests::update(
                $id,
                (string) $request->input('status', ''),
                $request->filled('message') ? (string) $request->input('message') : null,
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json($row);
    }

    public function process(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminGdprRequests::process($id);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'id' => (int) ($row['_id'] ?? $row['id'] ?? $id),
            'requestId' => (int) ($row['requestId'] ?? $id),
            'customerId' => isset($row['customerId']) ? (int) $row['customerId'] : null,
            'type' => $row['type'] ?? null,
            'status' => $row['status'] ?? null,
            'customerDeleted' => (bool) ($row['customerDeleted'] ?? false),
            'processedAt' => $row['processedAt'] ?? null,
            'message' => $row['message'] ?? null,
            'success' => true,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminGdprRequests::delete($id);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json($row);
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

    protected function userError(UserError $e): JsonResponse
    {
        $message = $e->getMessage();
        $status = 422;
        if (str_contains(strtolower($message), 'not found')) {
            $status = 404;
        }

        return response()->json(['message' => $message, 'error' => 'gdpr_request_error'], $status);
    }
}
