<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminMarketingTemplates;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Marketing → Communications → Email Templates REST.
 */
class AdminMarketingTemplatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminMarketingTemplates::paginate($request->all()));
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
            $row = GraphqlAdminMarketingTemplates::find($id);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Email template not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminMarketingTemplates::create($request->all());
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }

        return response()->json($row, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminMarketingTemplates::update($id, $request->all());
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
            return response()->json(GraphqlAdminMarketingTemplates::delete($id));
        } catch (UserError $e) {
            return $this->userError($e, 404);
        }
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
            return response()->json(GraphqlAdminMarketingTemplates::bulkDelete($ids));
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
            str_contains(strtolower($msg), 'required')
                || str_contains(strtolower($msg), 'cannot')
                || str_contains(strtolower($msg), 'must be')
                || str_contains(strtolower($msg), 'provide at least')
                || str_contains(strtolower($msg), 'system email') => 422,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
