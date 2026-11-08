<?php

namespace App\Domain\Ops\Abilities;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\Ability;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CatalogImportAbility implements Ability
{
    public function __construct(
        protected ProductUpdateAbility $updates = new ProductUpdateAbility,
    ) {}

    public function code(): string
    {
        return 'catalog.import.products';
    }

    public function label(): string
    {
        return 'Import products';
    }

    public function permission(): string
    {
        return 'catalog.products';
    }

    public function plan(array $input): array
    {
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        if ($rows === []) {
            throw new InvalidArgumentException('No product rows to import.');
        }
        if (count($rows) > 500) {
            throw new InvalidArgumentException('Import is limited to 500 rows per batch.');
        }

        $items = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '' || $name === '') {
                throw new InvalidArgumentException('Row '.($index + 1).' needs a SKU and name.');
            }
            $product = Product::query()->where('sku', $sku)->first();
            if ($product) {
                $before = $this->updates->snapshot($product);
                $after = $before;
                $after['name'] = $name;
                if (array_key_exists('price', $row) && $row['price'] !== '') {
                    $after['price'] = round((float) $row['price'], 2);
                }
                if (array_key_exists('stock_qty', $row) && $row['stock_qty'] !== '') {
                    $after['stock_qty'] = max(0, (int) $row['stock_qty']);
                }
                if (array_key_exists('is_enabled', $row) && $row['is_enabled'] !== '') {
                    $after['is_enabled'] = filter_var($row['is_enabled'], FILTER_VALIDATE_BOOLEAN);
                }
                $items[] = [
                    'entity_type' => 'product',
                    'entity_id' => (int) $product->id,
                    'action' => 'update',
                    'label' => $sku,
                    'before' => $before,
                    'after' => $after,
                    'reversible' => true,
                ];
            } else {
                $after = [
                    'sku' => $sku,
                    'name' => $name,
                    'price' => round((float) ($row['price'] ?? 0), 2),
                    'stock_qty' => max(0, (int) ($row['stock_qty'] ?? 0)),
                    'is_enabled' => array_key_exists('is_enabled', $row) && $row['is_enabled'] !== ''
                        ? filter_var($row['is_enabled'], FILTER_VALIDATE_BOOLEAN)
                        : true,
                    'short_description' => trim((string) ($row['short_description'] ?? '')),
                ];
                $items[] = [
                    'entity_type' => 'product',
                    'entity_id' => null,
                    'action' => 'create',
                    'label' => $sku,
                    'before' => [],
                    'after' => $after,
                    'reversible' => true,
                ];
            }
        }

        return $items;
    }

    public function execute(array $item, array $input): array
    {
        if (($item['action'] ?? '') === 'create') {
            return $this->create($item['after'] ?? []);
        }

        return $this->updates->execute($item, $input);
    }

    public function rollback(array $item): void
    {
        if (($item['action'] ?? '') === 'create') {
            $id = (int) ($item['entity_id'] ?? 0);
            if ($id > 0) {
                Product::query()->whereKey($id)->delete();
            }

            return;
        }
        $this->updates->rollback($item);
    }

    public function eventName(): ?string
    {
        return 'catalog.imported';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function create(array $state): array
    {
        $lang = Language::defaultCode();
        $name = (string) ($state['name'] ?? 'Product');
        $qty = (int) ($state['stock_qty'] ?? 0);
        $product = app(ProductService::class)->save([
            'product' => [
                'type' => 'simple',
                'sku' => (string) ($state['sku'] ?? ('IMP-'.Str::upper(Str::random(6)))),
                'price' => (float) ($state['price'] ?? 0),
                'stock_qty' => $qty,
                'stock_status' => $qty > 0 ? 'in_stock' : 'out_of_stock',
                'is_enabled' => (bool) ($state['is_enabled'] ?? true),
                'visibility' => 'catalog_search',
                'available_for_order' => true,
                'show_price' => true,
                'manage_stock' => true,
            ],
            'translations' => [
                $lang => [
                    'language_code' => $lang,
                    'name' => $name,
                    'slug' => Str::slug($name) ?: 'product',
                    'short_description' => (string) ($state['short_description'] ?? ''),
                    'description' => '',
                ],
            ],
            'category_ids' => [],
            'tag_ids' => [],
        ]);

        return $this->updates->snapshot($product->fresh(['translations']));
    }
}
