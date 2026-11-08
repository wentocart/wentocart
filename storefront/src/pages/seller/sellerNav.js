/** Seller portal navigation — flat items only (no catalog dropdown). */

export const sellerMenus = [
    { to: '/account/seller', label: 'Dashboard', icon: 'grid', end: true },
    { to: '/account/seller/products', label: 'Products', icon: 'package', match: (path) => path.startsWith('/account/seller/products') },
    { to: '/account/seller/categories', label: 'Categories', icon: 'list', match: (path) => path.startsWith('/account/seller/categories') },
    { to: '/account/seller/brands', label: 'Brands', icon: 'star', match: (path) => path.startsWith('/account/seller/brands') },
    { to: '/account/seller/suppliers', label: 'Suppliers', icon: 'truck', match: (path) => path.startsWith('/account/seller/suppliers') },
    { to: '/account/seller/features', label: 'Features', icon: 'filter', match: (path) => path.startsWith('/account/seller/features') || path.startsWith('/account/seller/feature-values') },
    { to: '/account/seller/attributes', label: 'Attributes', icon: 'layers', match: (path) => path.startsWith('/account/seller/attributes') || path.startsWith('/account/seller/attribute-values') },
    { to: '/account/seller/inventory', label: 'Inventory', icon: 'list' },
    { to: '/account/seller/orders', label: 'Orders', icon: 'cart', match: (path) => path.startsWith('/account/seller/orders') },
    { to: '/account/seller/shipments', label: 'Shipments', icon: 'truck' },
    { to: '/account/seller/returns', label: 'Returns', icon: 'return' },
    { to: '/account/seller/earnings', label: 'Earnings', icon: 'star' },
    { to: '/account/seller/transactions', label: 'Transactions', icon: 'list' },
    { to: '/account/seller/payouts', label: 'Payouts', icon: 'download' },
    { to: '/account/seller/reviews', label: 'Reviews', icon: 'star' },
    { to: '/account/seller/settings', label: 'Settings', icon: 'settings' },
];

export function navIsActive(item, pathname) {
    if (typeof item.match === 'function') {
        return item.match(pathname);
    }
    return pathname === item.to || (item.end ? pathname === item.to : pathname.startsWith(`${item.to}/`));
}

/** @deprecated Use sellerMenus — kept for any external imports during transition. */
export const sellerPrimaryMenus = sellerMenus;

/** @deprecated Catalog group removed — flat navigation only. */
export const sellerCatalogMenus = [];

/** @deprecated Catalog group removed — flat navigation only. */
export function isCatalogPath() {
    return false;
}
