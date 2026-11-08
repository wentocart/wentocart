/**
 * Prefetch a lazy route chunk on pointer hover / focus.
 * Maps common storefront paths to dynamic import() of the page module.
 */

const loaders = {
    '/catalog': () => import('../pages/Catalog.jsx'),
    '/new-arrivals': () => import('../pages/Catalog.jsx'),
    '/cart': () => import('../pages/Cart.jsx'),
    '/checkout': () => import('../pages/Checkout.jsx'),
    '/login': () => import('../pages/Login.jsx'),
    '/register': () => import('../pages/Register.jsx'),
    '/register/pending': () => import('../pages/RegisterPending.jsx'),
    '/search': () => import('../pages/Search.jsx'),
    '/compare': () => import('../pages/Compare.jsx'),
    '/brands': () => import('../pages/Brands.jsx'),
    '/help': () => import('../pages/Help.jsx'),
    '/order-status': () => import('../pages/OrderStatus.jsx'),
    '/blog': () => import('../pages/Blog.jsx'),
    '/wishlist': () => import('../pages/Wishlist.jsx'),
    '/account': () => import('../pages/Account.jsx'),
    '/shipping': () => import('../pages/Cms.jsx'),
    '/returns': () => import('../pages/Cms.jsx'),
    '/privacy': () => import('../pages/Cms.jsx'),
    '/terms': () => import('../pages/Cms.jsx'),
    '/faq': () => import('../pages/Cms.jsx'),
    '/contact': () => import('../pages/Cms.jsx'),
    '/stores': () => import('../pages/Cms.jsx'),
    '/about': () => import('../pages/Cms.jsx'),
    '/order-tracking': () => import('../pages/Cms.jsx'),
    '/payment-security': () => import('../pages/Cms.jsx'),
    '/warranty': () => import('../pages/Cms.jsx'),
};

const warmed = new Set();

function normalizePath(to) {
    if (!to || typeof to !== 'string') {
        return '';
    }
    if (to.startsWith('http') || to.startsWith('mailto:') || to.startsWith('tel:')) {
        return '';
    }
    const path = to.split('?')[0].split('#')[0];
    if (path.startsWith('/account/seller')) {
        return '/account/seller';
    }
    if (path.startsWith('/account')) {
        return '/account';
    }
    if (path.startsWith('/brand/')) {
        return '/brand';
    }
    if (path.startsWith('/shop/')) {
        return '/shop';
    }
    if (path.startsWith('/order/')) {
        return '/order';
    }
    return path;
}

const pathLoaders = {
    ...loaders,
    '/account/seller': () => import('../pages/seller/SellerLayout.jsx'),
    '/brand': () => import('../pages/Brand.jsx'),
    '/shop': () => import('../pages/Shop.jsx'),
    '/order': () => import('../pages/OrderSuccess.jsx'),
};

export function prefetchRoute(to) {
    const key = normalizePath(to);
    const loader = pathLoaders[key];
    if (!key || !loader || warmed.has(key)) {
        return;
    }
    warmed.add(key);
    void loader().catch(() => {
        warmed.delete(key);
    });
}

/** Props for Link / <a> to warm the target chunk before navigation. */
export function prefetchHandlers(to) {
    return {
        onMouseEnter: () => prefetchRoute(to),
        onFocus: () => prefetchRoute(to),
        onTouchStart: () => prefetchRoute(to),
    };
}
