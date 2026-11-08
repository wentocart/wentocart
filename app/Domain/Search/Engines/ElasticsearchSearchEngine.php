<?php

namespace App\Domain\Search\Engines;

use App\Domain\Search\Contracts\SearchEngine;
use App\Domain\Search\ElasticsearchIndex;

/**
 * Optional Elasticsearch adapter over HTTP — no heavy PHP client required.
 * Enable with search.elasticsearch=1 and search.elasticsearch_host. Writes via {@see ElasticsearchIndex}.
 */
final class ElasticsearchSearchEngine implements SearchEngine
{
    public function __construct(private ElasticsearchIndex $index) {}

    public function name(): string
    {
        return 'elasticsearch';
    }

    public function available(): bool
    {
        return $this->index->enabled();
    }

    public function searchIds(string $q, int $limit = 48): array
    {
        return $this->index->searchIds($q, $limit);
    }
}
