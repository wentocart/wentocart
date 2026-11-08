<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\Ability;
use InvalidArgumentException;

final class ProductUpdateAbility implements Ability
{
    public function code(): string
    {
        return 'catalog.product.update';
    }

    public function label(): string
    {
        return 'Update product';
    }

    public function permission(): string
    {
        return 'catalog.products';
    }

    public function plan(array $input): array
    {
        $product = Product::query()->find((int) ($input['id'] ?? 0));
        if (! $product) {
            throw new InvalidArgumentException('Product not found.');
        }
        $before = $this->snapshot($product);
        $after = array_merge($before, $this->patch($input, $before));

        return [[
            'entity_type' => 'product',
            'entity_id' => (int) $product->id,
            'action' => 'update',
            'label' => $before['name'] ?: ('#'.$product->id),
            'before' => $before,
            'after' => $after,
            'reversible' => true,
        ]];
    }

    public function execute(array $item, array $input): array
    {
        $product = Product::query()->findOrFail((int) $item['entity_id']);
        $this->apply($product, $item['after'] ?? []);

        return $this->snapshot($product->fresh(['translations']));
    }

    public function rollback(array $item): void
    {
        $product = Product::query()->find((int) $item['entity_id']);
        if (! $product) {
            return;
        }
        $this->apply($product, $item['before'] ?? []);
    }

    public function eventName(): ?string
    {
        return 'product.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Product $product): array
    {
        $product->loadMissing('translations');
        $tr = $product->translation(Language::defaultCode());

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'price' => (float) $product->price,
            'stock_qty' => (int) $product->stock_qty,
            'is_enabled' => (bool) $product->is_enabled,
            'name' => (string) ($tr?->name ?? ''),
            'short_description' => (string) ($tr?->short_description ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    protected function patch(array $input, array $before): array
    {
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : $input;
        $out = [];
        foreach (['sku', 'name', 'short_description'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null) {
                $out[$key] = trim((string) $fields[$key]);
            }
        }
        if (array_key_exists('price', $fields)) {
            $out['price'] = round((float) $fields['price'], 2);
        }
        if (array_key_exists('stock_qty', $fields)) {
            $out['stock_qty'] = max(0, (int) $fields['stock_qty']);
        }
        if (array_key_exists('is_enabled', $fields)) {
            $out['is_enabled'] = (bool) $fields['is_enabled'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function apply(Product $product, array $state): void
    {
        $lang = Language::defaultCode();
        $tr = $product->translations()->where('language_code', $lang)->first();
        $name = (string) ($state['name'] ?? $tr?->name ?? 'Product');
        $qty = (int) ($state['stock_qty'] ?? $product->stock_qty);

        app(ProductService::class)->save([
            'product' => [
                'type' => $product->type ?: 'simple',
                'sku' => (string) ($state['sku'] ?? $product->sku),
                'price' => (float) ($state['price'] ?? $product->price),
                'stock_qty' => $qty,
                'stock_status' => $qty > 0 ? 'in_stock' : 'out_of_stock',
                'is_enabled' => array_key_exists('is_enabled', $state) ? (bool) $state['is_enabled'] : (bool) $product->is_enabled,
                'visibility' => $product->visibility ?: 'catalog_search',
                'available_for_order' => (bool) $product->available_for_order,
                'show_price' => (bool) $product->show_price,
                'manage_stock' => (bool) $product->manage_stock,
            ],
            'translations' => [
                $lang => [
                    'language_code' => $lang,
                    'name' => $name,
                    'slug' => $tr?->slug ?: \Illuminate\Support\Str::slug($name).'-'.$product->id,
                    'short_description' => (string) ($state['short_description'] ?? $tr?->short_description ?? ''),
                    'description' => (string) ($tr?->description ?? ''),
                ],
            ],
            'category_ids' => $product->categories()->pluck('categories.id')->all(),
            'tag_ids' => $product->tags()->pluck('tags.id')->all(),
        ], $product);
    }
}
