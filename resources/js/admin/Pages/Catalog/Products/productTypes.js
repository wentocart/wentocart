import { t } from '../../../ui';

const PRODUCT_TYPE_OPTIONS = [
    { value: 'simple', label: 'Standard' },
    { value: 'combination', label: 'Combinations' },
    { value: 'group', label: 'Grouped' },
    { value: 'virtual', label: 'Virtual' },
    { value: 'bundle', label: 'Bundle' },
    { value: 'gift_card', label: 'Gift Card' },
    { value: 'service', label: 'Service' },
    { value: 'rental', label: 'Rental' },
];

export const PRODUCT_CAPABILITIES = {
    simple: ['inventory', 'shipping', 'preorder', 'customizable', 'subscription'],
    combination: ['inventory', 'shipping', 'combinations', 'preorder', 'customizable', 'subscription'],
    virtual: ['customizable', 'subscription', 'downloads'],
    group: ['inventory', 'shipping', 'customizable', 'subscription'],
    bundle: ['inventory', 'shipping', 'customizable', 'subscription'],
    gift_card: [],
    service: ['customizable', 'subscription'],
    rental: ['inventory', 'shipping', 'customizable'],
    preorder: ['inventory', 'shipping', 'preorder', 'customizable', 'subscription'],
};

const PREORDER_TAB = { id: 'preorder', label: 'Pre-order' };

