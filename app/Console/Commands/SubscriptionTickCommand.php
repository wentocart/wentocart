<?php

namespace App\Console\Commands;

use App\Domain\Subscription\Services\SubscriptionBilling;
use Illuminate\Console\Command;

class SubscriptionTickCommand extends Command
{
    protected $signature = 'wento:subscriptions-tick';

    protected $description = 'Charge due subscription renewals (saved method / sandbox), run dunning, and apply period-end / duration ends.';

    public function handle(SubscriptionBilling $billing): int
    {
        $n = $billing->tick();
        $this->info('Processed '.$n.' subscription renewal'.($n === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
