<?php

namespace App\Domain\Search\Engines;

use App\Domain\Search\Contracts\SearchEngine;

/**
 * Always available. Returns no ids so CatalogSearch runs its SQL LIKE / FULLTEXT path
 * (same idea as DatabaseEngine::search()).
 */
final class DatabaseSearchEngine implements SearchEngine
{
    public function name(): string
    {
        return 'database';
    }

    public function available(): bool
    {
        return true;
    }

    public function searchIds(string $q, int $limit = 48): array
    {
        return [];
    }
}
