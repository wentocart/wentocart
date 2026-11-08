<?php

namespace App\Console\Commands;

use App\Domain\Ops\WebhookDispatcher;
use Illuminate\Console\Command;

class WebhookTickCommand extends Command
{
    protected $signature = 'wento:webhooks-tick {--limit=40}';

    protected $description = 'Deliver due outbound webhooks and retry failed deliveries.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $ran = $dispatcher->tick(max(1, (int) $this->option('limit')));
        $this->info($ran === 1 ? '1 webhook delivery processed.' : $ran.' webhook deliveries processed.');

        return self::SUCCESS;
    }
}
