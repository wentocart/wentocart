<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\GiftCardService;
use Illuminate\Console\Command;

class ExpireGiftCardsCommand extends Command
{
    protected $signature = 'wento:gift-cards-expire';

    protected $description = 'Mark active gift cards past their expiry date as expired (idempotent).';

    public function handle(GiftCardService $giftCards): int
    {
        $count = $giftCards->expireDueCards();
        $this->info("Expired {$count} gift card(s).");

        return self::SUCCESS;
    }
}
