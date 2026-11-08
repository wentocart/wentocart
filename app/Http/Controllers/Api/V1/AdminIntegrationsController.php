<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminIntegrations;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminIntegrationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::paginate($request->all()));
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
            $row = GraphqlAdminIntegrations::find($id);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Integration token not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::create($request->all()), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function updateAllowlist(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::updateAllowlist($id, $request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function updateRateLimits(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::updateRateLimits($id, $request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function regenerate(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::regenerate($id));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminIntegrations::revoke($id));
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
            str_contains(strtolower($msg), 'disabled') => 422,
            str_contains(strtolower($msg), 'already has') => 422,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 404 ? 'not_found' : 'validation'),
        ], $status);
    }
}
