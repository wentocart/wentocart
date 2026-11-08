<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Contracts\HandlesPaymentWebhooks;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Security\WebhookIdempotency;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $driver, PaymentGatewayRegistry $gateways, PluginKernel $kernel): JsonResponse
    {
        $kernel->boot();
        if (! $gateways->has($driver)) {
            abort(404, 'Unknown payment driver.');
        }
        $gateway = $gateways->get($driver);
        if (! $gateway instanceof HandlesPaymentWebhooks) {
            abort(404, 'This driver does not accept webhooks.');
        }

        $processed = WebhookIdempotency::run(
            $driver,
            (string) $request->getContent(),
            $request->headers->all(),
            fn () => $gateway->handleWebhook(
                (string) $request->getContent(),
                $request->headers->all()
            )
        );

        return response()->json($processed['response'], $processed['status']);
    }
}
