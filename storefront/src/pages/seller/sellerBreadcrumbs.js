import { t } from '../../i18n.js';

const SELLER_ROOT = '/account/seller';

function label(key, fallback) {
    return t(key, fallback);
}

function section(nameKey, fallback, segment) {
    return { name: label(nameKey, fallback), url: `${SELLER_ROOT}/${segment}` };
}

function leaf(nameKey, fallback, nameOverride) {
    return { name: nameOverride || label(nameKey, fallback) };
}

/** @param {string} pathname */
function sellerSubPath(pathname) {
    return pathname.replace(/^\/account\/seller\/?/, '').replace(/#.*$/, '').replace(/\/$/, '');
}

/**
 * Build breadcrumb items for seller portal pages.
 * @param {string} pathname
 * @param {{ tailLabel?: string | null }} [options]
 */
export function buildSellerBreadcrumbs(pathname, { tailLabel } = {}) {
    const items = [
        { name: label('storefront.shell.home', 'Home'), url: '/' },
        { name: label('storefront.shell.my_account', 'My Account'), url: '/account' },
    ];

    const sub = sellerSubPath(pathname);

    if (!sub) {
        items.push({ name: label('storefront.seller.portal', 'Seller') });
        return items;
    }

    items.push({ name: label('storefront.seller.portal', 'Seller'), url: SELLER_ROOT });

    const trail = resolveSellerTrail(sub, tailLabel);
    return [...items, ...trail];
}

/** @param {string} sub Path after /account/seller/ */
function resolveSellerTrail(sub, tailLabel) {
    const parts = sub.split('/').filter(Boolean);
    const [a, b, c, d] = parts;

    const newLabel = () => leaf('storefront.seller.new', 'New', tailLabel);
    const editLabel = () => leaf('storefront.seller.edit', 'Edit', tailLabel);

    if (a === 'products') {
        if (b === 'new') {
            return [section('storefront.seller.menu.products', 'Products', 'products'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.menu.products', 'Products', 'products'), editLabel()];
        }
        return [leaf('storefront.seller.menu.products', 'Products', tailLabel)];
    }

    if (a === 'categories') {
        if (b === 'new') {
            return [section('storefront.seller.menu.categories', 'Categories', 'categories'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.menu.categories', 'Categories', 'categories'), editLabel()];
        }
        return [leaf('storefront.seller.menu.categories', 'Categories', tailLabel)];
    }

    if (a === 'brands') {
        if (b === 'new') {
            return [section('storefront.seller.menu.brands', 'Brands', 'brands'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.menu.brands', 'Brands', 'brands'), editLabel()];
        }
        return [leaf('storefront.seller.menu.brands', 'Brands', tailLabel)];
    }

    if (a === 'suppliers') {
        if (b === 'new') {
            return [section('storefront.seller.menu.suppliers', 'Suppliers', 'suppliers'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.menu.suppliers', 'Suppliers', 'suppliers'), editLabel()];
        }
        return [leaf('storefront.seller.menu.suppliers', 'Suppliers', tailLabel)];
    }

    if (a === 'features') {
        if (b === 'new') {
            return [section('storefront.seller.menu.features', 'Features', 'features'), newLabel()];
        }
        if (b && c === 'values') {
            const valuesSection = section('storefront.seller.feature_values', 'Feature values', `features/${b}/values`);
            if (d === 'new') {
                return [section('storefront.seller.menu.features', 'Features', 'features'), valuesSection, newLabel()];
            }
            return [section('storefront.seller.menu.features', 'Features', 'features'), valuesSection];
        }
        if (b) {
            return [section('storefront.seller.menu.features', 'Features', 'features'), editLabel()];
        }
        return [leaf('storefront.seller.menu.features', 'Features', tailLabel)];
    }

    if (a === 'feature-values') {
        if (b === 'new') {
            return [section('storefront.seller.feature_values', 'Feature values', 'feature-values'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.feature_values', 'Feature values', 'feature-values'), editLabel()];
        }
        return [leaf('storefront.seller.feature_values', 'Feature values', tailLabel)];
    }

    if (a === 'attributes') {
        if (b === 'new') {
            return [section('storefront.seller.menu.attributes', 'Attributes', 'attributes'), newLabel()];
        }
        if (b && c === 'values') {
            const valuesSection = section('storefront.seller.attribute_values', 'Attribute values', `attributes/${b}/values`);
            if (d === 'new') {
                return [section('storefront.seller.menu.attributes', 'Attributes', 'attributes'), valuesSection, newLabel()];
            }
            return [section('storefront.seller.menu.attributes', 'Attributes', 'attributes'), valuesSection];
        }
        if (b) {
            return [section('storefront.seller.menu.attributes', 'Attributes', 'attributes'), editLabel()];
        }
        return [leaf('storefront.seller.menu.attributes', 'Attributes', tailLabel)];
    }

    if (a === 'attribute-values') {
        if (b === 'new') {
            return [section('storefront.seller.attribute_values', 'Attribute values', 'attribute-values'), newLabel()];
        }
        if (b) {
            return [section('storefront.seller.attribute_values', 'Attribute values', 'attribute-values'), editLabel()];
        }
        return [leaf('storefront.seller.attribute_values', 'Attribute values', tailLabel)];
    }

    if (a === 'orders') {
        if (b) {
            return [
                section('storefront.seller.menu.orders', 'Orders', 'orders'),
                leaf('storefront.seller.order_detail', 'Order', tailLabel || `#${b}`),
            ];
        }
        return [leaf('storefront.seller.menu.orders', 'Orders', tailLabel)];
    }

    const menuBySegment = {
        inventory: ['storefront.seller.menu.inventory', 'Inventory'],
        shipments: ['storefront.seller.menu.shipments', 'Shipments'],
        returns: ['storefront.seller.menu.returns', 'Returns'],
        reviews: ['storefront.seller.menu.reviews', 'Reviews'],
        earnings: ['storefront.seller.menu.earnings', 'Earnings'],
        transactions: ['storefront.seller.menu.transactions', 'Transactions'],
        payouts: ['storefront.seller.menu.payouts', 'Payouts'],
        profile: ['storefront.seller.profile', 'Profile'],
        shop: ['storefront.seller.shop', 'Shop'],
        settings: ['storefront.seller.menu.settings', 'Settings'],
        commission: ['storefront.seller.commission', 'Commission'],
        ledger: ['storefront.seller.ledger', 'Ledger'],
        revenue: ['storefront.seller.revenue', 'Revenue'],
        shipping: ['storefront.seller.shipping', 'Shipping'],
        payments: ['storefront.seller.payments', 'Payments'],
        discounts: ['storefront.seller.discounts', 'Discounts'],
        messages: ['storefront.seller.messages', 'Messages'],
    };

    const menu = menuBySegment[a];
    if (menu) {
        return [leaf(menu[0], menu[1], tailLabel)];
    }

    return [leaf('storefront.seller.portal', 'Seller', tailLabel)];
}
