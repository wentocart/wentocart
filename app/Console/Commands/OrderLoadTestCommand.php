<?php

namespace App\Console\Commands;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Http\Controllers\Admin\OrderController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderLoadTestCommand extends Command
{
    protected $signature = 'orders:load-test
        {--count=1000000 : Target total order rows}
        {--chunk=500 : Rows per insert}
        {--seed-only : Insert rows without running the speed test}
        {--bench-only : Skip insert and only measure page queries}';

    protected $description = 'Insert a large order dataset and measure admin orders page query speed.';

    public function handle(): int
    {
        $target = max(1, (int) $this->option('count'));
        $chunk = max(50, min(1000, (int) $this->option('chunk')));

        if (! $this->option('bench-only')) {
            $this->seedOrders($target, $chunk);
        }

        if (! $this->option('seed-only')) {
            $this->benchmark();
        }

        return self::SUCCESS;
    }

    private function seedOrders(int $target, int $chunk): void
    {
        $existing = (int) DB::table('orders')->count();
        $needed = $target - $existing;

        $this->info("Orders now: {$existing}. Target: {$target}.");

        if ($needed <= 0) {
            $this->info('Target already reached.');

            return;
        }

        $payId = PaymentMethod::query()->value('id');
        $start = (int) DB::table('orders')->where('number', 'like', 'LT%')->count();
        $firstNames = ['John', 'Priya', 'Alex', 'Maria', 'David', 'Anita', 'Omar', 'Emily', 'Rahul', 'Sara'];
        $lastNames = ['Smith', 'Sharma', 'Chen', 'Garcia', 'Lee', 'Rao', 'Hassan', 'Park', 'Patel', 'Khan'];
        $statuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
        $address = '{"first_name":"Load","last_name":"Test","line1":"1 Main"}';

        DB::connection()->disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('SET UNIQUE_CHECKS=0');

        $bar = $this->output->createProgressBar($needed);
        $bar->start();

        $orders = [];
        $items = [];

        for ($i = 0; $i < $needed; $i++) {
            $first = $firstNames[$i % 10];
            $last = $lastNames[($i * 3) % 10];
            $status = $statuses[$i % 6];
            $paymentStatus = in_array($status, ['pending', 'refunded'], true) ? 'pending' : ($status === 'cancelled' ? 'failed' : 'paid');
            $qty = ($i % 4) + 1;
            $unit = 199 + (($i * 37) % 8800);
            $total = $qty * $unit;
            $created = date('Y-m-d H:i:s', time() - ($i % 86400));
            $seq = $start + $i + 1;

            $orders[] = [
                'number' => 'LT'.str_pad((string) $seq, 9, '0', STR_PAD_LEFT),
                'email' => strtolower($first.'.'.$last.$seq.'@load.test'),
                'status' => $status,
                'currency_code' => 'INR',
                'subtotal' => $total,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'grand_total' => $total,
                'payment_method_id' => $payId,
                'payment_status' => $paymentStatus,
                'billing_address' => $address,
                'shipping_address' => $address,
                'created_at' => $created,
                'updated_at' => $created,
            ];

            $items[] = [
                'sku' => 'DEMO-001',
                'name' => 'Demo Cotton Tee',
                'qty' => $qty,
                'unit_price' => $unit,
                'total' => $total,
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($orders) === $chunk || $i === $needed - 1) {
                DB::table('orders')->insert($orders);
                $firstId = (int) DB::getPdo()->lastInsertId();
                foreach ($items as $index => $item) {
                    $items[$index]['order_id'] = $firstId + $index;
                }
                DB::table('order_items')->insert($items);
                $bar->advance(count($orders));
                $orders = [];
                $items = [];
            }
        }

        $bar->finish();
        $this->newLine();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::statement('SET UNIQUE_CHECKS=1');
        DB::select('ANALYZE TABLE orders');
        DB::select('ANALYZE TABLE order_items');

        $this->info('Orders after seed: '.(int) DB::table('orders')->count());
    }

    private function benchmark(): void
    {
        $total = (int) DB::table('orders')->count();
        $this->newLine();
        $this->info("Speed test against {$total} orders");

        $this->line($this->explain('SELECT id FROM orders ORDER BY id DESC LIMIT 10'));
        $this->line($this->explain('SELECT status, COUNT(*) AS aggregate FROM orders GROUP BY status'));

        $timings = [];

        $timings['status tab counts'] = $this->time(function () {
            return Order::query()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
        });

        $timings['latest 10 rows'] = $this->time(function () {
            return Order::query()
                ->select(['id', 'number', 'customer_id', 'email', 'status', 'currency_code', 'grand_total', 'payment_method_id', 'payment_status', 'billing_address', 'created_at'])
                ->with([
                    'customer:id,name,email',
                    'items:id,order_id,qty',
                    'paymentMethod:id,name',
                ])
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        });

        $timings['filtered pending 10'] = $this->time(function () {
            return Order::query()
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'number', 'status']);
        });

        $request = Request::create('/console/orders', 'GET');
        $controller = app(OrderController::class);
        $timings['full orders page data'] = $this->time(fn () => $controller->index($request));

        $this->table(['Check', 'Milliseconds'], collect($timings)->map(fn ($ms, $label) => [$label, number_format($ms, 1)])->values()->all());
    }

    private function time(callable $callback): float
    {
        $started = hrtime(true);
        $callback();

        return (hrtime(true) - $started) / 1_000_000;
    }

    private function explain(string $sql): string
    {
        if (! Schema::hasTable('orders')) {
            return $sql;
        }

        $rows = DB::select('EXPLAIN '.$sql);
        $plan = collect($rows)->map(function ($row) {
            $row = (array) $row;

            return ($row['type'] ?? '').' / '.($row['key'] ?? 'no-index').' / rows '.($row['rows'] ?? '?');
        })->implode('; ');

        return $sql.' => '.$plan;
    }
};
