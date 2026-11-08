<?php

namespace App\Console\Commands;

use App\Domain\Marketplace\Services\MarketplaceSubscriptionTickService;
use Illuminate\Console\Command;

class MarketplaceSubscriptionTickCommand extends Command
{
    protected $signature = 'wento:marketplace-subscription-tick {--no-health : Skip account health refresh}';

    protected $description = 'Expire marketplace seller plans past ends_at and refresh account health (scheduler parity).';

    public function handle(MarketplaceSubscriptionTickService $tick): int
    {
        $result = $tick->run(! $this->option('no-health'));
        $this->info(sprintf(
            'Expired %d subscription(s); queued %d reminder(s); refreshed health for %d seller(s).',
            $result['expired'],
            $result['reminders'],
            $result['health'],
        ));

        return self::SUCCESS;
    }
}
