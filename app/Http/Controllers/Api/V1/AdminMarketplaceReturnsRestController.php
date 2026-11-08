<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Services\SellerReturnService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 */
class AdminMarketplaceReturnsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerReturn::query()
            ->with(['seller:id,shop_name,shop_slug', 'order:id,number'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, SellerReturn::STATUSES, true)) {
            $q->where('status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('number', 'like', $like)
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', $like));
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerReturn $row) => $this->row($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerReturn::query()
            ->with(['seller:id,shop_name,shop_slug', 'order:id,number', 'items', 'sellerOrder'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller return not found.', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'data' => array_merge(
                $this->row($row, true),
                ['detail' => app(SellerReturnService::class)->present($row)],
            ),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, 'approve');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, 'reject');
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, 'receive');
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, 'refund');
    }

    private function act(Request $request, int $id, string $action): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerReturn::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller return not found.', 'error' => 'not_found'], 404);
        }
        $note = trim((string) ($request->input('note') ?? $request->input('admin_note') ?? ''));
        $adminId = Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null;
        $service = app(SellerReturnService::class);

        try {
            $fresh = match ($action) {
                'approve' => $service->approve($row, null, $adminId, $note !== '' ? $note : null),
                'reject' => $service->reject($row, null, $adminId, $note !== '' ? $note : null),
                'receive' => $service->markReceived($row, null, $adminId),
                'refund' => $service->refund($row, null, $adminId, $note !== '' ? $note : null),
                default => throw ValidationException::withMessages(['action' => 'Unknown action.']),
            };
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: $e->getMessage(),
                'error' => 'validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'return_error'], 422);
        }

        return response()->json([
            'message' => 'Return '.$action.'d.',
            'data' => $this->row($fresh->loadMissing(['seller:id,shop_name,shop_slug', 'order:id,number']), true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerReturn $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'number' => (string) ($row->number ?? ''),
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'orderId' => (int) ($row->order_id ?? 0),
            'orderNumber' => (string) ($row->order?->number ?? ''),
            'sellerOrderId' => (int) ($row->seller_order_id ?? 0),
            'status' => (string) ($row->status ?? ''),
            'refundTotal' => (float) ($row->refund_total ?? 0),
            'reason' => (string) ($row->reason ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['customerNote'] = (string) ($row->customer_note ?? '');
            $data['adminNote'] = (string) ($row->admin_note ?? '');
            $data['approvedAt'] = optional($row->approved_at)?->toIso8601String();
            $data['rejectedAt'] = optional($row->rejected_at)?->toIso8601String();
            $data['refundedAt'] = optional($row->refunded_at)?->toIso8601String();
        }

        return $data;
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
