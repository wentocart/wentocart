<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\GiftCardService;
use Illuminate\Console\Command;

class ReleaseExpiredGiftCardHoldsCommand extends Command
{
    protected $signature = 'wento:gift-cards-release-expired-holds';

    protected $description = 'Release OPEN gift-card HOLDs past their deferred-payment timeout (idempotent; independent of unpaid auto-cancel).';

    public function handle(GiftCardService $giftCards): int
    {
        $stats = $giftCards->releaseExpiredHolds();

        $this->info(sprintf(
            'Gift card holds: released=%d committed=%d skipped=%d',
            $stats['released'],
            $stats['committed'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
