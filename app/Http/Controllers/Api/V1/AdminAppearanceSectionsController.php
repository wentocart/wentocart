<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAppearanceSections;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Appearance → Sections REST.
 */
class AdminAppearanceSectionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $data = GraphqlAdminAppearanceSections::list($request->all());

            return response()->json([
                'data' => $data,
                'meta' => ['total' => count($data)],
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::one(array_merge(
                $request->all(),
                ['id' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::create($request->all()), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::update(array_merge(
                $request->all(),
                ['id' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::delete(array_merge(
                $request->all(),
                ['id' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function status(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::status(array_merge(
                $request->all(),
                ['id' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function reorder(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::reorder($request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function publish(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::publish($request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function discard(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::discard($request->all()));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::duplicate(array_merge(
                $request->all(),
                ['id' => $id]
            )), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function fields(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(GraphqlAdminAppearanceSections::fields(array_merge(
                $request->all(),
                ['id' => $id]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 404);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $data = GraphqlAdminAppearanceSections::list(array_merge($request->all(), ['preview' => true]));

            return response()->json([
                'data' => $data,
                'meta' => ['total' => count($data)],
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
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
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