export const DEFAULT_PRODUCT_TABS = [
    { id: 'general', label: 'General' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'variants', label: 'Variants', countKey: 'variants' },
    { id: 'attributes', label: 'Attributes' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
    { id: 'history', label: 'History' },
];

const CATALOG_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

const GROUP_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'products', label: 'Products', countKey: 'products' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

const COMBINATION_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'combinations', label: 'Combinations', countKey: 'variants' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

const BUNDLE_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'bundle', label: 'Bundle', countKey: 'bundle' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

const GIFT_CARD_TABS = [
    { id: 'general', label: 'General' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'seo', label: 'SEO' },
    { id: 'gift_card', label: 'Gift Card', countKey: 'gift_card_amounts' },
];

const SERVICE_TABS = [
    { id: 'general', label: 'General' },
    { id: 'service', label: 'Service' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

/** Virtual products: public Media docs + private Digital Files (customer downloads). */
const VIRTUAL_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'downloads', label: 'Digital Files', countKey: 'downloads' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
    { id: 'subscription', label: 'Subscription', countKey: 'subscription' },
];

const RENTAL_TABS = [
    { id: 'general', label: 'General' },
    { id: 'rental', label: 'Rental', countKey: 'rental_tiers' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media', countKey: 'media' },
    { id: 'customizable', label: 'Customizable', countKey: 'customizable' },
];

export const PRODUCT_TYPE_CONFIG = {
    simple: {
        tabs: CATALOG_TABS,
        usesCatalogLayout: true,
        defaultManageStock: true,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.simple,
    },
    virtual: {
        tabs: VIRTUAL_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: false,
        capabilities: PRODUCT_CAPABILITIES.virtual,
    },
    group: {
        tabs: GROUP_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.group,
        defaults: {
            selection_mode: 'all',
            pricing_mode: 'sum',
            inventory_mode: 'derived',
            shipping_mode: 'derived',
        },
    },
    bundle: {
        tabs: BUNDLE_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.bundle,
        defaults: {
            bundle_type: 'fixed',
            pricing_mode: 'fixed',
            inventory_mode: 'derived',
            shipping_mode: 'derived',
        },
    },
    combination: {
        tabs: COMBINATION_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.combination,
    },
    gift_card: {
        tabs: GIFT_CARD_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: false,
        capabilities: PRODUCT_CAPABILITIES.gift_card,
    },
    service: {
        tabs: SERVICE_TABS,
        usesCatalogLayout: true,
        defaultManageStock: false,
        hasShipping: false,
        capabilities: PRODUCT_CAPABILITIES.service,
    },
    rental: {
        tabs: RENTAL_TABS,
        usesCatalogLayout: true,
        defaultManageStock: true,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.rental,
    },
    preorder: {
        tabs: CATALOG_TABS,
        usesCatalogLayout: true,
        defaultManageStock: true,
        hasShipping: true,
        capabilities: PRODUCT_CAPABILITIES.preorder,
        legacyType: true,
    },
};

export const PRODUCT_TYPE_TABS = Object.fromEntries(
    Object.entries(PRODUCT_TYPE_CONFIG).map(([type, config]) => [type, config.tabs]),
);

const FALLBACK_CONFIG = {
    tabs: DEFAULT_PRODUCT_TABS,
    usesCatalogLayout: false,
    defaultManageStock: true,
    hasShipping: true,
    capabilities: [],
};

export function normalizeProductType(type) {
    if (type === 'preorder') {
        return 'simple';
    }

    return type;
}

export function typeConfig(type) {
    return PRODUCT_TYPE_CONFIG[type] || FALLBACK_CONFIG;
}

export function typeCapabilities(type) {
    const normalized = normalizeProductType(type);
    const config = PRODUCT_TYPE_CONFIG[normalized] || PRODUCT_TYPE_CONFIG[type];

    return config?.capabilities || [];
}

export function typeSupportsCapability(type, capability) {
    return typeCapabilities(type).includes(capability);
}

export function tabsForType(type) {
    return typeConfig(normalizeProductType(type)).tabs;
}

function tabLabel(id, fallback) {
    switch (id) {
        case 'general': return t('admin.products.tab.general', 'General');
        case 'details': return t('admin.products.tab.details', 'Details');
        case 'pricing': return t('admin.products.tab.pricing', 'Pricing');
        case 'inventory': return t('admin.products.tab.inventory', 'Inventory');
        case 'shipping': return t('admin.products.tab.shipping', 'Shipping');
        case 'seo': return t('admin.products.tab.seo', 'SEO');
        case 'variants': return t('admin.products.tab.variants', 'Variants');
        case 'attributes': return t('admin.products.tab.attributes', 'Attributes');
        case 'media': return t('admin.products.tab.media', 'Media');
        case 'customizable': return t('admin.products.tab.customizable', 'Customizable');
        case 'subscription': return t('admin.products.tab.subscription', 'Subscription');
        case 'history': return t('admin.products.tab.history', 'History');
        case 'products': return t('admin.products.tab.products', 'Products');
        case 'combinations': return t('admin.products.tab.combinations', 'Combinations');
        case 'bundle': return t('admin.products.tab.bundle', 'Bundle');
        case 'gift_card': return t('admin.products.tab.gift_card', 'Gift card');
        case 'service': return t('admin.products.tab.service', 'Service');
        case 'downloads': return t('admin.products.tab.downloads', 'Digital files');
        case 'rental': return t('admin.products.tab.rental', 'Rental');
        case 'preorder': return t('admin.products.tab.preorder', 'Pre-order');
        default: return fallback;
    }
}

function translateTab(tab) {
    return { ...tab, label: tabLabel(tab.id, tab.label) };
}

export function tabsForProduct(type) {
    const normalized = normalizeProductType(type);
    const tabs = [...tabsForType(normalized)];

    if (typeSupportsCapability(normalized, 'preorder')) {
        tabs.push(PREORDER_TAB);
    }

    return tabs.map(translateTab);
}

export function typeHasTab(type, tabId) {
    return tabsForProduct(type).some((tab) => tab.id === tabId);
}

function productTypeLabel(value, fallback) {
    switch (value) {
        case 'simple': return t('admin.products.type.simple', 'Standard');
        case 'combination': return t('admin.products.type.combinations', 'Combinations');
        case 'group': return t('admin.products.type.grouped', 'Grouped');
        case 'virtual': return t('admin.products.type.virtual', 'Virtual');
        case 'bundle': return t('admin.products.type.bundle', 'Bundle');
        case 'gift_card': return t('admin.products.type.gift_card', 'Gift Card');
        case 'service': return t('admin.products.type.service', 'Service');
        case 'rental': return t('admin.products.type.rental', 'Rental');
        default: return fallback;
    }
}

export function productTypeOptions() {
    return PRODUCT_TYPE_OPTIONS.map((row) => ({
        ...row,
        label: productTypeLabel(row.value, row.label),
    }));
}

export default PRODUCT_TYPE_OPTIONS;
