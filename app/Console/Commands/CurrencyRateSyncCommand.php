<?php

namespace App\Console\Commands;

use App\Domain\Localization\Services\CurrencyRateSyncService;
use Illuminate\Console\Command;

class CurrencyRateSyncCommand extends Command
{
    protected $signature = 'wento:currencies-sync';

    protected $description = 'Synchronize foreign exchange rates against the default currency.';

    public function handle(CurrencyRateSyncService $syncService): int
    {
        $this->info('Fetching live currency exchange rates...');
        $result = $syncService->sync();

        if (! $result['ok']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        $this->info("Base Currency: {$result['base']}");
        $this->table(
            ['Currency', 'Exchange Rate'],
            collect($result['rates'])->map(fn ($rate, $code) => [$code, $rate])->all()
        );

        $this->info($result['message']);
        return self::SUCCESS;
    }
}
