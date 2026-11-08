<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $tab = $request->query('tab', 'defaults');
        if (! in_array($tab, ['defaults', 'inventory', 'search', 'pricing'], true)) {
            $tab = 'defaults';
        }

        return Inertia::render('Settings/Product', array_merge(ProductSettings::payload(), [
            'tab' => $tab,
            'inventory' => InventorySettings::payload(),
            'search' => SearchCatalogSettings::payload(),
            'pricing' => PricingSettings::payload(),
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $compareFieldsInput = collect($request->input('compare_fields', []))
            ->map(function ($row) {
                if (! is_array($row)) {
                    return null;
                }

                return [
                    'key' => (string) ($row['key'] ?? ''),
                    'enabled' => (bool) ($row['enabled'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->all();
        $request->merge(['compare_fields' => $compareFieldsInput]);
        $request->merge($this->normalizedIntegerSettings($request));

        $data = $request->validate([
            'default_status' => ['required', Rule::in(ProductSettings::STATUS_OPTIONS)],
            'default_type' => ['required', Rule::in(ProductSettings::DEFAULT_TYPE_OPTIONS)],
            'sku_mode' => ['required', Rule::in(ProductSettings::SKU_MODE_OPTIONS)],
            'sku_prefix' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-_]*$/'],
            'barcode_mode' => ['required', Rule::in(ProductSettings::BARCODE_MODE_OPTIONS)],
            'default_manage_stock' => ['required', 'boolean'],
            'default_allow_backorder' => ['sometimes', 'boolean'],
            'default_low_stock_alert' => ['required', 'integer', 'min:0', 'max:100000'],
            'out_of_stock_behavior' => ['required', Rule::in(ProductSettings::OOS_OPTIONS)],
            'default_min_qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'default_qty_step' => ['required', 'integer', 'min:1', 'max:100000'],
            'default_max_qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'compare_enabled' => ['required', 'boolean'],
            'compare_max' => ['required', 'integer', 'min:2', 'max:4'],
            'compare_fields' => ['required', 'array', 'min:1'],
            'compare_fields.*.key' => ['required', 'string', Rule::in(ProductSettings::compareFieldKeys())],
            'compare_fields.*.enabled' => ['required', 'boolean'],
            'require_cover_to_publish' => ['required', 'boolean'],
            'require_brand_to_publish' => ['required', 'boolean'],
            'default_available_for_order' => ['required', 'boolean'],
            'out_of_stock_notification' => ['required', 'boolean'],
            'catalog_mode' => ['required', 'boolean'],
            'show_price' => ['required', 'boolean'],
            'hide_prices_guests' => ['sometimes', 'boolean'],
            'short_description_max' => ['required', 'integer', 'min:50', 'max:5000'],
            'description_max' => ['required', 'integer', 'min:100', 'max:50000'],
            'row_highlight_enabled' => ['sometimes', 'boolean'],
            'row_highlight_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'row_highlight_ms' => ['required', 'integer', 'min:100', 'max:5000'],
        ]);

        if (! empty($data['default_max_qty']) && (int) $data['default_max_qty'] < (int) $data['default_min_qty']) {
            return back()->withErrors([
                'default_max_qty' => 'Maximum quantity must be greater than or equal to minimum quantity.',
            ]);
        }

        $compareFields = ProductSettings::normalizeCompareFields($data['compare_fields'] ?? []);

        ShopSetting::put('products.default_status', $data['default_status']);
        ShopSetting::put('products.default_type', $data['default_type']);
        ShopSetting::put('products.sku_mode', $data['sku_mode']);
        ShopSetting::put('products.sku_prefix', strtoupper(trim((string) ($data['sku_prefix'] ?? ''))));
        ShopSetting::put('products.barcode_mode', $data['barcode_mode']);
        ShopSetting::put('products.default_manage_stock', (bool) $data['default_manage_stock']);
        $oos = ProductSettings::normalizeStockBehavior($data['out_of_stock_behavior']);
        ShopSetting::put('products.out_of_stock_behavior', $oos);
        ShopSetting::put('products.default_allow_backorder', $oos === 'allow');
        ShopSetting::put('products.default_backorder_status', $oos);
        ShopSetting::put('products.catalog.hide_out_of_stock', $oos === 'hide');
        ShopSetting::put('products.default_low_stock_alert', (int) $data['default_low_stock_alert']);
        ShopSetting::put('products.default_min_qty', (int) $data['default_min_qty']);
        ShopSetting::put('products.default_qty_step', (int) $data['default_qty_step']);
        ShopSetting::put(
            'products.default_max_qty',
            ! empty($data['default_max_qty']) ? (int) $data['default_max_qty'] : null
        );
        ShopSetting::put('products.compare_enabled', (bool) $data['compare_enabled']);
        ShopSetting::put('products.catalog.compare_max', (int) $data['compare_max']);
        ShopSetting::put('products.compare_fields', $compareFields);
        ShopSetting::put('products.require_cover_to_publish', (bool) $data['require_cover_to_publish']);
        ShopSetting::put('products.require_brand_to_publish', (bool) $data['require_brand_to_publish']);
        ShopSetting::put('products.default_available_for_order', (bool) $data['default_available_for_order']);
        ShopSetting::put('products.out_of_stock_notification', (bool) $data['out_of_stock_notification']);
        ShopSetting::put('products.catalog_mode', (bool) $data['catalog_mode']);
        ShopSetting::put('products.catalog.show_price', (bool) $data['show_price']);
        if (array_key_exists('hide_prices_guests', $data)) {
            ShopSetting::put('products.hide_prices_guests', (bool) $data['hide_prices_guests']);
        }
        ShopSetting::put('products.content.short_description_max', (int) $data['short_description_max']);
        ShopSetting::put('products.catalog.short_description_max', (int) $data['short_description_max']);
        ShopSetting::put('products.content.description_max', (int) $data['description_max']);
        ShopSetting::put('products.catalog.description_max', (int) $data['description_max']);
        if (array_key_exists('row_highlight_enabled', $data)) {
            ShopSetting::put('products.list.row_highlight_enabled', (bool) $data['row_highlight_enabled']);
        }
        ShopSetting::put('products.list.row_highlight_color', strtolower($data['row_highlight_color']));
        ShopSetting::put('products.list.row_highlight_ms', (int) $data['row_highlight_ms']);
        FileChangeLog::record('updated', 'products/settings');
        StorefrontCatalogCache::bump();

        return back();
    }

    /**
     * Coerce digit strings (including leading zeros like "050") to ints before validation.
     *
     * @return array<string, int|null>
     */
    private function normalizedIntegerSettings(Request $request): array
    {
        $keys = [
            'default_low_stock_alert',
            'default_min_qty',
            'default_qty_step',
            'default_max_qty',
            'compare_max',
            'short_description_max',
            'description_max',
            'row_highlight_ms',
        ];

        $normalized = [];
        foreach ($keys as $key) {
            if (! $request->exists($key)) {
                continue;
            }

            $raw = $request->input($key);
            if ($raw === '' || $raw === null) {
                $normalized[$key] = $key === 'default_max_qty' ? null : $raw;

                continue;
            }

            if (is_int($raw) || is_float($raw) || (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1)) {
                $normalized[$key] = (int) $raw;
            }
        }

        return $normalized;
    }
}
