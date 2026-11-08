<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\StorefrontCatalogCache;

class CategoryObserver
{
    public function created(Category $category): void
    {
        StorefrontCatalogCache::bump();
    }

    public function updated(Category $category): void
    {
        StorefrontCatalogCache::bump();
    }

    public function deleted(Category $category): void
    {
        StorefrontCatalogCache::bump();
    }
}
