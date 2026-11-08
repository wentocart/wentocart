<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\ReviewService;
use App\Domain\Order\Models\Order;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Order\OrderStageService;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        protected CatalogPresenter $present,
        protected ReviewService $reviews,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $orders = $customer->orders()->with('items')->latest()->limit(5)->get();

        return response()->json([
            'customer' => $this->presentCustomer($customer),
            'stats' => [
                'orders' => $customer->orders()->count(),
                'wishlist' => $customer->wishlist()->count(),
                'addresses' => $customer->addresses()->count(),
                'reviews' => Review::query()->where('customer_id', $customer->id)->count(),
                'store_credit' => app(\App\Domain\Customer\Services\StoreCreditService::class)->balance($customer),
            ],
            'recent_orders' => $orders,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        if (! CustomerSettings::allowProfileEditing()) {
            abort(403, 'Profile editing is disabled.');
        }

        $customer = $request->user('customer');
        if ($request->exists('phone')) {
            $request->merge(['phone' => CustomerSettings::normalizePhone($request->input('phone'))]);
        }
        if (CustomerSettings::mobileLoginEnabled() && $request->exists('phone') && filled($request->input('phone'))) {
            $canonical = CustomerSettings::canonicalPhone($request->input('phone'));
            if ($canonical === null) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number with country code (e.g. +15551234567).']);
            }
            $request->merge(['phone' => $canonical]);
        }
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                Rule::unique('customers', 'email')->where(fn ($q) => $q->where('store_id', $customer->store_id))->ignore($customer->id),
            ],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'newsletter' => CustomerSettings::newsletterOptIn() ? ['nullable', 'boolean'] : ['prohibited'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => CustomerPassword::rules(required: false),
        ], [
            'phone' => CustomerSettings::mobileLoginEnabled()
                ? ['nullable', 'string', 'max:40', Rule::unique('customers', 'phone')->where(fn ($q) => $q->where('store_id', $customer->store_id))->ignore($customer->id)]
                : CustomerSettings::phoneRules(),
            'phone_token' => ['nullable', 'string', 'max:80'],
            'date_of_birth' => match (CustomerSettings::dateOfBirthMode()) {
                'hidden' => ['prohibited'],
                'required' => ['required', 'date'],
                default => ['nullable', 'date'],
            },
        ]), CustomerPassword::messages());

        if (! empty($data['password'])) {
            if (! Hash::check((string) $data['current_password'], $customer->password)) {
                abort(422, 'Current password is incorrect.');
            }
            $customer->password = $data['password'];
            $customer->remember_token = Str::random(60);
        }

        unset($data['current_password'], $data['password'], $data['password_confirmation']);
        if (CustomerSettings::mobileLoginEnabled() && array_key_exists('phone', $data) && (string) ($data['phone'] ?? '') !== (string) ($customer->phone ?? '')) {
            $proof = app(\App\Domain\Auth\SmsOtp::class)->consumeProof((string) ($data['phone_token'] ?? ''), 'profile');
            if ($proof === null || $proof !== (string) $data['phone']) {
                throw ValidationException::withMessages(['phone' => 'Verify your new mobile number with the SMS code first.']);
            }
        }
        unset($data['phone_token']);
        if (CustomerSettings::phoneMode() === 'hidden') {
            unset($data['phone']);
        }
        if (CustomerSettings::dateOfBirthMode() === 'hidden') {
            unset($data['date_of_birth']);
        }
        if (! CustomerSettings::newsletterOptIn()) {
            unset($data['newsletter']);
        }
        $customer->fill($data)->save();
        if (! empty($data['password'])) {
            SessionRevoker::revokeOthers('customer', $customer->id);
        }

        return response()->json(['customer' => $this->presentCustomer($customer->fresh())]);
    }

    public function destroyAccount(Request $request): JsonResponse
    {
        if (! CustomerSettings::allowAccountDeletion()) {
            abort(403, 'Account deletion is disabled.');
        }

        $customer = $request->user('customer');
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);
        $hashed = (string) ($customer->password ?? '');
        if ($hashed === '' || ! Hash::check($data['password'], $hashed)) {
            throw ValidationException::withMessages(['password' => 'Current password is incorrect.']);
        }

        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        app(\App\Domain\Customer\Services\CustomerAnonymizer::class)->erase($customer);

        return response()->json(['ok' => true]);
    }

    public function giftCards(Request $request, \App\Domain\Catalog\Services\GiftCardService $giftCards): JsonResponse
    {
        return response()->json([
            'gift_cards' => $giftCards->walletFor($request->user('customer')),
        ]);
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $adminId = (int) $request->session()->get('impersonator_admin_id', 0);
        if ($adminId < 1) {
            abort(403, 'Not impersonating.');
        }
        \App\Domain\Auth\Models\AdminImpersonation::query()
            ->where('admin_id', $adminId)
            ->where('customer_id', $request->user('customer')?->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
        \Illuminate\Support\Facades\Auth::guard('customer')->logout();
        $request->session()->forget('impersonator_admin_id');
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
    }

    public function export(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $customer->load(['addresses', 'orders.items', 'wishlist']);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'customer' => $this->presentCustomer($customer),
            'addresses' => $customer->addresses,
            'orders' => $customer->orders->map(fn ($order) => [
                'number' => $order->number,
                'status' => $order->status,
                'grand_total' => $order->grand_total,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'items' => $order->items->map(fn ($item) => [
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'qty' => $item->qty,
                    'total' => $item->total,
                ])->values()->all(),
            ])->values()->all(),
            'wishlist_ids' => $customer->wishlist->pluck('id')->values()->all(),
            'subscriptions' => \App\Domain\Subscription\Models\CustomerSubscription::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->get(['id', 'status', 'next_renewal_at', 'product_id', 'subscription_plan_id']),
        ]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $customerId = (int) $request->user('customer')->id;
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)
            ->syncSignupOrdersForCustomer($customerId);

        $rows = \App\Domain\Subscription\Models\CustomerSubscription::query()
            ->where('customer_id', $customerId)
            ->with(['plan.translations', 'product.translations', 'order', 'orderItem', 'lastRenewalOrder'])
            ->latest('id')
            ->get();

        $plansByProduct = $this->availablePlansByProduct($rows->pluck('product_id')->unique()->filter()->all());

        return response()->json([
            'subscriptions' => $rows->map(fn ($row) => $this->presentAccountSubscription(
                $row,
                $plansByProduct[(int) $row->product_id] ?? [],
            ))->values()->all(),
        ]);
    }

    public function subscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        $row->loadMissing(['plan.translations', 'product.translations', 'order', 'orderItem', 'lastRenewalOrder']);
        $plans = $this->availablePlansByProduct([(int) $row->product_id])[(int) $row->product_id] ?? [];

        return response()->json(['subscription' => $this->presentAccountSubscription($row, $plans)]);
    }

    public function cancelSubscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        $atPeriodEnd = $request->boolean('at_period_end')
            || str_ends_with(rtrim($request->path(), '/'), 'cancel-at-period-end');
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->cancel($row, $atPeriodEnd);

        return response()->json(['ok' => true]);
    }

    public function resumeCancelAtPeriodEnd(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->resumeCancelAtPeriodEnd($row);

        return response()->json(['ok' => true]);
    }

    public function pauseSubscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->pause($row);

        return response()->json(['ok' => true]);
    }

    public function resumeSubscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->resume($row);

        return response()->json(['ok' => true]);
    }

    public function skipSubscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->skip($row);

        return response()->json(['ok' => true]);
    }

    public function reactivateSubscription(Request $request, int $id): JsonResponse
    {
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->reactivate($row);

        return response()->json(['ok' => true]);
    }

    public function changeSubscriptionPlan(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);
        $row = $this->customerSubscription($request, $id);
        app(\App\Domain\Subscription\Services\SubscriptionBilling::class)
            ->changePlan($row, (int) $data['subscription_plan_id']);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  list<int|string>  $productIds
     * @return array<int, list<array<string, mixed>>>
     */
    protected function availablePlansByProduct(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        $grouped = [];
        $links = \App\Domain\Subscription\Models\ProductSubscriptionPlan::query()
            ->whereIn('product_id', $ids)
            ->where('is_enabled', true)
            ->with('plan.translations')
            ->orderBy('position')
            ->get();

        foreach ($links as $link) {
            if (! $link->plan || ! $link->plan->is_enabled) {
                continue;
            }
            $grouped[(int) $link->product_id][] = [
                'id' => (int) $link->subscription_plan_id,
                'name' => $link->plan->translation()?->name ?: $link->plan->code,
                'price' => (float) $link->price,
            ];
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>
     */
    protected function presentAccountSubscription(\App\Domain\Subscription\Models\CustomerSubscription $row, array $plans): array
    {
        $plan = $row->plan;
        $product = $row->product;
        $productTrans = $product?->translation();
        $order = $row->order;
        $item = $row->orderItem;
        $renewal = $row->lastRenewalOrder;
        $renewalOrders = $row->renewalOrders()->map(fn (\App\Domain\Order\Models\Order $order) => [
            'id' => (int) $order->id,
            'number' => $order->number,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'payment_status' => $order->payment_status,
            'status' => $order->status,
            'grand_total' => $order->grand_total !== null ? (float) $order->grand_total : null,
            'currency_code' => $order->currency_code,
            'kind' => 'renewal',
        ])->values()->all();
        $invoiceOrders = [];
        if ($order) {
            $invoiceOrders[] = [
                'id' => (int) $order->id,
                'number' => $order->number,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'payment_status' => $order->payment_status,
                'status' => $order->status,
                'grand_total' => $order->grand_total !== null ? (float) $order->grand_total : null,
                'currency_code' => $order->currency_code,
                'kind' => 'original',
            ];
        }
        $invoiceOrders = array_merge($invoiceOrders, $renewalOrders);
        $svc = app(\App\Domain\Subscription\Services\SubscriptionService::class);

        $assignment = collect($plans)->firstWhere('id', (int) $row->subscription_plan_id);
        $price = $assignment['price'] ?? null;
        if ($price === null && $item && $item->unit_price !== null) {
            $price = (float) $item->unit_price;
        }

        $payUrl = $row->last_pending_action_url;
        if (! $payUrl && $row->status === \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PAST_DUE && $renewal) {
            $payUrl = rtrim((string) config('app.url'), '/').'/account/orders/'.$renewal->number;
        }
        if (! $payUrl && $row->status === \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PENDING && $order?->number) {
            $payUrl = rtrim((string) config('app.url'), '/').'/account/orders/'.$order->number;
        }

        $manageable = $row->isManageable();
        $cycle = max(1, (int) ($plan?->cycle ?? 1));
        $frequency = (string) ($plan?->frequency ?: 'month');
        $paymentStatus = $this->presentSubscriptionPaymentStatus($row, $order);

        return [
            'id' => (int) $row->id,
            'status' => $row->status,
            'payment_status' => $paymentStatus,
            'on_trial' => $row->onTrial(),
            'price' => $price !== null ? (float) $price : null,
            'currency_code' => $order?->currency_code,
            'qty' => $item?->qty !== null ? (int) $item->qty : null,
            'billing_summary' => $plan ? $svc->billingLabel($cycle, $frequency) : null,
            'frequency' => $plan?->frequency,
            'cycle' => $plan ? (int) $plan->cycle : null,
            'trial_summary' => $plan ? $svc->trialSummary($plan) : null,
            'duration_summary' => $plan ? $svc->durationSummary($plan) : null,
            'next_renewal_at' => optional($row->next_renewal_at)?->toIso8601String(),
            'started_at' => optional($row->started_at)?->toIso8601String(),
            'trial_ends_at' => optional($row->trial_ends_at)?->toIso8601String(),
            'cancelled_at' => optional($row->cancelled_at)?->toIso8601String(),
            'ended_at' => optional($row->ended_at)?->toIso8601String(),
            'cancel_at_period_end' => (bool) $row->cancel_at_period_end,
            'cycles_completed' => (int) $row->cycles_completed,
            'dunning_attempts' => (int) $row->dunning_attempts,
            'plan' => $plan?->translation()?->name ?: $plan?->code,
            'plan_id' => (int) $row->subscription_plan_id,
            'product' => $productTrans?->name ?: $product?->sku,
            'product_id' => (int) $row->product_id,
            'product_slug' => $productTrans?->slug,
            'product_cover_url' => \App\Domain\Catalog\Support\CatalogMediaUrl::url($product?->cover_path),
            'order_id' => $order ? (int) $order->id : null,
            'order_number' => $order?->number,
            'order_created_at' => optional($order?->created_at)?->toIso8601String(),
            'last_renewal_order_id' => $renewal ? (int) $renewal->id : null,
            'last_renewal_order_number' => $renewal?->number,
            'renewal_orders' => $renewalOrders,
            'invoice_orders' => $invoiceOrders,
            'last_renewal_error' => $row->last_renewal_error,
            'pay_url' => $payUrl,
            'available_plans' => $plans,
            'can_pause' => in_array($row->status, [
                \App\Domain\Subscription\Models\CustomerSubscription::STATUS_ACTIVE,
                \App\Domain\Subscription\Models\CustomerSubscription::STATUS_TRIALING,
            ], true) && ! $row->cancel_at_period_end,
            'can_resume' => $row->status === \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PAUSED,
            'can_skip' => in_array($row->status, [
                \App\Domain\Subscription\Models\CustomerSubscription::STATUS_ACTIVE,
                \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PAUSED,
                \App\Domain\Subscription\Models\CustomerSubscription::STATUS_TRIALING,
            ], true),
            'can_cancel' => $manageable && ! $row->cancel_at_period_end,
            'can_cancel_at_period_end' => $manageable && ! $row->cancel_at_period_end,
            'can_resume_period_end' => $manageable && $row->cancel_at_period_end,
            'can_reactivate' => $row->canReactivate(),
            'can_change_plan' => $manageable && count($plans) > 1,
        ];
    }

    protected function presentSubscriptionPaymentStatus(
        \App\Domain\Subscription\Models\CustomerSubscription $row,
        ?\App\Domain\Order\Models\Order $order,
    ): string {
        if ($row->status === \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PENDING) {
            return 'pending';
        }
        if ($row->status === \App\Domain\Subscription\Models\CustomerSubscription::STATUS_PAST_DUE) {
            return 'failed';
        }
        $pay = $order?->payment_status;
        if (in_array($pay, ['failed', 'refunded'], true)) {
            return 'failed';
        }
        if ($pay === 'paid') {
            return 'paid';
        }

        return 'pending';
    }

    protected function customerSubscription(Request $request, int $id): \App\Domain\Subscription\Models\CustomerSubscription
    {
        return \App\Domain\Subscription\Models\CustomerSubscription::query()
            ->where('customer_id', $request->user('customer')->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    protected function accountOrderByNumber(Request $request, string $number): \App\Domain\Order\Models\Order
    {
        return $request->user('customer')->orders()
            ->where('number', $number)
            ->when($request->integer('id') > 0, fn ($query) => $query->whereKey($request->integer('id')))
            ->orderBy('id')
            ->firstOrFail();
    }

    public function orders(Request $request): JsonResponse
    {
        $perPage = max(1, min(50, (int) $request->integer('per_page', 10)));

        return response()->json([
            'orders' => $request->user('customer')->orders()
                ->with(['items.product', 'paymentMethod'])
                ->latest()
                ->paginate($perPage),
        ]);
    }

    public function preorders(Request $request, PreorderService $preorders): JsonResponse
    {
        $perPage = max(1, min(50, (int) $request->integer('per_page', 10)));

        return response()->json([
            'preorders' => $preorders->paginateForCustomer($request->user('customer'), $perPage),
        ]);
    }

    public function payPreorder(Request $request, int $id, PreorderService $preorders): JsonResponse
    {
        $result = $preorders->beginRemainingPayment($request->user('customer'), $id);

        return response()->json($result);
    }

    public function order(Request $request, string $number): JsonResponse
    {
        $locale = app()->getLocale();
        $order = $request->user('customer')->orders()
            ->with([
                'items.product.translations',
                'items.product.brand.translations',
                'items.product.seller',
                'items.product.attachments',
                'items.downloads.attachment',
                'items.downloads.product.translations',
                'sellerOrders.seller',
                'sellerOrders.items',
                'sellerOrders.shipments.items',
                'shipments.seller',
                'paymentMethod',
                'shippingMethod',
                'statusHistories',
                'checkoutGroup.orders.shippingMethod',
            ])
            ->where('number', $number)
            ->when($request->integer('id') > 0, fn ($query) => $query->whereKey($request->integer('id')))
            ->orderBy('id')
            ->firstOrFail();

        Gate::forUser($request->user('customer'))->authorize('view', $order);

        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        $orderPayload = $order->toArray();
        $orderPayload['can_cancel'] = OrderSettings::buyerMayCancelStatus((string) $order->status);
        $orderPayload['can_invoice'] = app(\App\Domain\Order\InvoiceDocument::class)->isAvailable($order);
        $preorders = app(PreorderService::class);
        $downloadService = app(\App\Domain\Order\Services\OrderDownloadService::class);
        // Load by order_id — order_item_id can drift if lines were rebuilt after issue.
        $orderDownloads = $downloadService->presentForOrder($order);
        $downloadsByProduct = collect($orderDownloads)->groupBy(fn (array $row) => (int) data_get($row, 'product.id'));
        $downloadsByItem = collect($orderDownloads)->groupBy(fn (array $row) => (int) data_get($row, 'order_item.id'));

        $orderPayload['items'] = $order->items->map(function ($item) use ($locale, $order, $preorders, $downloadService, $downloadsByProduct, $downloadsByItem) {
            $row = $item->toArray();
            $row['product'] = $item->product
                ? $this->present->product($item->product, $locale)
                : null;
            if (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                $row['preorder'] = $preorders->settleLine($item);
            }
            $row['returned_qty'] = (int) ($item->returned_qty ?? 0);
            $row['returnable_qty'] = $item->returnableQty();
            $virtual = in_array((string) ($item->product?->type ?: ''), \App\Domain\Order\Services\OrderDownloadService::VIRTUAL_TYPES, true);
            $row['is_virtual'] = $virtual;
            $row['downloads'] = [];
            if ($downloadService->orderAllowsDownloads($order) && $virtual && $item->product) {
                $byItem = $downloadsByItem->get((int) $item->id, collect());
                $byProduct = $downloadsByProduct->get((int) $item->product_id, collect());
                // Prefer exact line match; fall back to product match for rebuilt order lines.
                $row['downloads'] = ($byItem->isNotEmpty() ? $byItem : $byProduct)->values()->all();
            }

            return $row;
        })->values()->all();

        $orderPayload['downloads'] = $orderDownloads;
        $orderPayload['modules'] = [
            'detail' => ModuleBuses::collectSlots('storefront.order.detail', [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
            ]),
        ];

        $orderPayload['checkout_reference'] = $order->checkout_reference;
        $orderPayload['checkout_orders'] = $order->checkout_group_id
            ? $order->checkoutGroup?->orders()->with('shippingMethod')->orderBy('id')->get()->map(fn ($row) => [
                'id' => (int) $row->id,
                'number' => $row->number,
                'shipping_method' => $row->shippingMethod?->name,
                'shipping_total' => (float) $row->shipping_total,
                'grand_total' => (float) $row->grand_total,
                'status' => $row->status,
                'is_current' => (int) $row->id === (int) $order->id,
            ])->values()->all()
            : [];

        return response()->json([
            'order' => $orderPayload,
            'seller_orders' => $order->sellerOrders->map(static function ($sellerOrder) use ($fulfillment) {
                $active = $sellerOrder->shipments
                    ->where('status', '!=', \App\Domain\Shipping\Models\Shipment::STATUS_CANCELLED)
                    ->sortByDesc('id')
                    ->first();

                return [
                    'id' => (int) $sellerOrder->id,
                    'seller_id' => (int) $sellerOrder->seller_id,
                    'shop_name' => (string) ($sellerOrder->seller?->shop_name ?: 'Seller'),
                    'shop_slug' => $sellerOrder->seller?->shop_slug,
                    'status' => (string) $sellerOrder->status,
                    'subtotal' => (float) $sellerOrder->subtotal,
                    'tax_total' => (float) $sellerOrder->tax_total,
                    'discount_total' => (float) $sellerOrder->discount_total,
                    'shipping_total' => (float) $sellerOrder->shipping_total,
                    'grand_total' => (float) $sellerOrder->grand_total,
                    'item_count' => $sellerOrder->items->count(),
                    'items' => $sellerOrder->items->map(static fn ($item) => [
                        'id' => (int) $item->id,
                        'order_item_id' => $item->order_item_id ? (int) $item->order_item_id : null,
                        'name' => (string) $item->name,
                        'sku' => $item->sku,
                        'qty' => (int) $item->qty,
                        'returned_qty' => (int) ($item->returned_qty ?? 0),
                        'cancelled_qty' => (int) ($item->cancelled_qty ?? 0),
                        'returnable_qty' => $item->returnableQty(),
                        'total' => (float) $item->total,
                    ])->values()->all(),
                    'shipment' => $active ? $fulfillment->presentShipment($active) : null,
                ];
            })->values()->all(),
            'shipments' => $order->shipments
                ->where('status', '!=', \App\Domain\Shipping\Models\Shipment::STATUS_CANCELLED)
                ->map(fn ($shipment) => $fulfillment->presentShipment($shipment))
                ->values()
                ->all(),
            'returns' => collect()
                ->merge(
                    \App\Domain\Marketplace\Models\SellerReturn::query()
                        ->where('order_id', $order->id)
                        ->with('items')
                        ->latest('id')
                        ->get()
                        ->map(fn ($row) => app(\App\Domain\Marketplace\Services\SellerReturnService::class)->present($row))
                )
                ->merge(
                    \App\Domain\Order\Models\ShopReturn::query()
                        ->where('order_id', $order->id)
                        ->with('items')
                        ->latest('id')
                        ->get()
                        ->map(fn ($row) => app(\App\Domain\Order\Services\ShopReturnService::class)->present($row))
                )
                ->values()
                ->all(),
            'eu_withdrawals' => (function () use ($order) {
                \App\Domain\Order\EuWithdrawalSchema::ensure();

                return \App\Domain\Order\Models\EuWithdrawalRequest::query()
                    ->where('order_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn ($row) => app(\App\Domain\Order\Services\EuWithdrawalService::class)->present($row))
                    ->values()
                    ->all();
            })(),
            'can_request_eu_withdrawal' => (function () use ($order) {
                \App\Domain\Order\EuWithdrawalSchema::ensure();
                $service = app(\App\Domain\Order\Services\EuWithdrawalService::class);
                if (! $service->isEligible($order)) {
                    return false;
                }

                return ! \App\Domain\Order\Models\EuWithdrawalRequest::query()
                    ->where('order_id', $order->id)
                    ->whereIn('status', [
                        \App\Domain\Order\Models\EuWithdrawalRequest::STATUS_PENDING,
                        \App\Domain\Order\Models\EuWithdrawalRequest::STATUS_APPROVED,
                    ])
                    ->exists();
            })(),
        ]);
    }

    public function cancelOrder(Request $request, string $number, OrderStageService $stages): JsonResponse
    {
        $order = $this->accountOrderByNumber($request, $number);

        if (! OrderSettings::buyerMayCancelStatus((string) $order->status)) {
            abort(422, 'This order can no longer be cancelled.');
        }

        $stages->apply($order, 'cancelled', 'Cancelled by customer');

        return $this->order($request, $number);
    }

    public function reorder(Request $request, string $number, \App\Domain\Checkout\Services\CartService $carts): JsonResponse
    {
        $order = $this->accountOrderByNumber($request, $number)->load('items.product');
        $cart = $carts->current($request->user('customer'));
        $added = 0;
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->is_enabled) {
                continue;
            }
            try {
                $carts->add(
                    $cart,
                    $product,
                    max(1, (int) $item->qty),
                    is_array($item->customization) ? $item->customization : [],
                    [],
                    $item->subscription_plan_id ? (int) $item->subscription_plan_id : null,
                    is_array($item->gift_card_data) ? $item->gift_card_data : [],
                    $item->combination_id ? (int) $item->combination_id : null,
                    $item->booking_date?->toDateString(),
                    null,
                    null,
                    is_array($item->bundle_selection) ? $item->bundle_selection : null,
                    $item->booking_end_date?->toDateString(),
                    $item->rental_duration ? (int) $item->rental_duration : null,
                );
                $added++;
            } catch (\Throwable) {
                continue;
            }
        }
        if ($added < 1) {
            abort(422, 'None of these items can be added to the cart.');
        }

        return response()->json([
            'ok' => true,
            'added' => $added,
            'quantity_notices' => $carts->pullQuantityNotices(),
        ]);
    }

    public function invoice(Request $request, string $number, \App\Domain\Order\InvoiceDocument $invoices): \Illuminate\Http\Response
    {
        $order = $this->accountOrderByNumber($request, $number)->load('items');
        $invoices->ensurePaid($order);

        return response($invoices->html($order), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoice-'.$order->number.'.html"',
        ]);
    }

    public function requestReturn(Request $request, string $number): JsonResponse
    {
        $customer = $request->user('customer');
        $order = $this->accountOrderByNumber($request, $number);
        $data = $request->validate([
            'seller_order_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'restock' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.seller_order_item_id' => ['nullable', 'integer'],
            'items.*.order_item_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        if (! empty($data['seller_order_id'])) {
            $sellerOrder = \App\Domain\Marketplace\Models\SellerOrder::query()
                ->where('order_id', $order->id)
                ->whereKey($data['seller_order_id'])
                ->firstOrFail();

            $returns = app(\App\Domain\Marketplace\Services\SellerReturnService::class);
            $row = $returns->requestReturn($customer, $order, $sellerOrder, $data['items'], [
                'reason' => $data['reason'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'restock' => $data['restock'] ?? true,
            ]);

            return response()->json(['return' => $returns->present($row)], 201);
        }

        $shop = app(\App\Domain\Order\Services\ShopReturnService::class);
        $row = $shop->request($customer, $order, $data['items'], [
            'reason' => $data['reason'] ?? null,
            'customer_note' => $data['customer_note'] ?? null,
            'restock' => $data['restock'] ?? true,
        ]);

        return response()->json(['return' => $shop->present($row)], 201);
    }

    public function addresses(Request $request): JsonResponse
    {
        return response()->json(['addresses' => $request->user('customer')->addresses()->orderByDesc('is_default_shipping')->orderBy('id')->get()]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $max = CustomerSettings::maxSavedAddresses();
        $count = $customer->addresses()->count();
        if ($max !== null && $count >= $max) {
            abort(422, 'You have reached the maximum number of saved addresses.');
        }
        if (! CustomerSettings::allowMultipleAddresses() && $count >= 1) {
            abort(422, 'Multiple addresses are not allowed.');
        }

        $address = $customer->addresses()->create($this->addressData($request));
        $this->syncDefaults($request, $address);

        return response()->json(['address' => $address->fresh()], 201);
    }

    public function updateAddress(Request $request, int $address): JsonResponse
    {
        $row = $this->ownedAddress($request, $address);
        $row->update($this->addressData($request));
        $this->syncDefaults($request, $row->fresh());

        return response()->json(['address' => $row->fresh()]);
    }

    public function destroyAddress(Request $request, int $address): JsonResponse
    {
        $this->ownedAddress($request, $address)->delete();

        return response()->json(['ok' => true]);
    }

    public function wishlist(Request $request): JsonResponse
    {
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return response()->json(['wishlist' => []]);
        }
        $locale = app()->getLocale();
        $ids = $request->user('customer')->wishlist()->pluck('product_id');
        $products = $this->present->withRatings(Product::query()->whereIn('id', $ids))->get()
            ->map(fn (Product $product) => $this->present->product($product, $locale))
            ->values();

        return response()->json(['wishlist' => $products]);
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return response()->json(['message' => 'Wishlist is disabled.'], 422);
        }
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $customer = $request->user('customer');
        $product = Product::query()->findOrFail($data['product_id']);

        if ($customer->wishlist()->where('product_id', $product->id)->exists()) {
            $customer->wishlist()->detach($product->id);
            $on = false;
        } else {
            $customer->wishlist()->attach($product->id);
            $on = true;
        }

        return response()->json(['wishlisted' => $on]);
    }

    public function shareWishlist(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $share = \App\Domain\Customer\Models\WishlistShare::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            ['token' => bin2hex(random_bytes(12)), 'is_enabled' => true],
        );
        if (! $share->is_enabled) {
            $share->update(['is_enabled' => true]);
        }

        return response()->json([
            'token' => $share->token,
            'url' => rtrim((string) config('app.url'), '/').'/wishlist/shared/'.$share->token,
        ]);
    }

    public function downloads(Request $request, \App\Domain\Order\Services\OrderDownloadService $downloads): JsonResponse
    {
        $rows = $downloads->listForCustomer($request->user('customer'));

        return response()->json(['downloads' => $rows]);
    }

    public function downloadFile(Request $request, string $token, \App\Domain\Order\Services\OrderDownloadService $downloads)
    {
        $row = \App\Domain\Order\Models\OrderDownload::query()
            ->with(['order', 'attachment'])
            ->where('token', $token)
            ->firstOrFail();
        $owns = $request->user('customer')->orders()->whereKey($row->order_id)->exists();
        if (! $owns) {
            abort(403);
        }

        return $downloads->download($row);
    }

    /**
     */
    public function downloadFileById(Request $request, int $purchaseId, \App\Domain\Order\Services\OrderDownloadService $downloads)
    {
        $row = \App\Domain\Order\Models\OrderDownload::query()
            ->with(['order', 'attachment'])
            ->whereKey($purchaseId)
            ->firstOrFail();
        $owns = $request->user('customer')->orders()->whereKey($row->order_id)->exists();
        if (! $owns) {
            abort(403);
        }

        return $downloads->download($row);
    }

    public function previewDownloadFile(Request $request, string $token, \App\Domain\Order\Services\OrderDownloadService $downloads)
    {
        $row = \App\Domain\Order\Models\OrderDownload::query()
            ->with(['order', 'attachment'])
            ->where('token', $token)
            ->firstOrFail();
        $owns = $request->user('customer')->orders()->whereKey($row->order_id)->exists();
        if (! $owns) {
            abort(403);
        }

        return $downloads->preview($row);
    }

    public function quotes(Request $request, \App\Domain\Customer\Services\QuoteService $quotes): JsonResponse
    {
        $rows = \App\Domain\Customer\Models\B2bQuote::query()
            ->where('customer_id', $request->user('customer')->id)
            ->with('items')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $quotes->present($row))
            ->values();

        return response()->json(['quotes' => $rows]);
    }

    public function storeQuote(Request $request, \App\Domain\Customer\Services\QuoteService $quotes, \App\Domain\Checkout\Services\CartService $carts): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $quote = $quotes->requestFromCart($request->user('customer'), $carts, $data['note'] ?? null);

        return response()->json(['quote' => $quotes->present($quote)], 201);
    }

    public function checkoutQuote(Request $request, int $quote, \App\Domain\Customer\Services\QuoteService $quotes, \App\Domain\Checkout\Services\CartService $carts): JsonResponse
    {
        $row = \App\Domain\Customer\Models\B2bQuote::query()
            ->where('customer_id', $request->user('customer')->id)
            ->whereKey($quote)
            ->firstOrFail();
        $quotes->loadIntoCart($request->user('customer'), $row, $carts);

        return $this->quotes($request, $quotes);
    }

    public function storeCredit(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        $credits = app(\App\Domain\Customer\Services\StoreCreditService::class);

        return response()->json([
            'balance' => $credits->balance($customer),
            'entries' => \App\Domain\Customer\Models\CustomerCredit::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'amount', 'balance', 'reason', 'created_at']),
        ]);
    }

    public function priceAlerts(Request $request): JsonResponse
    {
        $rows = \App\Domain\Catalog\Models\PriceAlert::query()
            ->where('customer_id', $request->user('customer')->id)
            ->orderByDesc('id')
            ->get(['id', 'product_id', 'last_price', 'notified_at']);

        return response()->json(['alerts' => $rows]);
    }

    public function stockAlerts(Request $request): JsonResponse
    {
        $alerts = app(\App\Domain\Catalog\Services\CustomerStockAlertService::class);
        if (! $alerts->enabled()) {
            return response()->json(['alerts' => [], 'meta' => ['total' => 0]]);
        }
        $locale = app()->getLocale();
        $page = $alerts->listForCustomer($request->user('customer'), max(5, min(50, (int) $request->query('per_page', 20))));
        $rows = collect($page->items())->map(fn ($row) => $alerts->presentRow($row, $locale))->values();

        return response()->json([
            'alerts' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function destroyStockAlert(Request $request, int $alert): JsonResponse
    {
        $alerts = app(\App\Domain\Catalog\Services\CustomerStockAlertService::class);
        $alerts->unsubscribeById($request->user('customer'), $alert);

        return response()->json(['ok' => true, 'message' => 'Stock alert removed.']);
    }

    public function togglePriceAlert(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $customer = $request->user('customer');
        $product = Product::query()->findOrFail($data['product_id']);
        $existing = \App\Domain\Catalog\Models\PriceAlert::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->first();
        if ($existing) {
            $existing->delete();

            return response()->json(['watching' => false]);
        }
        \App\Domain\Catalog\Models\PriceAlert::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'last_price' => $product->sellingPrice(),
            'email' => $customer->email,
        ]);

        return response()->json(['watching' => true]);
    }

    public function reviews(Request $request): JsonResponse
    {
        if (! ReviewSettings::enabled()) {
            return response()->json(['reviews' => []]);
        }
        $locale = app()->getLocale();
        $rows = Review::query()
            ->where('customer_id', $request->user('customer')->id)
            ->with(['product.translations'])
            ->latest()
            ->get()
            ->map(function (Review $review) use ($locale) {
                $product = $review->product;

                return [
                    'id' => $review->id,
                    'product_id' => $review->product_id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'body' => $review->body,
                    'status' => $review->status ?: ($review->is_approved ? Review::STATUS_APPROVED : Review::STATUS_PENDING),
                    'is_approved' => $review->is_approved,
                    'rejected_reason' => $review->rejected_reason,
                    'admin_reply' => ReviewSettings::merchantReplies() ? $review->admin_reply : null,
                    'is_verified_purchase' => (bool) $review->is_verified_purchase,
                    'created_at' => $review->created_at?->toDateString(),
                    'updated_at' => $review->updated_at?->toDateString(),
                    ...$review->mediaPayload(),
                    ...$review->votePayload(),
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => $product->translation($locale)?->name,
                        'url' => '/'.ltrim((string) ($product->translation($locale)?->slug ?: $product->id), '/'),
                    ] : null,
                ];
            })
            ->values();

        return response()->json(['reviews' => $rows]);
    }

    public function vouchers(Request $request): JsonResponse
    {
        if (! CartCheckoutSettings::couponsEnabled()) {
            return response()->json(['vouchers' => []]);
        }
        $customer = $request->user('customer');
        $id = (string) $customer->id;
        $groupId = (string) ($customer->customer_group_id ?: '');
        $groupCode = strtoupper(trim((string) ($customer->customer_group ?? '')));

        $vouchers = DiscountRule::query()->live()->with('conditions')->orderBy('priority')->orderBy('id')->get()
            ->filter(function (DiscountRule $rule) use ($id, $groupId, $groupCode) {
                $customerConds = $rule->conditions->where('type', 'customer');
                $groupConds = $rule->conditions->where('type', 'customer_group');
                if ($customerConds->isEmpty() && $groupConds->isEmpty()) {
                    return false;
                }
                $okCustomer = $customerConds->isEmpty()
                    || $customerConds->contains(fn ($cond) => in_array($id, $cond->values(), true));
                $okGroup = $groupConds->isEmpty()
                    || $groupConds->contains(function ($cond) use ($groupId, $groupCode) {
                        $values = $cond->values();
                        if ($groupId !== '' && in_array($groupId, $values, true)) {
                            return true;
                        }

                        return $groupCode !== '' && in_array($groupCode, array_map('strtoupper', $values), true);
                    });

                return $okCustomer && $okGroup;
            })
            ->values()
            ->map(fn (DiscountRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'code' => $rule->code,
                'summary' => $rule->actionSummary(),
                'expires_at' => $rule->expires_at?->toDateString(),
                'apply_as' => $rule->apply_as,
            ]);

        return response()->json(['vouchers' => $vouchers]);
    }

    public function reviewEligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        return response()->json([
            'eligibility' => $this->reviews->eligibility($request->user('customer'), (int) $data['product_id']),
            'settings' => [
                'enabled' => ReviewSettings::enabled(),
                'who' => ReviewSettings::whoCanReview(),
                'media' => ReviewSettings::media(),
                'min_length' => ReviewSettings::minLength(),
            ],
        ]);
    }

    public function guestReview(Request $request, int $product): JsonResponse
    {
        if ($request->user('customer')) {
            $request->merge(['product_id' => $product]);

            return $this->review($request);
        }

        if (\App\Domain\Settings\ReviewSettings::whoCanReview() !== 'everyone') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'Sign in to write a review.',
            ]);
        }

        $request->merge(['product_id' => $product]);

        return $this->review($request, true);
    }

    public function review(Request $request, bool $asGuest = false): JsonResponse
    {
        $this->assertReviewUploadNotTruncated($request);

        $input = $request->all();
        // Multipart bodies can arrive empty (bad Content-Type / post size). Prefer query fallbacks.
        foreach (['product_id', 'rating', 'title', 'body'] as $key) {
            $missing = ! array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '';
            if ($missing && $request->query($key) !== null && $request->query($key) !== '') {
                $input[$key] = $request->query($key);
            }
        }
        if ((! array_key_exists('remove_video', $input) || $input['remove_video'] === null || $input['remove_video'] === '')
            && $request->query('remove_video') !== null) {
            $input['remove_video'] = $request->query('remove_video');
        }
        if (empty($input['remove_images']) && is_array($request->query('remove_images'))) {
            $input['remove_images'] = $request->query('remove_images');
        }

        $photos = $request->file('photos', []);
        if (! is_array($photos)) {
            $photos = $photos ? [$photos] : [];
        }
        $legacy = $request->file('photo');
        if ($legacy) {
            $photos[] = $legacy;
        }
        $photos = array_values(array_filter($photos, static fn ($file) => $file instanceof \Illuminate\Http\UploadedFile));

        $video = $request->file('video');
        if ($video && ! $video->isValid()) {
            throw ValidationException::withMessages([
                'video' => 'The video could not be uploaded. Try a smaller MP4/WebM file (max '
                    .round(Review::effectiveVideoMaxKb() / 1024, 1).' MB).',
            ]);
        }

        // Client flagged a video/photo upload but PHP dropped the file (usually size limits).
        // Prefer query flags — they survive when the multipart body is discarded.
        $expectsVideo = $request->boolean('has_video') || $request->boolean('_has_video');
        $expectsPhotos = $request->boolean('has_photos') || $request->boolean('_has_photos');
        if ($expectsVideo && ! $video) {
            throw ValidationException::withMessages([
                'video' => 'Video upload failed. Maximum size is '
                    .round(Review::effectiveVideoMaxKb() / 1024, 1).' MB (MP4 or WebM).',
            ]);
        }
        if ($expectsPhotos && $photos === []) {
            throw ValidationException::withMessages([
                'photos' => 'Image upload failed. Please try smaller JPEG/PNG/WebP files.',
            ]);
        }

        $review = $this->reviews->submit(
            $asGuest ? null : $request->user('customer'),
            $input,
            $photos,
            $video instanceof \Illuminate\Http\UploadedFile ? $video : null
        );

        return response()->json([
            'review' => array_merge([
                'id' => $review->id,
                'product_id' => $review->product_id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => $review->status,
                'is_approved' => $review->is_approved,
                'is_verified_purchase' => (bool) $review->is_verified_purchase,
            ], $review->mediaPayload(), $review->votePayload()),
        ], 201);
    }

    public function voteReview(Request $request, Review $review): JsonResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
        ]);

        $counts = $this->reviews->vote(
            $request->user('customer'),
            $review,
            (bool) $data['helpful']
        );

        return response()->json([
            'review_id' => $review->id,
            ...$counts,
        ]);
    }

    /**
     * When post_max_size is exceeded, PHP empties $_POST/$_FILES but the request still hits Laravel.
     */
    protected function assertReviewUploadNotTruncated(Request $request): void
    {
        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength <= 0) {
            return;
        }
        $postMax = $this->iniBytes((string) ini_get('post_max_size'));
        if ($postMax <= 0 || $contentLength <= $postMax) {
            return;
        }

        // PHP discarded the multipart body. Query flags still report upload intent.
        if ($request->boolean('has_video') || $request->boolean('has_photos') || $request->allFiles() === []) {
            throw ValidationException::withMessages([
                'video' => 'Upload is too large for the server. Maximum total size is about '
                    .round($postMax / 1024 / 1024, 1).' MB. Try a smaller video or fewer images.',
            ]);
        }
    }

    protected function iniBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;

        return (int) match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (float) $raw,
        };
    }

    public function askQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $question = ProductQuestion::query()->create([
            'product_id' => $data['product_id'],
            'customer_id' => $request->user('customer')->id,
            'body' => $data['body'],
            'status' => ProductQuestion::STATUS_PENDING,
            'is_approved' => false,
        ]);

        return response()->json(['question' => $question], 201);
    }

    /** @return array<string, mixed> */
    protected function addressData(Request $request): array
    {
        $country = (string) $request->input('country_code', '');
        $data = $request->validate(array_merge([
            'label' => ['nullable', 'string', 'max:40'],
            'first_name' => ['required', 'string'],
            'last_name' => ['nullable', 'string'],
            'line1' => ['required', 'string'],
            'city' => ['required', 'string'],
            'country_code' => ['required', 'string'],
            'is_default_shipping' => ['nullable', 'boolean'],
            'is_default_billing' => ['nullable', 'boolean'],
        ], CustomerSettings::addressFieldValidation('', $country)), CustomerSettings::addressValidationMessages());

        if (CustomerSettings::addressPhoneMode() === 'hidden') {
            $data['phone'] = null;
        }
        if (CustomerSettings::addressLine2Mode() === 'hidden') {
            $data['line2'] = null;
        }
        if (CustomerSettings::addressCompanyMode() === 'hidden') {
            $data['company'] = null;
        }
        if (CustomerSettings::addressTaxIdMode() === 'hidden') {
            $data['tax_id'] = null;
        }

        return $data;
    }

    protected function ownedAddress(Request $request, int $id): CustomerAddress
    {
        $address = $request->user('customer')->addresses()->whereKey($id)->firstOrFail();
        Gate::forUser($request->user('customer'))->authorize('update', $address);

        return $address;
    }

    protected function syncDefaults(Request $request, CustomerAddress $address): void
    {
        $customer = $request->user('customer');
        if ($address->is_default_shipping) {
            $customer->addresses()->whereKeyNot($address->id)->update(['is_default_shipping' => false]);
        }
        if ($address->is_default_billing) {
            $customer->addresses()->whereKeyNot($address->id)->update(['is_default_billing' => false]);
        }
    }

    /** @return array<string, mixed> */
    protected function presentCustomer($customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->displayName(),
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'gender' => $customer->gender,
            'newsletter' => (bool) $customer->newsletter,
            'seller' => $customer->sellerPayload(),
            'impersonating' => (bool) request()->session()->get('impersonator_admin_id'),
        ];
    }
}
