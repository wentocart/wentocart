<?php

namespace App\Console\Commands;

use App\Domain\Marketplace\Services\SellerEarningAvailabilityService;
use Illuminate\Console\Command;

class MarketplaceReleaseEarningsCommand extends Command
{
    protected $signature = 'wento:marketplace-release-earnings';

    protected $description = 'Release seller earnings whose return window or scheduled available_at has passed.';

    public function handle(SellerEarningAvailabilityService $availability): int
    {
        $count = $availability->releaseDueEarnings();
        $this->info("Released earnings for {$count} seller order(s).");

        return self::SUCCESS;
    }
}
