<?php

namespace App\Domain\Marketplace\Events;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Order\Models\Order;

interface MarketplaceDomainEvent
{
    public function action(): string;

    public function get(string $key, mixed $default = null): mixed;
}

/**
 * Phase 19 — marketplace domain events (single entry point for PSR-4 autoload).
 */
final class MarketplaceEvent implements MarketplaceDomainEvent
{
    /** @param  array<string, mixed>  $payload */
    private function __construct(
        private readonly string $actionName,
        public readonly array $payload = [],
    ) {}

    public function action(): string
    {
        return $this->actionName;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public static function sellerApplicationSubmitted(Seller $seller): self
    {
        return new self('seller.application_submitted', ['seller' => $seller]);
    }

    public static function sellerApproved(Seller $seller, ?int $adminId, ?string $fromStatus): self
    {
        return new self('seller.approved', compact('seller', 'adminId', 'fromStatus'));
    }

    public static function sellerRejected(Seller $seller, ?int $adminId, ?string $reason): self
    {
        return new self('seller.rejected', compact('seller', 'adminId', 'reason'));
    }

    public static function sellerSuspended(Seller $seller, ?int $adminId, ?string $reason): self
    {
        return new self('seller.suspended', compact('seller', 'adminId', 'reason'));
    }

    public static function sellerCommissionChanged(
        Seller $seller,
        ?int $adminId,
        float $beforeRate,
        float $afterRate,
        ?string $reason = null,
    ): self {
        return new self('seller.commission_changed', compact('seller', 'adminId', 'beforeRate', 'afterRate', 'reason'));
    }

    public static function sellerProductSubmitted(SellerProduct $listing, Seller $seller): self
    {
        return new self('seller.product_submitted', compact('listing', 'seller'));
    }

    public static function sellerProductApproved(SellerProduct $listing, ?int $adminId): self
    {
        return new self('seller.product_approved', compact('listing', 'adminId'));
    }

    public static function sellerProductRejected(SellerProduct $listing, ?int $adminId, ?string $reason): self
    {
        return new self('seller.product_rejected', compact('listing', 'adminId', 'reason'));
    }

    public static function sellerOrderCreated(Seller $seller, Order $order, SellerOrder $sellerOrder): self
    {
        return new self('seller.order_created', compact('seller', 'order', 'sellerOrder'));
    }

    public static function sellerOrderConfirmed(SellerOrder $sellerOrder, ?int $sellerId): self
    {
        return new self('seller.order_confirmed', compact('sellerOrder', 'sellerId'));
    }

    public static function sellerOrderShipped(SellerOrder $sellerOrder, ?int $sellerId, ?string $trackingNumber = null): self
    {
        return new self('seller.order_shipped', compact('sellerOrder', 'sellerId', 'trackingNumber'));
    }

    public static function sellerOrderDelivered(SellerOrder $sellerOrder, ?int $sellerId): self
    {
        return new self('seller.order_delivered', compact('sellerOrder', 'sellerId'));
    }

    public static function returnRequested(SellerReturn $return, Customer $customer): self
    {
        return new self('seller.return_requested', compact('return', 'customer'));
    }

    public static function returnApproved(SellerReturn $return, ?int $adminId, ?int $sellerId): self
    {
        return new self('seller.return_approved', compact('return', 'adminId', 'sellerId'));
    }

    public static function refundProcessed(SellerReturn $return, ?int $adminId, ?int $sellerId): self
    {
        return new self('seller.refund_processed', compact('return', 'adminId', 'sellerId'));
    }

    public static function payoutRequested(SellerWithdrawal $withdrawal): self
    {
        return new self('seller.payout_requested', compact('withdrawal'));
    }

    public static function payoutApproved(SellerWithdrawal $withdrawal, ?int $adminId): self
    {
        return new self('seller.payout_approved', compact('withdrawal', 'adminId'));
    }

    public static function payoutRejected(SellerWithdrawal $withdrawal, ?int $adminId, ?string $reason = null): self
    {
        return new self('seller.payout_rejected', compact('withdrawal', 'adminId', 'reason'));
    }

    public static function payoutPaid(SellerWithdrawal $withdrawal, ?int $adminId): self
    {
        return new self('seller.payout_paid', compact('withdrawal', 'adminId'));
    }

    public static function payoutFailed(SellerWithdrawal $withdrawal, ?int $adminId, ?string $reason = null): self
    {
        return new self('seller.payout_failed', compact('withdrawal', 'adminId', 'reason'));
    }

    public static function earningAdjusted(
        int $earningId,
        int $sellerId,
        ?int $adminId,
        string $beforeStatus,
        string $afterStatus,
    ): self {
        return new self('seller.earning_adjusted', compact('earningId', 'sellerId', 'adminId', 'beforeStatus', 'afterStatus'));
    }

    public static function inventoryAdjusted(
        Seller $seller,
        int $productId,
        int $delta,
        int $beforeQty,
        int $afterQty,
        ?string $note = null,
    ): self {
        return new self('seller.inventory_adjusted', compact('seller', 'productId', 'delta', 'beforeQty', 'afterQty', 'note'));
    }
}
