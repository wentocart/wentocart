<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\PaymentSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class HostedPayController extends Controller
{
    public function show(Request $request, string $driver, string $number): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }
        $order = $this->sandboxOrder($driver, $number);

        return response()->view('pay.sandbox', [
            'driver' => $driver,
            'order' => $order,
            'shop' => (string) config('wento.name', config('app.name')),
            'confirmUrl' => URL::temporarySignedRoute('pay.confirm', now()->addHours(12), [
                'driver' => strtolower($driver),
                'number' => $order->number,
            ]),
        ]);
    }

    public function confirm(Request $request, string $driver, string $number, CheckoutService $checkout): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }
        $order = $this->sandboxOrder($driver, $number);
        $checkout->markSandboxPaid($order, $driver);

        return redirect('/order/'.$order->number);
    }

    protected function sandboxOrder(string $driver, string $number): Order
    {
        if (! PaymentSettings::sandboxPay()) {
            abort(404);
        }
        $driver = strtolower($driver);
        if (! in_array($driver, ['paypal', 'advanced-paypal', 'razorpay', 'upi', 'stripe'], true)) {
            abort(404);
        }
        $order = Order::query()->with('paymentMethod')->where('number', $number)->firstOrFail();
        if (strtolower((string) ($order->paymentMethod?->driver ?: '')) !== $driver) {
            abort(404);
        }

        return $order;
    }
}
