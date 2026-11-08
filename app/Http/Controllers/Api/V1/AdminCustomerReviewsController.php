<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminCustomerReviews;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Customer Reviews REST moderation surface.
 */
class AdminCustomerReviewsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminCustomerReviews::paginate($request->all()));
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminCustomerReviews::find($id, true);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Review not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminCustomerReviews::updateStatus(
                $id,
                (string) $request->input('status', ''),
                $request->filled('rejected_reason') ? (string) $request->input('rejected_reason') : null,
            );
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }

        return response()->json($row);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $snapshot = GraphqlAdminCustomerReviews::delete($id);
        } catch (UserError $e) {
            return $this->userError($e, 404);
        }

        return response()->json($snapshot);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }

        try {
            return response()->json(GraphqlAdminCustomerReviews::bulkDelete($ids));
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $value = (string) ($request->input('value') ?? $request->input('status') ?? '');

        try {
            return response()->json(GraphqlAdminCustomerReviews::bulkUpdateStatus($ids, $value));
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
            str_contains(strtolower($msg), 'status must') => 422,
            str_contains(strtolower($msg), 'forbidden') => 403,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
