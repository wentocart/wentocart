<?php

namespace App\Jobs;

use App\Domain\Catalog\Models\Product;
use App\Domain\Search\CatalogSearchIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReindexCatalogSearch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function handle(CatalogSearchIndex $index): void
    {
        if (! $index->anyEnabled()) {
            return;
        }

        Product::query()
            ->select(['id', 'sku', 'is_enabled'])
            ->with('translations')
            ->orderBy('id')
            ->chunkById(250, function ($products) use ($index) {
                $index->upsertMany($products->all());
            });
    }
}
