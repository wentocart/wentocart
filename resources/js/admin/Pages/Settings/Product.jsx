import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Layout from '../Layout';
import {
    Card,
    Checkbox,
    ColorField,
    FeatureTile,
    FormErrors,
    Input,
    IntegerInput,
    PageHeader,
    Select,
    SelectionCheckbox,
    SettingsCount,
    SettingsSectionHead,
    ui,
    t,
    cx,
} from '../../ui';
import { PRODUCT_CLUB_TABS, SettingsClubTabs } from './clubTabs';
import InventoryEngine from './InventoryEngine';
import SearchCatalog from './SearchCatalog';
import Pricing from './Pricing';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.product_settings', 'Product Settings') },
];

const TYPE_LABELS = {
    simple: t('admin.product_settings.type_simple', 'Standard'),
    combination: t('admin.product_settings.type_combination', 'Combinations'),
    group: t('admin.product_settings.type_group', 'Grouped'),
    virtual: t('admin.product_settings.type_virtual', 'Virtual'),
    bundle: t('admin.product_settings.type_bundle', 'Bundle'),
    gift_card: t('admin.product_settings.type_gift_card', 'Gift Card'),
    service: t('admin.product_settings.type_service', 'Service'),
    rental: t('admin.product_settings.type_rental', 'Rental'),
};

const DEFAULT_COLOR = '#ffece5';
const DEFAULT_MS = 1100;
const TEXT_SAVE_KEYS = new Set([
    'sku_prefix',
    'row_highlight_color',
    'row_highlight_ms',
    'default_low_stock_alert',
    'short_description_max',
    'description_max',
    'default_min_qty',
    'default_qty_step',
    'default_max_qty',
    'compare_max',
]);

/** Field keys shown inline on FeatureTiles — omit them from the top FormErrors alert. */
const INLINE_ERROR_KEYS = [
    'row_highlight_color',
    'row_highlight_ms',
    'short_description_max',
    'description_max',
    'default_status',
    'default_type',
    'sku_mode',
    'sku_prefix',
    'barcode_mode',
    'default_low_stock_alert',
    'out_of_stock_behavior',
    'default_min_qty',
    'default_qty_step',
    'default_max_qty',
    'compare_max',
    'compare_fields',
];

const COMPARE_FIELD_DEFS = [
    { key: 'price', label: t('admin.product_settings.compare_field_price', 'Price') },
    { key: 'brand', label: t('admin.product_settings.compare_field_brand', 'Brand') },
    { key: 'size', label: t('admin.product_settings.compare_field_size', 'Size') },
    { key: 'color', label: t('admin.product_settings.compare_field_color', 'Color') },
    { key: 'condition', label: t('admin.product_settings.compare_field_condition', 'Condition') },
    { key: 'availability', label: t('admin.product_settings.compare_field_availability', 'Availability / Stock Status') },
    { key: 'shipping', label: t('admin.product_settings.compare_field_shipping', 'Shipping Availability') },
    { key: 'type', label: t('admin.product_settings.compare_field_type', 'Product Type') },
    { key: 'subscription', label: t('admin.product_settings.compare_field_subscription', 'Subscription Availability') },
    { key: 'preorder', label: t('admin.product_settings.compare_field_preorder', 'Pre-order Availability') },
    { key: 'weight', label: t('admin.product_settings.compare_field_weight', 'Weight') },
    { key: 'description', label: t('admin.product_settings.compare_field_description', 'Product Description') },
];

const DEFAULT_COMPARE_ENABLED = new Set(['price', 'brand', 'availability']);

function defaultCompareFields() {
    return COMPARE_FIELD_DEFS.map((field) => ({
        key: field.key,
        label: field.label,
        enabled: DEFAULT_COMPARE_ENABLED.has(field.key),
    }));
}

function normalizeCompareFields(raw) {
    const fallback = defaultCompareFields();
    const rows = Array.isArray(raw) ? raw : [];
    if (rows[0] && Object.prototype.hasOwnProperty.call(rows[0], 'feature_id') && !Object.prototype.hasOwnProperty.call(rows[0], 'key')) {
        return fallback;
    }
    const byKey = {};
    rows.forEach((row) => {
        if (row && row.key) {
            byKey[row.key] = !!row.enabled;
        }
    });
    return fallback.map((field) => ({
        ...field,
        enabled: Object.prototype.hasOwnProperty.call(byKey, field.key) ? byKey[field.key] : field.enabled,
    }));
}

