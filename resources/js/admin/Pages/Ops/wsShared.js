import { t, ui } from '../../ui';

export const INCLUDE_LABELS = {
    images: 'photos',
    categories: 'categories',
    brand: 'brand',
    tags: 'tags',
    features: 'features',
    related: 'related products',
    translations: 'translations',
    suppliers: 'suppliers',
    children: 'sub-categories',
    values: 'feature values',
    customer: 'customer',
    addresses: 'addresses',
    body: 'page content',
    countries: 'countries',
    regions: 'regions',
};

export const FILTER_LABELS = {
    sku: 'SKU',
    updated_since: 'changed since a date',
    page: 'page number',
    per_page: 'page size',
    status: 'status',
    number: 'order number',
    email: 'email',
    customer_id: 'customer',
    code: 'country code',
};

export const WS_GROUP_ORDER = ['Shop', 'Catalog', 'Orders', 'Customers', 'Webhooks'];

export function prettyList(keys, map) {
    return (keys || []).map((key) => map[key] || key).join(', ');
}

export function copyText(value) {
    const text = value == null ? '' : String(value);
    if (!text || !navigator?.clipboard?.writeText) {
        return;
    }
    navigator.clipboard.writeText(text).catch(() => {});
}

export function wsGroupMeta(group) {
    const rows = {
        Shop: {
            label: t('admin.ops_tools.ws_group_shop', 'Shop'),
            hint: t('admin.ops_tools.ws_group_shop_hint', 'Shop details, languages, currencies, countries, shipping, and pages.'),
            icon: 'globe',
            tone: ui.statIconInfo,
            badge: 'info',
        },
        Catalog: {
            label: t('admin.ops_tools.group_products', 'Products'),
            hint: t('admin.ops_tools.ws_group_products_hint', 'Products, stock, categories, brands, tags, and suppliers.'),
            icon: 'package',
            tone: ui.statIconSuccess,
            badge: 'success',
        },
        Orders: {
            label: t('admin.ops_tools.group_orders', 'Orders'),
            hint: t('admin.ops_tools.ws_group_orders_hint', 'Order lists and status updates.'),
            icon: 'bag',
            tone: ui.statIconPrimary,
            badge: 'primary',
        },
        Customers: {
            label: t('admin.ops_tools.group_customers', 'Customers'),
            hint: t('admin.ops_tools.ws_group_customers_hint', 'Customers, addresses, and groups.'),
            icon: 'users',
            tone: ui.statIconPrimary,
            badge: 'primary',
        },
        Webhooks: {
            label: t('admin.ops_tools.ws_group_webhooks', 'Webhooks'),
            hint: t('admin.ops_tools.ws_group_webhooks_hint', 'Register or remove outbound destinations from their app.'),
            icon: 'bell',
            tone: ui.statIconWarning,
            badge: 'warning',
        },
    };

    return rows[group] || { label: group, hint: '', icon: 'layers', tone: ui.statIconInfo, badge: 'info' };
}

export function orderedGroupNames(groups, order = WS_GROUP_ORDER) {
    const names = Object.keys(groups);
    return [...order.filter((name) => names.includes(name)), ...names.filter((name) => !order.includes(name))];
}

export function groupRows(list, key = 'group') {
    const groups = {};
    (list || []).forEach((row) => {
        const name = row[key] || 'Shop';
        groups[name] = groups[name] || [];
        groups[name].push(row);
    });
    return groups;
}
