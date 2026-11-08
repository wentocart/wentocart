<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Models\Product;

/**
 * Fan-out product documents to every enabled remote search index (Meili + ES).
 */
final class CatalogSearchIndex
{
    public function __construct(
        private MeilisearchIndex $meilisearch,
        private ElasticsearchIndex $elasticsearch,
    ) {}

    public function anyEnabled(): bool
    {
        return $this->meilisearch->enabled() || $this->elasticsearch->enabled();
    }

    public function upsert(Product $product): void
    {
        $fresh = $product->relationLoaded('translations')
            ? $product
            : $product->fresh(['translations']) ?? $product;

        $this->meilisearch->upsert($fresh);
        $this->elasticsearch->upsert($fresh);
    }

    /**
     * @param  list<Product>  $products
     */
    public function upsertMany(array $products): void
    {
        $this->meilisearch->upsertMany($products);
        $this->elasticsearch->upsertMany($products);
    }

    public function delete(int $productId): void
    {
        $this->meilisearch->delete($productId);
        $this->elasticsearch->delete($productId);
    }
}
