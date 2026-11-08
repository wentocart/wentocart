<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Events\MarketplaceDomainEvent;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Notify\AdminInbox;
use App\Domain\Settings\ShopMailer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 19 — domain event broker: audit trail + email notifications.
 */
class MarketplaceLifecycleBroker
{
    public function __construct(
        protected MarketplaceAuditService $audit,
        protected ShopMailer $mailer,
    ) {}

    public function dispatch(MarketplaceDomainEvent $event): void
    {
        try {
            match ($event->action()) {
                'seller.application_submitted' => $this->onSellerApplicationSubmitted($event),
                'seller.approved' => $this->onSellerApproved($event),
                'seller.rejected' => $this->onSellerRejected($event),
                'seller.suspended' => $this->onSellerSuspended($event),
                'seller.commission_changed' => $this->onSellerCommissionChanged($event),
                'seller.product_submitted' => $this->onSellerProductSubmitted($event),
                'seller.product_approved' => $this->onSellerProductApproved($event),
                'seller.product_rejected' => $this->onSellerProductRejected($event),
                'seller.order_created' => $this->onSellerOrderCreated($event),
                'seller.order_confirmed' => $this->onSellerOrderConfirmed($event),
                'seller.order_shipped' => $this->onSellerOrderShipped($event),
                'seller.order_delivered' => $this->onSellerOrderDelivered($event),
                'seller.return_requested' => $this->onReturnRequested($event),
                'seller.return_approved' => $this->onReturnApproved($event),
                'seller.refund_processed' => $this->onRefundProcessed($event),
                'seller.payout_requested' => $this->onPayoutRequested($event),
                'seller.payout_approved' => $this->onPayoutApproved($event),
                'seller.payout_rejected' => $this->onPayoutRejected($event),
                'seller.payout_paid' => $this->onPayoutPaid($event),
                'seller.payout_failed' => $this->onPayoutFailed($event),
                'seller.earning_adjusted' => $this->onEarningAdjusted($event),
                'seller.inventory_adjusted' => $this->onInventoryAdjusted($event),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('marketplace.lifecycle.failed', [
                'event' => $event->action(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function onSellerApplicationSubmitted(MarketplaceDomainEvent $event): void
    {
        /** @var MarketplaceEvent $event */
        $seller = $event->get('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller',
            entityId: (int) $seller->id,
            actorType: 'customer',
            actorId: $seller->customer_id ? (int) $seller->customer_id : null,
            after: ['status' => $seller->status, 'shop_slug' => $seller->shop_slug],
            reference: 'seller:'.$seller->id.':application:'.now()->timestamp,
            sellerId: (int) $seller->id,
            customerId: $seller->customer_id ? (int) $seller->customer_id : null,
        );
        $this->mailer->sellerApplicationSubmitted($seller);
        AdminInbox::sellerApplied($seller);
    }

    protected function onSellerApproved(MarketplaceDomainEvent $event): void
    {
        $seller = $event->get('seller');
        $adminId = $event->get('adminId');
        $fromStatus = $event->get('fromStatus');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller',
            entityId: (int) $seller->id,
            actorType: 'admin',
            actorId: $adminId,
            before: ['status' => $fromStatus],
            after: ['status' => $seller->status, 'commission_rate' => (float) $seller->commission_rate],
            reason: $seller->review_note,
            reference: 'seller:'.$seller->id.':approved:'.$seller->reviewed_at?->timestamp,
            sellerId: (int) $seller->id,
        );
        if ($fromStatus !== 'blocked') {
            $this->mailer->sellerApproved($seller);
        }
    }

    protected function onSellerRejected(MarketplaceDomainEvent $event): void
    {
        $seller = $event->get('seller');
        $adminId = $event->get('adminId');
        $reason = $event->get('reason');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller',
            entityId: (int) $seller->id,
            actorType: 'admin',
            actorId: $adminId,
            before: ['status' => 'pending'],
            after: ['status' => $seller->status],
            reason: $reason,
            reference: 'seller:'.$seller->id.':rejected:'.$seller->reviewed_at?->timestamp,
            sellerId: (int) $seller->id,
        );
        $this->mailer->sellerRejected($seller, $reason);
    }

    protected function onSellerSuspended(MarketplaceDomainEvent $event): void
    {
        $seller = $event->get('seller');
        $adminId = $event->get('adminId');
        $reason = $event->get('reason');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller',
            entityId: (int) $seller->id,
            actorType: 'admin',
            actorId: $adminId,
            before: ['status' => 'approved'],
            after: ['status' => $seller->status],
            reason: $reason,
            reference: 'seller:'.$seller->id.':suspended:'.$seller->reviewed_at?->timestamp,
            sellerId: (int) $seller->id,
        );
        $this->mailer->sellerSuspended($seller, $reason);
    }

    protected function onSellerCommissionChanged(MarketplaceDomainEvent $event): void
    {
        $seller = $event->get('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller',
            entityId: (int) $seller->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            before: ['commission_rate' => $event->get('beforeRate')],
            after: ['commission_rate' => $event->get('afterRate')],
            reason: $event->get('reason'),
            reference: 'seller:'.$seller->id.':commission:'.now()->timestamp,
            sellerId: (int) $seller->id,
        );
    }

    protected function onSellerProductSubmitted(MarketplaceDomainEvent $event): void
    {
        $listing = $event->get('listing');
        $seller = $event->get('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_product',
            entityId: (int) $listing->id,
            actorType: 'seller',
            actorId: (int) $seller->id,
            after: ['approval_status' => $listing->approval_status, 'name' => $listing->name],
            reference: 'seller_product:'.$listing->id.':submitted:'.$listing->updated_at?->timestamp,
            sellerId: (int) $seller->id,
        );
        $this->mailer->sellerProductSubmitted($seller, $listing);
        AdminInbox::sellerProductSubmitted($seller, $listing);
    }

    protected function onSellerProductApproved(MarketplaceDomainEvent $event): void
    {
        $listing = $event->get('listing');
        $listing->loadMissing('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_product',
            entityId: (int) $listing->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            before: ['approval_status' => 'pending'],
            after: ['approval_status' => $listing->approval_status],
            reference: 'seller_product:'.$listing->id.':approved:'.$listing->reviewed_at?->timestamp,
            sellerId: (int) $listing->seller_id,
        );
        if ($listing->seller) {
            $this->mailer->sellerProductApproved($listing->seller, $listing);
        }
    }

    protected function onSellerProductRejected(MarketplaceDomainEvent $event): void
    {
        $listing = $event->get('listing');
        $reason = $event->get('reason');
        $listing->loadMissing('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_product',
            entityId: (int) $listing->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            before: ['approval_status' => 'pending'],
            after: ['approval_status' => $listing->approval_status],
            reason: $reason,
            reference: 'seller_product:'.$listing->id.':rejected:'.$listing->reviewed_at?->timestamp,
            sellerId: (int) $listing->seller_id,
        );
        if ($listing->seller) {
            $this->mailer->sellerProductRejected($listing->seller, $listing, $reason);
        }
    }

    protected function onSellerOrderCreated(MarketplaceDomainEvent $event): void
    {
        $seller = $event->get('seller');
        $order = $event->get('order');
        $sellerOrder = $event->get('sellerOrder');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_order',
            entityId: (int) $sellerOrder->id,
            actorType: 'system',
            after: [
                'number' => $sellerOrder->number,
                'status' => $sellerOrder->status,
                'grand_total' => (float) $sellerOrder->grand_total,
            ],
            reference: 'seller_order:'.$sellerOrder->id.':created',
            sellerId: (int) $seller->id,
            customerId: $order->customer_id ? (int) $order->customer_id : null,
            context: ['order_id' => (int) $order->id, 'order_number' => $order->number],
        );
        $this->mailer->sellerOrderCreated($seller, $order, $sellerOrder);
    }

    protected function onSellerOrderConfirmed(MarketplaceDomainEvent $event): void
    {
        $sellerOrder = $event->get('sellerOrder');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_order',
            entityId: (int) $sellerOrder->id,
            actorType: $event->get('sellerId') ? 'seller' : 'system',
            actorId: $event->get('sellerId'),
            before: ['status' => 'pending'],
            after: ['status' => $sellerOrder->status],
            reference: 'seller_order:'.$sellerOrder->id.':confirmed',
            sellerId: (int) $sellerOrder->seller_id,
        );
        $sellerOrder->loadMissing('seller');
        if ($sellerOrder->seller) {
            $this->mailer->sellerOrderConfirmed($sellerOrder->seller, $sellerOrder);
        }
    }

    protected function onSellerOrderShipped(MarketplaceDomainEvent $event): void
    {
        $sellerOrder = $event->get('sellerOrder');
        $trackingNumber = $event->get('trackingNumber');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_order',
            entityId: (int) $sellerOrder->id,
            actorType: 'seller',
            actorId: $event->get('sellerId'),
            after: ['status' => $sellerOrder->status],
            reference: 'seller_order:'.$sellerOrder->id.':shipped',
            sellerId: (int) $sellerOrder->seller_id,
            context: ['tracking_number' => $trackingNumber],
        );
        $sellerOrder->loadMissing(['seller', 'order']);
        if ($sellerOrder->seller) {
            $this->mailer->sellerOrderShipped($sellerOrder->seller, $sellerOrder, $trackingNumber);
        }
    }

