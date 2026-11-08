<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\OrderStageService;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Shipping\Models\Shipment;
use GraphQL\Error\UserError;

/**
 * Shop REST extras: reviews, cancel order, invoices, shipments, order items/addresses.
 */
final class GraphqlCustomerAccountExtras
{
    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function reviews(array $args): array
    {
        $user = self::requireCustomer();
        $limit = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));

        return $user->reviews()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Review $r) => self::mapReview($r))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function review(array $args): array
    {
        $user = self::requireCustomer();
        $id = (int) ($args['id'] ?? 0);
        $row = $user->reviews()->whereKey($id)->first();
        if (! $row) {
            throw new UserError('Review not found.');
        }

        return self::mapReview($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{success: bool, message: string, order: array<string, mixed>}
     */
    public static function cancelOrder(array $args): array
    {
        $user = self::requireCustomer();
        $order = self::findOwnedOrder($user, $args);
        if (! OrderSettings::buyerMayCancelStatus((string) $order->status)) {
            throw new UserError('This order can no longer be cancelled.');
        }
        $comment = trim((string) ($args['comment'] ?? $args['reason'] ?? 'Cancelled by customer'));
        if ($comment === '') {
            $comment = 'Cancelled by customer';
        }
        app(OrderStageService::class)->apply($order, 'cancelled', $comment);
        $order->refresh();
        $mapped = GraphqlCustomerAccount::customerOrder(['id' => (string) $order->id]);

        return [
            'success' => true,
            'message' => 'Order cancelled.',
            'order' => $mapped,
            'cancelOrder' => [
                'success' => true,
                'message' => 'Order has been canceled successfully',
                'orderId' => (string) $order->id,
                'status' => (string) ($mapped['status'] ?? $order->status),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function invoices(array $args): array
    {
        $user = self::requireCustomer();
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return [];
        }
        $limit = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $docs = app(InvoiceDocument::class);

        return $user->orders()
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (Order $o) => $docs->isAvailable($o))
            ->take($limit)
            ->map(fn (Order $o) => self::mapInvoice($o, $docs))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function invoice(array $args): array
    {
        $user = self::requireCustomer();
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            throw new UserError('Invoices are disabled.');
        }
        $order = self::findOwnedOrder($user, $args);
        $docs = app(InvoiceDocument::class);
        if (! $docs->isAvailable($order)) {
            throw new UserError('Invoice not available for this order.');
        }

        return self::mapInvoice($order, $docs);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function shipments(array $args): array
    {
        $conn = self::shipmentsConnection($args);

        return array_map(fn (array $edge) => $edge['node'], $conn['edges']);
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function shipmentsConnection(array $args): array
    {
        $user = self::requireCustomer();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $orderId = (int) ($args['orderId'] ?? $args['order_id'] ?? 0);
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^shp:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = Shipment::query()
            ->whereHas('order', fn ($oq) => $oq->where('customer_id', $user->id))
            ->with(['order:id,number,customer_id', 'items.orderItem'])
            ->orderByDesc('id');
        if ($orderId > 0) {
            $q->where('order_id', $orderId);
        }
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '<', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (Shipment $s) => [
            'cursor' => base64_encode('shp:'.$s->id),
            'node' => self::mapShipment($s),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('shp:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function shipment(array $args): array
    {
        $user = self::requireCustomer();
        $id = (int) ($args['id'] ?? 0);
        $row = Shipment::query()
            ->whereKey($id)
            ->whereHas('order', fn ($oq) => $oq->where('customer_id', $user->id))
            ->with(['order:id,number,customer_id', 'items.orderItem'])
            ->first();
        if (! $row) {
            throw new UserError('Shipment not found.');
        }

        return self::mapShipment($row);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function findOwnedOrder(Customer $user, array $args): Order
    {
        $id = (int) ($args['id'] ?? $args['orderId'] ?? 0);
        $number = trim((string) ($args['incrementId'] ?? $args['number'] ?? $args['orderNumber'] ?? ''));
        $q = $user->orders();
        if ($id > 0) {
            $q->whereKey($id);
        } elseif ($number !== '') {
            $q->where('number', $number);
        } else {
            throw new UserError('Order not found.');
        }
        $order = $q->first();
        if (! $order) {
            throw new UserError('Order not found.');
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapReview(Review $r): array
    {
        return [
            'id' => (string) $r->id,

            'productId' => $r->product_id ? (string) $r->product_id : null,
            'rating' => (int) $r->rating,
            'title' => $r->title,
            'comment' => $r->body,
            'body' => $r->body,
            'status' => (string) ($r->status ?: ($r->is_approved ? 'approved' : 'pending')),
            'createdAt' => optional($r->created_at)?->toIso8601String(),
            'created_at' => optional($r->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapInvoice(Order $order, InvoiceDocument $docs): array
    {
        $payload = $docs->payload($order);
        $number = (string) ($payload['number'] ?? $order->number);

        return [
            'id' => (string) $order->id,

            'orderId' => (string) $order->id,
            'incrementId' => $order->number,
            'invoiceNumber' => $number,
            'invoice_number' => $number,
            'status' => (string) $order->payment_status,
            'grandTotal' => $order->grand_total !== null ? (float) $order->grand_total : null,
            'grand_total' => $order->grand_total !== null ? (float) $order->grand_total : null,
            'currencyCode' => $order->currency_code,
            'createdAt' => optional($order->created_at)?->toIso8601String(),
            'downloadUrl' => url('/api/v1/account/orders/'.$order->number.'/invoice'),
            'items' => collect($payload['items'] ?? [])->map(fn ($item) => [
                'id' => isset($item['id']) ? (string) $item['id'] : null,
                'name' => $item['name'] ?? null,
                'sku' => $item['sku'] ?? null,
                'qty' => isset($item['qty']) ? (int) $item['qty'] : null,
                'price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                'total' => isset($item['total']) ? (float) $item['total'] : null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapShipment(Shipment $s): array
    {
        $carrier = $s->carrier;
        $track = $s->tracking_number;

        return [
            'id' => (string) $s->id,

            'orderId' => (string) $s->order_id,
            'incrementId' => $s->order?->number,
            'status' => (string) $s->status,
            'carrier' => $carrier,
            'carrierTitle' => $carrier,
            'trackingNumber' => $track,
            'trackNumber' => $track,
            'trackingUrl' => $s->tracking_url,
            'shippedAt' => optional($s->shipped_at)?->toIso8601String(),
            'deliveredAt' => optional($s->delivered_at)?->toIso8601String(),
            'createdAt' => optional($s->created_at)?->toIso8601String(),
            'items' => $s->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'name' => $item->orderItem?->name,
                'sku' => $item->orderItem?->sku,
                'qty' => $item->qty !== null ? (float) $item->qty : null,
            ])->values()->all(),
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function orderItemsConnection(array $args): array
    {
        $user = self::requireCustomer();
        $orderId = (int) ($args['orderId'] ?? $args['order_id'] ?? 0);
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($orderId < 1) {
            return $empty;
        }
        if (! $user->orders()->whereKey($orderId)->exists()) {
            throw new UserError('Order not found.');
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^oit:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = OrderItem::query()->where('order_id', $orderId)->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (OrderItem $item) => [
            'cursor' => base64_encode('oit:'.$item->id),
            'node' => self::mapOrderItem($item),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('oit:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function orderAddress(Order $order, string $which): ?array
    {
        $raw = $which === 'shipping'
            ? ($order->shipping_address ?? null)
            : ($order->billing_address ?? null);
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $line1 = (string) ($raw['line1'] ?? $raw['address'] ?? $raw['address1'] ?? '');
        $first = (string) ($raw['first_name'] ?? $raw['firstName'] ?? '');
        $last = (string) ($raw['last_name'] ?? $raw['lastName'] ?? '');

        return [
            'id' => null,
            'firstName' => $first !== '' ? $first : null,
            'lastName' => $last !== '' ? $last : null,
            'address' => $line1 !== '' ? $line1 : null,
            'line1' => $line1 !== '' ? $line1 : null,
            'line2' => $raw['line2'] ?? $raw['address2'] ?? null,
            'city' => $raw['city'] ?? null,
            'state' => $raw['state'] ?? $raw['region'] ?? null,
            'country' => $raw['country_code'] ?? $raw['country'] ?? null,
            'zipCode' => $raw['postal_code'] ?? $raw['zipCode'] ?? $raw['postcode'] ?? null,
            'phoneNumber' => $raw['phone'] ?? $raw['phoneNumber'] ?? null,
            'email' => $raw['email'] ?? $order->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapOrderItem(OrderItem $item): array
    {
        $qty = $item->qty !== null ? (float) $item->qty : null;
        $price = $item->unit_price !== null ? (float) $item->unit_price : null;
        $total = $item->total !== null ? (float) $item->total : null;

        return [
            'id' => (string) $item->id,

            'productId' => $item->product_id ? (string) $item->product_id : null,
            'productName' => $item->name,
            'name' => $item->name,
            'sku' => $item->sku,
            'quantity' => $qty,
            'qty' => $qty,
            'price' => $price,
            'totalPrice' => $total,
            'total' => $total,
        ];
    }

    protected static function requireCustomer(): Customer
    {
        $user = auth('customer')->user();
        if (! $user instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        return $user;
    }
}
