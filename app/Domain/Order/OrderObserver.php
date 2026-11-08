<?php

namespace App\Domain\Order;

use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Notify\AdminInbox;
use App\Domain\Ops\CommerceTopics;
use App\Domain\Ops\WebhookPayload;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopMailer;
use App\Domain\Subscription\Services\SubscriptionBilling;

class OrderObserver
{
    public function created(Order $order): void
    {
        if ($order->payment_status === 'paid') {
            app(ShopMailer::class)->orderPaid($order);
            app(GiftCardService::class)->issueForPaidOrder($order);
            app(SellerService::class)->markOrderPaid($order);
            app(SubscriptionBilling::class)->startFromOrder($order);
        }
        CommerceTopics::notify('order.placed', WebhookPayload::order($order));
        AdminInbox::orderPlaced($order);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
            app(ShopMailer::class)->orderPaid($order);
            app(GiftCardService::class)->issueForPaidOrder($order);
            app(SellerService::class)->markOrderPaid($order);
            app(SubscriptionBilling::class)->startFromOrder($order);
        }

        if ($order->wasChanged('payment_status') && $order->payment_status === 'failed') {
            app(SubscriptionBilling::class)->handleSignupOrderClosed($order);
        }

        // A refund can be expressed either as an order status or as a payment
        // status; both must cancel/disable the cards issued for this order.
        $refundedByStatus = $order->wasChanged('status') && $order->status === 'refunded';
        $refundedByPayment = $order->wasChanged('payment_status') && $order->payment_status === 'refunded';

        if ($refundedByStatus || $refundedByPayment) {
            app(GiftCardService::class)->handleOrderRefunded($order);
            app(SellerService::class)->markOrderRefunded($order);
            app(SubscriptionBilling::class)->handleSignupOrderClosed($order);
        }

        if ($order->wasChanged('status') && $order->status === 'cancelled') {
            app(GiftCardService::class)->handleOrderCancelled($order);
            app(SubscriptionBilling::class)->handleSignupOrderClosed($order);
        }

        if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
            // Safety net for payment-first / webhook paths that skip completeDeferredCapture.
            app(GiftCardService::class)->commitHold($order);
        }

        if ($order->wasChanged('status')) {
            app(InvoiceDocument::class)->maybeEmail($order, app(ShopMailer::class));
        }

        $payload = WebhookPayload::order($order);

        if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
            CommerceTopics::notify('order.paid', $payload);
        }
        if ($refundedByStatus || $refundedByPayment) {
            CommerceTopics::notify('order.refunded', $payload);
        }
        if ($order->wasChanged('status') && $order->status === 'cancelled') {
            CommerceTopics::notify('order.cancelled', $payload);
        }

        if ($order->wasChanged('payment_status') && $order->payment_status === 'paid') {
            AdminInbox::paymentReceived($order);
        }
        if ($order->wasChanged('payment_status') && $order->payment_status === 'failed') {
            AdminInbox::paymentFailed($order);
        }
        if (
            $order->wasChanged('status')
            && $order->status === 'cancelled'
            && ! ($order->wasChanged('payment_status') && $order->payment_status === 'failed')
        ) {
            AdminInbox::orderCancelled($order);
        }

        if ($order->wasChanged(['status', 'payment_status', 'grand_total', 'email'])) {
            CommerceTopics::notify('order.updated', array_merge($payload, [
                'previous' => [
                    'status' => $order->getOriginal('status'),
                    'payment_status' => $order->getOriginal('payment_status'),
                ],
            ]));
        }
    }
}
