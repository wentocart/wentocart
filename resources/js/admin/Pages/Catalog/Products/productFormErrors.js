/**
 * Maps product form / API validation keys to Product Edit tabs.
 * Keep in sync with app/Domain/Catalog/Support/ProductFormErrorTabs.php
 */
export const PRODUCT_ERROR_TAB_RULES = [
    { tab: 'seo', match: /^(translations\.[^.]+\.)?(meta_|slug$|slug\.|canonical|robots|focus_keyword)/i },
    { tab: 'subscription', match: /^(subscription|purchase_option|translations\.[^.]+\.subscription_message)/i },
    { tab: 'customizable', match: /^customization/i },
    { tab: 'preorder', match: /^preorder/i },
    { tab: 'gift_card', match: /^gift_card/i },
    { tab: 'service', match: /^service/i },
    { tab: 'rental', match: /^rental/i },
    { tab: 'combinations', match: /^combination/i },
    { tab: 'products', match: /^components/i },
    { tab: 'media', match: /^(images|video_url|attachment)/i },
    { tab: 'downloads', match: /^(paid_attachment|download)/i },
    { tab: 'inventory', match: /^(stock|warehouse|qty_|min_qty|max_qty|reserved|manage_stock|low_stock|critical_stock|sale_unit)/i },
    { tab: 'shipping', match: /^(length|width|height|weight|processing_time|delivery_|additional_shipping|shipping_method|available_all_carriers)/i },
    { tab: 'pricing', match: /^(price|sale_price|compare_price|cost_price|tax_class|sale_starts|sale_ends|pricing_)/i },
    { tab: 'details', match: /^(sku|isbn|upc|barcode|mpn|gtin|supplier|condition|display_condition)/i },
    { tab: 'attributes', match: /^feature/i },
    { tab: 'general', match: /^(name|short_description|description|category|brand|cover|seller|type|is_enabled|available_for_order|show_price|translations)/i },
];

function resolveAvailable(tab, availableTabs) {
    if (!availableTabs.length) {
        return tab;
    }
    const set = new Set(availableTabs);
    if (tab === 'products' && !set.has('products') && set.has('bundle')) {
        return 'bundle';
    }
    if (tab === 'combinations' && !set.has('combinations') && set.has('variants')) {
        return 'variants';
    }
    if (!set.has(tab)) {
        return null;
    }

    return tab;
}

export function tabForErrorKey(key, availableTabs = []) {
    const raw = String(key || '');
    const rule = PRODUCT_ERROR_TAB_RULES.find((item) => item.match.test(raw));
    if (!rule) {
        return null;
    }

    return resolveAvailable(rule.tab, availableTabs);
}

export function tabsForFormErrors(errors, availableTabs = []) {
    const found = new Set();
    Object.keys(errors || {}).forEach((key) => {
        const tab = tabForErrorKey(key, availableTabs);
        if (tab) {
            found.add(tab);
        }
    });
    if (availableTabs.length) {
        return availableTabs.filter((tab) => found.has(tab));
    }

    return [...found];
}
