<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 */
class AdminMarketplaceSubscriptionPlansRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = MarketplaceSubscriptionPlan::query()->orderBy('position')->orderBy('id');
        if ($request->query('enabled') !== null && $request->query('enabled') !== '') {
            $q->where('is_enabled', filter_var($request->query('enabled'), FILTER_VALIDATE_BOOLEAN));
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (MarketplaceSubscriptionPlan $row) => $this->row($row))
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
        MarketplaceSubscriptionPlanSchema::ensure();
        $row = MarketplaceSubscriptionPlan::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Plan not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        [$payload, $error] = $this->validated($request);
        if ($error) {
            return $error;
        }
        $row = MarketplaceSubscriptionPlan::query()->create($payload);

        return response()->json(['message' => 'Plan created.', 'data' => $this->row($row, true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $row = MarketplaceSubscriptionPlan::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Plan not found.', 'error' => 'not_found'], 404);
        }
        [$payload, $error] = $this->validated($request, $row);
        if ($error) {
            return $error;
        }
        $row->fill($payload)->save();

        return response()->json(['message' => 'Plan updated.', 'data' => $this->row($row->fresh() ?? $row, true)]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $row = MarketplaceSubscriptionPlan::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Plan not found.', 'error' => 'not_found'], 404);
        }
        if ($row->subscriptions()->exists()) {
            return response()->json(['message' => 'Plan has seller subscriptions; disable instead of deleting.', 'error' => 'validation'], 422);
        }
        $row->delete();

        return response()->json(['message' => 'Plan deleted.', 'data' => ['id' => $id]]);
    }

    public function subscriptionsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = MarketplaceSellerSubscription::query()
            ->with(['seller:id,shop_name,shop_slug', 'plan:id,name,code,price'])
            ->orderByDesc('id');
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, MarketplaceSellerSubscription::STATUSES, true)) {
            $q->where('status', $status);
        }
        $sellerId = (int) ($request->query('seller_id') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (MarketplaceSellerSubscription $s) => [
                'id' => (int) $s->id,
                'sellerId' => (int) $s->seller_id,
                'shopName' => (string) ($s->seller?->shop_name ?? ''),
                'planId' => (int) $s->plan_id,
                'planName' => (string) ($s->plan?->name ?? ''),
                'status' => (string) $s->status,
                'paymentMethod' => (string) ($s->payment_method ?? ''),
                'price' => (float) ($s->price ?? $s->plan?->price ?? 0),
                'startsAt' => optional($s->starts_at)?->toIso8601String(),
                'endsAt' => optional($s->ends_at)?->toIso8601String(),
                'createdAt' => optional($s->created_at)?->toIso8601String(),
            ])->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function subscriptionsApprove(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $sub = MarketplaceSellerSubscription::query()->with('plan')->find($id);
        if (! $sub) {
            return response()->json(['message' => 'Subscription not found.', 'error' => 'not_found'], 404);
        }
        if ($sub->status !== MarketplaceSellerSubscription::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending subscriptions can be approved.', 'error' => 'validation'], 422);
        }
        $result = app(\App\Domain\Marketplace\Services\SellerPlanActivationService::class)
            ->activateOrSchedule($sub);
        $fresh = $sub->fresh();

        return response()->json([
            'message' => $result['scheduled']
                ? 'Subscription approved and scheduled after the current plan.'
                : 'Subscription approved.',
            'data' => [
                'id' => (int) $sub->id,
                'status' => (string) $result['status'],
                'startsAt' => optional($fresh?->starts_at)?->toIso8601String(),
                'endsAt' => optional($fresh?->ends_at)?->toIso8601String(),
                'productsDisabled' => $result['productsDisabled'],
                'scheduled' => $result['scheduled'],
            ],
        ]);
    }

    public function subscriptionsCancel(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $sub = MarketplaceSellerSubscription::query()->with('plan')->find($id);
        if (! $sub) {
            return response()->json(['message' => 'Subscription not found.', 'error' => 'not_found'], 404);
        }
        if (! in_array($sub->status, [
            MarketplaceSellerSubscription::STATUS_PENDING,
            MarketplaceSellerSubscription::STATUS_ACTIVE,
            MarketplaceSellerSubscription::STATUS_SCHEDULED,
        ], true)) {
            return response()->json(['message' => 'Subscription cannot be cancelled.', 'error' => 'validation'], 422);
        }
        $sub->update([
            'status' => MarketplaceSellerSubscription::STATUS_CANCELLED,
            'ends_at' => now(),
        ]);
        try {
            $seller = Seller::query()->find($sub->seller_id);
            $email = (string) ($seller?->email ?? $seller?->contact_email ?? '');
            if ($email !== '') {
                app(\App\Domain\Settings\ShopMailer::class)->sendEvent('marketplace.subscription.cancelled', [
                    'plan' => (string) ($sub->plan?->name ?? ''),
                    'shop' => (string) ($seller?->shop_name ?? ''),
                ], $email);
            }
        } catch (\Throwable) {
            // ignore
        }

        return response()->json([
            'message' => 'Subscription cancelled.',
            'data' => ['id' => (int) $sub->id, 'status' => MarketplaceSellerSubscription::STATUS_CANCELLED],
        ]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $plan = MarketplaceSubscriptionPlan::query()->find($id);
        if (! $plan || ! $plan->is_enabled) {
            return response()->json(['message' => 'Plan not found or disabled.', 'error' => 'not_found'], 404);
        }
        $sellerId = (int) ($request->input('seller_id') ?? $request->input('sellerId') ?? 0);
        $seller = Seller::query()->find($sellerId);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'validation'], 422);
        }
        $resolved = $plan->resolvedDurationDays();
        $days = $request->filled('days')
            ? max(1, (int) $request->input('days'))
            : $resolved;
        MarketplaceSellerSubscription::query()
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->update(['status' => MarketplaceSellerSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

        $sub = MarketplaceSellerSubscription::query()->create([
            'seller_id' => $seller->id,
            'plan_id' => $plan->id,
            'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => $days === null ? null : now()->addDays($days),
        ]);

        $disabled = app(\App\Domain\Marketplace\Services\SellerPlanActivationService::class)
            ->applyProductCap($seller, $plan);

        return response()->json([
            'message' => 'Plan assigned to seller.',
            'data' => [
                'id' => (int) $sub->id,
                'sellerId' => (int) $seller->id,
                'planId' => (int) $plan->id,
                'status' => $sub->status,
                'startsAt' => optional($sub->starts_at)?->toIso8601String(),
                'endsAt' => optional($sub->ends_at)?->toIso8601String(),
                'productsDisabled' => $disabled,
            ],
        ], 201);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: JsonResponse|null}
     */
    private function validated(Request $request, ?MarketplaceSubscriptionPlan $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('marketplace_subscription_plans', 'code')->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_period' => ['required', Rule::in([
                MarketplaceSubscriptionPlan::PERIOD_MONTH,
                MarketplaceSubscriptionPlan::PERIOD_YEAR,
                MarketplaceSubscriptionPlan::PERIOD_LIFETIME,
            ])],
            'product_limit' => ['nullable', 'integer', 'min:0'],
            'order_limit' => ['nullable', 'integer', 'min:0'],
            'max_staff' => ['nullable', 'integer', 'min:0'],
            'allows_communication' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'allowed_product_types' => ['nullable', 'array'],
            'allowed_product_types.*' => ['string', 'max:40'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:80'],
            'commission_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ]);
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug((string) $data['name']);
        }
        if ($code === '') {
            $code = 'plan-'.Str::lower(Str::random(6));
        }

        $types = $data['allowed_product_types'] ?? null;
        if (is_array($types)) {
            $types = array_values(array_unique(array_filter(array_map(
                static fn ($t) => is_string($t) ? trim($t) : '',
                $types
            ))));
            if ($types === []) {
                $types = null;
            }
        }
        $perms = $data['permissions'] ?? null;
        if (is_array($perms)) {
            $perms = array_values(array_unique(array_filter(array_map(
                static fn ($t) => is_string($t) ? trim($t) : '',
                $perms
            ))));
            if ($perms === []) {
                $perms = null;
            }
        }

        return [[
            'name' => $data['name'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'price' => round((float) $data['price'], 2),
            'billing_period' => $data['billing_period'],
            'product_limit' => $data['product_limit'] ?? null,
            'order_limit' => $data['order_limit'] ?? null,
            'max_staff' => $data['max_staff'] ?? null,
            'allows_communication' => (bool) ($data['allows_communication'] ?? true),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'position' => (int) ($data['position'] ?? 0),
            'allowed_product_types' => $types,
            'permissions' => $perms,
            'commission_value' => array_key_exists('commission_value', $data)
                ? round((float) $data['commission_value'], 2)
                : null,
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'duration_days' => array_key_exists('duration_days', $data)
                ? ($data['duration_days'] !== null ? (int) $data['duration_days'] : null)
                : null,
        ], null];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MarketplaceSubscriptionPlan $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'code' => (string) $row->code,
            'price' => (float) $row->price,
            'billingPeriod' => (string) $row->billing_period,
            'productLimit' => $row->product_limit !== null ? (int) $row->product_limit : null,
            'orderLimit' => $row->order_limit !== null ? (int) $row->order_limit : null,
            'maxStaff' => $row->max_staff !== null ? (int) $row->max_staff : null,
            'allowsCommunication' => $row->allows_communication ? 1 : 0,
            'isEnabled' => $row->is_enabled ? 1 : 0,
            'position' => (int) $row->position,
            'allowedProductTypes' => is_array($row->allowed_product_types)
                ? array_values($row->allowed_product_types)
                : null,
            'permissions' => is_array($row->permissions) ? array_values($row->permissions) : null,
            'commissionValue' => $row->commission_value !== null ? (float) $row->commission_value : null,
            'isFeatured' => $row->is_featured ? 1 : 0,
            'isDefault' => $row->is_default ? 1 : 0,
            'durationDays' => $row->duration_days !== null ? (int) $row->duration_days : null,
        ];
        if ($detail) {
            $data['description'] = (string) ($row->description ?? '');
            $data['activeSubscriptions'] = (int) $row->subscriptions()
                ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
                ->count();
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
