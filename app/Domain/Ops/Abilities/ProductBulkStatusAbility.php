<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Catalog\Models\Product;
use App\Domain\Ops\Ability;
use InvalidArgumentException;

final class ProductBulkStatusAbility implements Ability
{
    public function __construct(
        protected ProductUpdateAbility $single = new ProductUpdateAbility,
    ) {}

    public function code(): string
    {
        return 'catalog.product.bulk_status';
    }

    public function label(): string
    {
        return 'Bulk enable or disable products';
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
        if (! array_key_exists('is_enabled', $input) && ! array_key_exists('enabled', $input)) {
            throw new InvalidArgumentException('Choose enabled or disabled.');
        }
        $enabled = (bool) ($input['is_enabled'] ?? $input['enabled']);

        $items = [];
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $before = $this->single->snapshot($product);
            $after = $before;
            $after['is_enabled'] = $enabled;
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
        return 'product.status.updated';
    }
}
