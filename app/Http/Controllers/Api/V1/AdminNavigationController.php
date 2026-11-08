<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminNavigation;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Navigation REST: menu, permissions, profile.
 */
class AdminNavigationController extends Controller
{
    public function menu(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminNavigation::menu(false);
        } catch (UserError $e) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return response()->json([[
            'id' => $row['_id'],
            'tree' => $row['tree'],
        ]]);
    }

    public function permissions(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminNavigation::permissions();
        } catch (UserError $e) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return response()->json([[
            'id' => $row['_id'],
            'permissionType' => $row['permissionType'],
            'permissions' => $row['permissions'],
        ]]);
    }

    public function profile(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $row = GraphqlAdminNavigation::profile();
        } catch (UserError $e) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return response()->json($row);
    }

    protected function guard(): ?JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
