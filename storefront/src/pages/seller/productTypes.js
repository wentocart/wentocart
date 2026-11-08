/**
 * Seller product type config — mirrors admin productTypes.js capabilities/tabs.
 * Types come from the API (`options.product_types`); this file only drives UI sections.
 */

export const PRODUCT_CAPABILITIES = {
    simple: ['inventory', 'shipping', 'preorder', 'combinations'],
    combination: ['inventory', 'shipping', 'combinations', 'preorder'],
    virtual: [],
    group: ['inventory', 'shipping', 'components'],
    bundle: ['inventory', 'shipping', 'components'],
    gift_card: ['gift_card'],
    service: ['service'],
    rental: ['inventory', 'shipping', 'rental'],
};

const CATALOG_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'preorder', label: 'Pre-order' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const COMBINATION_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'combinations', label: 'Combinations' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'preorder', label: 'Pre-order' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const GROUP_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'products', label: 'Products' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const BUNDLE_TABS = [
    { id: 'general', label: 'General' },
    { id: 'details', label: 'Details' },
    { id: 'bundle', label: 'Bundle' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const GIFT_CARD_TABS = [
    { id: 'general', label: 'General' },
    { id: 'gift_card', label: 'Gift Card' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const SERVICE_TABS = [
    { id: 'general', label: 'General' },
    { id: 'service', label: 'Service' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

const RENTAL_TABS = [
    { id: 'general', label: 'General' },
    { id: 'rental', label: 'Rental' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'inventory', label: 'Inventory' },
    { id: 'shipping', label: 'Shipping' },
    { id: 'seo', label: 'SEO' },
    { id: 'media', label: 'Media' },
];

export const PRODUCT_TYPE_CONFIG = {
    simple: { tabs: CATALOG_TABS, hasShipping: true, capabilities: PRODUCT_CAPABILITIES.simple },
    virtual: {
        tabs: CATALOG_TABS.filter((tab) => tab.id !== 'shipping'),
        hasShipping: false,
        capabilities: PRODUCT_CAPABILITIES.virtual,
    },
    combination: { tabs: COMBINATION_TABS, hasShipping: true, capabilities: PRODUCT_CAPABILITIES.combination },
    group: { tabs: GROUP_TABS, hasShipping: true, capabilities: PRODUCT_CAPABILITIES.group },
    bundle: { tabs: BUNDLE_TABS, hasShipping: true, capabilities: PRODUCT_CAPABILITIES.bundle },
    gift_card: { tabs: GIFT_CARD_TABS, hasShipping: false, capabilities: PRODUCT_CAPABILITIES.gift_card },
    service: { tabs: SERVICE_TABS, hasShipping: false, capabilities: PRODUCT_CAPABILITIES.service },
    rental: { tabs: RENTAL_TABS, hasShipping: true, capabilities: PRODUCT_CAPABILITIES.rental },
};

export function normalizeProductType(type) {
    return type === 'preorder' ? 'simple' : (type || 'simple');
}

export function typeConfig(type) {
    return PRODUCT_TYPE_CONFIG[normalizeProductType(type)] || PRODUCT_TYPE_CONFIG.simple;
}

export function tabsForType(type) {
    return typeConfig(type).tabs.filter((tab) => tab.id !== 'preorder' || typeSupports(type, 'preorder'));
}

export function typeSupports(type, capability) {
    return (typeConfig(type).capabilities || []).includes(capability);
}

/** Cartesian product of attribute value id lists → variant rows. */
export function cartesianVariants(axes) {
    const lists = (axes || [])
        .filter((axis) => axis.attribute_id && (axis.value_ids || []).length)
        .map((axis) => (axis.value_ids || []).map((id) => Number(id)));
    if (lists.length === 0) {
        return [];
    }
    return lists.reduce((acc, ids) => {
        if (acc.length === 0) {
            return ids.map((id) => [id]);
        }
        const next = [];
        acc.forEach((combo) => {
            ids.forEach((id) => next.push([...combo, id]));
        });
        return next;
    }, []).map((attribute_value_ids, index) => ({
        attribute_value_ids,
        sku: '',
        barcode: '',
        price: '',
        sale_price: '',
        stock_qty: 0,
        manage_stock: true,
        stock_status: 'in_stock',
        weight: '',
        is_enabled: true,
        is_default: index === 0,
        position: index,
    }));
}
