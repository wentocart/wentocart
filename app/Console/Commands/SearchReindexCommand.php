<?php

namespace App\Console\Commands;

use App\Jobs\ReindexCatalogSearch;
use Illuminate\Console\Command;

class SearchReindexCommand extends Command
{
    protected $signature = 'wento:search-reindex {--sync : Run in this process instead of the queue}';

    protected $description = 'Rebuild Meilisearch and/or Elasticsearch product indexes (MySQL search still works without them).';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Reindexing now…');
            (new ReindexCatalogSearch)->handle(app(\App\Domain\Search\CatalogSearchIndex::class));
            $this->info('Done.');

            return self::SUCCESS;
        }

        ReindexCatalogSearch::dispatch();
        $this->info('Queued catalog search reindex. Set QUEUE_CONNECTION=database or redis and run a worker, or pass --sync.');

        return self::SUCCESS;
    }
}
