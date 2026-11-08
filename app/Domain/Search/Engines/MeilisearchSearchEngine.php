<?php

namespace App\Domain\Search\Engines;

use App\Domain\Search\Contracts\SearchEngine;
use App\Domain\Search\MeilisearchIndex;

final class MeilisearchSearchEngine implements SearchEngine
{
    public function __construct(private MeilisearchIndex $index) {}

    public function name(): string
    {
        return 'meilisearch';
    }

    public function available(): bool
    {
        return $this->index->enabled();
    }

    public function searchIds(string $q, int $limit = 48): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->index->searchIds($q, $limit);
    }
}
