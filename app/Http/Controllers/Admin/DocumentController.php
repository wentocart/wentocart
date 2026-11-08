<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Order\CreditSlipDocument;
use App\Domain\Order\DeliverySlipDocument;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\AdminCsv;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\DocumentPdf;
use App\Domain\Settings\DocumentRenderer;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Domain\Settings\ShopMailer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function invoices(Request $request, InvoiceDocument $invoices): Response
    {
        $search = trim($request->string('q')->toString());
        $number = ltrim(trim($request->string('number')->toString()), '#');
        $customer = trim($request->string('customer')->toString());
        $paymentMethod = trim($request->string('payment_method')->toString());
        $totalMin = trim($request->string('total_min')->toString() ?: $request->string('total')->toString());
        $totalMax = trim($request->string('total_max')->toString());
        $date = trim($request->string('date')->toString());
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $sort = $this->documentSort($requestedSort);
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        $rows = $invoices->paidQuery()
            ->with(['customer:id,name,email', 'paymentMethod:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('orders.number', 'like', $like)
                        ->orWhere('orders.email', 'like', $like)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->when($number !== '', fn ($query) => $query->where('orders.number', 'like', '%'.$number.'%'))
            ->when($customer !== '', function ($query) use ($customer) {
                $like = '%'.$customer.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('orders.email', 'like', $like)
                        ->orWhere('orders.billing_address->first_name', 'like', $like)
                        ->orWhere('orders.billing_address->last_name', 'like', $like)
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->when($paymentMethod !== '', function ($query) use ($paymentMethod) {
                $like = '%'.$paymentMethod.'%';
                $query->where(function ($inner) use ($like, $paymentMethod) {
                    $inner->whereHas('paymentMethod', fn ($method) => $method->where('name', 'like', $like)->orWhere('code', 'like', $like));
                    if (stripos('offline', $paymentMethod) !== false) {
                        $inner->orWhereNull('orders.payment_method_id');
                    }
                });
            })
            ->when($request->filled('id_min'), fn ($query) => $query->where('orders.id', '>=', (int) $request->input('id_min')))
            ->when($request->filled('id_max'), fn ($query) => $query->where('orders.id', '<=', (int) $request->input('id_max')))
            ->when($totalMin !== '', fn ($query) => $query->where('orders.grand_total', '>=', (float) $totalMin))
            ->when($totalMax !== '', fn ($query) => $query->where('orders.grand_total', '<=', (float) $totalMax))
            ->when($date !== '', fn ($query) => $query->whereDate('orders.created_at', $date))
            ->orderBy($sort, $dir)
            ->orderByDesc('orders.id')
            ->adminPaginate(10);

        return Inertia::render('Invoices/Index', [
            'invoices_enabled' => DocumentCommerceSettings::invoicesEnabled(),
            'invoices' => $rows,
            'filters' => [
                'q' => $search,
                'number' => $number,
                'customer' => $customer,
                'payment_method' => $paymentMethod,
                'total_min' => $totalMin,
                'total_max' => $totalMax,
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'date' => $date,
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function exportInvoices(Request $request, InvoiceDocument $invoices)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        $rows = $invoices->paidQuery()
            ->with(['customer:id,name,email', 'paymentMethod:id,name'])
            ->when($ids !== [], fn ($query) => $query->whereIn('orders.id', $ids))
            ->orderByDesc('orders.id')
            ->limit(10000)
            ->get()
            ->map(fn (Order $order) => [
                'number' => $order->number,
                'email' => $order->email,
                'customer' => $order->customer?->name,
                'payment_method' => $order->paymentMethod?->name,
                'grand_total' => $order->grand_total,
                'currency' => $order->currency_code,
                'created_at' => $order->created_at?->toDateTimeString(),
            ])
            ->all();

        return $this->csvDownload('invoices.csv', AdminCsv::stringify([
            'number', 'email', 'customer', 'payment_method', 'grand_total', 'currency', 'created_at',
        ], $rows));
    }

    public function show(Request $request, Order $order, InvoiceDocument $invoices): Response|RedirectResponse
    {
        if ($redirect = $this->invoicesTurnedOff()) {
            return $redirect;
        }
        $invoices->ensurePaid($order);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoices->payload($order),
            'source' => $request->routeIs('invoices.*') ? 'invoices' : 'orders',
            'urls' => $this->urls($request, $order),
            'can_view_order' => (bool) $request->user('admin')?->hasPermission('orders.manage'),
        ]);
    }

    public function download(Order $order, InvoiceDocument $invoices, DocumentPdf $pdf): HttpResponse|RedirectResponse
    {
        if ($redirect = $this->invoicesTurnedOff()) {
            return $redirect;
        }
        $invoices->ensurePaid($order);

        return $this->pdfFile($pdf, $invoices->html($order), 'invoice-'.$order->number.'.pdf');
    }

    public function downloadPdf(Order $order, InvoiceDocument $invoices, DocumentPdf $pdf): HttpResponse|RedirectResponse
    {
        if ($redirect = $this->invoicesTurnedOff()) {
            return $redirect;
        }
        $invoices->ensurePaid($order);

        return $this->pdfFile($pdf, $invoices->html($order), 'invoice-'.$order->number.'.pdf');
    }

    public function email(Request $request, Order $order, InvoiceDocument $invoices, ShopMailer $mailer): RedirectResponse
    {
        if ($redirect = $this->invoicesTurnedOff()) {
            return $redirect;
        }
        $invoices->ensurePaid($order);
        $to = $order->email ?: $order->customer?->email;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This order has no customer email.');
        }

        $shop = $invoices->shop()['name'];
        $subject = app(DocumentRenderer::class)->emailSubject(
            DocumentTemplate::TYPE_INVOICE,
            $order,
            $invoices->payload($order),
            'Invoice #'.$order->number.' from '.$shop,
        );
        $sent = $mailer->sendHtml(
            $to,
            $subject,
            $invoices->html($order),
            'invoice',
        );

        return back()->with(
            $sent ? 'success' : 'error',
            $sent ? 'Invoice emailed to '.$to.'.' : 'Could not send the invoice email.',
        );
    }

    public function creditSlips(Request $request): Response
    {
        $number = ltrim(trim($request->string('number')->toString()), '#');
        $customer = trim($request->string('customer')->toString());
        $paymentMethod = trim($request->string('payment_method')->toString());
        $totalMin = trim($request->string('total_min')->toString() ?: $request->string('total')->toString());
        $totalMax = trim($request->string('total_max')->toString());
        $date = trim($request->string('date')->toString());
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $sort = $this->documentSort($requestedSort);
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        $rows = app(CreditSlipDocument::class)->refundedQuery()
            ->with(['customer:id,name,email', 'paymentMethod:id,name'])
            ->when($number !== '', fn ($query) => $query->where('orders.number', 'like', '%'.$number.'%'))
            ->when($customer !== '', function ($query) use ($customer) {
                $like = '%'.$customer.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('orders.email', 'like', $like)
                        ->orWhere('orders.billing_address->first_name', 'like', $like)
                        ->orWhere('orders.billing_address->last_name', 'like', $like)
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->when($paymentMethod !== '', function ($query) use ($paymentMethod) {
                $like = '%'.$paymentMethod.'%';
                $query->where(function ($inner) use ($like, $paymentMethod) {
                    $inner->whereHas('paymentMethod', fn ($method) => $method->where('name', 'like', $like)->orWhere('code', 'like', $like));
                    if (stripos('offline', $paymentMethod) !== false) {
                        $inner->orWhereNull('orders.payment_method_id');
                    }
                });
            })
            ->when($request->filled('id_min'), fn ($query) => $query->where('orders.id', '>=', (int) $request->input('id_min')))
            ->when($request->filled('id_max'), fn ($query) => $query->where('orders.id', '<=', (int) $request->input('id_max')))
            ->when($totalMin !== '', fn ($query) => $query->where('orders.grand_total', '>=', (float) $totalMin))
            ->when($totalMax !== '', fn ($query) => $query->where('orders.grand_total', '<=', (float) $totalMax))
            ->when($date !== '', fn ($query) => $query->whereDate('orders.created_at', $date))
            ->orderBy($sort, $dir)
            ->orderByDesc('orders.id')
            ->adminPaginate(10);

        return Inertia::render('CreditSlips/Index', [
            'creditSlips' => $rows,
            'filters' => [
                'number' => $number,
                'customer' => $customer,
                'payment_method' => $paymentMethod,
                'total_min' => $totalMin,
                'total_max' => $totalMax,
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'date' => $date,
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function exportCreditSlips(Request $request, CreditSlipDocument $creditSlips)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        $rows = $creditSlips->refundedQuery()
            ->with(['customer:id,name,email', 'paymentMethod:id,name'])
            ->when($ids !== [], fn ($query) => $query->whereIn('orders.id', $ids))
            ->orderByDesc('orders.id')
            ->limit(10000)
            ->get()
            ->map(fn (Order $order) => [
                'number' => $order->number,
                'email' => $order->email,
                'customer' => $order->customer?->name,
                'payment_method' => $order->paymentMethod?->name,
                'grand_total' => $order->grand_total,
                'currency' => $order->currency_code,
                'created_at' => $order->created_at?->toDateTimeString(),
            ])
            ->all();

        return $this->csvDownload('credit-slips.csv', AdminCsv::stringify([
            'number', 'email', 'customer', 'payment_method', 'grand_total', 'currency', 'created_at',
        ], $rows));
    }

    public function showCreditSlip(Request $request, Order $order, CreditSlipDocument $creditSlips): Response
    {
        $creditSlips->ensureRefunded($order);

        return Inertia::render('CreditSlips/Show', [
            'creditSlip' => $creditSlips->payload($order),
            'source' => $request->routeIs('credit-slips.*') ? 'credit-slips' : 'orders',
            'urls' => $this->creditSlipUrls($request, $order),
            'can_view_order' => (bool) $request->user('admin')?->hasPermission('orders.manage'),
        ]);
    }

    public function downloadCreditSlip(Order $order, CreditSlipDocument $creditSlips, DocumentPdf $pdf): HttpResponse
    {
        $creditSlips->ensureRefunded($order);

        return $this->pdfFile($pdf, $creditSlips->html($order), 'credit-slip-'.$order->number.'.pdf');
    }

    public function downloadCreditSlipPdf(Order $order, CreditSlipDocument $creditSlips, DocumentPdf $pdf): HttpResponse
    {
        $creditSlips->ensureRefunded($order);

        return $this->pdfFile($pdf, $creditSlips->html($order), 'credit-slip-'.$order->number.'.pdf');
    }

    public function emailCreditSlip(Request $request, Order $order, CreditSlipDocument $creditSlips, ShopMailer $mailer): RedirectResponse
    {
        $creditSlips->ensureRefunded($order);
        $to = $order->email ?: $order->customer?->email;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This order has no customer email.');
        }

        $shop = $creditSlips->shop()['name'];
        $subject = app(DocumentRenderer::class)->emailSubject(
            DocumentTemplate::TYPE_CREDIT_SLIP,
            $order,
            $creditSlips->payload($order),
            'Credit Slip #'.$order->number.' from '.$shop,
        );
        $sent = $mailer->sendHtml(
            $to,
            $subject,
            $creditSlips->html($order),
            'credit-slip',
        );

        return back()->with(
            $sent ? 'success' : 'error',
            $sent ? 'Credit slip emailed to '.$to.'.' : 'Could not send the credit slip email.',
        );
    }

    public function deliverySlips(Request $request, DeliverySlipDocument $deliverySlips): Response
    {
        $number = ltrim(trim($request->string('number')->toString()), '#');
        $customer = trim($request->string('customer')->toString());
        $shippingMethod = trim($request->string('shipping_method')->toString());
        $date = trim($request->string('date')->toString());
        $requestedSort = $request->string('sort')->toString() ?: 'id';
        $sort = $this->documentSort($requestedSort);
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        $rows = $deliverySlips->deliveryQuery()
            ->with(['customer:id,name,email', 'shippingMethod:id,name'])
            ->when($number !== '', fn ($query) => $query->where('orders.number', 'like', '%'.$number.'%'))
            ->when($customer !== '', function ($query) use ($customer) {
                $like = '%'.$customer.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('orders.email', 'like', $like)
                        ->orWhere('orders.shipping_address->first_name', 'like', $like)
                        ->orWhere('orders.shipping_address->last_name', 'like', $like)
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->when($shippingMethod !== '', function ($query) use ($shippingMethod) {
                $like = '%'.$shippingMethod.'%';
                $query->where(function ($inner) use ($like, $shippingMethod) {
                    $inner->whereHas('shippingMethod', fn ($method) => $method->where('name', 'like', $like)->orWhere('code', 'like', $like));
                    if (stripos('offline', $shippingMethod) !== false) {
                        $inner->orWhereNull('orders.shipping_method_id');
                    }
                });
            })
            ->when($request->filled('id_min'), fn ($query) => $query->where('orders.id', '>=', (int) $request->input('id_min')))
            ->when($request->filled('id_max'), fn ($query) => $query->where('orders.id', '<=', (int) $request->input('id_max')))
            ->when($date !== '', fn ($query) => $query->whereDate('orders.created_at', $date))
            ->orderBy($sort, $dir)
            ->orderByDesc('orders.id')
            ->adminPaginate(10);

        return Inertia::render('DeliverySlips/Index', [
            'deliverySlips' => $rows,
            'filters' => [
                'number' => $number,
                'customer' => $customer,
                'shipping_method' => $shippingMethod,
                'id_min' => $request->string('id_min')->toString(),
                'id_max' => $request->string('id_max')->toString(),
                'date' => $date,
                'sort' => $requestedSort,
                'dir' => $dir,
            ],
        ]);
    }

    public function exportDeliverySlips(Request $request, DeliverySlipDocument $deliverySlips)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        $rows = $deliverySlips->deliveryQuery()
            ->with(['customer:id,name,email', 'shippingMethod:id,name'])
            ->when($ids !== [], fn ($query) => $query->whereIn('orders.id', $ids))
            ->orderByDesc('orders.id')
            ->limit(10000)
            ->get()
            ->map(fn (Order $order) => [
                'number' => $order->number,
                'email' => $order->email,
                'customer' => $order->customer?->name,
                'shipping_method' => $order->shippingMethod?->name,
                'grand_total' => $order->grand_total,
                'currency' => $order->currency_code,
                'created_at' => $order->created_at?->toDateTimeString(),
            ])
            ->all();

        return $this->csvDownload('delivery-slips.csv', AdminCsv::stringify([
            'number', 'email', 'customer', 'shipping_method', 'grand_total', 'currency', 'created_at',
        ], $rows));
    }

    public function showDeliverySlip(Request $request, Order $order, DeliverySlipDocument $deliverySlips): Response
    {
        $deliverySlips->ensureDeliverable($order);

        return Inertia::render('DeliverySlips/Show', [
            'deliverySlip' => $deliverySlips->payload($order),
            'source' => $request->routeIs('delivery-slips.*') ? 'delivery-slips' : 'orders',
            'urls' => $this->deliverySlipUrls($request, $order),
            'can_view_order' => (bool) $request->user('admin')?->hasPermission('orders.manage'),
        ]);
    }

    public function downloadDeliverySlip(Order $order, DeliverySlipDocument $deliverySlips, DocumentPdf $pdf): HttpResponse
    {
        $deliverySlips->ensureDeliverable($order);

        return $this->pdfFile($pdf, $deliverySlips->html($order), 'delivery-slip-'.$order->number.'.pdf');
    }

    public function downloadDeliverySlipPdf(Order $order, DeliverySlipDocument $deliverySlips, DocumentPdf $pdf): HttpResponse
    {
        $deliverySlips->ensureDeliverable($order);

        return $this->pdfFile($pdf, $deliverySlips->html($order), 'delivery-slip-'.$order->number.'.pdf');
    }

    public function emailDeliverySlip(Request $request, Order $order, DeliverySlipDocument $deliverySlips, ShopMailer $mailer): RedirectResponse
    {
        $deliverySlips->ensureDeliverable($order);
        $to = $order->email ?: $order->customer?->email;
        if (! is_string($to) || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This order has no customer email.');
        }

        $shop = $deliverySlips->shop()['name'];
        $subject = app(DocumentRenderer::class)->emailSubject(
            DocumentTemplate::TYPE_DELIVERY_SLIP,
            $order,
            $deliverySlips->payload($order),
            'Delivery Slip #'.$order->number.' from '.$shop,
        );
        $sent = $mailer->sendHtml(
            $to,
            $subject,
            $deliverySlips->html($order),
            'delivery-slip',
        );

        return back()->with(
            $sent ? 'success' : 'error',
            $sent ? 'Delivery slip emailed to '.$to.'.' : 'Could not send the delivery slip email.',
        );
    }

    /**
     * @return array{show: string, download: string, email: string, order: string}
     */
    protected function invoicesTurnedOff(): ?RedirectResponse
    {
        if (DocumentCommerceSettings::invoicesEnabled()) {
            return null;
        }

        return redirect()
            ->route('admin.order-settings.edit', ['tab' => 'invoices'])
            ->with('error', InvoiceDocument::DISABLED_MESSAGE);
    }

    protected function urls(Request $request, Order $order): array
    {
        if ($request->routeIs('invoices.*')) {
            return [
                'show' => '/admin/invoices/'.$order->id,
                'download' => '/admin/invoices/'.$order->id.'/download',
                'pdf' => '/admin/invoices/'.$order->id.'/pdf',
                'email' => '/admin/invoices/'.$order->id.'/email',
                'order' => '/admin/orders/'.$order->id,
            ];
        }

        return [
            'show' => '/admin/orders/'.$order->id.'/invoice',
            'download' => '/admin/orders/'.$order->id.'/invoice/download',
            'pdf' => '/admin/orders/'.$order->id.'/invoice/pdf',
            'email' => '/admin/orders/'.$order->id.'/invoice/email',
            'order' => '/admin/orders/'.$order->id,
        ];
    }

    /**
     * @return array{show: string, download: string, email: string, order: string}
     */
    protected function creditSlipUrls(Request $request, Order $order): array
    {
        if ($request->routeIs('credit-slips.*')) {
            return [
                'show' => '/admin/credit-slips/'.$order->id,
                'download' => '/admin/credit-slips/'.$order->id.'/download',
                'pdf' => '/admin/credit-slips/'.$order->id.'/pdf',
                'email' => '/admin/credit-slips/'.$order->id.'/email',
                'order' => '/admin/orders/'.$order->id,
            ];
        }

        return [
            'show' => '/admin/orders/'.$order->id.'/credit-slip',
            'download' => '/admin/orders/'.$order->id.'/credit-slip/download',
            'pdf' => '/admin/orders/'.$order->id.'/credit-slip/pdf',
            'email' => '/admin/orders/'.$order->id.'/credit-slip/email',
            'order' => '/admin/orders/'.$order->id,
        ];
    }

    /**
     * @return array{show: string, download: string, email: string, order: string}
     */
    protected function deliverySlipUrls(Request $request, Order $order): array
    {
        if ($request->routeIs('delivery-slips.*')) {
            return [
                'show' => '/admin/delivery-slips/'.$order->id,
                'download' => '/admin/delivery-slips/'.$order->id.'/download',
                'pdf' => '/admin/delivery-slips/'.$order->id.'/pdf',
                'email' => '/admin/delivery-slips/'.$order->id.'/email',
                'order' => '/admin/orders/'.$order->id,
            ];
        }

        return [
            'show' => '/admin/orders/'.$order->id.'/delivery-slip',
            'download' => '/admin/orders/'.$order->id.'/delivery-slip/download',
            'pdf' => '/admin/orders/'.$order->id.'/delivery-slip/pdf',
            'email' => '/admin/orders/'.$order->id.'/delivery-slip/email',
            'order' => '/admin/orders/'.$order->id,
        ];
    }

    public function downloadOrderSummary(Order $order, DocumentPdf $pdf): HttpResponse
    {
        if (! DocumentCommerceSettings::orderPdf()) {
            abort(404);
        }

        $order->loadMissing('items');
        $rows = '';
        foreach ($order->items as $item) {
            $rows .= '<tr><td>'.e($item->name).'</td><td>'.e((string) $item->qty).'</td><td>'.e(number_format((float) $item->total, 2)).'</td></tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Order '.e($order->number).'</title>'
            .'<style>body{font-family:sans-serif;color:#111}table{width:100%;border-collapse:collapse}td,th{padding:8px 0;border-bottom:1px solid #eee;text-align:left}</style></head><body>'
            .'<h1>Order '.e($order->number).'</h1>'
            .'<p>'.e((string) $order->email).'</p>'
            .'<table><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p><strong>Total '.e($order->currency_code).' '.e(number_format((float) $order->grand_total, 2)).'</strong></p>'
            .'</body></html>';

        return $this->pdfFile($pdf, $html, 'order-'.$order->number.'.pdf');
    }

    protected function pdfFile(DocumentPdf $pdf, string $html, string $filename): HttpResponse
    {
        return response($pdf->output($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    protected function documentSort(string $sort): string|\Illuminate\Database\Query\Expression
    {
        return match ($sort) {
            'number' => 'orders.number',
            'email' => 'orders.email',
            'customer' => DB::raw('(SELECT name FROM customers WHERE customers.id = orders.customer_id LIMIT 1)'),
            'payment', 'payment_method' => DB::raw('(SELECT name FROM payment_methods WHERE payment_methods.id = orders.payment_method_id LIMIT 1)'),
            'shipping', 'shipping_method' => DB::raw('(SELECT name FROM shipping_methods WHERE shipping_methods.id = orders.shipping_method_id LIMIT 1)'),
            'grand_total', 'total' => 'orders.grand_total',
            'created_at', 'date' => 'orders.created_at',
            default => 'orders.id',
        };
    }

    protected function page(string $title, string $hint): Response
    {
        return Inertia::render('Placeholder', [
            'title' => $title,
            'hint' => $hint,
        ]);
    }
}
