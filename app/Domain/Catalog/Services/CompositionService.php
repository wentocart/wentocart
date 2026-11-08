<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompositionService
{
    public function sync(Product $product, array $rows): void
    {
        $this->assertValid($product, $rows);

        DB::transaction(function () use ($product, $rows) {
            $keep = [];
            foreach (array_values($rows) as $index => $row) {
                $childId = (int) ($row['child_product_id'] ?? $row['id'] ?? 0);
                $keep[] = $childId;
                $quantity = max(1, (int) ($row['quantity'] ?? 1));

                ProductComponent::query()->updateOrCreate(
                    [
                        'parent_product_id' => $product->id,
                        'child_product_id' => $childId,
                    ],
                    [
                        'quantity' => $quantity,
                        'min_quantity' => $this->nullableInt($row['min_quantity'] ?? null),
                        'max_quantity' => $this->nullableInt($row['max_quantity'] ?? null),
                        'qty_editable' => (bool) ($row['qty_editable'] ?? true),
                        'is_required' => (bool) ($row['is_required'] ?? true),
                        'default_selected' => (bool) ($row['default_selected'] ?? true),
                        'option_group' => ($row['option_group'] ?? '') === '' ? null : $row['option_group'],
                        'position' => $index,
                    ]
                );
            }

            $query = ProductComponent::query()->where('parent_product_id', $product->id);
            if ($keep !== []) {
                $query->whereNotIn('child_product_id', $keep);
            }
            $query->delete();
        });
    }

    public function present(Product $product): array
    {
        $product->loadMissing(['components.child.translations']);
        $forceAll = $product->type === 'group' && ($product->selection_mode ?: 'all') === 'all';

        return $product->components
            ->sortBy('position')
            ->values()
            ->map(function (ProductComponent $row) use ($forceAll) {
                $child = $row->child;
                $qty = max(1, (int) $row->quantity);
                $inStock = $child ? $this->childInStockFor($child, $qty) : false;

                return [
                    'id' => $child?->id,
                    'sku' => $child?->sku,
                    'name' => $child?->translation()?->name ?: $child?->sku,
                    'price' => $child ? (float) $child->price : 0,
                    'selling_price' => $child ? $child->sellingPrice() : 0,
                    'stock_qty' => $child ? (float) $child->availableStock() : 0,
                    'stock_status' => $child?->stock_status,
                    'manage_stock' => (bool) ($child?->manage_stock),
                    'allow_backorder' => (bool) ($child?->allow_backorder),
                    'in_stock' => $inStock,
                    'is_enabled' => (bool) ($child?->is_enabled),
                    'type' => $child?->type,
                    'quantity' => $qty,
                    'min_quantity' => $row->min_quantity,
                    'max_quantity' => $row->max_quantity,
                    'qty_editable' => $forceAll ? false : (bool) $row->qty_editable,
                    'is_required' => $forceAll ? true : (bool) $row->is_required,
                    'default_selected' => $forceAll ? true : (bool) $row->default_selected,
                    'option_group' => $row->option_group,
                ];
            })
            ->all();
    }

    /**
     * Resolve which group children to add, with qty rules and pack unit prices.
     *
     * @param  list<array{product_id?: int, qty?: int|float}>  $posted
     * @return list<array{product_id: int, qty: int, unit_price: float, regular_price: float}>
     */
    public function resolveGroupCartLines(Product $group, array $posted): array
    {
        if ($group->type !== 'group') {
            throw ValidationException::withMessages([
                'product_id' => 'Only grouped products support component selection.',
            ]);
        }

        $group->loadMissing(['components.child']);
        $forceAll = ($group->selection_mode ?: 'all') === 'all';

        $byId = [];
        foreach ($posted as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = max(1, (int) ($row['qty'] ?? 1));
            }
        }

        $selected = [];
        foreach ($group->components as $component) {
            $child = $component->child;
            $childId = (int) $component->child_product_id;
            if ($childId < 1 || ! $child) {
                continue;
            }

            $defaultQty = max(1, (int) $component->quantity);
            $required = $forceAll || (bool) $component->is_required;
            $qtyEditable = ! $forceAll && (bool) $component->qty_editable;

            if ($forceAll || $byId !== []) {
                if (! isset($byId[$childId])) {
                    if ($required) {
                        $selected[] = [
                            'product_id' => $childId,
                            'qty' => $defaultQty,
                            'child' => $child,
                        ];
                    }

                    continue;
                }

                if ($qtyEditable) {
                    $qty = (int) $byId[$childId];
                    if ($component->min_quantity) {
                        $qty = max($qty, (int) $component->min_quantity);
                    }
                    if ($component->max_quantity) {
                        $qty = min($qty, (int) $component->max_quantity);
                    }
                    $qty = max(1, $qty);
                } else {
                    $qty = $defaultQty;
                }

                $selected[] = [
                    'product_id' => $childId,
                    'qty' => $qty,
                    'child' => $child,
                ];

                continue;
            }

            if ($required || $component->default_selected) {
                $selected[] = [
                    'product_id' => $childId,
                    'qty' => $defaultQty,
                    'child' => $child,
                ];
            }
        }

        if ($selected === []) {
            throw ValidationException::withMessages([
                'components' => 'Select at least one product from this group.',
            ]);
        }

        if ($forceAll && count($selected) < $group->components->count()) {
            throw ValidationException::withMessages([
                'components' => 'This group requires every listed product.',
            ]);
        }

        foreach ($selected as $row) {
            /** @var Product $child */
            $child = $row['child'];
            if (! $child->is_enabled) {
                throw ValidationException::withMessages([
                    'components' => 'One of the selected products is no longer available.',
                ]);
            }
            $validateStock = \App\Domain\Settings\InventorySettings::enabled()
                && ! \App\Domain\Settings\InventorySettings::allowNegative()
                && \App\Domain\Settings\CartCheckoutSettings::stockValidation();
            if ($validateStock && ! $this->childInStockFor($child, (float) $row['qty'])) {
                throw ValidationException::withMessages([
                    'stock' => 'Not enough stock for '.($child->sku ?: 'product #'.$child->id).'.',
                ]);
            }
        }

        return $this->priceGroupSelection($group, $selected);
    }

    /**
     * @param  list<array{product_id: int, qty: int, child: Product}>  $selected
     * @return list<array{product_id: int, qty: int, unit_price: float, regular_price: float}>
     */
    public function priceGroupSelection(Product $group, array $selected): array
    {
        $raw = 0.0;
        $rows = [];
        foreach ($selected as $row) {
            $child = $row['child'];
            $regular = $child->sellingPrice();
            $qty = max(1, (int) $row['qty']);
            $raw += $regular * $qty;
            $rows[] = [
                'product_id' => (int) $row['product_id'],
                'qty' => $qty,
                'regular_price' => $regular,
            ];
        }

        $packTotal = $this->priceFromSubtotal($group, $raw);
        if ($raw <= 0.00001) {
            return array_map(fn (array $row) => [
                'product_id' => $row['product_id'],
                'qty' => $row['qty'],
                'unit_price' => 0.0,
                'regular_price' => $row['regular_price'],
            ], $rows);
        }

        $ratio = $packTotal / $raw;
        $priced = [];
        $allocated = 0.0;
        $lastIndex = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $lineRaw = $row['regular_price'] * $row['qty'];
            if ($index === $lastIndex) {
                $lineTotal = round($packTotal - $allocated, 2);
            } else {
                $lineTotal = round($lineRaw * $ratio, 2);
                $allocated += $lineTotal;
            }
            $unit = $row['qty'] > 0 ? round($lineTotal / $row['qty'], 2) : 0.0;
            $priced[] = [
                'product_id' => $row['product_id'],
                'qty' => $row['qty'],
                'unit_price' => max(0, $unit),
                'regular_price' => $row['regular_price'],
            ];
        }

        return $priced;
    }

    public function priceFromSubtotal(Product $product, float $sum): float
    {
        $mode = $product->pricing_mode ?: ($product->type === 'bundle' ? 'fixed' : 'sum');
        if ($mode === 'fixed') {
            return $product->catalogSellingPrice();
        }

        $value = (float) ($product->pricing_discount_value ?? 0);
        if ($mode === 'sum_discount') {
            if (($product->pricing_discount_type ?: 'fixed') === 'percent') {
                $sum *= (1 - (min(100, max(0, $value)) / 100));
            } else {
                $sum -= max(0, min($sum, $value));
            }
        }

        if ($mode === 'sum_adjustment') {
            $sum += $value;
        }

        return max(0, round($sum, 2));
    }

    public function childInStockFor(Product $child, float $qty): bool
    {
        if (! $child->is_enabled) {
            return false;
        }

        return $child->inStockFor($qty);
    }

    public function subtotal(Product $product): float
    {
        $product->loadMissing(['components.child']);
        $sum = 0.0;
        foreach ($this->catalogPricingComponents($product) as $row) {
            if (! $row->child) {
                continue;
            }
            $sum += $row->child->sellingPrice() * max(1, (int) $row->quantity);
        }

        return round($sum, 2);
    }

    public function sellingPrice(Product $product): float
    {
        $mode = $product->pricing_mode ?: ($product->type === 'bundle' ? 'fixed' : 'sum');
        if ($mode === 'fixed') {
            return $product->catalogSellingPrice();
        }

        return $this->priceFromSubtotal($product, $this->subtotal($product));
    }

    /**
     * Resolve bundle component selection, pack unit price, and derived shipping weight.
     *
     * @param  list<array{product_id?: int, qty?: int|float}>  $posted
     * @return array{
     *   unit_price: float,
     *   shipping_weight: float|null,
     *   components: list<array{product_id: int, qty: int, sku: string, name: string, weight: float|null}>
     * }
     */
    public function resolveBundleSelection(Product $bundle, array $posted = []): array
    {
        if ($bundle->type !== 'bundle') {
            throw ValidationException::withMessages([
                'product_id' => 'Only bundle products support bundle component selection.',
            ]);
        }

        $bundle->loadMissing(['components.child.translations']);
        $configurable = ($bundle->bundle_type ?: 'fixed') === 'configurable';

        $byId = [];
        foreach ($posted as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = max(1, (int) ($row['qty'] ?? 1));
            }
        }

        $selected = [];
        foreach ($bundle->components as $component) {
            $child = $component->child;
            $childId = (int) $component->child_product_id;
            if ($childId < 1 || ! $child) {
                continue;
            }

            $defaultQty = max(1, (int) $component->quantity);
            $required = (bool) $component->is_required;

            if (! $configurable) {
                $selected[] = [
                    'product_id' => $childId,
                    'qty' => $defaultQty,
                    'child' => $child,
                ];

                continue;
            }

            if ($required) {
                $selected[] = [
                    'product_id' => $childId,
                    'qty' => $defaultQty,
                    'child' => $child,
                ];

                continue;
            }

            if ($byId === []) {
                if ($component->default_selected) {
                    $selected[] = [
                        'product_id' => $childId,
                        'qty' => $defaultQty,
                        'child' => $child,
                    ];
                }

                continue;
            }

            if (isset($byId[$childId])) {
                $selected[] = [
                    'product_id' => $childId,
                    'qty' => $defaultQty,
                    'child' => $child,
                ];
            }
        }

        if ($selected === []) {
            throw ValidationException::withMessages([
                'components' => 'Select at least one product for this bundle.',
            ]);
        }

        foreach ($bundle->components as $component) {
            if (! $configurable || ! $component->is_required) {
                continue;
            }
            $childId = (int) $component->child_product_id;
            $found = collect($selected)->contains(fn (array $row) => (int) $row['product_id'] === $childId);
            if (! $found) {
                throw ValidationException::withMessages([
                    'components' => 'This bundle requires every required component.',
                ]);
            }
        }

        foreach ($selected as $row) {
            /** @var Product $child */
            $child = $row['child'];
            if (! $child->is_enabled) {
                throw ValidationException::withMessages([
                    'components' => 'One of the selected products is no longer available.',
                ]);
            }
            $validateStock = \App\Domain\Settings\InventorySettings::enabled()
                && ! \App\Domain\Settings\InventorySettings::allowNegative()
                && \App\Domain\Settings\CartCheckoutSettings::stockValidation();
            if ($validateStock && ! $this->childInStockFor($child, (float) $row['qty'])) {
                throw ValidationException::withMessages([
                    'stock' => 'Not enough stock for '.($child->sku ?: 'product #'.$child->id).'.',
                ]);
            }
        }

        $raw = 0.0;
        $components = [];
        $weight = 0.0;
        $weightCounted = 0;
        foreach ($selected as $row) {
            /** @var Product $child */
            $child = $row['child'];
            $qty = max(1, (int) $row['qty']);
            $raw += $child->sellingPrice() * $qty;
            $childWeight = $child->weight;
            if ($childWeight !== null && $childWeight !== '') {
                $weight += (float) $childWeight * $qty;
                $weightCounted++;
            }
            $components[] = [
                'product_id' => (int) $row['product_id'],
                'qty' => $qty,
                'sku' => (string) ($child->sku ?: ''),
                'name' => (string) ($child->translation()?->name ?: $child->sku ?: ('#'.$child->id)),
                'weight' => ($childWeight !== null && $childWeight !== '') ? (float) $childWeight : null,
            ];
        }

        $mode = $bundle->pricing_mode ?: 'fixed';
        $unitPrice = $mode === 'fixed'
            ? $bundle->catalogSellingPrice()
            : $this->priceFromSubtotal($bundle, $raw);

        return [
            'unit_price' => max(0, round($unitPrice, 2)),
            'shipping_weight' => $weightCounted ? round($weight, 3) : null,
            'components' => $components,
        ];
    }

    /**
     * Catalog list/PDP price components: fixed = all; configurable = required + defaults.
     *
     * @return \Illuminate\Support\Collection<int, ProductComponent>
     */
    protected function catalogPricingComponents(Product $product)
    {
        $product->loadMissing(['components.child']);
        if ($product->type !== 'bundle' || ($product->bundle_type ?: 'fixed') !== 'configurable') {
            return $product->components;
        }

        $rows = $product->components->filter(
            fn (ProductComponent $row) => (bool) $row->is_required || (bool) $row->default_selected
        );

        return $rows->isEmpty() ? $product->components : $rows;
    }

    /**
     * @param  list<array{product_id: int, qty: int}>|null  $selection
     */
    public function shippingWeightForSelection(Product $product, ?array $selection = null): ?float
    {
        if (($product->shipping_mode ?: 'derived') !== 'derived') {
            $w = $product->weight;

            return ($w === null || $w === '') ? null : (float) $w;
        }

        if ($selection !== null && $selection !== []) {
            $weight = 0.0;
            $counted = 0;
            $product->loadMissing(['components.child']);
            $byId = [];
            foreach ($product->components as $row) {
                $byId[(int) $row->child_product_id] = $row->child;
            }
            foreach ($selection as $row) {
                $childId = (int) ($row['product_id'] ?? 0);
                $qty = max(1, (int) ($row['qty'] ?? 1));
                $child = $byId[$childId] ?? null;
                $stored = $row['weight'] ?? null;
                if ($stored !== null && $stored !== '') {
                    $weight += (float) $stored * $qty;
                    $counted++;

                    continue;
                }
                if (! $child || $child->weight === null || $child->weight === '') {
                    continue;
                }
                $weight += (float) $child->weight * $qty;
                $counted++;
            }

            return $counted ? round($weight, 3) : null;
        }

        return $this->shippingSummary($product)['weight'];
    }

    public function availableQty(Product $product): int
    {
        $product->loadMissing(['components.child']);
        if ($product->components->isEmpty()) {
            return 0;
        }

        $limiting = $product->components->filter(function (ProductComponent $row) use ($product) {
            if ($product->type === 'bundle') {
                return (bool) $row->is_required;
            }
            if (($product->selection_mode ?: 'all') === 'customer') {
                return (bool) $row->default_selected;
            }

            return true;
        });

        if ($limiting->isEmpty()) {
            $limiting = $product->components;
        }

        $max = null;
        foreach ($limiting as $row) {
            $child = $row->child;
            $need = max(1, (int) $row->quantity);
            if (! $child) {
                return 0;
            }
            if (! $child->manage_stock) {
                continue;
            }
            $can = intdiv(max(0, $child->availableStock()), $need);
            $max = $max === null ? $can : min($max, $can);
        }

        return $max === null ? 0 : max(0, $max);
    }

    public function shippingSummary(Product $product): array
    {
        $product->loadMissing(['components.child']);
        $weight = 0.0;
        $counted = 0;
        foreach ($product->components as $row) {
            $child = $row->child;
            if (! $child || $child->weight === null || $child->weight === '') {
                continue;
            }
            $weight += (float) $child->weight * max(1, (int) $row->quantity);
            $counted++;
        }

        return [
            'weight' => $counted ? round($weight, 3) : null,
            'items' => $product->components->count(),
        ];
    }

    protected function assertValid(Product $parent, array $rows): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                'components' => 'Add at least one product.',
            ]);
        }

        $seen = [];
        $existing = ProductComponent::query()
            ->where('parent_product_id', $parent->id)
            ->pluck('child_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($rows as $index => $row) {
            $childId = (int) ($row['child_product_id'] ?? $row['id'] ?? 0);
            $key = "components.$index";

            if ($childId < 1) {
                throw ValidationException::withMessages([$key.'.id' => 'Select a valid product.']);
            }
            if ($childId === (int) $parent->id) {
                throw ValidationException::withMessages([$key.'.id' => 'A product cannot include itself.']);
            }
            if (in_array($childId, $seen, true)) {
                throw ValidationException::withMessages([$key.'.id' => 'Each product can only be added once.']);
            }
            $seen[] = $childId;

            $child = Product::query()->find($childId);
            if (! $child) {
                throw ValidationException::withMessages([$key.'.id' => 'The selected product no longer exists.']);
            }
            if (in_array($child->type, ['group', 'bundle'], true)) {
                throw ValidationException::withMessages([$key.'.id' => 'Grouped and bundle products cannot be nested.']);
            }
            if (! $child->is_enabled && ! in_array($childId, $existing, true)) {
                throw ValidationException::withMessages([$key.'.id' => 'Disabled products cannot be newly assigned.']);
            }
            if ($this->contains($childId, (int) $parent->id)) {
                throw ValidationException::withMessages([$key.'.id' => 'This assignment would create a circular relationship.']);
            }

            $qty = (int) ($row['quantity'] ?? 1);
            if ($qty < 1) {
                throw ValidationException::withMessages([$key.'.quantity' => 'Quantity must be greater than 0.']);
            }
            $min = $this->nullableInt($row['min_quantity'] ?? null);
            $max = $this->nullableInt($row['max_quantity'] ?? null);
            if ($min !== null && $min < 1) {
                throw ValidationException::withMessages([$key.'.min_quantity' => 'Minimum quantity must be at least 1.']);
            }
            if ($min !== null && $min > $qty) {
                throw ValidationException::withMessages([$key.'.min_quantity' => 'Minimum quantity cannot exceed quantity.']);
            }
            if ($max !== null && $max < $qty) {
                throw ValidationException::withMessages([$key.'.max_quantity' => 'Maximum quantity cannot be less than quantity.']);
            }
        }
    }

    protected function contains(int $fromId, int $targetId, array $seen = []): bool
    {
        if ($fromId === $targetId) {
            return true;
        }
        if (in_array($fromId, $seen, true)) {
            return false;
        }
        $seen[] = $fromId;

        $childIds = ProductComponent::query()
            ->where('parent_product_id', $fromId)
            ->pluck('child_product_id');

        foreach ($childIds as $childId) {
            if ($this->contains((int) $childId, $targetId, $seen)) {
                return true;
            }
        }

        return false;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
