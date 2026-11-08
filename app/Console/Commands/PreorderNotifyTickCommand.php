<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\PreorderService;
use Illuminate\Console\Command;

class PreorderNotifyTickCommand extends Command
{
    protected $signature = 'wento:preorder-notify-tick {--limit=100}';

    protected $description = 'Email customers subscribed to pre-order availability notifications.';

    public function handle(PreorderService $preorders): int
    {
        $sent = $preorders->dispatchAvailabilityNotifications(max(1, (int) $this->option('limit')));
        $this->info('Notified '.$sent.' pre-order subscriber'.($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
