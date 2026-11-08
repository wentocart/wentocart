<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminCustomerNotes;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET  /api/v1/console/customers/{id}/notes
 * POST /api/v1/console/customers/{id}/notes
 */
class AdminCustomerNotesController extends Controller
{
    public function index(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $page = GraphqlAdminCustomerNotes::paginate(
                $customerId,
                (int) $request->input('page', 1),
                (int) ($request->input('per_page') ?: $request->input('perPage') ?: 20),
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json($page);
    }

    public function store(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $note = GraphqlAdminCustomerNotes::create(
                $customerId,
                (string) ($request->input('note') ?? ''),
                (bool) ($request->input('customer_notified') ?? $request->input('customerNotified') ?? false),
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json($note, 201);
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
        $status = str_contains(strtolower($message), 'not found') ? 404 : 422;

        return response()->json(['message' => $message, 'error' => 'customer_note_error'], $status);
    }
}
