<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\StoreCreditService;
use App\Domain\Localization\Models\Currency;
use App\Domain\Ops\ShopStats;
use App\Domain\Ops\Timeline;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderComment;
use App\Domain\Order\Models\OrderStage;
use App\Domain\Order\OrderStageCatalog;
use App\Domain\Order\OrderStageService;
use App\Domain\Order\PaymentStatusCatalog;
use App\Domain\Order\SalesChannelCatalog;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Order\Services\EuWithdrawalService;
use App\Domain\Order\Services\ShopReturnService;
use App\Domain\Order\Support\OrderCsvSchema;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Plugin\OrderShowSlots;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Promo\Services\RefundVoucherIssuer;
use App\Domain\Search\PrefixQuery;
use App\Domain\Security\CsvSafe;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ShopMailer;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Services\LabelService;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\TaxEngine;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(protected OrderNumberService $orderNumbers) {}

    public function index(Request $request): Response
    {
        $status = $this->normalizeStatus($request->string('status')->toString());
        $fulfillment = $this->normalizeStatus($request->string('fulfillment')->toString());
        $counts = $this->statusCounts();
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $sort = $this->normalizeSort($requestedSort);
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        $orders = $this->filteredQuery($request)
            ->select([
                'orders.id', 'orders.number', 'orders.checkout_reference', 'orders.customer_id', 'orders.email', 'orders.status', 'orders.currency_code',
                'orders.grand_total', 'orders.payment_method_id', 'orders.payment_status', 'orders.billing_address', 'orders.created_at',
            ])
            ->with([
                'customer:id,name,email',
                'items:id,order_id,qty',
                'paymentMethod:id,name',
            ])
            ->when($status, fn ($query) => $query->where('orders.status', $status))
            ->when($fulfillment, fn ($query) => $query->where('orders.status', $fulfillment))
            ->orderBy($sort, $dir)
            ->orderByDesc('orders.id');
        $moduleList = ModuleBuses::applyList('orders.orders', $orders);
        $orders = $orders
            ->paginate(\App\Support\AdminPagination::perPage(request(), 10))
            ->withQueryString();
        $orders->getCollection()->transform(function (Order $order) use ($moduleList) {
            $order->setAttribute('module_values', ModuleBuses::rowValues($order, $moduleList['columns']));

            return $order;
        });

        return Inertia::render('Orders/Index', [
            'invoices_enabled' => \App\Domain\Settings\DocumentCommerceSettings::invoicesEnabled(),
            'orders' => $orders,
            'counts' => $counts,
            'stats' => Inertia::defer(fn () => $this->orderStats()),
            'moduleList' => $moduleList,
            'filters' => [
                'status' => $request->string('status')->toString() ?: 'all',
                'q' => $request->string('q')->toString(),
                'number' => $request->string('number')->toString(),
                'customer' => $request->string('customer')->toString(),
                'payment_status' => $request->string('payment_status')->toString(),
                'payment_method' => $request->string('payment_method')->toString(),
                'fulfillment' => $request->string('fulfillment')->toString(),
                'total' => $request->string('total')->toString(),
                'total_min' => $request->string('total_min')->toString() ?: $request->string('total')->toString(),
                'total_max' => $request->string('total_max')->toString(),
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'date' => $request->string('date')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function preorders(Request $request, PreorderService $preorders): Response
    {
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $rows = $preorders->paginateForAdmin(
            $request,
            \App\Support\AdminPagination::perPage($request, 10),
        );

        return Inertia::render('Orders/Preorders', [
            'preorders' => $rows,
            'counts' => $this->statusCounts(),
            'filters' => [
                'status' => 'preorders',
                'q' => $request->string('q')->toString(),
                'number' => $request->string('number')->toString(),
                'customer' => $request->string('customer')->toString(),
                'product' => $request->string('product')->toString(),
                'payment_status' => $request->string('payment_status')->toString(),
                'preorder_status' => $request->string('preorder_status')->toString(),
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'date' => $request->string('date')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $csv = $this->exportCsv($request);

        return response()->streamDownload(fn () => print ($csv), 'orders-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:8192']]);
        $count = $this->importCsv($request->file('file')->getContent());

        return back()->with('success', "Imported {$count} orders.");
    }

    public function importSample()
    {
        return $this->csvDownload('orders-sample.csv', OrderCsvSchema::sampleCsv());
    }

    public function create(): Response
    {
        return Inertia::render('Orders/Create', $this->formOptions());
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());
        $locale = app()->getLocale();

        $products = Product::query()
            ->enabled()
            ->with(['translations', 'taxClass.rules', 'images'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('sku', 'like', PrefixQuery::like($search))
                        ->orWhereHas('translations', fn ($translation) => $translation->where('name', 'like', PrefixQuery::like($search)));
                });
            })
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Product $product) => $this->productPayload($product, $locale))
            ->values();

        return response()->json(['products' => $products]);
    }

    public function store(Request $request, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'email' => ['required', 'email'],
            'billing_address' => ['required', 'array'],
            'billing_address.first_name' => ['required', 'string'],
            'billing_address.line1' => ['required', 'string'],
            'billing_address.city' => ['required', 'string'],
            'billing_address.country_code' => ['required', 'string'],
            'shipping_address' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string'],
            'items.*.sku' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percent,amount'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'shipping_method_id' => ['nullable', 'integer', 'exists:shipping_methods,id'],
            'currency_code' => ['required', 'string', 'size:3'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'payment_status_id' => ['nullable', 'integer', 'exists:payment_statuses,id'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,refunded,partial'],
            'sales_channel_id' => ['nullable', 'integer', 'exists:sales_channels,id'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'created_at' => ['nullable', 'date'],
            'draft' => ['nullable', 'boolean'],
        ]);

        OrderStageCatalog::seed();
        $totals = $this->buildOrderTotals($data);
        $lines = $totals['lines'];
        $subtotal = $totals['subtotal'];
        $discountTotal = $totals['discount_total'];
        $taxTotal = $totals['tax_total'];
        $shippingTotal = $totals['shipping_total'];
        $grandTotal = (float) $totals['grand_total'];

        $requestedStatus = PaymentStatusCatalog::resolveCode($data['payment_status_id'] ?? $data['payment_status'] ?? 'pending') ?: 'pending';
        $paidInput = array_key_exists('paid_amount', $data) && $data['paid_amount'] !== null && $data['paid_amount'] !== ''
            ? max(0, round((float) $data['paid_amount'], 2))
            : ($requestedStatus === 'paid' ? $grandTotal : 0.0);

        [$amountPaid, $amountDue, $paymentStatus] = $this->syncPaymentProgress(
            $paidInput,
            $grandTotal,
            $requestedStatus,
            'pending',
        );

        $pendingStage = OrderStage::query()->where('code', 'pending')->first();
        $channelCode = SalesChannelCatalog::codeForId($data['sales_channel_id'] ?? null) ?: 'web';
        $channelId = SalesChannelCatalog::idForCode($channelCode);
        $paymentStatusId = PaymentStatusCatalog::idForCode($paymentStatus);

        $order = DB::transaction(function () use ($data, $lines, $subtotal, $discountTotal, $taxTotal, $shippingTotal, $grandTotal, $amountPaid, $amountDue, $paymentStatus, $paymentStatusId, $pendingStage, $channelCode, $channelId, $inventory) {
            $order = Order::createTrusted([
                'number' => $this->orderNumbers->next(),
                'customer_id' => $data['customer_id'] ?? null,
                'email' => $data['email'],
                'status' => 'pending',
                'order_stage_id' => $pendingStage?->id,
                'channel' => $channelCode,
                'sales_channel_id' => $channelId,
                'currency_code' => $data['currency_code'],
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'shipping_total' => round($shippingTotal, 2),
                'discount_total' => round($discountTotal, 2),
                'grand_total' => $grandTotal,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'shipping_method_id' => $data['shipping_method_id'] ?? null,
                'payment_status' => $paymentStatus,
                'payment_status_id' => $paymentStatusId,
                'payment_details' => $amountPaid > 0 ? [
                    'paid_total' => $amountPaid,
                    'initial_payment' => $amountPaid,
                ] : null,
                'billing_address' => $data['billing_address'],
                'shipping_address' => $data['shipping_address'] ?? $data['billing_address'],
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            foreach ($lines as $line) {
                $product = ! empty($line['product_id']) ? Product::query()->find($line['product_id']) : null;
                $warehouseId = null;
                if ($product && ($product->manage_stock || $product->isComposite() || ($product->isCombination() && ! empty($line['combination_id'])))) {
                    $warehouseId = $inventory->pickWarehouse($product, (float) $line['qty'])->id;
                }
                $order->items()->create(array_merge($line, ['warehouse_id' => $warehouseId]));

                if ($product?->manage_stock || ($product?->isCombination() && ! empty($line['combination_id']))) {
                    $warehouse = $warehouseId
                        ? \App\Domain\Catalog\Models\Warehouse::query()->find($warehouseId)
                        : null;
                    $inventory->deduct(
                        $product,
                        (int) $line['qty'],
                        'order',
                        $order->number ?? ('ORD-'.$order->id),
                        $warehouse,
                        ! empty($line['combination_id']) ? (int) $line['combination_id'] : null,
                    );
                }
            }

            $historyComment = ! empty($data['draft']) ? 'Draft order created by admin' : 'Order created by admin';
            if ($paymentStatus === 'partial' && $amountDue > 0.009) {
                $historyComment .= ' (Partially paid: '.number_format($amountPaid, 2).', balance due: '.number_format($amountDue, 2).')';
            }

            $order->statusHistories()->create([
                'status' => 'pending',
                'order_stage_id' => $pendingStage?->id,
                'comment' => $historyComment,
            ]);

            if (! empty($data['created_at'])) {
                $order->created_at = $data['created_at'];
                $order->save();
            }

            return $order;
        });

        ShopStats::flushOrders();

        return redirect()->route('admin.orders.show', $order)->with('success', 'Order created.');
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'line1' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $parts = preg_split('/\s+/', trim($data['name']), 2) ?: [$data['name']];

        $customer = Customer::createTrusted([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => 'password',
            'is_enabled' => true,
        ]);

        app(ShopMailer::class)->customerCreated($customer);

        $customer->addresses()->create([
            'label' => 'Billing',
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'phone' => $data['phone'] ?? null,
            'line1' => $data['line1'],
            'city' => $data['city'],
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country_code' => strtoupper($data['country_code']),
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ]);

        return back()->with('success', 'Customer added.')->with('customer_id', $customer->id);
    }

    public function show(Order $order): Response
    {
        OrderStageCatalog::seed();

        return Inertia::render('Orders/Show', [
            'order' => $order->load([
                'items.product:id,cover_path,sku',
                'items.subscriptionPlan.translations',
                'statusHistories',
                'paymentMethod',
                'shippingMethod',
                'customer',
                'shipments',
                'checkoutGroup.orders.shippingMethod',
            ]),
            'counts' => $this->statusCounts(),
            'stages' => $this->stageOptions($order),
            'moduleSlots' => OrderShowSlots::afterPayment($order),
            'orderActionSlots' => OrderShowSlots::headerActions($order),
            'timeline' => app(Timeline::class)->for('order', (int) $order->id),
            'shop_returns' => $order->shopReturns()->with('items')->latest('id')->get(),
            'eu_withdrawals' => (function () use ($order) {
                \App\Domain\Order\EuWithdrawalSchema::ensure();

                return $order->euWithdrawalRequests()->latest('id')->get()->map(
                    fn ($row) => app(\App\Domain\Order\Services\EuWithdrawalService::class)->present($row)
                )->values()->all();
            })(),
            'has_credit_slip' => $this->orderHasCreditSlip($order),
            'refunds' => $this->orderRefundRecords($order),
            'can_buy_label' => (function () use ($order) {
                if (! $order->shipping_method_id || in_array($order->status, ['cancelled', 'refunded'], true)) {
                    return false;
                }
                $method = $order->shippingMethod;
                $driver = strtolower(trim((string) ($method?->driver ?: 'offline')));
                if ($driver === '' || $driver === 'offline') {
                    return false;
                }
                $carrier = app(\App\Domain\Shipping\ShippingCarrierRegistry::class)->get($driver);

                return $carrier instanceof \App\Domain\Shipping\Contracts\BuysShippingLabels;
            })(),
            'can_void_label' => (function () use ($order) {
                if (! $order->shipping_method_id || in_array($order->status, ['cancelled', 'refunded'], true)) {
                    return false;
                }
                $details = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
                $hasLabel = ! empty($details['label']['tracking_number']) && empty($details['label']['voided']);
                if (! $hasLabel) {
                    return false;
                }
                $method = $order->shippingMethod;
                $driver = strtolower(trim((string) ($method?->driver ?: 'offline')));
                if ($driver === '' || $driver === 'offline') {
                    return false;
                }
                $carrier = app(\App\Domain\Shipping\ShippingCarrierRegistry::class)->get($driver);

                return $carrier instanceof \App\Domain\Shipping\Contracts\RefundsShippingLabels;
            })(),
            'order_pdf' => \App\Domain\Settings\DocumentCommerceSettings::orderPdf(),
            'invoices_enabled' => \App\Domain\Settings\DocumentCommerceSettings::invoicesEnabled(),
            'invoice_ready' => app(\App\Domain\Order\InvoiceDocument::class)->matchesCreatePoint($order),
        ]);
    }

    public function buyLabel(Request $request, Order $order, LabelService $labels): RedirectResponse
    {
        $admin = $request->user('admin');
        try {
            $shipment = $labels->buy($order, $admin);
        } catch (ValidationException $e) {
            return back()->with([
                'error' => collect($e->errors())->flatten()->first() ?: 'Could not buy a label.',
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Label purchase failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }

        $byAdmin = $admin ? ' by '.$admin->name : '';

        return back()->with([
            'success' => 'Shipping label '.$shipment->tracking_number.' ('.$shipment->carrier.') created'.$byAdmin.'.',
            'toast' => true,
        ]);
    }

    public function voidLabel(Request $request, Order $order, LabelService $labels): RedirectResponse
    {
        $admin = $request->user('admin');
        try {
            $shipment = $labels->void($order, $admin);
        } catch (ValidationException $e) {
            return back()->with([
                'error' => collect($e->errors())->flatten()->first() ?: 'Could not void label.',
                'toast' => true,
            ]);
        } catch (\Throwable $e) {
            return back()->with([
                'error' => 'Voiding label failed: '.$e->getMessage(),
                'toast' => true,
            ]);
        }

        return back()->with([
            'success' => 'Shipping label '.$shipment->tracking_number.' voided successfully.',
            'toast' => true,
        ]);
    }

    public function edit(Order $order): Response
    {
        $order->load([
            'customer:id,name,email',
            'paymentMethod',
            'items.product:id,cover_path,sku,tax_class_id',
            'items.product.taxClass.rules',
            'items.product.images',
        ]);

        $lineDiscountSum = 0.0;
        $items = $order->items->map(function ($item) use (&$lineDiscountSum) {
            $qty = (int) $item->qty;
            $unit = (float) $item->unit_price;
            $line = $qty * $unit;
            $taxRate = (float) $item->tax_rate;
            if ($taxRate <= 0 && $item->product) {
                $taxRate = (float) ($item->product->taxClass?->rules->firstWhere('is_enabled', true)?->rate ?? 0);
            }
            $total = (float) $item->total;
            $taxable = $taxRate > 0
                ? $total / (1 + ($taxRate / 100))
                : max(0, $total - (float) $item->tax_amount);
            $discountAmount = max(0, round($line - $taxable, 2));
            $lineDiscountSum += $discountAmount;

            return [
                'key' => $item->id,
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'sku' => $item->sku,
                'unit_price' => $unit,
                'qty' => $qty,
                'returned_qty' => 0,
                'returnable_qty' => (float) $qty,
                'line_total' => $total,
                'tax_rate' => $taxRate,
                'discount' => $line > 0 ? round(($discountAmount / $line) * 100, 2) : 0,
                'discount_type' => 'percent',
                'cover_path' => $item->product?->cover_path ?: $item->product?->images->first()?->path,
                'booking_date' => $item->booking_date?->toDateString(),
                'booking_end_date' => $item->booking_end_date?->toDateString(),
                'rental_duration' => $item->rental_duration,
                'rental_data' => is_array($item->rental_data) ? $item->rental_data : null,
                'preorder_data' => is_array($item->preorder_data) ? $item->preorder_data : null,
                'customization' => $item->customization,
                'subscription_plan' => $item->relationLoaded('subscriptionPlan') ? $item->subscriptionPlan : null,
            ];
        })->values();

        $orderDiscount = max(0, round((float) $order->discount_total - $lineDiscountSum, 2));
        $billing = $this->withAddressDefaults($order->billing_address ?: [], $order);
        $shippingAddress = $this->withAddressDefaults($order->shipping_address ?: $billing, $order);

        return Inertia::render('Orders/Edit', array_merge($this->formOptions($order), [
            'orderActionSlots' => OrderShowSlots::headerActions($order),
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'customer_id' => $order->customer_id,
                'email' => $order->email,
                'status' => $order->status,
                'order_stage_id' => $order->order_stage_id
                    ?: OrderStage::query()->where('code', $order->status)->value('id'),
                'payment_status' => $order->payment_status,
                'payment_status_id' => $order->payment_status_id
                    ?: PaymentStatusCatalog::idForCode((string) $order->payment_status),
                'channel' => $order->channel ?: 'web',
                'sales_channel_id' => $order->sales_channel_id
                    ?: SalesChannelCatalog::idForCode((string) ($order->channel ?: 'web')),
                'currency_code' => $order->currency_code,
                'payment_method_id' => $order->payment_method_id,
                'shipping_method_id' => $order->shipping_method_id,
                'shipping_total' => (float) $order->shipping_total,
                'order_discount' => $orderDiscount,
                'order_discount_type' => 'amount',
                'grand_total' => (float) $order->grand_total,
                'billing_address' => $billing,
                'shipping_address' => $shippingAddress,
                'customer_note' => $order->customer_note,
                'created_at' => $order->created_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i'),
                'items' => $items,
                'refunded_total' => (float) ($order->refunded_total ?? 0),
                'amount_paid' => (float) ($order->amount_paid ?? 0),
                'amount_due' => (float) ($order->amount_due ?? 0),
                'paid_total' => (float) ((is_array($order->payment_details) ? ($order->payment_details['paid_total'] ?? null) : null)
                    ?? (((float) ($order->amount_paid ?? 0) > 0)
                        ? $order->amount_paid
                        : ((float) $order->grand_total + (float) ($order->refunded_total ?? 0)))),
                'has_credit_slip' => $this->orderHasCreditSlip($order),
                'refunds' => $this->orderRefundRecords($order),
                'comments' => OrderComment::query()
                    ->where('order_id', $order->id)
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get(['id', 'author_type', 'visibility', 'body', 'created_at']),
                'pay_url' => url('/pay/'.($order->paymentMethod?->driver ?: 'offline').'/'.$order->number),
            ],
        ]));
    }

    public function update(Request $request, Order $order, InventoryService $inventory): RedirectResponse
    {
        OrderStageCatalog::seed();
        $allowedIds = $this->allowedStatusIds($request, $order);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'email' => ['required', 'email'],
            'order_stage_id' => ['required', 'integer', 'in:'.implode(',', $allowedIds ?: [0])],
            'billing_address' => ['required', 'array'],
            'billing_address.first_name' => ['required', 'string'],
            'billing_address.line1' => ['required', 'string'],
            'billing_address.city' => ['required', 'string'],
            'billing_address.country_code' => ['required', 'string'],
            'shipping_address' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string'],
            'items.*.sku' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'in:percent,amount'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'shipping_method_id' => ['nullable', 'integer', 'exists:shipping_methods,id'],
            'currency_code' => ['required', 'string', 'size:3'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'payment_status_id' => ['nullable', 'integer', 'exists:payment_statuses,id'],
            'payment_status' => ['nullable', 'in:pending,paid,failed,refunded,partial'],
            'sales_channel_id' => ['nullable', 'integer', 'exists:sales_channels,id'],
            'order_discount' => ['nullable', 'numeric', 'min:0'],
            'order_discount_type' => ['nullable', 'in:percent,amount'],
            'shipping_total' => ['nullable', 'numeric', 'min:0'],
            'created_at' => ['nullable', 'date'],
        ]);

        $stage = OrderStage::query()->whereKey((int) $data['order_stage_id'])->firstOrFail();
        $data['status'] = $stage->code;
        $data['payment_status'] = PaymentStatusCatalog::resolveCode($data['payment_status_id'] ?? $data['payment_status'] ?? $order->payment_status)
            ?: (string) $order->payment_status;
        $channelCode = SalesChannelCatalog::codeForId($data['sales_channel_id'] ?? null)
            ?: (string) ($order->channel ?: 'web');

        $totals = $this->buildOrderTotals($data);
        $previousStatus = $order->status;
        $previousGrand = (float) $order->grand_total;
        $previousPaymentStatus = (string) $order->payment_status;
        $paidBaseline = $this->resolvedAmountPaid($order, $previousGrand);

        try {
            DB::transaction(function () use (
                $order,
                $data,
                $totals,
                $previousStatus,
                $previousPaymentStatus,
                $paidBaseline,
                $inventory,
                $request,
                $stage,
                $channelCode,
            ) {
                $order->load('items');
                $previousGiftCardData = [];
                $previousLineSnapshots = [];
                foreach ($order->items as $old) {
                    $pid = (int) ($old->product_id ?: 0);
                    if ($pid > 0) {
                        $previousLineSnapshots[$pid][] = [
                            'preorder_data' => is_array($old->preorder_data) ? $old->preorder_data : null,
                            'rental_data' => is_array($old->rental_data) ? $old->rental_data : null,
                            'combination_id' => $old->combination_id,
                            'parent_product_id' => $old->parent_product_id,
                            'subscription_plan_id' => $old->subscription_plan_id,
                            'customization' => $old->customization,
                            'bundle_selection' => $old->bundle_selection,
                            'booking_date' => $old->booking_date,
                            'booking_end_date' => $old->booking_end_date,
                            'rental_duration' => $old->rental_duration,
                        ];
                    }
                    if ($old->product_id && is_array($old->gift_card_data) && $old->gift_card_data !== []) {
                        $previousGiftCardData[(int) $old->product_id] = $old->gift_card_data;
                    }
                    if ($old->product_id) {
                        $product = Product::query()->find($old->product_id);
                        if ($product?->manage_stock || ($product?->isCombination() && $old->combination_id)) {
                            $warehouse = $inventory->warehouseForOrderItem(
                                $old->warehouse_id ? (int) $old->warehouse_id : null,
                                $product,
                                (float) $old->qty,
                                $order->number ?? ('ORD-'.$order->id)
                            );
                            $inventory->restock(
                                $product,
                                (int) $old->qty,
                                'order_edit',
                                $order->number ?? ('ORD-'.$order->id),
                                $warehouse,
                                $old->combination_id ? (int) $old->combination_id : null,
                            );
                        }
                    }
                }

                $order->items()->delete();

                $newGrand = (float) $totals['grand_total'];
                $requestedPayment = (string) ($data['payment_status'] ?? $previousPaymentStatus);
                [$amountPaid, $amountDue, $paymentStatus] = $this->syncPaymentProgress(
                    $paidBaseline,
                    $newGrand,
                    $requestedPayment,
                    $previousPaymentStatus,
                );

                // Rebuild line items BEFORE flipping payment_status so OrderObserver /
                // gift-card issuance can see gift_card products on the paid transition.
                foreach ($totals['lines'] as $line) {
                    $product = ! empty($line['product_id']) ? Product::query()->find($line['product_id']) : null;
                    $warehouseId = null;
                    if ($product && ($product->manage_stock || $product->isComposite() || ($product->isCombination() && ! empty($line['combination_id'])))) {
                        $warehouseId = $inventory->pickWarehouse($product, (float) $line['qty'])->id;
                    }
                    $payload = array_merge($line, ['warehouse_id' => $warehouseId]);
                    $productId = (int) ($line['product_id'] ?? 0);
                    if ($productId > 0 && ! empty($previousLineSnapshots[$productId])) {
                        $snap = array_shift($previousLineSnapshots[$productId]);
                        foreach ($snap as $key => $value) {
                            if ($value !== null && $value !== []) {
                                $payload[$key] = $value;
                            }
                        }
                    }
                    if ($productId > 0 && isset($previousGiftCardData[$productId])) {
                        $payload['gift_card_data'] = $previousGiftCardData[$productId];
                    }
                    $order->items()->create($payload);

                    if ($product?->manage_stock || ($product?->isCombination() && ! empty($line['combination_id']))) {
                        $warehouse = $warehouseId
                            ? \App\Domain\Catalog\Models\Warehouse::query()->find($warehouseId)
                            : null;
                        $inventory->deduct(
                            $product,
                            (int) $line['qty'],
                            'order',
                            $order->number ?? ('ORD-'.$order->id),
                            $warehouse,
                            ! empty($line['combination_id']) ? (int) $line['combination_id'] : null,
                        );
                    }
                }

                $order->updateTrusted([
                    'customer_id' => $data['customer_id'] ?? null,
                    'email' => $data['email'],
                    'status' => $stage->code,
                    'order_stage_id' => $stage->id,
                    'channel' => $channelCode,
                    'sales_channel_id' => SalesChannelCatalog::idForCode($channelCode),
                    'currency_code' => $data['currency_code'],
                    'subtotal' => round($totals['subtotal'], 2),
                    'tax_total' => round($totals['tax_total'], 2),
                    'shipping_total' => round($totals['shipping_total'], 2),
                    'discount_total' => round($totals['discount_total'], 2),
                    'grand_total' => $newGrand,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'shipping_method_id' => $data['shipping_method_id'] ?? null,
                    'payment_status' => $paymentStatus,
                    'payment_status_id' => PaymentStatusCatalog::idForCode($paymentStatus),
                    'amount_paid' => $amountPaid,
                    'amount_due' => $amountDue,
                    'billing_address' => $data['billing_address'],
                    'shipping_address' => $data['shipping_address'] ?? $data['billing_address'],
                    'customer_note' => $data['customer_note'] ?? null,
                ]);

                if ($previousStatus !== $stage->code) {
                    $this->assertCanSetStatus($request, $stage->code);
                    $order->statusHistories()->create([
                        'status' => $stage->code,
                        'order_stage_id' => $stage->id,
                        'comment' => 'Order updated by admin',
                    ]);
                }

                if ($paymentStatus === 'partial' && $amountDue > 0.009 && $previousPaymentStatus === 'paid') {
                    $order->statusHistories()->create([
                        'status' => $order->status,
                        'order_stage_id' => $order->order_stage_id,
                        'comment' => 'Balance due after order edit: '.number_format($amountDue, 2),
                    ]);
                }

                if (! empty($data['created_at'])) {
                    $order->created_at = $data['created_at'];
                    $order->save();
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the order.');
        }

        ShopStats::flushOrders();

        $order->refresh();
        if ($previousPaymentStatus !== 'paid' && $order->payment_status === 'paid') {
            app(\App\Domain\Catalog\Services\GiftCardService::class)->issueForPaidOrder($order->fresh(['items.product']));
        }
        $message = 'Order updated.';
        if ((float) ($order->amount_due ?? 0) > 0.009) {
            $message = 'Order updated. Balance due: '.number_format((float) $order->amount_due, 2).' — send a pay link or mark the balance paid.';
        }

        return redirect()->route('admin.orders.edit', $order)->with('success', $message);
    }

    public function refund(Request $request, Order $order, OrderStageService $stages, RefundVoucherIssuer $vouchers, PaymentGatewayRegistry $gateways): RedirectResponse
    {
        if (! in_array($order->payment_status, ['paid', 'partial', 'partially_refunded'], true)) {
            return back()->with('error', 'Only paid orders can be refunded.');
        }
        $order->loadMissing(['paymentMethod', 'items.product']);

        $data = $request->validate([
            'issue_voucher' => ['sometimes', 'boolean'],
            'issue_credit' => ['sometimes', 'boolean'],
            'restock' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);
        $issueVoucher = (bool) ($data['issue_voucher'] ?? false);
        $issueCredit = (bool) ($data['issue_credit'] ?? false);
        $restock = ! empty($data['restock']);

        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $already = (float) ($order->refunded_total ?? 0);
        $paidTotal = (float) ($details['paid_total'] ?? 0);
        if ($paidTotal <= 0) {
            $paidTotal = round(max((float) ($order->amount_paid ?? 0), (float) $order->grand_total + $already), 2);
        }
        $remaining = round(max(0, $paidTotal - $already), 2);

        $refundLines = [];
        $linesTotal = 0.0;
        foreach ($data['items'] as $line) {
            $item = $order->items->firstWhere('id', (int) $line['order_item_id']);
            if (! $item) {
                continue;
            }
            $qty = min((float) $line['qty'], (float) $item->qty);
            if ($qty <= 0) {
                continue;
            }
            $ratio = $qty / max(0.001, (float) $item->qty);
            $share = round(((float) $item->total) * $ratio, 2);
            $refundLines[] = [
                'item' => $item,
                'qty' => $qty,
                'share' => $share,
                'snapshot' => [
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'qty' => $qty,
                    'unit_price' => (float) $item->unit_price,
                    'total' => $share,
                ],
            ];
            $linesTotal += $share;
        }
        $amount = round($linesTotal, 2);
        if ($refundLines === [] || $amount < 0.01) {
            return back()->with('error', 'Select at least one product to refund.');
        }
        if ($amount > $remaining + 0.009) {
            return back()->with('error', 'Refund amount exceeds the remaining paid total.');
        }

        if (($issueVoucher || $issueCredit) && ! $order->customer_id) {
            return back()->with('error', 'A customer account is required to issue store credit or a voucher.');
        }

        $driver = (string) ($order->paymentMethod?->driver ?: 'offline');
        try {
            $gateway = $gateways->get($driver);
            if ($gateway instanceof RefundsPayments) {
                $result = $gateway->refund($order, $amount);
                if ($result->status === 'failed') {
                    return back()->with('error', $result->message ?: 'Gateway refund failed.');
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not refund through the payment driver.');
        }

        $voucherRule = null;
        $message = 'Payment refunded to bank / original payment method.';

        try {
            DB::transaction(function () use (
                $order,
                $refundLines,
                $restock,
                $amount,
                $already,
                $paidTotal,
                $details,
                $issueCredit,
                $issueVoucher,
                $vouchers,
                $stages,
                &$voucherRule,
                &$message,
            ) {
                $inventory = app(InventoryService::class);
                foreach ($refundLines as $row) {
                    /** @var \App\Domain\Order\Models\OrderItem $item */
                    $item = $row['item']->fresh() ?? $row['item'];
                    $qty = (float) $row['qty'];
                    if ($restock && $item->product) {
                        $warehouse = $inventory->warehouseForOrderItem(
                            $item->warehouse_id ? (int) $item->warehouse_id : null,
                            $item->product,
                            $qty,
                            $order->number
                        );
                        $inventory->restock(
                            $item->product,
                            $qty,
                            InventoryMovement::ACTION_RESTOCK,
                            'refund:'.$order->number,
                            $warehouse,
                            $item->combination_id ? (int) $item->combination_id : null,
                        );
                    }

                    if ($qty + 0.001 >= (float) $item->qty) {
                        $item->delete();
                    } else {
                        $ratio = $qty / max(0.001, (float) $item->qty);
                        $item->qty = round((float) $item->qty - $qty, 3);
                        $item->tax_amount = round((float) $item->tax_amount * (1 - $ratio), 2);
                        $item->total = round((float) $item->total * (1 - $ratio), 2);
                        $item->returned_qty = 0;
                        $item->save();
                    }
                }

                $order->refresh()->load('items');
                $subtotal = 0.0;
                $taxTotal = 0.0;
                foreach ($order->items as $item) {
                    $subtotal += (float) $item->unit_price * (float) $item->qty;
                    $taxTotal += (float) $item->tax_amount;
                }
                $discountTotal = min((float) $order->discount_total, $subtotal);
                $shippingTotal = $order->items->isEmpty() ? 0.0 : (float) $order->shipping_total;
                $grandTotal = round(max(0, $subtotal - $discountTotal + $taxTotal + $shippingTotal), 2);

                $newRefunded = round($already + $amount, 2);
                $full = $newRefunded + 0.009 >= $paidTotal || $order->items->isEmpty();

                OrderStageCatalog::seed();
                if ($full && $order->status !== 'refunded') {
                    $stages->apply($order, 'refunded', 'Payment refunded by admin');
                    $order->refresh();
                }

                if ($issueCredit && $order->customer_id) {
                    $buyer = Customer::query()->find($order->customer_id);
                    if ($buyer) {
                        app(StoreCreditService::class)->credit(
                            $buyer,
                            $amount,
                            'refund:'.$order->number,
                            (int) $order->id,
                            auth('admin')->id(),
                        );
                        $message = 'Store credit issued to the customer.';
                    }
                }
                if ($issueVoucher) {
                    $voucherRule = $vouchers->issue($order, $amount);
                    $message .= ' Store voucher '.$voucherRule->code.' issued for '.$amount.'.';
                }

                $refunds = is_array($details['refunds'] ?? null) ? $details['refunds'] : [];
                $refunds[] = [
                    'at' => now()->toIso8601String(),
                    'amount' => $amount,
                    'restock' => $restock,
                    'store_credit' => $issueCredit,
                    'voucher_id' => $voucherRule?->id,
                    'voucher_code' => $voucherRule?->code,
                    'items' => array_map(fn ($row) => $row['snapshot'], $refundLines),
                ];
                $details['paid_total'] = $paidTotal;
                $details['refunds'] = $refunds;

                $nextPayment = $full ? 'refunded' : 'partially_refunded';
                $order->forceFill([
                    'subtotal' => round($subtotal, 2),
                    'tax_total' => round($taxTotal, 2),
                    'discount_total' => round($discountTotal, 2),
                    'shipping_total' => round($shippingTotal, 2),
                    'grand_total' => $grandTotal,
                    'refunded_total' => $newRefunded,
                    'payment_status' => $nextPayment,
                    'payment_status_id' => PaymentStatusCatalog::idForCode($nextPayment),
                    'payment_details' => $details,
                ])->save();
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Refund was sent but the order could not be updated. Check the order and inventory.');
        }

        return redirect()->route('admin.orders.edit', $order)->with('success', $message)->with('toast', true);
    }

    public function approveShopReturn(Order $order, int $return, ShopReturnService $returns): RedirectResponse
    {
        $row = $order->shopReturns()->whereKey($return)->firstOrFail();
        $returns->approve($row, auth('admin')->id());

        return back()->with('success', 'Return approved.');
    }

    public function rejectShopReturn(Order $order, int $return, ShopReturnService $returns): RedirectResponse
    {
        $row = $order->shopReturns()->whereKey($return)->firstOrFail();
        $returns->reject($row, auth('admin')->id());

        return back()->with('success', 'Return rejected.');
    }

    public function receiveShopReturn(Order $order, int $return, ShopReturnService $returns): RedirectResponse
    {
        $row = $order->shopReturns()->whereKey($return)->firstOrFail();
        $returns->receive($row, auth('admin')->id());

        return back()->with('success', 'Return marked received.');
    }

    public function refundShopReturn(Order $order, int $return, ShopReturnService $returns): RedirectResponse
    {
        $row = $order->shopReturns()->whereKey($return)->firstOrFail();
        $returns->refund($row, auth('admin')->id());

        return back()->with('success', 'Return refunded.');
    }

    public function approveEuWithdrawal(Order $order, int $withdrawal, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $row = $order->euWithdrawalRequests()->whereKey($withdrawal)->firstOrFail();
        $withdrawals->approve($row, auth('admin')->id(), request('admin_note'));

        return back()->with('success', 'EU withdrawal approved.');
    }

    public function rejectEuWithdrawal(Order $order, int $withdrawal, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $row = $order->euWithdrawalRequests()->whereKey($withdrawal)->firstOrFail();
        $withdrawals->reject($row, auth('admin')->id(), request('admin_note'));

        return back()->with('success', 'EU withdrawal rejected.');
    }

    public function completeEuWithdrawal(Order $order, int $withdrawal, EuWithdrawalService $withdrawals): RedirectResponse
    {
        $row = $order->euWithdrawalRequests()->whereKey($withdrawal)->firstOrFail();
        $withdrawals->complete($row, request('admin_note'));

        return back()->with('success', 'EU withdrawal marked completed.');
    }

    public function comment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'visibility' => ['required', 'in:customer,private'],
        ]);
        OrderComment::query()->create([
            'order_id' => $order->id,
            'author_type' => 'admin',
            'author_id' => auth('admin')->id(),
            'visibility' => $data['visibility'],
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Comment added.');
    }

    public function payLink(Order $order): RedirectResponse
    {
        $due = round((float) ($order->amount_due ?? max(0, (float) $order->grand_total - (float) ($order->amount_paid ?? 0))), 2);
        if (in_array($order->payment_status, ['refunded'], true)) {
            return back()->with('error', 'This order is already settled.');
        }
        if (in_array($order->payment_status, ['paid'], true) && $due <= 0.009) {
            return back()->with('error', 'This order is already paid in full.');
        }
        $to = $order->email;
        if (! $to) {
            return back()->with('error', 'This order has no email address.');
        }
        $driver = $order->paymentMethod?->driver ?: 'offline';
        $body = '<p>A balance of <strong>'.e(number_format($due, 2)).' '.e((string) $order->currency_code).'</strong> is due on order <strong>'.e($order->number).'</strong>.</p>';
        if (in_array(strtolower((string) $driver), ['paypal', 'advanced-paypal', 'razorpay', 'upi', 'stripe'], true)) {
            $url = \App\Domain\Payment\PaymentSettings::sandboxPayUrl($driver, $order);
            $body .= '<p>Pay using the link below.</p><p><a href="'.e($url).'">'.$url.'</a></p>';
        } else {
            $body .= '<p>Please contact the store to complete payment for the remaining balance.</p>';
        }
        app(ShopMailer::class)->sendHtml(
            $to,
            ($due > 0.009 ? 'Balance due for order ' : 'Pay for order ').$order->number,
            $body,
            'pay-link'
        );

        return back()->with('success', 'Payment reminder emailed to '.$to.'.');
    }

    public function recordPayment(Request $request, Order $order): RedirectResponse
    {
        if (in_array($order->payment_status, ['refunded'], true)) {
            return back()->with('error', 'Refunded orders cannot accept new payments.');
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'mark_paid' => ['sometimes', 'boolean'],
        ]);

        $grand = round((float) $order->grand_total, 2);
        $paid = $this->resolvedAmountPaid($order, $grand);
        $due = round(max(0, $grand - $paid), 2);
        if ($due <= 0.009) {
            return back()->with('error', 'Nothing is due on this order.');
        }

        $amount = ! empty($data['mark_paid'])
            ? $due
            : round(min($due, (float) ($data['amount'] ?? $due)), 2);
        if ($amount < 0.01) {
            return back()->with('error', 'Enter a payment amount.');
        }

        $newPaid = round($paid + $amount, 2);
        $newDue = round(max(0, $grand - $newPaid), 2);
        $status = $newDue <= 0.009 ? 'paid' : 'partial';

        $previousPaymentStatus = (string) $order->payment_status;

        $order->forceFill([
            'amount_paid' => $newPaid,
            'amount_due' => $newDue,
            'payment_status' => $status,
            'payment_status_id' => PaymentStatusCatalog::idForCode($status),
        ])->save();

        $order->statusHistories()->create([
            'status' => $order->status,
            'order_stage_id' => $order->order_stage_id,
            'comment' => 'Payment recorded by admin: '.number_format($amount, 2).($status === 'paid' ? ' (order paid in full)' : ''),
        ]);

        if ($previousPaymentStatus !== 'paid' && $status === 'paid') {
            app(\App\Domain\Catalog\Services\GiftCardService::class)->issueForPaidOrder($order->fresh(['items.product']));
        }

        return back()->with('success', $status === 'paid'
            ? 'Balance paid. Order is fully paid.'
            : 'Payment of '.number_format($amount, 2).' recorded. Remaining due: '.number_format($newDue, 2).'.'
        )->with('toast', true);
    }

    public function splitShipment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);
        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'status' => Shipment::STATUS_PROCESSING,
            'notes' => 'Split shipment',
        ]);
        foreach ($data['items'] as $row) {
            $item = $order->items()->whereKey($row['order_item_id'])->first();
            if (! $item) {
                continue;
            }
            $shipment->items()->create([
                'order_item_id' => $item->id,
                'qty' => min((int) $item->qty, (int) $row['qty']),
            ]);
        }

        return back()->with('success', 'Shipment created.');
    }

    public function updateStatus(Request $request, Order $order, OrderStageService $stages): RedirectResponse
    {
        OrderStageCatalog::seed();
        $allowedIds = $this->allowedStatusIds($request, $order);
        $data = $request->validate([
            'order_stage_id' => ['required', 'integer', 'in:'.implode(',', $allowedIds ?: [0])],
            'comment' => ['nullable', 'string'],
        ]);

        $stage = OrderStage::query()->whereKey((int) $data['order_stage_id'])->firstOrFail();
        if ($stage->code !== $order->status) {
            $this->assertCanSetStatus($request, $stage->code);
        }

        wento_action('order.status', 'before', ['id' => $order->id, 'status' => $stage->code, 'order_stage_id' => $stage->id]);
        $stages->apply($order, $stage->id, $data['comment'] ?? null);
        wento_action('order.status', 'after', ['id' => $order->id, 'status' => $stage->code, 'order_stage_id' => $stage->id]);

        return back()->with('success', 'Order status updated.');
    }

    private function filteredQuery(Request $request)
    {
        $totalMin = $request->filled('total_min') ? $request->input('total_min') : $request->input('total');

        return Order::query()
            ->when($request->filled('q'), fn ($query) => $this->applySearch($query, $request->string('q')->toString()))
            ->when($request->filled('number'), fn ($query) => $query->where('number', 'like', PrefixQuery::like(ltrim($request->string('number')->toString(), '#'))))
            ->when($request->filled('customer'), fn ($query) => $this->applyCustomerFilter($query, $request->string('customer')->toString()))
            ->when($request->filled('payment_status'), fn ($query) => $query->where('orders.payment_status', $request->string('payment_status')))
            ->when($request->filled('payment_method'), fn ($query) => $query->whereHas(
                'paymentMethod',
                fn ($method) => $method->where('name', 'like', PrefixQuery::like($request->string('payment_method')->toString())),
            ))
            ->when($request->filled('id_min'), fn ($query) => $query->where('orders.id', '>=', (int) $request->input('id_min')))
            ->when($request->filled('id_max'), fn ($query) => $query->where('orders.id', '<=', (int) $request->input('id_max')))
            ->when($totalMin !== null && $totalMin !== '', fn ($query) => $query->where('grand_total', '>=', (float) $totalMin))
            ->when($request->filled('total_max'), fn ($query) => $query->where('grand_total', '<=', (float) $request->input('total_max')))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('orders.created_at', $request->input('date')))
            ->when($request->filled('from') && ! $request->filled('date'), fn ($query) => $query->where('orders.created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query->where('orders.created_at', '<=', $request->date('to')->endOfDay()));
    }

    private function normalizeSort(string $sort): string|\Illuminate\Database\Query\Expression
    {
        return match ($sort) {
            'number' => 'orders.number',
            'email' => 'orders.email',
            'customer' => DB::raw('(SELECT name FROM customers WHERE customers.id = orders.customer_id LIMIT 1)'),
            'status' => 'orders.status',
            'payment_status' => 'orders.payment_status',
            'grand_total', 'total' => 'orders.grand_total',
            'created_at', 'date' => 'orders.created_at',
            default => 'orders.id',
        };
    }

    private function exportCsv(Request $request): string
    {
        $status = $this->normalizeStatus($request->string('status')->toString());
        $fulfillment = $this->normalizeStatus($request->string('fulfillment')->toString());
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, ['id', 'number', 'email', 'customer', 'status', 'payment_status', 'payment_method', 'grand_total', 'currency', 'created_at']);

        $this->filteredQuery($request)
            ->with(['customer:id,name,email', 'paymentMethod:id,name'])
            ->when($ids !== [], fn ($query) => $query->whereIn('orders.id', $ids))
            ->when($status, fn ($query) => $query->where('orders.status', $status))
            ->when($fulfillment, fn ($query) => $query->where('orders.status', $fulfillment))
            ->orderByDesc('orders.id')
            ->limit(10000)
            ->each(function (Order $order) use ($out) {
                CsvSafe::put($out, [
                    $order->id,
                    $order->number,
                    $order->email,
                    $order->customer?->name ?: '',
                    $order->status,
                    $order->payment_status,
                    $order->paymentMethod?->name ?: '',
                    $order->grand_total,
                    $order->currency_code,
                    $order->created_at?->toDateTimeString(),
                ]);
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    private function importCsv(string $content): int
    {
        return OrderCsvSchema::import($content);
    }

    /**
     * Map list filters to stored status values.
     */
    private function normalizeStatus(string $status): ?string
    {
        if ($status === '' || $status === 'all') {
            return null;
        }

        if ($status === 'delivered') {
            return 'completed';
        }

        OrderStageCatalog::seed();
        if (OrderStage::query()->where('code', $status)->exists()) {
            return $status;
        }

        return match ($status) {
            'pending', 'processing', 'shipped', 'cancelled', 'refunded', 'completed' => $status,
            default => null,
        };
    }

    private function applyCustomerFilter($query, string $raw): void
    {
        $term = trim($raw);
        if ($term === '') {
            return;
        }

        $like = PrefixQuery::like($term);
        $query->where(function ($inner) use ($like) {
            $inner->where('email', 'like', $like)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhere('billing_address->first_name', 'like', $like)
                ->orWhere('billing_address->last_name', 'like', $like);
        });
    }

    /**
     * Match list columns: order, customer, status, payment, fulfillment, total, date.
     */
    private function applySearch($query, string $raw): void
    {
        $term = ltrim(trim($raw), '#');
        if ($term === '') {
            return;
        }

        $like = PrefixQuery::like($term);
        $lower = strtolower($term);
        $statusMatch = $this->normalizeStatus($lower);
        $paymentMatch = in_array($lower, ['paid', 'pending', 'failed'], true) ? $lower : null;
        $date = $this->parseSearchDate($term);

        $query->where(function ($inner) use ($like, $term, $statusMatch, $paymentMatch, $date) {
            $inner->where('number', 'like', $like)
                ->orWhere('checkout_reference', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('payment_status', 'like', $like)
                ->orWhere('grand_total', 'like', $like)
                ->orWhereHas('customer', function ($customer) use ($like) {
                    $customer->where('name', 'like', $like)->orWhere('email', 'like', $like);
                })
                ->orWhereHas('paymentMethod', fn ($method) => $method->where('name', 'like', $like))
                ->orWhere('billing_address->first_name', 'like', $like)
                ->orWhere('billing_address->last_name', 'like', $like);

            if (is_numeric($term)) {
                $inner->orWhere('grand_total', $term);
            }

            if ($statusMatch) {
                $inner->orWhere('status', $statusMatch);
            }

            if ($paymentMatch) {
                $inner->orWhere('payment_status', $paymentMatch);
            }

            if ($date) {
                $inner->orWhereDate('created_at', $date);
            }
        });
    }

    private function parseSearchDate(string $term): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'M j, Y', 'M d, Y', 'd M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $term);

                return $date->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * KPI cards above the orders list.
     *
     * @return array<string, mixed>
     */
    private function orderStats(): array
    {
        return Cache::remember('admin.orders.stats.'.ShopStats::orderCacheSuffix(), 60, function () {
            $now = now();
            $prevStart = $now->copy()->subDays(60);
            $prevEnd = $now->copy()->subDays(30);

            $row = Order::query()
                ->toBase()
                ->selectRaw(
                    'COUNT(*) as total,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_count,
                    SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as total_prev,
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as new_prev,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = ? AND created_at < ? THEN 1 ELSE 0 END) as pending_prev,
                    SUM(CASE WHEN payment_status = ? THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN payment_status = ? AND created_at < ? THEN 1 ELSE 0 END) as paid_prev',
                    [$prevEnd, $prevEnd, $prevStart, $prevEnd, 'pending', 'pending', $prevEnd, 'paid', 'paid', $prevEnd]
                )
                ->first();

            $total = (int) ($row->total ?? 0);
            $totalPrev = (int) ($row->total_prev ?? 0);
            $new = (int) ($row->new_count ?? 0);
            $newPrev = (int) ($row->new_prev ?? 0);
            $pending = (int) ($row->pending ?? 0);
            $pendingPrev = (int) ($row->pending_prev ?? 0);
            $paidRate = $total > 0 ? round(((int) ($row->paid ?? 0) / $total) * 100, 1) : 0;
            $paidRatePrev = $totalPrev > 0 ? round(((int) ($row->paid_prev ?? 0) / $totalPrev) * 100, 1) : 0;

            $aov = (float) Order::query()->where('status', '!=', 'cancelled')->avg('grand_total');
            $aovPrev = (float) Order::query()
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$prevStart, $prevEnd])
                ->avg('grand_total');

            return [
                'total' => $total,
                'total_trend' => $this->statTrend($total, $totalPrev),
                'new' => $new,
                'new_trend' => $this->statTrend($new, $newPrev),
                'pending' => $pending,
                'pending_trend' => $this->statTrend($pending, $pendingPrev),
                'paid_rate' => $paidRate,
                'paid_trend' => $this->statTrend($paidRate, $paidRatePrev),
                'aov' => round($aov, 2),
                'aov_trend' => $this->statTrend($aov, $aovPrev),
            ];
        });
    }

    private function statTrend(float|int $current, float|int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Tab counts for the orders list.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        return Cache::remember('admin.orders.status_counts.'.ShopStats::orderCacheSuffix(), 60, function () {
            $counts = Order::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            return [
                'all' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'processing' => (int) ($counts['processing'] ?? 0),
                'shipped' => (int) ($counts['shipped'] ?? 0),
                'delivered' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
                'refunded' => (int) ($counts['refunded'] ?? 0),
            ];
        });
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function countForStatus(array $counts, ?string $status): int
    {
        if ($status === 'completed') {
            return $counts['delivered'];
        }

        return $status ? ($counts[$status] ?? 0) : $counts['all'];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Order $order = null): array
    {
        OrderStageCatalog::seed();

        return [
            'order_pdf' => \App\Domain\Settings\DocumentCommerceSettings::orderPdf(),
            'invoices_enabled' => \App\Domain\Settings\DocumentCommerceSettings::invoicesEnabled(),
            'counts' => $this->statusCounts(),
            'stages' => $this->stageOptions($order),
            'paymentStatuses' => PaymentStatusCatalog::options(),
            'salesChannels' => SalesChannelCatalog::options(),
            'customers' => Customer::query()
                ->with('addresses')
                ->withCount('orders')
                ->withSum('orders', 'grand_total')
                ->orderBy('name')
                ->get()
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->addresses->first()?->phone,
                    'orders_count' => (int) $customer->orders_count,
                    'total_spent' => (float) ($customer->orders_sum_grand_total ?? 0),
                    'addresses' => $customer->addresses->map(fn ($address) => [
                        'id' => $address->id,
                        'label' => $address->label ?: 'Address',
                        'first_name' => $address->first_name,
                        'last_name' => $address->last_name,
                        'phone' => $address->phone,
                        'line1' => $address->line1,
                        'line2' => $address->line2,
                        'city' => $address->city,
                        'state' => $address->state,
                        'postal_code' => $address->postal_code,
                        'country_code' => $address->country_code,
                    ])->values(),
                ]),
            'paymentMethods' => PaymentMethod::query()->where('is_enabled', true)->orderBy('position')->get(['id', 'name', 'code']),
            'shippingMethods' => ShippingMethod::query()->where('is_enabled', true)->orderBy('position')->get(['id', 'name', 'code', 'price']),
            'taxClasses' => TaxClass::query()
                ->with(['rules' => fn ($query) => $query->where('is_enabled', true)])
                ->get()
                ->map(fn (TaxClass $class) => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'rate' => (float) ($class->rules->first()?->rate ?? 0),
                ]),
            'currencies' => Currency::query()->where('is_enabled', true)->orderByDesc('is_default')->get(['code', 'name', 'symbol']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lines: array<int, array<string, mixed>>, subtotal: float, discount_total: float, tax_total: float, shipping_total: float, grand_total: float}
     */
    private function buildOrderTotals(array $data): array
    {
        $shipping = isset($data['shipping_method_id'])
            ? ShippingMethod::query()->find($data['shipping_method_id'])
            : null;
        $shippingTotal = array_key_exists('shipping_total', $data) && $data['shipping_total'] !== null && $data['shipping_total'] !== ''
            ? (float) $data['shipping_total']
            : ($shipping ? (float) $shipping->price : 0);

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        foreach ($data['items'] as $item) {
            $qty = (int) $item['qty'];
            $unit = (float) $item['unit_price'];
            $line = $qty * $unit;
            $discount = (float) ($item['discount'] ?? 0);
            $discountAmount = ($item['discount_type'] ?? 'percent') === 'amount'
                ? min($discount, $line)
                : $line * ($discount / 100);
            $taxable = max(0, $line - $discountAmount);
            $tax = $taxable * ((float) ($item['tax_rate'] ?? 0) / 100);

            $subtotal += $line;
            $discountTotal += $discountAmount;
            $taxTotal += $tax;
            $lines[] = [
                'product_id' => $item['product_id'] ?? null,
                'sku' => $item['sku'] ?: 'CUSTOM',
                'name' => $item['name'],
                'qty' => $qty,
                'unit_price' => $unit,
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                'tax_amount' => round($tax, 2),
                'total' => round($taxable + $tax, 2),
            ];
        }

        $afterLine = max(0, $subtotal - $discountTotal);
        $orderDiscount = (float) ($data['order_discount'] ?? 0);
        $orderDiscountAmount = ($data['order_discount_type'] ?? 'amount') === 'percent'
            ? $afterLine * ($orderDiscount / 100)
            : min($orderDiscount, $afterLine);
        $discountTotal += $orderDiscountAmount;

        $shipTax = 0.0;
        $shipClass = PricingSettings::shippingTax() && $shippingTotal > 0 && $shipping?->tax_class_id
            ? (int) $shipping->tax_class_id
            : null;
        if ($shipClass) {
            $countryCode = $data['shipping_address']['country_code']
                ?? $data['billing_address']['country_code']
                ?? null;
            $regionCode = $data['shipping_address']['state']
                ?? $data['shipping_address']['region_code']
                ?? $data['billing_address']['state']
                ?? null;
            $tax = app(TaxEngine::class);
            $layers = $tax->resolveLayers($shipClass, is_string($countryCode) ? $countryCode : null, is_string($regionCode) ? $regionCode : null);
            // Admin order lines are priced tax-exclusive; keep shipping tax on the same basis.
            $shipTax = $tax->applyLayers($shippingTotal, $layers, false)['tax'];
            $taxTotal += $shipTax;
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => $shippingTotal,
            'shipping_tax' => round($shipTax, 2),
            'grand_total' => round($subtotal - $discountTotal + $taxTotal + $shippingTotal, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function withAddressDefaults(array $address, Order $order): array
    {
        $name = $order->customer?->name ?: ($order->email ?: 'Customer');
        $parts = preg_split('/\s+/', trim($name), 2) ?: [$name];

        return [
            'id' => $address['id'] ?? null,
            'first_name' => ($address['first_name'] ?? '') ?: ($parts[0] ?? $name),
            'last_name' => $address['last_name'] ?? ($parts[1] ?? ''),
            'phone' => $address['phone'] ?? '',
            'line1' => $address['line1'] ?? '',
            'line2' => $address['line2'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'postal_code' => $address['postal_code'] ?? '',
            'country_code' => ($address['country_code'] ?? '') ?: 'IN',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, string $locale): array
    {
        $name = $product->translations->firstWhere('language_code', $locale)?->name
            ?? $product->translations->first()?->name
            ?? $product->sku;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $name,
            'price' => $product->sellingPrice(),
            'stock_qty' => (int) $product->stock_qty,
            'stock_status' => $product->stock_status,
            'cover_path' => $product->cover_path ?: $product->images->first()?->path,
            'tax_class_id' => $product->tax_class_id,
            'tax_rate' => (float) ($product->taxClass?->rules->firstWhere('is_enabled', true)?->rate ?? 0),
        ];
    }

    private function resolvedAmountPaid(Order $order, float $fallbackGrand): float
    {
        if ($order->amount_paid !== null) {
            return round((float) $order->amount_paid, 2);
        }

        $details = is_array($order->payment_details) ? $order->payment_details : [];
        if (isset($details['paid_total']) && (float) $details['paid_total'] > 0) {
            return round((float) $details['paid_total'] - (float) ($order->refunded_total ?? 0), 2);
        }

        if (in_array((string) $order->payment_status, ['paid', 'partially_refunded'], true)) {
            return round(max(0, $fallbackGrand - (float) ($order->refunded_total ?? 0)), 2);
        }

        if ((string) $order->payment_status === 'partial') {
            return round(max(0, $fallbackGrand - (float) ($order->amount_due ?? $fallbackGrand)), 2);
        }

        return 0.0;
    }

    /**
     * @return array{0: float, 1: float, 2: string}
     */
    private function syncPaymentProgress(float $paidBaseline, float $newGrand, string $requestedStatus, string $previousStatus): array
    {
        $paid = round(max(0, $paidBaseline), 2);
        $newGrand = round($newGrand, 2);

        if ($requestedStatus === 'refunded' || $previousStatus === 'refunded') {
            return [$paid, round(max(0, $newGrand - $paid), 2), 'refunded'];
        }

        if ($requestedStatus === 'paid' && $previousStatus !== 'paid' && $previousStatus !== 'partial' && $paid <= 0.009) {
            return [$newGrand, 0.0, 'paid'];
        }

        if ($requestedStatus === 'failed') {
            return [$paid, round(max(0, $newGrand - $paid), 2), 'failed'];
        }

        $due = round(max(0, $newGrand - $paid), 2);
        if ($due <= 0.009) {
            return [min($paid, $newGrand), 0.0, 'paid'];
        }
        if ($paid > 0.009) {
            return [$paid, $due, 'partial'];
        }

        return [0.0, $newGrand, in_array($requestedStatus, ['pending', 'failed'], true) ? $requestedStatus : 'pending'];
    }

    private function orderHasCreditSlip(Order $order): bool
    {
        if ((float) ($order->refunded_total ?? 0) > 0) {
            return true;
        }

        return in_array($order->payment_status, ['refunded', 'partially_refunded'], true)
            || $order->status === 'refunded';
    }

    /**
     * @return list<array{at: string|null, amount: float, voucher_id: int|null, voucher_code: string|null, store_credit: bool, items: list<array<string, mixed>>}>
     */
    private function orderRefundRecords(Order $order): array
    {
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $rows = is_array($details['refunds'] ?? null) ? $details['refunds'] : [];

        return collect($rows)
            ->map(fn ($row) => [
                'at' => $row['at'] ?? null,
                'amount' => (float) ($row['amount'] ?? 0),
                'voucher_id' => isset($row['voucher_id']) ? (int) $row['voucher_id'] : null,
                'voucher_code' => $row['voucher_code'] ?? null,
                'store_credit' => (bool) ($row['store_credit'] ?? false),
                'items' => is_array($row['items'] ?? null) ? $row['items'] : [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string, tone: string|null, unlock_invoice: bool, unlock_credit_slip: bool, unlock_delivery_slip: bool}>
     */
    private function stageOptions(?Order $order = null): array
    {
        OrderStageCatalog::seed();

        return OrderStage::optionsForAdmin(request()->user('admin'), $order)
            ->map(fn (OrderStage $stage) => [
                'id' => (int) $stage->id,
                'code' => $stage->code,
                'name' => $stage->name,
                'tone' => $stage->tone,
                'unlock_invoice' => $stage->unlock_invoice,
                'unlock_credit_slip' => $stage->unlock_credit_slip,
                'unlock_delivery_slip' => $stage->unlock_delivery_slip,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function allowedStatusIds(Request $request, ?Order $order = null): array
    {
        return OrderStage::optionsForAdmin($request->user('admin'), $order)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function allowedStatusCodes(Request $request, ?Order $order = null): array
    {
        return OrderStage::optionsForAdmin($request->user('admin'), $order)->pluck('code')->all();
    }

    private function assertCanSetStatus(Request $request, string $code): void
    {
        $stage = OrderStage::query()->where('code', $code)->first();
        abort_unless(
            $stage && $stage->is_enabled && $stage->isAccessibleTo($request->user('admin')),
            403,
            'You cannot set this order status.'
        );
    }
}
