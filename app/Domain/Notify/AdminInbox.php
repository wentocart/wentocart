<?php

namespace App\Domain\Notify;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Money;
use App\Domain\Notify\Models\AdminNotification;
use App\Domain\Notify\Models\AdminNotificationRead;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shop inbox behind the admin bell.
 *
 * One row per event. Each staff member has their own read mark.
 * Green (success) is good news. Blue (info) is waiting on the merchant.
 * Events raised from the back office or the POS register are skipped.
 */
final class AdminInbox
{
    public static function orderPlaced(Order $order): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        [$who, $money] = self::orderBits($order);
        $paid = $order->payment_status === 'paid';
        self::push(
            type: 'order.placed',
            title: 'New order '.$order->number,
            body: trim($who.' · '.$money.($paid ? ' · Paid' : '')),
            href: '/admin/orders/'.$order->id,
            tone: $paid ? 'success' : 'info',
            storeId: self::storeId($order),
            subject: $order,
        );
    }

    public static function paymentReceived(Order $order): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        [$who, $money] = self::orderBits($order);
        self::push(
            type: 'payment.received',
            title: 'Payment received',
            body: trim($order->number.' · '.$who.' · '.$money),
            href: '/admin/orders/'.$order->id,
            tone: 'success',
            storeId: self::storeId($order),
            subject: $order,
        );
    }

    public static function paymentFailed(Order $order): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        [$who, $money] = self::orderBits($order);
        self::push(
            type: 'payment.failed',
            title: 'Payment failed',
            body: trim($order->number.' · '.$who.' · '.$money),
            href: '/admin/orders/'.$order->id,
            tone: 'info',
            storeId: self::storeId($order),
            subject: $order,
        );
    }

    public static function orderCancelled(Order $order): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        [$who, $money] = self::orderBits($order);
        self::push(
            type: 'order.cancelled',
            title: 'Order cancelled',
            body: trim($order->number.' · '.$who.' · '.$money),
            href: '/admin/orders/'.$order->id,
            tone: 'info',
            storeId: self::storeId($order),
            subject: $order,
        );
    }

    public static function customerRegistered(Customer $customer): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        $body = trim($customer->displayName().' · '.$customer->email);
        if (! $customer->is_enabled) {
            $body .= ' · Waiting for activation';
        }

        self::push(
            type: 'customer.registered',
            title: 'New customer',
            body: $body,
            href: '/admin/customers/'.$customer->id,
            tone: 'success',
            storeId: self::storeId($customer),
            subject: $customer,
        );
    }

    public static function stockLow(Product $product, string $name, string $qty): void
    {
        $out = (float) $qty <= 0;
        self::push(
            type: 'stock.low',
            title: $out ? 'Out of stock' : 'Low stock',
            body: $out
                ? trim($name.' · '.$product->sku)
                : trim($name.' · '.$product->sku.' · '.$qty.' left'),
            href: '/admin/products/'.$product->id.'/edit',
            tone: 'info',
            storeId: null,
            subject: $product,
            quietForHours: 12,
        );
    }

    public static function returnRequested(ShopReturn $return): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        $return->loadMissing('order');
        $order = $return->order;
        self::push(
            type: 'return.requested',
            title: 'Return requested',
            body: trim($return->number.($order ? ' · Order '.$order->number : '')),
            href: $order ? '/admin/orders/'.$order->id.'/edit' : null,
            tone: 'info',
            storeId: $order ? self::storeId($order) : null,
            subject: $return,
        );
    }

    public static function reviewSubmitted(Review $review, Customer $customer, string $productName): void
    {
        if (self::skipStaffAction() || ! $review->wasRecentlyCreated) {
            return;
        }

        $pending = $review->status === Review::STATUS_PENDING;
        self::push(
            type: 'review.submitted',
            title: $pending ? 'Review waiting for approval' : 'New review',
            body: trim($productName.' · '.$review->rating.' stars · '.$customer->displayName()),
            href: '/admin/reviews',
            tone: $pending ? 'info' : 'success',
            storeId: self::storeId($customer),
            subject: $review,
        );
    }

    public static function contactReceived(string $name, string $email, string $message, ?string $orderNumber = null, ?Model $ticket = null): void
    {
        $snippet = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (mb_strlen($snippet) > 140) {
            $snippet = mb_substr($snippet, 0, 137).'…';
        }
        $body = trim($name.' · '.$email.($orderNumber ? ' · Order '.$orderNumber : ''));
        if ($snippet !== '') {
            $body .= ' — '.$snippet;
        }

        self::push(
            type: 'contact.received',
            title: 'New message',
            body: $body,
            href: $ticket ? '/admin/support/'.$ticket->getKey() : '/admin/support',
            tone: 'info',
            storeId: $ticket ? self::storeId($ticket) : null,
            subject: $ticket,
        );
    }

    public static function sellerApplied(Model $seller): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        $shop = (string) ($seller->shop_name ?? $seller->profile_name ?? 'New seller');
        self::push(
            type: 'seller.applied',
            title: 'Seller application',
            body: $shop,
            href: '/admin/multivendor/seller-requests/'.$seller->getKey(),
            tone: 'info',
            subject: $seller,
        );
    }

    public static function sellerProductSubmitted(Model $seller, Model $listing): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        $shop = (string) ($seller->shop_name ?? 'Seller');
        self::push(
            type: 'seller.product_submitted',
            title: 'Product waiting for approval',
            body: trim((string) ($listing->name ?? 'Product').' · '.$shop),
            href: '/admin/multivendor/products/'.$listing->getKey().'/edit',
            tone: 'info',
            subject: $listing,
        );
    }

    public static function sellerReturnRequested(Model $return, ?Model $customer = null): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        $who = $customer && method_exists($customer, 'displayName') ? $customer->displayName() : '';
        self::push(
            type: 'seller.return_requested',
            title: 'Marketplace return',
            body: trim((string) ($return->number ?? 'Return').($who !== '' ? ' · '.$who : '')),
            href: '/admin/multivendor/returns/'.$return->getKey(),
            tone: 'info',
            subject: $return,
        );
    }

    public static function payoutRequested(Model $withdrawal): void
    {
        if (self::skipStaffAction()) {
            return;
        }

        try {
            $withdrawal->loadMissing('seller');
        } catch (Throwable) {
            // A missing relation must not block the payout row itself.
        }
        $seller = $withdrawal->seller ?? null;
        $shop = (string) ($seller->shop_name ?? 'Seller');
        try {
            $amount = Money::format((float) ($withdrawal->amount ?? 0));
        } catch (Throwable) {
            $amount = (string) ($withdrawal->amount ?? '');
        }

        self::push(
            type: 'payout.requested',
            title: 'Payout requested',
            body: trim($shop.' · '.$amount),
            href: '/admin/multivendor/payouts/'.$withdrawal->getKey(),
            tone: 'info',
            subject: $withdrawal,
        );
    }

    public static function unreadCount(Admin $admin): int
    {
        try {
            return (int) self::visible()
                ->whereDoesntHave('reads', fn (Builder $q) => $q->where('admin_id', $admin->id))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{id: int, type: string, tone: string, title: string, body: ?string, href: ?string, read: bool, created_at: string}>
     */
    public static function recent(Admin $admin, bool $unreadOnly = false, int $limit = 30): array
    {
        $limit = max(1, min(50, $limit));

        return self::visible()
            ->with(['reads' => fn ($q) => $q->where('admin_id', $admin->id)])
            ->when($unreadOnly, fn (Builder $q) => $q->whereDoesntHave(
                'reads',
                fn (Builder $reads) => $reads->where('admin_id', $admin->id),
            ))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AdminNotification $row) => [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'tone' => $row->tone === 'success' ? 'success' : 'info',
                'title' => (string) $row->title,
                'body' => $row->body,
                'href' => $row->href,
                'read' => $row->reads->isNotEmpty(),
                'created_at' => $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->all();
    }

    public static function findVisible(int $id): ?AdminNotification
    {
        return self::visible()->whereKey($id)->first();
    }

    public static function markRead(Admin $admin, AdminNotification $row): void
    {
        AdminNotificationRead::query()->firstOrCreate(
            [
                'admin_notification_id' => $row->id,
                'admin_id' => $admin->id,
            ],
            ['read_at' => now()],
        );
    }

    public static function markAllRead(Admin $admin): void
    {
        $ids = self::visible()
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('admin_id', $admin->id))
            ->pluck('id');

        $now = now();
        foreach ($ids->chunk(200) as $chunk) {
            AdminNotificationRead::query()->insertOrIgnore($chunk->map(fn ($id) => [
                'admin_notification_id' => $id,
                'admin_id' => $admin->id,
                'read_at' => $now,
            ])->all());
        }
    }

    /**
     * @return Builder<AdminNotification>
     */
    public static function visible(): Builder
    {
        $query = AdminNotification::query();
        $context = app(StoreContext::class);
        if (! $context->filters()) {
            return $query;
        }

        $ids = $context->idsFor('orders');
        return $query->where(function (Builder $inner) use ($ids) {
            $inner->whereNull('store_id');
            if ($ids !== []) {
                $inner->orWhereIn('store_id', $ids);
            }
        });
    }

    public static function push(
        string $type,
        string $title,
        ?string $body = null,
        ?string $href = null,
        string $tone = 'info',
        ?int $storeId = null,
        ?Model $subject = null,
        int $quietForHours = 0,
    ): void {
        try {
            if ($subject && self::recentDuplicate($type, $subject, $quietForHours)) {
                return;
            }

            $row = AdminNotification::query()->create([
                'store_id' => $storeId && $storeId > 0 ? $storeId : null,
                'type' => $type,
                'tone' => $tone === 'success' ? 'success' : 'info',
                'title' => mb_substr($title, 0, 180),
                'body' => $body !== null && $body !== '' ? mb_substr($body, 0, 500) : null,
                'href' => $href,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject ? (int) $subject->getKey() : null,
            ]);

            // Optional websocket fan-out when BROADCAST_CONNECTION is set (SSE remains default).
            event(new \App\Events\AdminInboxUpdated($row));
        } catch (Throwable $e) {
            Log::warning('admin.notification.failed', [
                'type' => $type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected static function recentDuplicate(string $type, Model $subject, int $quietForHours): bool
    {
        $query = AdminNotification::query()
            ->where('type', $type)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());

        if ($quietForHours > 0) {
            return $query->where(function (Builder $inner) use ($quietForHours) {
                $inner->whereDoesntHave('reads')
                    ->orWhere('created_at', '>=', now()->subHours($quietForHours));
            })->exists();
        }

        return $query->where('created_at', '>=', now()->subMinutes(2))->exists();
    }

    /** @return array{0: string, 1: string} */
    protected static function orderBits(Order $order): array
    {
        try {
            $order->loadMissing('customer');
            $billing = is_array($order->billing_address) ? $order->billing_address : [];
            $who = trim((string) (($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')));
            if ($who === '' && $order->customer) {
                $who = $order->customer->displayName();
            }
            if ($who === '') {
                $who = (string) $order->email;
            }

            return [$who, Money::format((float) $order->grand_total, $order->currency_code)];
        } catch (Throwable) {
            return [(string) $order->email, (string) $order->grand_total];
        }
    }

    protected static function storeId(Model $model): ?int
    {
        $id = (int) ($model->store_id ?? 0);

        return $id > 0 ? $id : null;
    }

    protected static function skipStaffAction(): bool
    {
        try {
            $request = request();
        } catch (Throwable) {
            return false;
        }

        return $request->is('admin', 'admin/*');
    }
}