export default function Product({
    defaults = {},
    list = {},
    catalog = {},
    content = {},
    typeOptions = [],
    tab = 'defaults',
    inventory = {},
    search = {},
    pricing = {},
}) {
    const shop = usePage().props.shop;
    const types = (typeOptions.length ? typeOptions : Object.keys(TYPE_LABELS));
    const form = useForm({
        default_status: defaults.default_status || 'active',
        default_type: defaults.default_type || 'simple',
        sku_mode: defaults.sku_mode || 'auto',
        sku_prefix: defaults.sku_prefix || 'WENTO-',
        barcode_mode: defaults.barcode_mode || 'optional',
        default_manage_stock: defaults.default_manage_stock !== false,
        default_low_stock_alert: Number(defaults.default_low_stock_alert ?? 5),
        out_of_stock_behavior: ['allow', 'deny', 'hide'].includes(defaults.out_of_stock_behavior)
            ? defaults.out_of_stock_behavior
            : (defaults.default_allow_backorder ? 'allow' : (defaults.out_of_stock_behavior === 'hide' ? 'hide' : 'deny')),
        default_min_qty: Number(defaults.default_min_qty ?? 1),
        default_qty_step: Number(defaults.default_qty_step ?? 1),
        default_max_qty: defaults.default_max_qty ?? '',
        compare_enabled: defaults.compare_enabled !== false,
        compare_max: Number(defaults.compare_max) || 4,
        compare_fields: normalizeCompareFields(defaults.compare_fields),
        require_cover_to_publish: !!defaults.require_cover_to_publish,
        require_brand_to_publish: !!defaults.require_brand_to_publish,
        default_available_for_order: defaults.default_available_for_order !== false,
        out_of_stock_notification: !!defaults.out_of_stock_notification,
        catalog_mode: !!catalog.catalog_mode,
        show_price: catalog.show_price !== false,
        hide_prices_guests: !!catalog.hide_prices_guests,
        short_description_max: Number(content.short_description_max ?? catalog.short_description_max ?? 400),
        description_max: Number(content.description_max ?? catalog.description_max ?? 5000),
        row_highlight_enabled: list.enabled !== false,
        row_highlight_color: list.color || DEFAULT_COLOR,
        row_highlight_ms: Number(list.duration_ms) || DEFAULT_MS,
    });

    const setCompareFieldEnabled = (key, enabled) => {
        form.setData('compare_fields', form.data.compare_fields.map((field) => (
            field.key === key ? { ...field, enabled } : field
        )));
    };

    const setAllCompareFields = (enabled) => {
        form.setData('compare_fields', form.data.compare_fields.map((field) => ({ ...field, enabled })));
    };

    const compareFields = form.data.compare_fields || [];
    const compareAllSelected = compareFields.length > 0 && compareFields.every((field) => !!field.enabled);
    const compareSomeSelected = compareFields.some((field) => !!field.enabled) && !compareAllSelected;

    const formRef = useRef(form);
    formRef.current = form;
    const booted = useRef(false);
    const lastData = useRef(form.data);
    const lastSent = useRef('');
    const saveTimer = useRef(null);
    const lastColor = useRef(
        /^#[0-9A-Fa-f]{6}$/.test(String(form.data.row_highlight_color || ''))
            ? form.data.row_highlight_color
            : DEFAULT_COLOR,
    );

    useEffect(() => {
        const snapshot = JSON.stringify(form.data);
        if (!booted.current) {
            booted.current = true;
            lastData.current = form.data;
            lastSent.current = snapshot;

            return undefined;
        }
        if (snapshot === lastSent.current) {
            return undefined;
        }

        const previous = lastData.current || {};
        lastData.current = form.data;
        const changed = Object.keys(form.data).filter((key) => JSON.stringify(form.data[key]) !== JSON.stringify(previous[key]));
        const textOnly = changed.length > 0 && changed.every((key) => TEXT_SAVE_KEYS.has(key));

        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
        saveTimer.current = setTimeout(() => {
            const current = formRef.current;
            const body = { ...current.data };
            if (/^#[0-9A-Fa-f]{6}$/.test(String(body.row_highlight_color || ''))) {
                lastColor.current = body.row_highlight_color;
            } else {
                body.row_highlight_color = lastColor.current;
            }
            body.default_max_qty = body.default_max_qty === '' || body.default_max_qty === null
                ? null
                : Number(body.default_max_qty);
            for (const key of TEXT_SAVE_KEYS) {
                if (key === 'sku_prefix' || key === 'row_highlight_color') {
                    continue;
                }
                if (body[key] === '' || body[key] == null) {
                    continue;
                }
                body[key] = Number(body[key]);
            }
            lastSent.current = JSON.stringify(current.data);
            current.transform(() => body);
            current.put('/admin/product-settings', {
                preserveScroll: true,
                preserveState: true,
            });
        }, textOnly ? 1000 : 0);

        return () => {
            if (saveTimer.current) {
                clearTimeout(saveTimer.current);
            }
        };
    }, [form.data]);

    const previewColor = /^#[0-9A-Fa-f]{6}$/.test(String(form.data.row_highlight_color || ''))
        ? form.data.row_highlight_color
        : DEFAULT_COLOR;
    const durationMs = Math.min(5000, Math.max(100, Number(form.data.row_highlight_ms) || DEFAULT_MS));

    const highlightPreview = (
        <div
            className="relative overflow-hidden rounded-xl border border-neutral-200 bg-white"
            style={{
                '--admin-list-row-color': previewColor,
                '--admin-list-row-duration': `${durationMs}ms`,
            }}
        >
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 origin-left"
                style={{
                    background: 'var(--admin-list-row-color)',
                    animation: `product-settings-preview-sweep var(--admin-list-row-duration) cubic-bezier(0.22, 1, 0.36, 1) infinite alternate`,
                }}
            />
            <div className="relative z-10 px-4 py-3 text-sm text-neutral-800">
                {t('admin.product_settings.preview_row', 'Sample product row')}
            </div>
        </div>
    );

    return (
        <Layout>
            <PageHeader
                title={t('admin.product_settings.title', 'Product Settings')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.product_settings.subtitle', 'Global product behavior for the whole store.')}
            />
            <SettingsClubTabs tabs={PRODUCT_CLUB_TABS} value={tab} base="/admin/product-settings" />
            {tab === 'inventory' ? <InventoryEngine embedded {...inventory} /> : null}
            {tab === 'search' ? <SearchCatalog embedded {...search} /> : null}
            {tab === 'pricing' ? <Pricing embedded {...pricing} /> : null}
            {tab === 'defaults' ? (
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                }}
            >
                <FormErrors errors={form.errors} except={INLINE_ERROR_KEYS} />

                <div className="space-y-4">
                    <Card>
                        <SettingsSectionHead
                            icon="package"
                            title={t('admin.product_settings.panel_list', 'Products list highlight')}
                            hint={t('admin.product_settings.panel_list_hint', 'On the admin product list, this color paints across a row when it is selected.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: form.data.row_highlight_enabled ? 3 : 1 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="palette"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.product_settings.highlight_enabled', 'Highlight color')}
                                hint={t('admin.product_settings.highlight_enabled_hint', 'Paint a wash across selected rows on the admin product list. Turn this off to leave those rows plain.')}
                                value={!!form.data.row_highlight_enabled}
                                onChange={(on) => form.setData('row_highlight_enabled', on)}
                            />
                            {form.data.row_highlight_enabled ? (
                                <FeatureTile
                                    icon="palette"
                                    tone="bg-rose-100 text-rose-600"
                                    title={t('admin.product_settings.highlight_color', 'Highlight color')}
                                    hint={t('admin.product_settings.highlight_color_hint', 'Used as the selected-row wash color.')}
                                    error={form.errors.row_highlight_color}
                                    control={(
                                        <ColorField
                                            value={form.data.row_highlight_color}
                                            onChange={(color) => form.setData('row_highlight_color', color)}
                                            placeholder={DEFAULT_COLOR}
                                            fallback={DEFAULT_COLOR}
                                            ariaLabel={t('admin.product_settings.highlight_color', 'Highlight color')}
                                            swatchClassName="box-border !h-10 !w-12 !p-0"
                                            inputClassName="box-border !h-10 !py-0"
                                        />
                                    )}
                                />
                            ) : null}
                            {form.data.row_highlight_enabled ? (
                                <FeatureTile
                                    icon="clock"
                                    tone="bg-amber-100 text-amber-600"
                                    title={t('admin.product_settings.highlight_ms', 'Highlight timing (ms)')}
                                    hint={t('admin.product_settings.highlight_ms_hint', 'How long the left-to-right paint takes. Range: 100 to 5000.')}
                                    error={form.errors.row_highlight_ms}
                                    control={(
                                        <IntegerInput
                                            className="w-24 shrink-0"
                                            min="100"
                                            max="5000"
                                            invalid={!!form.errors.row_highlight_ms}
                                            value={form.data.row_highlight_ms}
                                            onValueChange={(next) => form.setData('row_highlight_ms', next)}
                                        />
                                    )}
                                />
                            ) : null}
                        </div>
                        {form.data.row_highlight_enabled ? (
                        <div className="mt-4 space-y-2">
                            <p className={cx(ui.label, 'mb-0')}>
                                {t('admin.product_settings.preview_title', 'Preview')}
                            </p>
                            <p className={cx(ui.muted, 'text-sm')}>
                                {t('admin.product_settings.preview_hint', 'Sample row using the current color and timing.')}
                            </p>
                            {highlightPreview}
                        </div>
                        ) : null}
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="bag"
                            title={t('admin.product_settings.panel_catalog', 'Catalog display')}
                            hint={t('admin.product_settings.panel_catalog_hint', 'Shop-wide catalog mode, prices, and description length limits.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="eye"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.product_settings.catalog_mode', 'Catalog mode')}
                                hint={t('admin.product_settings.catalog_mode_hint', 'Apply storefront catalog display rules (prices and content limits).')}
                                value={!!form.data.catalog_mode}
                                onChange={(on) => form.setData('catalog_mode', on)}
                            />
                            {form.data.catalog_mode ? (
                                <FeatureTile
                                    icon="tag"
                                    tone="bg-violet-100 text-violet-600"
                                    title={t('admin.product_settings.show_price', 'Show price')}
                                    hint={t('admin.product_settings.show_price_hint', 'When off, the storefront hides product prices shop-wide.')}
                                    value={!!form.data.show_price}
                                    onChange={(on) => form.setData('show_price', on)}
                                />
                            ) : null}
                            <FeatureTile
                                icon="lock"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.product_settings.hide_prices_guests', 'Hide prices from guests')}
                                hint={t('admin.product_settings.hide_prices_guests_hint', 'Logged-in customers still see prices. Guest catalog shows “login to see price”.')}
                                value={!!form.data.hide_prices_guests}
                                onChange={(on) => form.setData('hide_prices_guests', on)}
                            />
                            <FeatureTile
                                icon="file"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.product_settings.short_description_max', 'Short description max')}
                                hint={t('admin.product_settings.short_description_max_hint', 'Plain-text character limit on product short descriptions.')}
                                error={form.errors.short_description_max}
                                control={(
                                    <IntegerInput
                                        className="w-24 shrink-0"
                                        min="50"
                                        max="5000"
                                        invalid={!!form.errors.short_description_max}
                                        value={form.data.short_description_max}
                                        onValueChange={(next) => form.setData('short_description_max', next)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="file"
                                tone="bg-indigo-100 text-indigo-600"
                                title={t('admin.product_settings.description_max', 'Description max')}
                                hint={t('admin.product_settings.description_max_hint', 'Plain-text character limit on product descriptions.')}
                                error={form.errors.description_max}
                                control={(
                                    <IntegerInput
                                        className="w-28 shrink-0"
                                        min="100"
                                        max="50000"
                                        invalid={!!form.errors.description_max}
                                        value={form.data.description_max}
                                        onValueChange={(next) => form.setData('description_max', next)}
                                    />
                                )}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="package"
                            title={t('admin.product_settings.panel_defaults', 'Product Defaults')}
                            hint={t('admin.product_settings.panel_defaults_hint', 'Status, type, SKU, and barcode for new products.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="check"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.product_settings.default_status', 'Default product status')}
                                hint={t('admin.product_settings.default_status_hint', 'Status applied when a product is created.')}
                                error={form.errors.default_status}
                                control={(
                                    <Select className="w-36 shrink-0" invalid={!!form.errors.default_status} value={form.data.default_status} onChange={(e) => form.setData('default_status', e.target.value)}>
                                        <option value="draft">{t('admin.product_settings.status_draft', 'Draft')}</option>
                                        <option value="active">{t('admin.product_settings.status_active', 'Active')}</option>
                                    </Select>
                                )}
                            />
                            <FeatureTile
                                icon="package"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.product_settings.default_type', 'Default product type')}
                                hint={t('admin.product_settings.default_type_hint', 'Pre-selected when you click New product. You can still choose another type.')}
                                error={form.errors.default_type}
                                control={(
                                    <Select className="w-48 shrink-0" invalid={!!form.errors.default_type} value={form.data.default_type} onChange={(e) => form.setData('default_type', e.target.value)}>
                                        {types.map((value) => (
                                            <option key={value} value={value}>{TYPE_LABELS[value] || value}</option>
                                        ))}
                                    </Select>
                                )}
                            />
                            <FeatureTile
                                icon="pin"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.product_settings.sku_mode', 'SKU generation')}
                                hint={form.data.sku_mode === 'auto'
                                    ? t('admin.product_settings.sku_prefix_hint', 'Used when generating SKUs automatically.')
                                    : t('admin.product_settings.sku_manual_note', 'Manual mode requires a unique SKU on every product.')}
                                error={form.errors.sku_mode || form.errors.sku_prefix}
                                control={(
                                    <Select className="w-36 shrink-0" invalid={!!form.errors.sku_mode} value={form.data.sku_mode} onChange={(e) => form.setData('sku_mode', e.target.value)}>
                                        <option value="auto">{t('admin.product_settings.sku_auto', 'Automatic')}</option>
                                        <option value="manual">{t('admin.product_settings.sku_manual', 'Manual')}</option>
                                    </Select>
                                )}
                            >
                                {form.data.sku_mode === 'auto' ? (
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm text-neutral-700">{t('admin.product_settings.sku_prefix', 'SKU prefix')}</span>
                                        <Input
                                            className="w-32 shrink-0"
                                            invalid={!!form.errors.sku_prefix}
                                            value={form.data.sku_prefix}
                                            onChange={(e) => form.setData('sku_prefix', e.target.value.toUpperCase())}
                                            placeholder="WENTO-"
                                        />
                                    </div>
                                ) : null}
                            </FeatureTile>
                            <FeatureTile
                                icon="code"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.product_settings.barcode_mode', 'Barcode support')}
                                hint={t('admin.product_settings.barcode_mode_hint', 'Off hides the product QR and the retail codes. Optional or Required shows a QR code of the product page — scanning it opens that product. Required also blocks an active product until a UPC or GTIN is filled.')}
                                error={form.errors.barcode_mode}
                                control={(
                                    <Select className="w-36 shrink-0" invalid={!!form.errors.barcode_mode} value={form.data.barcode_mode} onChange={(e) => form.setData('barcode_mode', e.target.value)}>
                                        <option value="off">{t('admin.product_settings.barcode_off', 'Off')}</option>
                                        <option value="optional">{t('admin.product_settings.barcode_optional', 'Optional')}</option>
                                        <option value="required">{t('admin.product_settings.barcode_required', 'Required')}</option>
                                    </Select>
                                )}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="layers"
                            title={t('admin.product_settings.panel_inventory', 'Inventory Defaults')}
                            hint={t('admin.product_settings.panel_inventory_hint', 'Stock tracking and out-of-stock behavior for new products.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 3 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="package"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.product_settings.default_manage_stock', 'Track inventory by default')}
                                hint={t('admin.product_settings.default_manage_stock_hint', 'New products count stock. At zero, Out-of-stock behavior below decides if they can still be bought or stay listed. Turn this off and quantity does not limit the product.')}
                                value={!!form.data.default_manage_stock}
                                onChange={(on) => form.setData('default_manage_stock', on)}
                            />
                            <FeatureTile
                                icon="bell"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.product_settings.default_low_stock', 'Low stock threshold')}
                                hint={t('admin.product_settings.default_low_stock_hint', 'Copied onto new products. When tracked stock is at or below this number, the admin product is marked low stock and the shop can email you. Customers do not see this number.')}
                                error={form.errors.default_low_stock_alert}
                                control={(
                                    <IntegerInput
                                        className="w-24 shrink-0"
                                        min="0"
                                        invalid={!!form.errors.default_low_stock_alert}
                                        value={form.data.default_low_stock_alert}
                                        onValueChange={(next) => form.setData('default_low_stock_alert', next)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="alert-triangle"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.product_settings.oos_behavior', 'Out-of-stock behavior')}
                                hint={t('admin.product_settings.oos_behavior_hint', 'Default for new products. Allow keeps purchases open at zero stock. Do not allow keeps the product visible but blocks purchase. Hide products removes it from listings and search when out of stock.')}
                                error={form.errors.out_of_stock_behavior}
                                control={(
                                    <Select className="w-44 shrink-0" invalid={!!form.errors.out_of_stock_behavior} value={form.data.out_of_stock_behavior} onChange={(e) => form.setData('out_of_stock_behavior', e.target.value)}>
                                        <option value="allow">{t('admin.product_settings.oos_allow', 'Allow')}</option>
                                        <option value="deny">{t('admin.product_settings.oos_deny', 'Do not allow')}</option>
                                        <option value="hide">{t('admin.product_settings.oos_hide_products', 'Hide products')}</option>
                                    </Select>
                                )}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="settings"
                            title={t('admin.product_settings.panel_qty', 'Measurements & Quantity')}
                            hint={t('admin.product_settings.panel_qty_hint', 'Default order quantities. Shop units come from Localization.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 3 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="layers"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.product_settings.default_min_qty', 'Default minimum order quantity')}
                                hint={t('admin.product_settings.default_min_qty_hint', 'Smallest quantity a customer can add. A product uses this when its own Min qty is empty.')}
                                error={form.errors.default_min_qty}
                                control={(
                                    <IntegerInput
                                        className="w-24 shrink-0"
                                        min="1"
                                        invalid={!!form.errors.default_min_qty}
                                        value={form.data.default_min_qty}
                                        onValueChange={(next) => form.setData('default_min_qty', next)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="plus"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.product_settings.default_qty_step', 'Default quantity increment')}
                                hint={t('admin.product_settings.default_qty_step_hint', 'Quantity changes in this step on the product page and in the cart. A product uses this when its own Qty step is empty.')}
                                error={form.errors.default_qty_step}
                                control={(
                                    <IntegerInput
                                        className="w-24 shrink-0"
                                        min="1"
                                        invalid={!!form.errors.default_qty_step}
                                        value={form.data.default_qty_step}
                                        onValueChange={(next) => form.setData('default_qty_step', next)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="pin"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.product_settings.default_max_qty', 'Default maximum quantity')}
                                hint={t('admin.product_settings.default_max_qty_hint', 'Largest quantity a customer can add. Leave empty for no shop maximum. A product uses this when its own Max qty is empty.')}
                                error={form.errors.default_max_qty}
                                control={(
                                    <IntegerInput
                                        className="w-24 shrink-0"
                                        min="1"
                                        invalid={!!form.errors.default_max_qty}
                                        value={form.data.default_max_qty}
                                        onValueChange={(next) => form.setData('default_max_qty', next)}
                                    />
                                )}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="star"
                            title={t('admin.product_settings.panel_features', 'Product Features')}
                            hint={t('admin.product_settings.panel_features_hint', 'Compare, publish rules, and availability defaults.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 6 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                className="md:col-span-2"
                                icon="grid"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.product_settings.compare_enabled', 'Product comparison')}
                                hint={t('admin.product_settings.compare_enabled_hint', 'Shoppers add products from the card or product page. The header Compare icon opens the comparison.')}
                                value={!!form.data.compare_enabled}
                                onChange={(on) => form.setData('compare_enabled', on)}
                            >
                                {form.data.compare_enabled ? (
                                    <>
                                        <div className="space-y-1">
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="text-sm text-neutral-700">{t('admin.product_settings.compare_max', 'Compare list size')}</span>
                                                <IntegerInput
                                                    className="w-24 shrink-0"
                                                    min="2"
                                                    max="4"
                                                    invalid={!!form.errors.compare_max}
                                                    value={form.data.compare_max}
                                                    onValueChange={(next) => form.setData('compare_max', next)}
                                                />
                                            </div>
                                            {form.errors.compare_max ? (
                                                <p className={ui.fieldError} role="alert">{form.errors.compare_max}</p>
                                            ) : null}
                                        </div>
                                        <div className="space-y-2">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-neutral-800">
                                                    {t('admin.product_settings.compare_fields', 'Comparison fields')}
                                                </p>
                                                <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                                    {t('admin.product_settings.compare_fields_hint', 'Choose which product fields appear in compare results. Product name and image always show in the comparison header. Unchecked fields stay hidden without changing compare list size.')}
                                                </p>
                                            </div>
                                            <div className={cx(ui.tableWrap, 'rounded-lg border border-neutral-200')}>
                                                <table className="min-w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b border-neutral-200 bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                                            <th className="px-3 py-2.5">{t('admin.product_settings.compare_col_field', 'Product Field')}</th>
                                                            <th className="px-3 py-2.5 text-right whitespace-nowrap">
                                                                <div className="inline-flex items-center justify-end gap-2">
                                                                    <span>{t('admin.product_settings.compare_col_show', 'Show in Comparison')}</span>
                                                                    <SelectionCheckbox
                                                                        checked={compareAllSelected}
                                                                        indeterminate={compareSomeSelected}
                                                                        onChange={(e) => setAllCompareFields(e.target.checked)}
                                                                        ariaLabel={t('admin.product_settings.compare_toggle_all', 'Show all comparison fields')}
                                                                    />
                                                                </div>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {compareFields.map((field) => (
                                                            <tr key={field.key} className="border-b border-neutral-100 last:border-0">
                                                                <td className="px-3 py-2.5 text-neutral-800">{field.label}</td>
                                                                <td className="px-3 py-2.5">
                                                                    <div className="flex justify-end">
                                                                        <Checkbox
                                                                            checked={!!field.enabled}
                                                                            onChange={(e) => setCompareFieldEnabled(field.key, e.target.checked)}
                                                                            aria-label={t('admin.product_settings.compare_show_field', 'Show {field} in comparison', { field: field.label })}
                                                                        />
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                            {form.errors.compare_fields ? (
                                                <p className={ui.fieldError} role="alert">{form.errors.compare_fields}</p>
                                            ) : null}
                                        </div>
                                    </>
                                ) : null}
                            </FeatureTile>
                            <FeatureTile
                                icon="bag"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.product_settings.default_available', 'Available for order by default')}
                                hint={t('admin.product_settings.default_available_hint', 'New products can be purchased until you change them.')}
                                value={!!form.data.default_available_for_order}
                                onChange={(on) => form.setData('default_available_for_order', on)}
                            />
                            <FeatureTile
                                icon="photo"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.product_settings.require_cover', 'Require cover image before publish')}
                                hint={t('admin.product_settings.require_cover_hint', 'A product cannot go live without a cover image.')}
                                value={!!form.data.require_cover_to_publish}
                                onChange={(on) => form.setData('require_cover_to_publish', on)}
                            />
                            <FeatureTile
                                icon="tag"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.product_settings.require_brand', 'Require brand before publish')}
                                hint={t('admin.product_settings.require_brand_hint', 'A product cannot go live without a brand.')}
                                value={!!form.data.require_brand_to_publish}
                                onChange={(on) => form.setData('require_brand_to_publish', on)}
                            />
                            <FeatureTile
                                icon="play"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.store.product_video', 'Product Video')}
                                hint={t('admin.store.product_video_hint', 'Add a YouTube or Vimeo video to a product. Shoppers can watch it under the photos on the product page.')}
                                value={shop?.community?.product_video !== false}
                                onChange={(on) => router.post('/admin/store/community', { key: 'product_video', enabled: on ? 1 : 0 }, { preserveScroll: true })}
                            />
                            <FeatureTile
                                icon="bell"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.product_settings.out_of_stock_notification', 'Out of stock notification')}
                                hint={t('admin.product_settings.out_of_stock_notification_hint', 'Allow customers to subscribe for a notification when an out-of-stock product becomes available.')}
                                value={!!form.data.out_of_stock_notification}
                                onChange={(on) => form.setData('out_of_stock_notification', on)}
                            />
                        </div>
                    </Card>
                </div>

            </form>
            ) : null}

            <style>{`
                @keyframes product-settings-preview-sweep {
                    from { transform: scaleX(0); }
                    to { transform: scaleX(1); }
                }
                @media (prefers-reduced-motion: reduce) {
                    .relative > span[aria-hidden="true"] {
                        animation: none !important;
                        transform: scaleX(1) !important;
                    }
                }
            `}</style>
        </Layout>
    );
}
