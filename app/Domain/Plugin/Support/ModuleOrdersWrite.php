<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Order\Models\Order;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use App\Models\Admin;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Allowlisted order payment writes for extensions (partial / instalment progress).
 * No arbitrary column dumps — core validates amounts against grand_total.
 */
class ModuleOrdersWrite
{
    /** @var list<string> */
    protected array $detailKeys = [
        'plan_code', 'next_due_at', 'notes', 'schedule',
    ];

    /** @var list<string> */
    protected array $fulfillmentKeys = [
        'pickup_code', 'pickup_label', 'pickup_address', 'pickup_at', 'notes',
    ];

    /** @var list<string> */
    protected array $statuses = [
        'pending', 'partial', 'paid', 'failed', 'refunded',
    ];

    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Store schedule / plan metadata on the order (merged into payment_details).
     *
     * @param  array<string, mixed>  $details
     */
    public function setPaymentDetails(int $orderId, array $details): void
    {
        $this->assertCanWrite();
        $order = $this->findOrder($orderId);
        $merged = array_merge(
            is_array($order->payment_details) ? $order->payment_details : [],
            $this->sanitizeDetails($details),
            ['extension' => $this->manifest->id()]
        );
        $order->updateTrusted(['payment_details' => $merged]);
    }

    /**
     * Record a payment toward the order balance (down payment or later instalment).
     *
     * @param  array<string, mixed>|null  $details  optional payment_details merge
     */
    public function recordPayment(int $orderId, float $amount, ?array $details = null): void
    {
        $this->assertCanWrite();
        $order = $this->findOrder($orderId);
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'amount must be greater than 0.']);
        }

        $grand = round((float) $order->grand_total, 2);
        $already = $order->amount_paid !== null ? round((float) $order->amount_paid, 2) : 0.0;
        $paid = round($already + $amount, 2);
        if ($paid > $grand + 0.009) {
            throw ValidationException::withMessages(['amount' => 'Payment would exceed the order total.']);
        }

        $due = round(max(0, $grand - $paid), 2);
        $status = $due <= 0.009 ? 'paid' : 'partial';

        $payload = [
            'amount_paid' => $paid,
            'amount_due' => $due,
            'payment_status' => $status,
        ];
        if ($details !== null) {
            $payload['payment_details'] = array_merge(
                is_array($order->payment_details) ? $order->payment_details : [],
                $this->sanitizeDetails($details),
                ['extension' => $this->manifest->id()]
            );
        }

        $order->updateTrusted($payload);
        wento_action('order.status', 'after', [
            'id' => $order->id,
            'number' => $order->number,
            'payment_status' => $status,
            'amount_paid' => $paid,
            'amount_due' => $due,
        ]);
    }

    /**
     * Store pickup / click-collect details on the order.
     *
     * @param  array<string, mixed>  $details
     */
    public function setFulfillmentDetails(int $orderId, array $details): void
    {
        $this->assertCanWrite();
        $order = $this->findOrder($orderId);
        $clean = [];
        foreach ($this->fulfillmentKeys as $key) {
            if (! array_key_exists($key, $details) || ! is_string($details[$key])) {
                continue;
            }
            $value = Str::limit(trim(strip_tags($details[$key])), $key === 'notes' ? 500 : 180, '');
            if ($value !== '') {
                $clean[$key] = $value;
            }
        }
        $clean['extension'] = $this->manifest->id();
        $order->updateTrusted([
            'fulfillment_details' => array_merge(
                is_array($order->fulfillment_details) ? $order->fulfillment_details : [],
                $clean
            ),
        ]);
    }

    /**
     * Set payment_status only when it matches allowlisted values.
     */
    public function setPaymentStatus(int $orderId, string $status): void
    {
        $this->assertCanWrite();
        $status = strtolower(trim($status));
        if (! in_array($status, $this->statuses, true)) {
            throw ValidationException::withMessages(['payment_status' => 'Unsupported payment status.']);
        }
        $order = $this->findOrder($orderId);
        $order->updateTrusted(['payment_status' => $status]);
        wento_action('order.status', 'after', [
            'id' => $order->id,
            'number' => $order->number,
            'payment_status' => $status,
        ]);
    }

    /**
     * Assign (or clear) an enabled staff admin on the order.
     */
    public function assignAdmin(int $orderId, ?int $adminId): void
    {
        $this->assertCanWrite();
        $order = $this->findOrder($orderId);
        if ($adminId === null || $adminId === 0) {
            $order->updateTrusted(['assigned_admin_id' => null]);

            return;
        }
        $admin = Admin::query()->where('is_enabled', true)->find($adminId);
        if (! $admin) {
            throw ValidationException::withMessages(['assigned_admin_id' => 'Staff member was not found or is disabled.']);
        }
        $order->updateTrusted(['assigned_admin_id' => (int) $admin->id]);
    }

    /**
     * Move an order to an enabled journey stage (pending, processing, shipped, …).
     * Goes through OrderStageService (stock / mail / history).
     */
    public function setStatus(int $orderId, string $stageCode, ?string $comment = null): void
    {
        $this->assertCanWrite();
        $order = $this->findOrder($orderId);
        $code = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($stageCode))) ?? '';
        if ($code === '') {
            throw ValidationException::withMessages(['status' => 'Invalid order stage.']);
        }
        $note = $comment !== null
            ? Str::limit(trim(strip_tags($comment)), 500, '')
            : null;

        $stage = app(\App\Domain\Order\OrderStageService::class)->findByCode($code);
        if (! $stage || ! $stage->is_enabled) {
            throw ValidationException::withMessages(['status' => 'Unknown or disabled order stage.']);
        }

        wento_action('order.status', 'before', [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $code,
        ]);
        $fresh = app(\App\Domain\Order\OrderStageService::class)->apply($order, $code, $note);
        wento_action('order.status', 'after', [
            'id' => $fresh->id,
            'number' => $fresh->number,
            'status' => $fresh->status,
            'payment_status' => $fresh->payment_status,
        ]);
    }

    /**
     * Allowlisted payment snapshot for account / instalment UIs (not full orders.read).
     *
     * @return array{
     *   id: int,
     *   number: string,
     *   email: string,
     *   customer_id: ?int,
     *   status: string,
     *   payment_status: string,
     *   grand_total: float,
     *   amount_paid: float,
     *   amount_due: float,
     *   payment_details: array<string, mixed>
     * }|null
     */
    public function paymentSnapshot(int $orderId): ?array
    {
        $this->assertCanWrite();
        $order = Order::query()->find($orderId);
        if (! $order) {
            return null;
        }

        return $this->serializePayment($order);
    }

    /**
     * Open (unpaid / partial) orders for a customer — account “pay next” lists.
     *
     * @return list<array{
     *   id: int,
     *   number: string,
     *   email: string,
     *   customer_id: ?int,
     *   status: string,
     *   payment_status: string,
     *   grand_total: float,
     *   amount_paid: float,
     *   amount_due: float,
     *   payment_details: array<string, mixed>
     * }>
     */
    public function listOpenForCustomer(int $customerId, int $limit = 20): array
    {
        $this->assertCanWrite();
        $limit = max(1, min(50, $limit));

        return Order::query()
            ->where('customer_id', $customerId)
            ->whereIn('payment_status', ['pending', 'partial'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => $this->serializePayment($order))
            ->all();
    }

    /**
     * Enabled staff for assignment pickers (id, name, email only).
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function listStaff(): array
    {
        $this->assertCanWrite();

        return Admin::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email'])
            ->map(static fn (Admin $admin) => [
                'id' => (int) $admin->id,
                'name' => (string) $admin->name,
                'email' => (string) $admin->email,
            ])
            ->all();
    }

    /**
     * @return array{
     *   id: int,
     *   number: string,
     *   email: string,
     *   customer_id: ?int,
     *   status: string,
     *   payment_status: string,
     *   grand_total: float,
     *   amount_paid: float,
     *   amount_due: float,
     *   payment_details: array<string, mixed>
     * }
     */
    protected function serializePayment(Order $order): array
    {
        $details = is_array($order->payment_details) ? $order->payment_details : [];

        return [
            'id' => (int) $order->id,
            'number' => (string) $order->number,
            'email' => (string) $order->email,
            'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
            'status' => (string) $order->status,
            'payment_status' => (string) $order->payment_status,
            'grand_total' => round((float) $order->grand_total, 2),
            'amount_paid' => round((float) ($order->amount_paid ?? 0), 2),
            'amount_due' => round((float) ($order->amount_due ?? $order->grand_total), 2),
            'payment_details' => $details,
        ];
    }

    protected function findOrder(int $orderId): Order
    {
        $order = Order::query()->find($orderId);
        if (! $order) {
            throw ValidationException::withMessages(['id' => 'Order '.$orderId.' was not found.']);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function sanitizeDetails(array $details): array
    {
        $clean = [];
        foreach ($this->detailKeys as $key) {
            if (! array_key_exists($key, $details)) {
                continue;
            }
            $value = $details[$key];
            if ($key === 'schedule' && is_array($value)) {
                $clean[$key] = $this->sanitizeSchedule($value);
            } elseif ($key === 'notes' && is_string($value)) {
                $clean[$key] = Str::limit(trim(strip_tags($value)), 500, '');
            } elseif ($key === 'plan_code' && is_string($value)) {
                $code = preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
                if ($code !== '') {
                    $clean[$key] = substr($code, 0, 48);
                }
            } elseif ($key === 'next_due_at' && is_string($value)) {
                $clean[$key] = Str::limit(trim(strip_tags($value)), 40, '');
            }
        }

        return $clean;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{due_at: string, amount: float, status: string}>
     */
    protected function sanitizeSchedule(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($out) >= 36) {
                break;
            }
            $dueAt = isset($row['due_at']) && is_string($row['due_at'])
                ? Str::limit(trim(strip_tags($row['due_at'])), 40, '')
                : '';
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $status = strtolower((string) ($row['status'] ?? 'pending'));
            if (! in_array($status, ['pending', 'paid', 'overdue', 'cancelled'], true)) {
                $status = 'pending';
            }
            if ($dueAt === '' || $amount < 0) {
                continue;
            }
            $out[] = [
                'due_at' => $dueAt,
                'amount' => $amount,
                'status' => $status,
            ];
        }

        return $out;
    }

    protected function assertCanWrite(): void
    {
        if (! $this->manifest->can(Capabilities::ORDERS_WRITE)) {
            throw new InvalidArgumentException('This app did not declare orders.write.');
        }
    }
}
