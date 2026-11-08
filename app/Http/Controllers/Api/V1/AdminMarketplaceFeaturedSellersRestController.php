<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerFeaturedSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceFeaturedSellersRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFeaturedSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = Seller::query()
            ->where('is_featured', true)
            ->where('status', Seller::STATUS_APPROVED)
            ->orderBy('featured_position')
            ->orderBy('shop_name');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Seller $s) => $this->row($s))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFeaturedSchema::ensure();
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $featured = $request->exists('is_featured') || $request->exists('isFeatured') || $request->exists('featured')
            ? filter_var($request->input('is_featured', $request->input('isFeatured', $request->input('featured'))), FILTER_VALIDATE_BOOLEAN)
            : (bool) $seller->is_featured;
        $position = $request->exists('featured_position') || $request->exists('featuredPosition') || $request->exists('position')
            ? max(0, (int) ($request->input('featured_position') ?? $request->input('featuredPosition') ?? $request->input('position')))
            : (int) ($seller->featured_position ?? 0);

        $seller->forceFill([
            'is_featured' => $featured,
            'featured_position' => $position,
        ])->save();

        return response()->json([
            'message' => 'Featured seller updated.',
            'data' => $this->row($seller->fresh() ?? $seller),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFeaturedSchema::ensure();
        $order = collect($request->input('order')  ?? [])
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->values()->all();
        if ($order === []) {
            return response()->json(['message' => 'order is required.', 'error' => 'validation'], 422);
        }
        foreach ($order as $i => $sellerId) {
            Seller::query()->whereKey($sellerId)->update([
                'is_featured' => true,
                'featured_position' => $i + 1,
            ]);
        }

        return response()->json([
            'message' => 'Featured sellers reordered.',
            'data' => ['count' => count($order)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Seller $seller): array
    {
        return [
            'id' => (int) $seller->id,
            'shopName' => (string) $seller->shop_name,
            'shopSlug' => (string) $seller->shop_slug,
            'isFeatured' => $seller->is_featured ? 1 : 0,
            'featuredPosition' => (int) ($seller->featured_position ?? 0),
            'status' => (string) $seller->status,
        ];
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
