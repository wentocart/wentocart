<?php

namespace App\Console\Commands;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Settings\OrderSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CancelUnpaidOrdersCommand extends Command
{
    protected $signature = 'wento:orders-cancel-unpaid';

    protected $description = 'Cancel unpaid orders past the Order Settings unpaid timeout.';

    public function handle(OrderStageService $stages, CheckoutService $checkout): int
    {
        $released = $checkout->releaseExpiredReservations();
        if ($released > 0) {
            $this->info("Released stock held on {$released} unpaid order(s).");
        }

        if (! OrderSettings::autoCancelUnpaid()) {
            $this->info('Auto-cancel unpaid orders is disabled.');

            return self::SUCCESS;
        }

        $minutes = OrderSettings::unpaidTimeoutMinutes();
        $cutoff = Carbon::now()->subMinutes($minutes);
        $cancelled = 0;

        Order::query()
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereNotIn('status', ['cancelled', 'refunded', 'completed', 'shipped'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($orders) use ($stages, &$cancelled) {
                foreach ($orders as $order) {
                    try {
                        $stages->apply($order, 'cancelled', 'Automatically cancelled — unpaid timeout');
                        $cancelled++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Cancelled {$cancelled} unpaid order(s).");

        return self::SUCCESS;
    }
}