    protected function onSellerOrderDelivered(MarketplaceDomainEvent $event): void
    {
        $sellerOrder = $event->get('sellerOrder');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_order',
            entityId: (int) $sellerOrder->id,
            actorType: 'seller',
            actorId: $event->get('sellerId'),
            after: ['status' => $sellerOrder->status],
            reference: 'seller_order:'.$sellerOrder->id.':delivered',
            sellerId: (int) $sellerOrder->seller_id,
        );
        $sellerOrder->loadMissing('seller');
        if ($sellerOrder->seller) {
            $this->mailer->sellerOrderDelivered($sellerOrder->seller, $sellerOrder);
        }
    }

    protected function onReturnRequested(MarketplaceDomainEvent $event): void
    {
        $return = $event->get('return');
        $customer = $event->get('customer');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_return',
            entityId: (int) $return->id,
            actorType: 'customer',
            actorId: (int) $customer->id,
            after: ['status' => $return->status, 'refund_total' => (float) $return->refund_total],
            reason: $return->reason,
            reference: 'seller_return:'.$return->id.':requested',
            sellerId: (int) $return->seller_id,
            customerId: (int) $customer->id,
            context: ['number' => $return->number],
        );
        $return->loadMissing('seller');
        if ($return->seller) {
            $this->mailer->returnRequested($return->seller, $return, $customer);
        }
        AdminInbox::sellerReturnRequested($return, $customer);
    }

    protected function onReturnApproved(MarketplaceDomainEvent $event): void
    {
        $return = $event->get('return');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_return',
            entityId: (int) $return->id,
            actorType: $event->get('adminId') ? 'admin' : 'seller',
            actorId: $event->get('adminId') ?: $event->get('sellerId'),
            before: ['status' => 'requested'],
            after: ['status' => $return->status],
            reference: 'seller_return:'.$return->id.':approved:'.$return->approved_at?->timestamp,
            sellerId: (int) $return->seller_id,
        );
        $return->loadMissing(['seller', 'order']);
        if ($return->seller) {
            $this->mailer->returnApproved($return->seller, $return);
        }
    }

    protected function onRefundProcessed(MarketplaceDomainEvent $event): void
    {
        $return = $event->get('return');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_return',
            entityId: (int) $return->id,
            actorType: $event->get('adminId') ? 'admin' : 'seller',
            actorId: $event->get('adminId') ?: $event->get('sellerId'),
            before: ['status' => 'approved'],
            after: ['status' => $return->status, 'refund_total' => (float) $return->refund_total],
            reference: 'seller_return:'.$return->id.':refunded:'.$return->refunded_at?->timestamp,
            sellerId: (int) $return->seller_id,
            context: [
                'commission_reversed' => (float) $return->commission_reversed,
                'seller_net_reversed' => (float) $return->seller_net_reversed,
            ],
        );
        $return->loadMissing(['order', 'seller']);
        if ($return->order) {
            $this->mailer->refundProcessed($return->order, $return);
        }
        if ($return->seller) {
            $this->mailer->refundProcessedSeller($return->seller, $return);
        }
    }

    protected function onPayoutRequested(MarketplaceDomainEvent $event): void
    {
        $withdrawal = $event->get('withdrawal');
        $withdrawal->loadMissing('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_withdrawal',
            entityId: (int) $withdrawal->id,
            actorType: 'seller',
            actorId: (int) $withdrawal->seller_id,
            after: ['status' => $withdrawal->status, 'amount' => (float) $withdrawal->amount],
            reference: 'seller_withdrawal:'.$withdrawal->id.':requested',
            sellerId: (int) $withdrawal->seller_id,
        );
        $this->mailer->payoutRequested($withdrawal->seller, $withdrawal);
        AdminInbox::payoutRequested($withdrawal);
    }

    protected function onPayoutApproved(MarketplaceDomainEvent $event): void
    {
        $withdrawal = $event->get('withdrawal');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_withdrawal',
            entityId: (int) $withdrawal->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            after: ['status' => $withdrawal->status],
            reference: 'seller_withdrawal:'.$withdrawal->id.':approved:'.$withdrawal->processed_at?->timestamp,
            sellerId: (int) $withdrawal->seller_id,
        );
    }

    protected function onPayoutRejected(MarketplaceDomainEvent $event): void
    {
        $withdrawal = $event->get('withdrawal');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_withdrawal',
            entityId: (int) $withdrawal->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            after: ['status' => $withdrawal->status],
            reason: $event->get('reason'),
            reference: 'seller_withdrawal:'.$withdrawal->id.':rejected:'.$withdrawal->processed_at?->timestamp,
            sellerId: (int) $withdrawal->seller_id,
        );
    }

    protected function onPayoutPaid(MarketplaceDomainEvent $event): void
    {
        $withdrawal = $event->get('withdrawal');
        $withdrawal->loadMissing('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_withdrawal',
            entityId: (int) $withdrawal->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            after: ['status' => $withdrawal->status, 'amount' => (float) $withdrawal->amount],
            reference: 'seller_withdrawal:'.$withdrawal->id.':paid',
            sellerId: (int) $withdrawal->seller_id,
        );
        if ($withdrawal->seller) {
            $this->mailer->payoutPaid($withdrawal->seller, $withdrawal);
        }
    }

    protected function onPayoutFailed(MarketplaceDomainEvent $event): void
    {
        $withdrawal = $event->get('withdrawal');
        $reason = $event->get('reason');
        $withdrawal->loadMissing('seller');
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_withdrawal',
            entityId: (int) $withdrawal->id,
            actorType: 'admin',
            actorId: $event->get('adminId'),
            after: ['status' => $withdrawal->status],
            reason: $reason,
            reference: 'seller_withdrawal:'.$withdrawal->id.':failed:'.$withdrawal->processed_at?->timestamp,
            sellerId: (int) $withdrawal->seller_id,
        );
        if ($withdrawal->seller) {
            $this->mailer->payoutFailed($withdrawal->seller, $withdrawal, $reason);
        }
    }

    protected function onEarningAdjusted(MarketplaceDomainEvent $event): void
    {
        $this->audit->record(
            action: $event->action(),
            entityType: 'seller_earning',
            entityId: $event->get('earningId'),
            actorType: 'admin',
            actorId: $event->get('adminId'),
            before: ['status' => $event->get('beforeStatus')],
            after: ['status' => $event->get('afterStatus')],
            reference: 'seller_earning:'.$event->get('earningId').':adjusted:'.now()->timestamp,
            sellerId: $event->get('sellerId'),
        );
    }

    protected function onInventoryAdjusted(MarketplaceDomainEvent $event): void
    {
        $this->audit->record(
            action: $event->action(),
            entityType: 'product',
            entityId: $event->get('productId'),
            actorType: 'seller',
            actorId: (int) $event->get('seller')->id,
            before: ['stock_qty' => $event->get('beforeQty')],
            after: ['stock_qty' => $event->get('afterQty'), 'delta' => $event->get('delta')],
            reason: $event->get('note'),
            reference: 'product:'.$event->get('productId').':inventory:'.now()->timestamp,
            sellerId: (int) $event->get('seller')->id,
        );
    }
}
