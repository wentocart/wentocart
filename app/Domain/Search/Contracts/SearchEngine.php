<?php

namespace App\Domain\Search\Contracts;

/**
 */
interface SearchEngine
{
    public function name(): string;

    public function available(): bool;

    /**
     * @return list<int> Product ids matching the query (may be empty).
     */
    public function searchIds(string $q, int $limit = 48): array;
}
