<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\LowStockNotifier;
use Illuminate\Console\Command;

class StockAlertTickCommand extends Command
{
    protected $signature = 'wento:stock-alerts-tick {--limit=100}';

    protected $description = 'Email merchant low-stock notices. Customer stock alerts are sent when stock returns.';

    public function handle(LowStockNotifier $lowStock): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Customer stock alerts are event-driven (Product/ProductVariant observers).
        $merchant = $lowStock->tick($limit);
        $this->info('Notified '.$merchant.' low-stock product'.($merchant === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
