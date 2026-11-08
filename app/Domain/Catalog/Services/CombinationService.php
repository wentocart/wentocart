<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CombinationService
{
    public function syncProduct(Product $product, array $data): void
    {
        if (! $product->isCombination() || ! array_key_exists('combination_axes', $data)) {
            return;
        }

        $variants = $data['combination_variants'] ?? [];
        $deletedIds = array_values(array_map('intval', $data['deleted_combination_variant_ids'] ?? []));

        $this->validateVariants($product, $variants, $deletedIds);
        $this->syncConfig($product, $data['combination_axes'] ?? []);
        $this->generateVariants(
            $product->fresh(),
            $variants,
            (bool) ($data['remove_missing_combinations'] ?? false),
        );
        $this->syncVariants($product->fresh(), $variants);
        $this->deleteVariants($product->fresh(), $deletedIds);
        $this->applyDefaultFromPayload($product->fresh(), $variants);
        $this->normalizeDefaults($product->fresh());
        $this->syncParentInventory($product->fresh());
    }

    public function applyDefaultFromPayload(Product $product, array $variants): void
    {
        $defaultRow = collect($variants)->first(fn ($row) => $this->boolValue($row, 'is_default'));
        if (! $defaultRow) {
            return;
        }

        $variant = null;
        if (! empty($defaultRow['id'])) {
            $variant = $product->variants()->whereKey((int) $defaultRow['id'])->first();
        }
        if (! $variant) {
            $variant = $product->variants()->where('combination_key', $this->combinationKey($defaultRow['attribute_value_ids'] ?? []))->first();
        }
        if (! $variant || ! $this->boolValue($defaultRow, 'is_enabled', true)) {
            return;
        }

        $product->variants()->update(['is_default' => false]);
        $variant->update(['is_default' => true]);
    }
    public function presentConfig(Product $product): array
    {
        $product->loadMissing([
            'combinationAxes.attribute.translations',
            'combinationAxisValues.attributeValue.translations',
        ]);

        $selectedValues = $product->combinationAxisValues
            ->groupBy('attribute_id')
            ->map(fn ($rows) => $rows->pluck('attribute_value_id')->map(fn ($id) => (int) $id)->values()->all());

        return $product->combinationAxes
            ->sortBy('position')
            ->values()
            ->map(function ($axis) use ($selectedValues) {
                $attribute = $axis->attribute;
                $valueIds = $selectedValues->get($attribute?->id, []);
                $values = AttributeValue::query()
                    ->with('translations')
                    ->where('attribute_id', $attribute?->id)
                    ->whereIn('id', $valueIds)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (AttributeValue $value) => [
                        'id' => $value->id,
                        'value' => $value->translation()?->value ?: $value->code,
                        'code' => $value->code,
                        'swatch_type' => $value->swatch_type,
                        'color_code' => $value->color_code,
                        'swatch_image' => $value->swatch_image,
                        'is_enabled' => (bool) $value->is_enabled,
                    ])
                    ->values()
                    ->all();

                return [
                    'attribute_id' => $attribute?->id,
                    'name' => $attribute?->translation()?->name ?: $attribute?->code,
                    'code' => $attribute?->code,
                    'type' => $attribute?->type,
                    'supports_swatches' => (bool) ($attribute?->supports_swatches),
                    'position' => (int) $axis->position,
                    'value_ids' => $valueIds,
                    'values' => $values,
                ];
            })
            ->all();
    }

    public function presentVariants(Product $product): array
    {
        $product->loadMissing([
            'variants.attributeValues.translations',
            'variants.attributeValues.attribute.translations',
            'variants.coverImage',
        ]);

        return $product->variants
            ->sortBy('position')
            ->values()
            ->map(function (ProductVariant $variant) {
                $labels = $variant->attributeValues
                    ->sortBy(fn (AttributeValue $value) => $value->attribute?->position ?? 0)
                    ->map(fn (AttributeValue $value) => $value->translation()?->value ?: $value->code)
                    ->values()
                    ->all();

                $valueDetails = $variant->attributeValues
                    ->sortBy(fn (AttributeValue $value) => $value->attribute?->position ?? 0)
                    ->map(function (AttributeValue $value) {
                        $attribute = $value->attribute;

                        return [
                            'id' => $value->id,
                            'attribute_id' => $value->attribute_id,
                            'attribute_name' => $attribute?->translation()?->name ?: $attribute?->code,
                            'value' => $value->translation()?->value ?: $value->code,
                            'is_enabled' => (bool) $value->is_enabled,
                            'attribute_enabled' => (bool) ($attribute?->is_enabled),
                        ];
                    })
                    ->values()
                    ->all();

                $warnings = [];
                foreach ($valueDetails as $detail) {
                    if (! ($detail['attribute_enabled'] ?? true)) {
                        $warnings[] = 'Attribute "'.$detail['attribute_name'].'" is disabled';
                    }
                    if (! ($detail['is_enabled'] ?? true)) {
                        $warnings[] = $detail['attribute_name'].' value "'.$detail['value'].'" is disabled';
                    }
                }

                $cover = $this->presentVariantCover($variant);

                return [
                    'id' => $variant->id,
                    'label' => implode(' / ', $labels),
                    'combination_key' => $variant->combination_key,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'sale_price_type' => $variant->sale_price_type,
                    'cost_price' => $variant->cost_price,
                    'stock_qty' => (float) $variant->stock_qty,
                    'reserved_qty' => (float) ($variant->reserved_qty ?? 0),
                    'manage_stock' => (bool) $variant->manage_stock,
                    'stock_status' => $variant->stock_status,
                    'allow_backorder' => (bool) $variant->allow_backorder,
                    'weight' => $variant->weight,
                    'length' => $variant->length,
                    'width' => $variant->width,
                    'height' => $variant->height,
                    'cover_image_id' => $cover['cover_image_id'],
                    'cover_path' => $cover['cover_path'],
                    'cover_url' => $cover['cover_url'],
                    'is_enabled' => (bool) $variant->is_enabled,
                    'is_default' => (bool) $variant->is_default,
                    'position' => (int) $variant->position,
                    'attribute_value_ids' => $variant->attributeValues->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'value_details' => $valueDetails,
                    'warnings' => $warnings,
                    'can_delete' => ! $this->variantInUse($variant),
                ];
            })
            ->all();
    }

    /**
     * Storefront axes and variants for the product page. Empty when the product is not a combination.
     *
     * @return array{axes: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    public function presentStorefront(Product $product): array
    {
        if (! $product->isCombination()) {
            return ['axes' => [], 'variants' => []];
        }

        $variants = collect($this->presentVariants($product))
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'label' => $row['label'],
                'sku' => $row['sku'],
                'price' => $row['price'],
                'sale_price' => $row['sale_price'],
                'sale_price_type' => $row['sale_price_type'],
                'selling_price' => (function () use ($product, $row) {
                    $variant = $product->variants->firstWhere('id', $row['id']);
                    if ($variant) {
                        $price = $variant->sellingPrice();
                    } else {
                        $regular = max(0, (float) ($row['price'] ?? 0));
                        $price = $regular;
                        if (\App\Domain\Settings\PricingSettings::salePricing()
                            && $row['sale_price'] !== null
                            && $row['sale_price'] !== ''
                            && $product->saleWindowOpen()) {
                            if (($row['sale_price_type'] ?? 'fixed') === 'percent') {
                                $percent = max(0, min(100, (float) $row['sale_price']));
                                $price = max(0, round($regular * (1 - ($percent / 100)), 2));
                            } else {
                                $price = max(0, min($regular, (float) $row['sale_price']));
                            }
                        }
                    }

                    return \App\Domain\Settings\CustomerSettings::applyGroupDiscount($price, auth('customer')->user());
                })(),
                'stock_qty' => $row['stock_qty'],
                'stock_status' => $row['stock_status'],
                'manage_stock' => $row['manage_stock'],
                'allow_backorder' => $row['allow_backorder'],
                'is_enabled' => $row['is_enabled'],
                'is_default' => $row['is_default'],
                'attribute_value_ids' => $row['attribute_value_ids'],
                'cover_url' => $row['cover_url'],
            ])
            ->values()
            ->all();

        return [
            'axes' => $this->presentConfig($product),
            'variants' => $variants,
        ];
    }

    public function syncConfig(Product $product, array $axes): void
    {
        $this->assertValidConfig($product, $axes);

        DB::transaction(function () use ($product, $axes) {
            $attributeIds = [];
            foreach (array_values($axes) as $index => $axis) {
                $attributeId = (int) ($axis['attribute_id'] ?? 0);
                $attributeIds[] = $attributeId;

                DB::table('product_combination_axes')->updateOrInsert(
                    ['product_id' => $product->id, 'attribute_id' => $attributeId],
                    ['position' => $index, 'updated_at' => now(), 'created_at' => now()],
                );

                $valueIds = array_values(array_unique(array_map('intval', $axis['value_ids'] ?? [])));
                DB::table('product_combination_axis_values')
                    ->where('product_id', $product->id)
                    ->where('attribute_id', $attributeId)
                    ->whereNotIn('attribute_value_id', $valueIds)
                    ->delete();

                foreach ($valueIds as $valueId) {
                    DB::table('product_combination_axis_values')->updateOrInsert(
                        [
                            'product_id' => $product->id,
                            'attribute_id' => $attributeId,
                            'attribute_value_id' => $valueId,
                        ],
                        ['updated_at' => now(), 'created_at' => now()],
                    );
                }
            }

            $query = DB::table('product_combination_axes')->where('product_id', $product->id);
            if ($attributeIds !== []) {
                $query->whereNotIn('attribute_id', $attributeIds);
            }
            $removedAttributeIds = $query->pluck('attribute_id')->all();
            if ($removedAttributeIds !== []) {
                DB::table('product_combination_axis_values')
                    ->where('product_id', $product->id)
                    ->whereIn('attribute_id', $removedAttributeIds)
                    ->delete();
            }
            $query->delete();
        });
    }

    public function generateVariants(Product $product, array $variantsPayload = [], bool $removeMissing = false): array
    {
        $product->loadMissing(['combinationAxes', 'combinationAxisValues']);
        $axes = $this->axisValueGroups($product);
        if ($axes === []) {
            return ['created' => 0, 'kept' => 0, 'removed' => 0];
        }

        $combinations = $this->cartesian($axes);
        $existing = $product->variants()->with('attributeValues')->get()->keyBy('combination_key');
        $created = 0;
        $kept = 0;
        $seenKeys = [];
        $payloadByKey = collect($variantsPayload)->keyBy(fn ($row) => $this->combinationKey($row['attribute_value_ids'] ?? []));

        DB::transaction(function () use ($product, $combinations, $existing, $payloadByKey, $removeMissing, &$created, &$kept, &$seenKeys) {
            foreach ($combinations as $index => $valueIds) {
                $key = $this->combinationKey($valueIds);
                $seenKeys[] = $key;
                $rowPayload = $payloadByKey->get($key, []);

                if ($existing->has($key)) {
                    $variant = $existing->get($key);
                    $this->applyVariantPayload($variant, $rowPayload, $index);
                    $kept++;

                    continue;
                }

                $variant = $product->variants()->create([
                    'combination_key' => $key,
                    'sku' => $this->autoSku($product, $valueIds, $rowPayload['sku'] ?? null),
                    'barcode' => $rowPayload['barcode'] ?? null,
                    'price' => $rowPayload['price'] ?? null,
                    'sale_price' => ($rowPayload['sale_price'] ?? '') === '' ? null : $rowPayload['sale_price'],
                    'sale_price_type' => $rowPayload['sale_price_type'] ?? 'fixed',
                    'cost_price' => ($rowPayload['cost_price'] ?? '') === '' ? null : $rowPayload['cost_price'],
                    'stock_qty' => (int) ($rowPayload['stock_qty'] ?? 0),
                    'manage_stock' => array_key_exists('manage_stock', $rowPayload) ? (bool) $rowPayload['manage_stock'] : true,
                    'stock_status' => $rowPayload['stock_status'] ?? 'in_stock',
                    'allow_backorder' => (bool) ($rowPayload['allow_backorder'] ?? false),
                    'weight' => ($rowPayload['weight'] ?? '') === '' ? null : $rowPayload['weight'],
                    'length' => ($rowPayload['length'] ?? '') === '' ? null : $rowPayload['length'],
                    'width' => ($rowPayload['width'] ?? '') === '' ? null : $rowPayload['width'],
                    'height' => ($rowPayload['height'] ?? '') === '' ? null : $rowPayload['height'],
                    'cover_path' => null,
                    'cover_image_id' => null,
                    'is_enabled' => array_key_exists('is_enabled', $rowPayload) ? (bool) $rowPayload['is_enabled'] : true,
                    'is_default' => (bool) ($rowPayload['is_default'] ?? false),
                    'position' => $index,
                ]);
                $this->applyCoverFromPayload($variant, $rowPayload, $product);
                $variant->save();
                $variant->attributeValues()->sync($valueIds);
                $created++;
            }

            if ($removeMissing) {
                $product->variants()->whereNotIn('combination_key', $seenKeys)->delete();
            }
        });

        $removed = $removeMissing ? max(0, $existing->count() - count($seenKeys)) : 0;

        return compact('created', 'kept', 'removed');
    }

    public function syncVariants(Product $product, array $variants): void
    {
        DB::transaction(function () use ($product, $variants) {
            $defaultMarked = collect($variants)->contains(fn ($row) => $this->boolValue($row, 'is_default'));
            $defaultApplied = false;

            foreach ($variants as $index => $row) {
                $variantId = (int) ($row['id'] ?? 0);
                $key = $this->combinationKey($row['attribute_value_ids'] ?? []);
                $variant = $variantId
                    ? $product->variants()->whereKey($variantId)->first()
                    : $product->variants()->where('combination_key', $key)->first();

                if (! $variant) {
                    continue;
                }

                $isEnabled = array_key_exists('is_enabled', $row) ? $this->boolValue($row, 'is_enabled') : $variant->is_enabled;
                $isDefault = $this->boolValue($row, 'is_default');

                if ($defaultMarked && ! $defaultApplied && $isDefault) {
                    $product->variants()->update(['is_default' => false]);
                    $defaultApplied = true;
                }

                $variant->fill([
                    'sku' => $this->autoSku($product, $row['attribute_value_ids'] ?? $variant->attributeValues->pluck('id')->all(), $row['sku'] ?? $variant->sku, $variant->id),
                    'barcode' => $row['barcode'] ?? $variant->barcode,
                    'price' => ($row['price'] ?? '') === '' ? null : $row['price'],
                    'sale_price' => ($row['sale_price'] ?? '') === '' ? null : $row['sale_price'],
                    'sale_price_type' => $row['sale_price_type'] ?? $variant->sale_price_type,
                    'cost_price' => ($row['cost_price'] ?? '') === '' ? null : $row['cost_price'],
                    'stock_qty' => (int) ($row['stock_qty'] ?? $variant->stock_qty),
                    'manage_stock' => array_key_exists('manage_stock', $row) ? (bool) $row['manage_stock'] : $variant->manage_stock,
                    'stock_status' => $row['stock_status'] ?? $variant->stock_status,
                    'allow_backorder' => array_key_exists('allow_backorder', $row) ? (bool) $row['allow_backorder'] : $variant->allow_backorder,
                    'weight' => ($row['weight'] ?? '') === '' ? null : $row['weight'],
                    'length' => ($row['length'] ?? '') === '' ? null : $row['length'],
                    'width' => ($row['width'] ?? '') === '' ? null : $row['width'],
                    'height' => ($row['height'] ?? '') === '' ? null : $row['height'],
                    'is_enabled' => $isEnabled,
                    'is_default' => $isDefault && $isEnabled,
                    'position' => $index,
                ]);
                $this->applyCoverFromPayload($variant, $row, $product);
                $variant->save();
            }
        });
    }

    public function validateVariants(Product $product, array $variants, array $deletedIds = []): void
    {
        $seenKeys = [];
        $seenSkus = [];
        $defaultCount = 0;

        foreach ($variants as $index => $row) {
            $key = "combination_variants.$index";
            $valueIds = array_values(array_unique(array_map('intval', $row['attribute_value_ids'] ?? [])));
            if ($valueIds === []) {
                throw ValidationException::withMessages([$key.'.attribute_value_ids' => 'Each combination must include attribute values.']);
            }

            $comboKey = $this->combinationKey($valueIds);
            if (in_array($comboKey, $seenKeys, true)) {
                throw ValidationException::withMessages([$key.'.attribute_value_ids' => 'Duplicate combination detected.']);
            }
            $seenKeys[] = $comboKey;

            $values = AttributeValue::query()->with('attribute.translations')->whereIn('id', $valueIds)->get()->keyBy('id');
            if ($values->count() !== count($valueIds)) {
                throw ValidationException::withMessages([$key.'.attribute_value_ids' => 'One or more attribute values are invalid.']);
            }

            $attributeIds = [];
            foreach ($valueIds as $valueIndex => $valueId) {
                $value = $values->get($valueId);
                $attributeId = (int) $value->attribute_id;
                if (in_array($attributeId, $attributeIds, true)) {
                    throw ValidationException::withMessages(["$key.attribute_value_ids.$valueIndex" => 'Each attribute can only appear once in a combination.']);
                }
                $attributeIds[] = $attributeId;
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '') {
                if (in_array(mb_strtolower($sku), $seenSkus, true)) {
                    throw ValidationException::withMessages([$key.'.sku' => 'Duplicate SKU within this product.']);
                }
                $seenSkus[] = mb_strtolower($sku);

                if (Product::query()->where('sku', $sku)->where('id', '!=', $product->id)->exists()) {
                    throw ValidationException::withMessages([$key.'.sku' => 'This SKU is already used by another product.']);
                }

                $variantQuery = ProductVariant::query()->where('sku', $sku)->where('product_id', '!=', $product->id);
                $currentId = (int) ($row['id'] ?? 0);
                if ($currentId) {
                    $variantQuery->where('id', '!=', $currentId);
                }
                if ($variantQuery->exists()) {
                    throw ValidationException::withMessages([$key.'.sku' => 'This SKU is already used by another combination.']);
                }

                $sameProduct = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('sku', $sku)
                    ->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))
                    ->when(! $currentId, fn ($query) => $query->where('combination_key', '!=', $comboKey))
                    ->exists();
                if ($sameProduct) {
                    throw ValidationException::withMessages([$key.'.sku' => 'This SKU is already used by another combination on this product.']);
                }
            }

            if (array_key_exists('price', $row) && $row['price'] !== '' && $row['price'] !== null && (float) $row['price'] < 0) {
                throw ValidationException::withMessages([$key.'.price' => 'Price cannot be negative.']);
            }
            if (array_key_exists('sale_price', $row) && $row['sale_price'] !== '' && $row['sale_price'] !== null && (float) $row['sale_price'] < 0) {
                throw ValidationException::withMessages([$key.'.sale_price' => 'Sale price cannot be negative.']);
            }
            if (($row['sale_price_type'] ?? 'fixed') === 'percent' && (float) ($row['sale_price'] ?? 0) > 100) {
                throw ValidationException::withMessages([$key.'.sale_price' => 'Percentage sale price cannot exceed 100%.']);
            }
            if (array_key_exists('cost_price', $row) && $row['cost_price'] !== '' && $row['cost_price'] !== null && (float) $row['cost_price'] < 0) {
                throw ValidationException::withMessages([$key.'.cost_price' => 'Cost price cannot be negative.']);
            }
            if (array_key_exists('stock_qty', $row) && (int) $row['stock_qty'] < 0) {
                throw ValidationException::withMessages([$key.'.stock_qty' => 'Stock quantity cannot be negative.']);
            }

            foreach (['weight', 'length', 'width', 'height'] as $dimension) {
                if (array_key_exists($dimension, $row) && $row[$dimension] !== '' && $row[$dimension] !== null && (float) $row[$dimension] < 0) {
                    throw ValidationException::withMessages(["$key.$dimension" => ucfirst($dimension).' adjustment cannot be negative.']);
                }
            }

            if (array_key_exists('cover_image_id', $row) && $row['cover_image_id'] !== null && $row['cover_image_id'] !== '') {
                $owned = ProductImage::query()
                    ->where('product_id', $product->id)
                    ->whereKey((int) $row['cover_image_id'])
                    ->exists();
                if (! $owned) {
                    throw ValidationException::withMessages([
                        $key.'.cover_image_id' => 'Combination image must belong to this product.',
                    ]);
                }
            }

            $isDefault = (bool) ($row['is_default'] ?? false);
            $isEnabled = array_key_exists('is_enabled', $row) ? (bool) $row['is_enabled'] : true;
            if ($isDefault && ! $isEnabled) {
                throw ValidationException::withMessages([$key.'.is_default' => 'A disabled combination cannot be the default.']);
            }
            if ($isDefault) {
                $defaultCount++;
            }
        }

        if ($defaultCount > 1) {
            throw ValidationException::withMessages(['combination_variants' => 'Only one combination can be marked as default.']);
        }

        foreach ($deletedIds as $deletedId) {
            $variant = $product->variants()->whereKey($deletedId)->first();
            if (! $variant) {
                continue;
            }
            if ($message = $this->variantDeleteMessage($variant)) {
                throw ValidationException::withMessages(['deleted_combination_variant_ids' => $message]);
            }
        }
    }

    public function deleteVariants(Product $product, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $variant = $product->variants()->whereKey($id)->first();
            if (! $variant) {
                continue;
            }
            if ($this->variantInUse($variant)) {
                continue;
            }
            $variant->delete();
            $deleted++;
        }

        return $deleted;
    }

    public function normalizeDefaults(Product $product): void
    {
        $variants = $product->variants()->orderBy('position')->orderBy('id')->get();
        if ($variants->isEmpty()) {
            return;
        }

        $defaults = $variants->filter(fn (ProductVariant $variant) => $variant->is_default && $variant->is_enabled);
        if ($defaults->count() === 1) {
            return;
        }

        $default = $defaults->first() ?: $variants->first(fn (ProductVariant $variant) => $variant->is_enabled);
        $product->variants()->update(['is_default' => false]);
        if ($default) {
            $default->update(['is_default' => true, 'is_enabled' => true]);
        }
    }

    public function syncParentInventory(Product $product): void
    {
        $total = (int) $product->variants()
            ->where('is_enabled', true)
            ->where('manage_stock', true)
            ->sum('stock_qty');

        $hasStock = $product->variants()
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->where('stock_status', 'in_stock')
                    ->orWhere('allow_backorder', true);
            })
            ->exists();

        $product->update([
            'stock_qty' => $total,
            'stock_status' => $hasStock || $total > 0 ? 'in_stock' : 'out_of_stock',
        ]);
    }

    public function combinationKey(array $valueIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $valueIds)));
        sort($ids, SORT_NUMERIC);

        return implode('-', $ids);
    }

    public function previewCount(Product $product): int
    {
        $groups = $this->axisValueGroups($product);
        if ($groups === []) {
            return 0;
        }

        return count($this->cartesian($groups));
    }

    protected function axisValueGroups(Product $product): array
    {
        $axes = $product->combinationAxes()->orderBy('position')->get();
        if ($axes->isEmpty()) {
            return [];
        }

        $selected = DB::table('product_combination_axis_values')
            ->where('product_id', $product->id)
            ->get()
            ->groupBy('attribute_id');

        $groups = [];
        foreach ($axes as $axis) {
            $valueIds = ($selected->get($axis->attribute_id) ?? collect())->pluck('attribute_value_id')->map(fn ($id) => (int) $id)->values()->all();
            if ($valueIds === []) {
                return [];
            }
            $groups[] = $valueIds;
        }

        return $groups;
    }

    protected function cartesian(array $groups): array
    {
        $result = [[]];
        foreach ($groups as $group) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($group as $valueId) {
                    $next[] = [...$combo, (int) $valueId];
                }
            }
            $result = $next;
        }

        return $result;
    }

    protected function applyVariantPayload(ProductVariant $variant, array $payload, int $position): void
    {
        if ($payload === []) {
            $variant->loadMissing('attributeValues');
            $variant->update([
                'position' => $position,
                'sku' => $this->autoSku(
                    $variant->product ?? Product::query()->find($variant->product_id),
                    $variant->attributeValues->pluck('id')->all(),
                    $variant->sku,
                    $variant->id,
                ) ?: $variant->sku,
            ]);

            return;
        }

        $variant->loadMissing('attributeValues');
        $variant->fill([
            'sku' => $this->autoSku($variant->product ?? Product::query()->find($variant->product_id), $variant->attributeValues->pluck('id')->all(), $payload['sku'] ?? $variant->sku, $variant->id),
            'barcode' => $payload['barcode'] ?? $variant->barcode,
            'price' => array_key_exists('price', $payload) ? (($payload['price'] ?? '') === '' ? null : $payload['price']) : $variant->price,
            'sale_price' => array_key_exists('sale_price', $payload) ? (($payload['sale_price'] ?? '') === '' ? null : $payload['sale_price']) : $variant->sale_price,
            'sale_price_type' => $payload['sale_price_type'] ?? $variant->sale_price_type,
            'cost_price' => array_key_exists('cost_price', $payload) ? (($payload['cost_price'] ?? '') === '' ? null : $payload['cost_price']) : $variant->cost_price,
            'stock_qty' => array_key_exists('stock_qty', $payload) ? (int) $payload['stock_qty'] : $variant->stock_qty,
            'manage_stock' => array_key_exists('manage_stock', $payload) ? (bool) $payload['manage_stock'] : $variant->manage_stock,
            'stock_status' => $payload['stock_status'] ?? $variant->stock_status,
            'allow_backorder' => array_key_exists('allow_backorder', $payload) ? (bool) $payload['allow_backorder'] : $variant->allow_backorder,
            'weight' => array_key_exists('weight', $payload) ? (($payload['weight'] ?? '') === '' ? null : $payload['weight']) : $variant->weight,
            'length' => array_key_exists('length', $payload) ? (($payload['length'] ?? '') === '' ? null : $payload['length']) : $variant->length,
            'width' => array_key_exists('width', $payload) ? (($payload['width'] ?? '') === '' ? null : $payload['width']) : $variant->width,
            'height' => array_key_exists('height', $payload) ? (($payload['height'] ?? '') === '' ? null : $payload['height']) : $variant->height,
            'is_enabled' => array_key_exists('is_enabled', $payload) ? (bool) $payload['is_enabled'] : $variant->is_enabled,
            'is_default' => array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : $variant->is_default,
            'position' => $position,
        ]);
        $this->applyCoverFromPayload($variant, $payload, $variant->product ?? $variant->product()->first());
        $variant->save();
    }

    /** @return array{cover_image_id: ?int, cover_path: ?string, cover_url: ?string} */
    protected function presentVariantCover(ProductVariant $variant): array
    {
        $image = null;
        if ($variant->cover_image_id) {
            $image = $variant->relationLoaded('coverImage')
                ? $variant->coverImage
                : ProductImage::query()
                    ->whereKey($variant->cover_image_id)
                    ->where('product_id', $variant->product_id)
                    ->first();
        }

        if (! $image && $variant->cover_path) {
            $image = ProductImage::query()
                ->where('product_id', $variant->product_id)
                ->where('path', $variant->cover_path)
                ->first();
        }

        if ($image) {
            return [
                'cover_image_id' => (int) $image->id,
                'cover_path' => $image->path,
                'cover_url' => CatalogMediaUrl::url($image->path, DefaultImage::SM),
            ];
        }

        return [
            'cover_image_id' => null,
            'cover_path' => null,
            'cover_url' => CatalogMediaUrl::url(null, DefaultImage::SM),
        ];
    }

    protected function applyCoverFromPayload(ProductVariant $variant, array $row, ?Product $product = null): void
    {
        $product ??= $variant->product ?? Product::query()->find($variant->product_id);
        if (! $product) {
            return;
        }

        if (array_key_exists('cover_image_id', $row)) {
            $raw = $row['cover_image_id'];
            if ($raw === null || $raw === '' || $raw === false) {
                $variant->cover_image_id = null;
                $variant->cover_path = null;

                return;
            }

            $image = ProductImage::query()
                ->where('product_id', $product->id)
                ->whereKey((int) $raw)
                ->first();

            $variant->cover_image_id = $image?->id;
            $variant->cover_path = $image?->path;

            return;
        }

        if (! array_key_exists('cover_path', $row)) {
            return;
        }

        $path = $row['cover_path'] !== null && $row['cover_path'] !== '' ? (string) $row['cover_path'] : null;
        if ($path === null) {
            $variant->cover_image_id = null;
            $variant->cover_path = null;

            return;
        }

        $image = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('path', $path)
            ->first();

        $variant->cover_path = $path;
        $variant->cover_image_id = $image?->id;
    }

    protected function assertValidConfig(Product $product, array $axes): void
    {
        if ($axes === []) {
            return;
        }

        $existingAxisValues = DB::table('product_combination_axis_values')
            ->where('product_id', $product->id)
            ->pluck('attribute_value_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $existingAttributeIds = DB::table('product_combination_axes')
            ->where('product_id', $product->id)
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $seenAttributes = [];
        foreach ($axes as $index => $axis) {
            $attributeId = (int) ($axis['attribute_id'] ?? 0);
            $key = "combination_axes.$index";

            if ($attributeId < 1) {
                throw ValidationException::withMessages([$key.'.attribute_id' => 'Select a valid attribute.']);
            }
            if (in_array($attributeId, $seenAttributes, true)) {
                throw ValidationException::withMessages([$key.'.attribute_id' => 'Each attribute can only be added once.']);
            }
            $seenAttributes[] = $attributeId;

            $attribute = CatalogAttribute::query()->find($attributeId);
            if (! $attribute) {
                throw ValidationException::withMessages([$key.'.attribute_id' => 'The selected attribute no longer exists.']);
            }
            $alreadyAssigned = in_array($attributeId, $existingAttributeIds, true);
            if (! $attribute->is_enabled && ! $alreadyAssigned) {
                throw ValidationException::withMessages([$key.'.attribute_id' => 'Disabled attributes cannot be newly assigned.']);
            }
            if (! $attribute->isCombinationType()) {
                throw ValidationException::withMessages([$key.'.attribute_id' => 'Only selectable attribute types can be used for combinations.']);
            }

            $valueIds = array_values(array_unique(array_map('intval', $axis['value_ids'] ?? [])));
            if ($valueIds === []) {
                throw ValidationException::withMessages([$key.'.value_ids' => 'Select at least one value.']);
            }

            foreach ($valueIds as $valueIndex => $valueId) {
                $value = AttributeValue::query()->where('attribute_id', $attributeId)->find($valueId);
                if (! $value) {
                    throw ValidationException::withMessages(["$key.value_ids.$valueIndex" => 'Invalid attribute value.']);
                }
                $alreadySelected = in_array($valueId, $existingAxisValues, true);
                if (! $value->is_enabled && ! $alreadySelected) {
                    throw ValidationException::withMessages(["$key.value_ids.$valueIndex" => 'Disabled values cannot be newly assigned.']);
                }
            }
        }
    }

    protected function variantInUse(ProductVariant $variant): bool
    {
        if (DB::table('order_items')->where('combination_id', $variant->id)->exists()) {
            return true;
        }

        if (! $variant->sku) {
            return false;
        }

        return DB::table('order_items')
            ->where('product_id', $variant->product_id)
            ->where('sku', $variant->sku)
            ->exists();
    }

    protected function variantDeleteMessage(ProductVariant $variant): ?string
    {
        if (! $this->variantInUse($variant)) {
            return null;
        }

        return 'Combination "'.$variant->combination_key.'" is used by existing records and cannot be deleted.';
    }

    protected function boolValue(array $row, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $row)) {
            return $default;
        }

        return filter_var($row[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function autoSku(?Product $product, array $valueIds, mixed $sku, ?int $ignoreVariantId = null): ?string
    {
        $sku = trim((string) ($sku ?? ''));
        if ($sku !== '') {
            return $sku;
        }
        if (! $product) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $valueIds)));
        $values = AttributeValue::query()
            ->with('translations')
            ->whereIn('id', $ids)
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->get();
        $codes = $values->map(function (AttributeValue $value) {
            $raw = trim((string) ($value->code ?: $value->translation()?->value ?: ''));
            $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $raw));

            return $slug !== '' ? $slug : ('V'.$value->id);
        })->all();

        $base = trim((string) $product->sku);
        $candidate = $base !== ''
            ? $base.($codes !== [] ? '-'.implode('-', $codes) : '-VAR')
            : ($codes !== [] ? implode('-', $codes) : 'VAR');
        $try = $candidate;
        $i = 2;
        while ($this->skuTaken($product, $try, $ignoreVariantId) && $i < 100) {
            $try = $candidate.'-'.$i;
            $i++;
        }

        return $try;
    }

    protected function skuTaken(Product $product, string $sku, ?int $ignoreVariantId = null): bool
    {
        if (Product::query()->where('sku', $sku)->where('id', '!=', $product->id)->exists()) {
            return true;
        }

        return ProductVariant::query()
            ->where('sku', $sku)
            ->when($ignoreVariantId, fn ($query) => $query->where('id', '!=', $ignoreVariantId))
            ->exists();
    }
}
