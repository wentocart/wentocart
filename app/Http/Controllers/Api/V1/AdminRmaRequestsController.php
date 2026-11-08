<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminRmaGraphql;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin REST Sales → RMA Requests REST (`/api/v1/console/rma/requests`).
 */
class AdminRmaRequestsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, min(50, (int) $request->input('per_page', $request->input('limit', 10))));
            $args = $request->all();
            $args['limit'] = 500;
            $all = AdminRmaGraphql::listReturns($args);
            $total = count($all);
            $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

            return response()->json([
                'data' => array_values($slice),
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'lastPage' => max(1, (int) ceil($total / $perPage)),
                    'total' => $total,
                ],
            ]);
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
            $row = AdminRmaGraphql::getReturn(['id' => $id]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($row === null) {
            return response()->json(['message' => 'Return not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($row);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $payload = $request->all();
        if (empty($payload['itemsJson']) && is_array($request->input('items'))) {
            $payload['itemsJson'] = json_encode($request->input('items'));
        }
        if (empty($payload['orderId']) && $request->filled('order_id')) {
            $payload['orderId'] = $request->input('order_id');
        }
        if (empty($payload['reasonId']) && $request->filled('rmaReasonId')) {
            $payload['reasonId'] = $request->input('rmaReasonId');
        }

        try {
            return response()->json(AdminRmaGraphql::createReturn($payload), 201);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(AdminRmaGraphql::updateStatus(array_merge(
                $request->all(),
                [
                    'id' => $id,
                    'statusId' => $request->input('statusId', $request->input('rmaStatusId', $request->input('status'))),
                ]
            )));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function reopen(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(AdminRmaGraphql::reopen(['id' => $id]));
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function returnableItems(int $orderId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json([
                'data' => AdminRmaGraphql::returnableItems(['orderId' => $orderId]),
            ]);
        } catch (UserError $e) {
            return $this->userError($e, 422);
        }
    }

    public function reasons(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json([
                'data' => AdminRmaGraphql::returnReasons($request->all()),
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function messages(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json([
                'data' => AdminRmaGraphql::listMessages(['returnId' => $id]),
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            return response()->json(AdminRmaGraphql::sendMessage(array_merge(
                $request->all(),
                ['returnId' => $id]
            )), 201);
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
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : ($status === 422 ? 'validation' : 'error'),
        ], $status);
    }
}
