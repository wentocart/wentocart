<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 */
class AdminMarketplaceCommissionRulesRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = MarketplaceCommissionRule::query()
            ->with(['seller:id,shop_name', 'product:id,sku', 'category:id'])
            ->orderByDesc('id');
        $scope = trim((string) ($request->query('scope') ?? ''));
        if (in_array($scope, ['product', 'seller', 'category', 'global'], true)) {
            $q->where('scope', $scope);
        }
        $enabled = $request->query('enabled') ?? $request->query('is_enabled');
        if ($enabled !== null && $enabled !== '') {
            $q->where('is_enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN));
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)->orWhere('notes', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (MarketplaceCommissionRule $row) => $this->row($row))
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
        $row = MarketplaceCommissionRule::query()
            ->with(['seller:id,shop_name', 'product:id,sku', 'category:id'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Commission rule not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        [$payload, $error] = $this->validatedPayload($request);
        if ($error) {
            return $error;
        }
        $row = MarketplaceCommissionRule::query()->create($payload);

        return response()->json(['message' => 'Commission rule created.', 'data' => $this->row($row->fresh(), true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = MarketplaceCommissionRule::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Commission rule not found.', 'error' => 'not_found'], 404);
        }
        [$payload, $error] = $this->validatedPayload($request);
        if ($error) {
            return $error;
        }
        $row->fill($payload)->save();

        return response()->json(['message' => 'Commission rule updated.', 'data' => $this->row($row->fresh(), true)]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = MarketplaceCommissionRule::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Commission rule not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Commission rule deleted.', 'data' => ['id' => $id]]);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: JsonResponse|null}
     */
    private function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in([
                MarketplaceCommissionRule::SCOPE_PRODUCT,
                MarketplaceCommissionRule::SCOPE_SELLER,
                MarketplaceCommissionRule::SCOPE_CATEGORY,
                MarketplaceCommissionRule::SCOPE_GLOBAL,
            ])],
            'type' => ['required', Rule::in([
                MarketplaceCommissionRule::TYPE_PERCENT,
                MarketplaceCommissionRule::TYPE_FIXED,
            ])],
            'value' => ['required', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['type'] === MarketplaceCommissionRule::TYPE_PERCENT && (float) $data['value'] > 100) {
            return [null, response()->json(['message' => 'Percent commission cannot exceed 100.', 'error' => 'validation'], 422)];
        }
        $payload = [
            'name' => $data['name'],
            'scope' => $data['scope'],
            'type' => $data['type'],
            'value' => round((float) $data['value'], 2),
            'product_id' => null,
            'seller_id' => null,
            'category_id' => null,
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'notes' => $data['notes'] ?? null,
        ];
        if ($data['scope'] === MarketplaceCommissionRule::SCOPE_PRODUCT) {
            if (empty($data['product_id'])) {
                return [null, response()->json(['message' => 'product_id is required for product scope.', 'error' => 'validation'], 422)];
            }
            $payload['product_id'] = (int) $data['product_id'];
        } elseif ($data['scope'] === MarketplaceCommissionRule::SCOPE_SELLER) {
            if (empty($data['seller_id'])) {
                return [null, response()->json(['message' => 'seller_id is required for seller scope.', 'error' => 'validation'], 422)];
            }
            $payload['seller_id'] = (int) $data['seller_id'];
        } elseif ($data['scope'] === MarketplaceCommissionRule::SCOPE_CATEGORY) {
            if (empty($data['category_id'])) {
                return [null, response()->json(['message' => 'category_id is required for category scope.', 'error' => 'validation'], 422)];
            }
            $payload['category_id'] = (int) $data['category_id'];
        }

        return [$payload, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MarketplaceCommissionRule $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'name' => (string) ($row->name ?? ''),
            'scope' => (string) ($row->scope ?? ''),
            'type' => (string) ($row->type ?? ''),
            'value' => (float) ($row->value ?? 0),
            'isEnabled' => $row->is_enabled ? 1 : 0,
            'sellerId' => $row->seller_id ? (int) $row->seller_id : null,
            'productId' => $row->product_id ? (int) $row->product_id : null,
            'categoryId' => $row->category_id ? (int) $row->category_id : null,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['notes'] = (string) ($row->notes ?? '');
            $data['shopName'] = (string) ($row->seller?->shop_name ?? '');
            $data['productSku'] = (string) ($row->product?->sku ?? '');
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
