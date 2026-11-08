<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerPlanActivationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 */
class SellerPlansRestController extends Controller
{
    public function available(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! MarketplaceSettings::subscriptionPlansEnabled()) {
            return response()->json(['data' => [], 'meta' => ['enabled' => false]]);
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $current = $this->currentSubscription($seller);
        $rows = MarketplaceSubscriptionPlan::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (MarketplaceSubscriptionPlan $p) => $this->planRow($p, $current))
            ->values()
            ->all();

        $expiresIn = null;
        if ($current?->ends_at) {
            $expiresIn = max(0, (int) now()->startOfDay()->diffInDays($current->ends_at->copy()->startOfDay(), false));
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'enabled' => true,
                'current' => $current ? $this->subscriptionRow($current) : null,
                'subscriptionExpiresInDays' => ($expiresIn !== null && $expiresIn <= 9) ? $expiresIn : null,
                'paymentMethods' => [
                    ['code' => 'paypal', 'label' => 'PayPal', 'activates' => 'on_payment'],
                    ['code' => 'stripe', 'label' => 'Stripe', 'activates' => 'on_payment'],
                    ['code' => 'razorpay', 'label' => 'Razorpay', 'activates' => 'on_payment'],
                    ['code' => 'payu', 'label' => 'PayU', 'activates' => 'on_payment'],
                    ['code' => 'money_transfer', 'label' => 'Money Transfer', 'activates' => 'on_admin_approval'],
                    ['code' => 'free', 'label' => 'Free', 'activates' => 'immediate'],
                ],
            ],
        ]);
    }

    public function history(): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $rows = MarketplaceSellerSubscription::query()
            ->with('plan:id,name,code,price')
            ->where('seller_id', $seller->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MarketplaceSellerSubscription $s) => $this->subscriptionRow($s))
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! MarketplaceSettings::subscriptionPlansEnabled()) {
            return response()->json(['message' => 'Subscription plans are disabled.', 'error' => 'disabled'], 422);
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'payment_method' => ['nullable', 'string', Rule::in([
                'free', 'offline', 'money_transfer', 'paypal', 'stripe', 'razorpay', 'payu',
            ])],
            'accept_terms' => ['required', 'accepted'],
        ]);
        $plan = MarketplaceSubscriptionPlan::query()
            ->where('is_enabled', true)
            ->find((int) $data['plan_id']);
        if (! $plan) {
            return response()->json(['message' => 'Plan not found.', 'error' => 'not_found'], 404);
        }
        $method = (string) ($data['payment_method'] ?? ((float) $plan->price <= 0 ? 'free' : 'offline'));
        $isFree = (float) $plan->price <= 0 || $method === 'free' || $plan->is_default;
        if ($isFree && (float) $plan->price > 0 && ! $plan->is_default) {
            return response()->json(['message' => 'This plan requires payment.', 'error' => 'validation'], 422);
        }
        if ($plan->is_default || ((float) $plan->price <= 0 && $isFree)) {
            $hadActive = MarketplaceSellerSubscription::query()
                ->where('seller_id', $seller->id)
                ->whereIn('status', [
                    MarketplaceSellerSubscription::STATUS_ACTIVE,
                    MarketplaceSellerSubscription::STATUS_EXPIRED,
                    MarketplaceSellerSubscription::STATUS_CANCELLED,
                ])
                ->whereNotNull('starts_at')
                ->exists();
            if ($hadActive && $plan->is_default) {
                return response()->json([
                    'message' => 'The free plan is only available before your first active subscription.',
                    'error' => 'free_plan_used',
                ], 422);
            }
        }

        // Cancel pending purchases when buying again (parity).
        MarketplaceSellerSubscription::query()
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_PENDING)
            ->update(['status' => MarketplaceSellerSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

        $days = $plan->resolvedDurationDays();

        if ($isFree) {
            MarketplaceSellerSubscription::query()
                ->where('seller_id', $seller->id)
                ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
                ->update(['status' => MarketplaceSellerSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

            $sub = MarketplaceSellerSubscription::query()->create([
                'seller_id' => $seller->id,
                'plan_id' => $plan->id,
                'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
                'payment_method' => 'free',
                'price' => (float) $plan->price,
                'starts_at' => now(),
                'ends_at' => $days === null ? null : now()->addDays($days),
            ]);

            app(SellerPlanActivationService::class)->applyProductCap($seller, $plan);

            return response()->json([
                'message' => 'Plan activated.',
                'data' => $this->subscriptionRow($sub->load('plan:id,name,code,price')),
            ], 201);
        }

        if (in_array($method, ['paypal', 'stripe', 'razorpay', 'payu'], true)) {
            // Gateway plans stay pending until payment confirms (parity).
            $prefix = match ($method) {
                'stripe' => 'ST',
                'razorpay' => 'RZ',
                'payu' => 'PU',
                default => 'PP',
            };
            $token = $prefix.'-'.Str::upper(Str::random(16));
            $sub = MarketplaceSellerSubscription::query()->create([
                'seller_id' => $seller->id,
                'plan_id' => $plan->id,
                'status' => MarketplaceSellerSubscription::STATUS_PENDING,
                'payment_method' => $method,
                'price' => (float) $plan->price,
                'starts_at' => null,
                'ends_at' => null,
                'paypal_token' => $method === 'paypal' ? $token : null,
                'payment_token' => $token,
            ]);
            $confirmPath = match ($method) {
                'stripe' => '/api/v1/account/seller/plans/stripe/confirm',
                'razorpay' => '/api/v1/account/seller/plans/razorpay/confirm',
                'payu' => '/api/v1/account/seller/plans/payu/confirm',
                default => '/api/v1/account/seller/plans/paypal/return',
            };

            return response()->json([
                'message' => 'Complete '.$method.' payment to activate this plan.',
                'data' => array_merge($this->subscriptionRow($sub->load('plan:id,name,code,price')), [
                    'paymentToken' => $token,
                    'paypalToken' => $method === 'paypal' ? $token : null,
                    'approveUrl' => url($confirmPath.'?token='.$token),
                    'gateway' => $method,
                ]),
            ], 201);
        }

        // Offline / money transfer → pending admin approval.
        $sub = MarketplaceSellerSubscription::query()->create([
            'seller_id' => $seller->id,
            'plan_id' => $plan->id,
            'status' => MarketplaceSellerSubscription::STATUS_PENDING,
            'payment_method' => $method,
            'price' => (float) $plan->price,
            'starts_at' => null,
            'ends_at' => null,
        ]);

        return response()->json([
            'message' => 'Plan purchase pending admin approval.',
            'data' => $this->subscriptionRow($sub->load('plan:id,name,code,price')),
        ], 201);
    }

    /** PayPal return / confirm — activates pending paypal subscription (parity). */
    public function paypalConfirm(Request $request): JsonResponse
    {
        return $this->gatewayConfirm($request, 'paypal');
    }

    public function stripeConfirm(Request $request): JsonResponse
    {
        return $this->gatewayConfirm($request, 'stripe');
    }

    public function razorpayConfirm(Request $request): JsonResponse
    {
        return $this->gatewayConfirm($request, 'razorpay');
    }

    public function payuConfirm(Request $request): JsonResponse
    {
        return $this->gatewayConfirm($request, 'payu');
    }

    private function gatewayConfirm(Request $request, string $method): JsonResponse
    {
        $seller = $this->owner();
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        MarketplaceSubscriptionPlanSchema::ensure();
        $token = trim((string) ($request->input('token') ?? $request->query('token') ?? ''));
        if ($token === '') {
            return response()->json(['message' => 'Payment token required.', 'error' => 'validation'], 422);
        }
        $sub = MarketplaceSellerSubscription::query()
            ->with('plan')
            ->where('seller_id', $seller->id)
            ->where('payment_method', $method)
            ->where('status', MarketplaceSellerSubscription::STATUS_PENDING)
            ->where(function ($q) use ($token, $method) {
                $q->where('payment_token', $token);
                if ($method === 'paypal') {
                    $q->orWhere('paypal_token', $token);
                }
            })
            ->first();
        if (! $sub) {
            return response()->json(['message' => 'Pending '.$method.' subscription not found.', 'error' => 'not_found'], 404);
        }
        $result = app(SellerPlanActivationService::class)->activateOrSchedule($sub);
        $sub->update(['payment_token' => null, 'paypal_token' => null]);

        return response()->json([
            'message' => $result['scheduled']
                ? ucfirst($method).' payment received. Plan starts when your current plan ends.'
                : ucfirst($method).' plan activated.',
            'data' => array_merge(
                $this->subscriptionRow($sub->fresh()->load('plan:id,name,code,price')),
                ['productsDisabled' => $result['productsDisabled'], 'scheduled' => $result['scheduled'], 'gateway' => $method]
            ),
        ]);
    }

    /**
     * @return Seller|JsonResponse
     */
    private function owner(): Seller|JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $seller = Seller::query()->where('customer_id', $customer->id)->first();
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Seller shop not found.', 'error' => 'not_found'], 404);
        }

        return $seller;
    }

    private function currentSubscription(Seller $seller): ?MarketplaceSellerSubscription
    {
        return MarketplaceSellerSubscription::query()
            ->with('plan:id,name,code,price,billing_period,product_limit,max_staff')
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(MarketplaceSubscriptionPlan $plan, ?MarketplaceSellerSubscription $current): array
    {
        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'code' => (string) $plan->code,
            'description' => (string) ($plan->description ?? ''),
            'price' => (float) $plan->price,
            'billingPeriod' => (string) $plan->billing_period,
            'productLimit' => $plan->product_limit !== null ? (int) $plan->product_limit : null,
            'maxStaff' => $plan->max_staff !== null ? (int) $plan->max_staff : null,
            'allowedProductTypes' => is_array($plan->allowed_product_types)
                ? array_values($plan->allowed_product_types)
                : null,
            'isCurrent' => $current && (int) $current->plan_id === (int) $plan->id ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionRow(MarketplaceSellerSubscription $sub): array
    {
        return [
            'id' => (int) $sub->id,
            'planId' => (int) $sub->plan_id,
            'planName' => (string) ($sub->plan?->name ?? ''),
            'status' => (string) $sub->status,
            'paymentMethod' => (string) ($sub->payment_method ?? ''),
            'price' => (float) ($sub->price ?? $sub->plan?->price ?? 0),
            'startsAt' => optional($sub->starts_at)?->toIso8601String(),
            'endsAt' => optional($sub->ends_at)?->toIso8601String(),
            'createdAt' => optional($sub->created_at)?->toIso8601String(),
        ];
    }

}
