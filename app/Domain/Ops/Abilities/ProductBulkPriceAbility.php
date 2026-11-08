<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ops\Ability;
use InvalidArgumentException;

final class ProductBulkPriceAbility implements Ability
{
    public function __construct(
        protected ProductUpdateAbility $single = new ProductUpdateAbility,
    ) {}

    public function code(): string
    {
        return 'catalog.product.bulk_price';
    }

    public function label(): string
    {
        return 'Bulk update product prices';
    }

    public function permission(): string
    {
        return 'catalog.products';
    }

    public function plan(array $input): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($input['ids'] ?? []))));
        $ids = array_filter($ids, fn (int $id) => $id > 0);
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one product.');
        }
        if (count($ids) > 500) {
            throw new InvalidArgumentException('Bulk price changes are limited to 500 products.');
        }

        $percent = isset($input['percent']) ? (float) $input['percent'] : null;
        $set = array_key_exists('price', $input) ? (float) $input['price'] : null;
        if ($percent === null && $set === null) {
            throw new InvalidArgumentException('Provide percent or a new price.');
        }

        $items = [];
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $before = $this->single->snapshot($product);
            $price = $set !== null
                ? round($set, 2)
                : round(((float) $before['price']) * (1 + ($percent / 100)), 2);
            $after = $before;
            $after['price'] = max(0, $price);
            $items[] = [
                'entity_type' => 'product',
                'entity_id' => (int) $product->id,
                'action' => 'update',
                'label' => $before['name'] ?: ('#'.$product->id),
                'before' => $before,
                'after' => $after,
                'reversible' => true,
            ];
        }
        if ($items === []) {
            throw new InvalidArgumentException('No matching products.');
        }

        return $items;
    }

    public function execute(array $item, array $input): array
    {
        return $this->single->execute($item, $input);
    }

    public function rollback(array $item): void
    {
        $this->single->rollback($item);
    }

    public function eventName(): ?string
    {
        return 'product.prices.updated';
    }
}
