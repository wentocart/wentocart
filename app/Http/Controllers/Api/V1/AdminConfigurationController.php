<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminConfiguration;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Configuration REST (menu / slugs / values / update).
 */
class AdminConfigurationController extends Controller
{
    public function menu(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminConfiguration::menu($request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 400);
        }
    }

    public function slugs(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(['data' => GraphqlAdminConfiguration::slugs()]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function values(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $slug = trim((string) $request->query('slug', ''));
        if ($slug === '') {
            return response()->json(['message' => 'slug is required.', 'error' => 'validation'], 400);
        }

        try {
            return response()->json(GraphqlAdminConfiguration::values($slug));
        } catch (UserError $e) {
            return $this->userError($e, 400);
        }
    }

    public function update(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminConfiguration::update($request->all()));
        } catch (UserError $e) {
            $msg = strtolower($e->getMessage());
            $default = str_contains($msg, 'escape') || str_contains($msg, 'prefix') || str_contains($msg, 'must start')
                ? 400
                : 422;

            return $this->userError($e, $default);
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
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 400 || $status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
