<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Domain\Marketplace\SellerCommunicationsSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceCommunicationsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerCommunicationsSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerMessage::query()->with('seller:id,shop_name,shop_slug,communications_blocked_at')->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status === 'open') {
            $q->whereNull('reply_body');
        } elseif ($status === 'replied') {
            $q->whereNotNull('reply_body');
        } elseif ($status === 'unread') {
            $q->where('is_read', false);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('body', 'like', $like)
                    ->orWhere('reply_body', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerMessage $row) => $this->row($row))
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
        $row = SellerMessage::query()->with('seller:id,shop_name,shop_slug')->find($id);
        if (! $row) {
            return response()->json(['message' => 'Message not found.', 'error' => 'not_found'], 404);
        }
        if (! $row->is_read) {
            $row->update(['is_read' => true]);
            $row->refresh();
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerCommunicationsSchema::ensure();
        $row = SellerMessage::query()->with('seller')->find($id);
        if (! $row) {
            return response()->json(['message' => 'Message not found.', 'error' => 'not_found'], 404);
        }
        if ($row->seller?->communications_blocked_at) {
            return response()->json([
                'message' => 'You are blocked by admin',
                'error' => 'communications_blocked',
            ], 422);
        }
        $reply = trim((string) ($request->input('reply_body') ?? $request->input('body') ?? $request->input('message') ?? ''));
        if ($reply === '') {
            return response()->json(['message' => 'reply_body is required.', 'error' => 'validation'], 422);
        }
        if (mb_strlen($reply) > 4000) {
            return response()->json(['message' => 'reply_body is too long.', 'error' => 'validation'], 422);
        }
        $row->update([
            'reply_body' => $reply,
            'replied_at' => now(),
            'is_read' => true,
        ]);

        return response()->json([
            'message' => 'Reply saved.',
            'data' => $this->row($row->fresh(['seller:id,shop_name,shop_slug']) ?? $row, true),
        ]);
    }

    /** Note: Block Seller / Unblock Seller on a communications thread. */
    public function block(int $sellerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerCommunicationsSchema::ensure();
        $seller = Seller::query()->find($sellerId);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $seller->update(['communications_blocked_at' => now()]);

        return response()->json([
            'message' => 'Seller blocked from communications.',
            'data' => [
                'sellerId' => (int) $seller->id,
                'communicationsBlocked' => true,
                'blockedAt' => optional($seller->fresh()->communications_blocked_at)?->toIso8601String(),
            ],
        ]);
    }

    public function unblock(int $sellerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerCommunicationsSchema::ensure();
        $seller = Seller::query()->find($sellerId);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $seller->update(['communications_blocked_at' => null]);

        return response()->json([
            'message' => 'Seller unblocked from communications.',
            'data' => [
                'sellerId' => (int) $seller->id,
                'communicationsBlocked' => false,
            ],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerMessage::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Message not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Message deleted.', 'data' => ['id' => $id]]);
    }

    public function clearHistory(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $sellerId = (int) ($request->input('seller_id') ?? $request->input('sellerId') ?? $request->query('seller_id') ?? 0);
        if ($sellerId <= 0) {
            return response()->json(['message' => 'seller_id is required.', 'error' => 'validation'], 422);
        }
        $deleted = SellerMessage::query()->where('seller_id', $sellerId)->delete();

        return response()->json([
            'message' => 'Chat history cleared.',
            'data' => ['deleted' => (int) $deleted, 'sellerId' => $sellerId],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerMessage $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'name' => (string) ($row->name ?? ''),
            'email' => (string) ($row->email ?? ''),
            'preview' => mb_substr((string) ($row->body ?? ''), 0, 120),
            'status' => $row->reply_body ? 'replied' : 'open',
            'isRead' => $row->is_read ? 1 : 0,
            'communicationsBlocked' => $row->seller?->communications_blocked_at ? 1 : 0,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['body'] = (string) ($row->body ?? '');
            $data['replyBody'] = (string) ($row->reply_body ?? '');
            $data['repliedAt'] = optional($row->replied_at)?->toIso8601String();
            $data['customerId'] = $row->customer_id ? (int) $row->customer_id : null;
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
