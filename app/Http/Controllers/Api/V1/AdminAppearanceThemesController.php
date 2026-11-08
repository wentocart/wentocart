<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAppearanceThemes;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Appearance → Themes REST.
 */
class AdminAppearanceThemesController extends Controller
{
    public function index(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $data = GraphqlAdminAppearanceThemes::list();

            return response()->json([
                'data' => $data,
                'meta' => [
                    'total' => count($data),
                ],
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function show(string $code): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminAppearanceThemes::find($code);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Theme is not installed.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function impact(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceThemes::impact(array_merge(
                $request->all(),
                ['code' => $code]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function activate(Request $request, string $code): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceThemes::activate(array_merge(
                $request->all(),
                ['code' => $code]
            )));
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
            str_contains(strtolower($msg), 'not found') || str_contains(strtolower($msg), 'not installed') => 404,
            str_contains(strtolower($msg), 'forbidden') => 403,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
