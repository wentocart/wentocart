/**
 * Sync document head on SPA navigations (title, description, robots, canonical, OG, JSON-LD).
 */

function ensureMeta(selector, attr, attrValue, content) {
    let el = document.head.querySelector(selector);
    if (!el) {
        el = document.createElement('meta');
        el.setAttribute(attr, attrValue);
        document.head.appendChild(el);
    }
    el.setAttribute('content', content ?? '');
    return el;
}

function ensureLink(rel, href) {
    let el = document.head.querySelector(`link[rel="${rel}"]`);
    if (!el) {
        el = document.createElement('link');
        el.setAttribute('rel', rel);
        document.head.appendChild(el);
    }
    if (href) {
        el.setAttribute('href', href);
    }
    return el;
}

function clearHreflangAlternates() {
    document.head.querySelectorAll('link[rel="alternate"][hreflang]').forEach((el) => el.remove());
}

/** Replace every JSON-LD block so Blade SSR scripts do not stay stale after SPA nav. */
function ensureJsonLd(data) {
    document.head.querySelectorAll('script[type="application/ld+json"]').forEach((el) => el.remove());
    if (!data) {
        return;
    }
    const el = document.createElement('script');
    el.type = 'application/ld+json';
    el.setAttribute('data-wento-seo-ld', '1');
    el.textContent = JSON.stringify(data);
    document.head.appendChild(el);
}

function absoluteCanonical(pathOrUrl) {
    if (!pathOrUrl) {
        return window.location.origin + window.location.pathname;
    }
    if (/^https?:\/\//i.test(pathOrUrl)) {
        return pathOrUrl;
    }
    return window.location.origin + (pathOrUrl.startsWith('/') ? pathOrUrl : `/${pathOrUrl}`);
}

/**
 * @param {object|null|undefined} seo
 */
export function applySeoHead(seo) {
    if (!seo || typeof seo !== 'object') {
        return;
    }
    if (seo.title) {
        document.title = seo.title;
    }
    if (seo.description != null) {
        ensureMeta('meta[name="description"]', 'name', 'description', seo.description);
        ensureMeta('meta[property="og:description"]', 'property', 'og:description', seo.description);
        ensureMeta('meta[name="twitter:description"]', 'name', 'twitter:description', seo.description);
    }
    if (seo.robots) {
        ensureMeta('meta[name="robots"]', 'name', 'robots', seo.robots);
    }
    if (seo.canonical) {
        const canonical = absoluteCanonical(seo.canonical);
        ensureLink('canonical', canonical);
        ensureMeta('meta[property="og:url"]', 'property', 'og:url', canonical);
    }
    if (seo.title) {
        ensureMeta('meta[property="og:title"]', 'property', 'og:title', seo.title);
        ensureMeta('meta[name="twitter:title"]', 'name', 'twitter:title', seo.title);
    }
    if (seo.og_type) {
        ensureMeta('meta[property="og:type"]', 'property', 'og:type', seo.og_type);
    }
    const shopName = window.__WENTO__?.shop || 'Shop';
    ensureMeta('meta[property="og:site_name"]', 'property', 'og:site_name', shopName);
    const twitterCard = seo.twitter_card || (seo.og_image ? 'summary_large_image' : 'summary');
    ensureMeta('meta[name="twitter:card"]', 'name', 'twitter:card', twitterCard);
    if (seo.og_image) {
        ensureMeta('meta[property="og:image"]', 'property', 'og:image', seo.og_image);
        ensureMeta('meta[name="twitter:image"]', 'name', 'twitter:image', seo.og_image);
    } else if (seo.clear_og_image) {
        document.head.querySelectorAll('meta[property="og:image"], meta[name="twitter:image"]').forEach((el) => el.remove());
    }
    if (seo.json_ld !== undefined) {
        ensureJsonLd(seo.json_ld);
    }
    // Always replace hreflang so SPA nav never leaves stale SSR alternates.
    clearHreflangAlternates();
    const hreflang = Array.isArray(seo.hreflang) ? seo.hreflang : [];
    hreflang.forEach((row) => {
        if (!row?.lang || !row?.url) {
            return;
        }
        const link = document.createElement('link');
        link.rel = 'alternate';
        link.hreflang = row.lang;
        link.href = row.url;
        document.head.appendChild(link);
    });
    if (window.__WENTO__ && hreflang.length) {
        window.__WENTO__.hreflang = hreflang;
    }
}

export function applyBootSeo(overrides = {}) {
    const boot = window.__WENTO__?.seo || {};
    const shop = window.__WENTO__?.shop || 'Shop';
    applySeoHead({
        title: boot.title || shop,
        description: boot.description || '',
        robots: boot.robots || 'index,follow',
        canonical: boot.canonical || (window.location.origin + '/'),
        og_type: boot.og_type || 'website',
        og_image: boot.og_image || null,
        clear_og_image: !boot.og_image,
        twitter_card: boot.twitter_card || (boot.og_image ? 'summary_large_image' : 'summary'),
        json_ld: boot.json_ld ?? null,
        hreflang: window.__WENTO__?.hreflang || [],
        ...overrides,
    });
}

export function applyNotFoundSeo() {
    const shop = window.__WENTO__?.shop || 'Shop';
    applySeoHead({
        title: `Page not found | ${shop}`,
        description: 'The requested page could not be found.',
        robots: 'noindex,nofollow',
        canonical: window.location.origin + window.location.pathname,
        og_type: 'website',
        clear_og_image: true,
        twitter_card: 'summary',
        json_ld: { '@context': 'https://schema.org', '@type': 'WebPage', name: 'Page not found' },
        hreflang: [],
    });
}

export function applyThinShellSeo(label) {
    const shop = window.__WENTO__?.shop || 'Shop';
    const title = label ? `${label} | ${shop}` : shop;
    applySeoHead({
        title,
        description: window.__WENTO__?.seo?.description || '',
        robots: 'noindex,follow',
        canonical: window.location.origin + window.location.pathname,
        og_type: 'website',
        clear_og_image: true,
        twitter_card: 'summary',
        json_ld: null,
        hreflang: [],
    });
}

/** Apply CMS page SEO from API payload; falls back to thin shell when missing. */
export function applyCmsPageSeo(page, fallbackLabel) {
    if (!page) {
        applyThinShellSeo(fallbackLabel);
        return;
    }
    const seo = page.seo || {};
    applySeoHead({
        title: seo.title || page.meta_title || page.title,
        description: seo.description || page.meta_description || page.short_description || '',
        robots: seo.robots || page.robots || 'index,follow',
        canonical: seo.canonical || page.canonical_url || barePathCanonical(),
        og_type: seo.og_type || 'article',
        og_image: seo.og_image || null,
        clear_og_image: !seo.og_image,
        twitter_card: seo.og_image ? 'summary_large_image' : 'summary',
        json_ld: seo.json_ld ?? null,
        hreflang: seo.hreflang || [],
    });
}

/** True when URL has PDP/listing variant noise that must stay noindex. */
export function hasVariantQuery(search = window.location.search) {
    const params = new URLSearchParams(search);
    const keys = ['ipa', 'combination', 'brand', 'price', 'rating', 'discount', 'color', 'availability', 'values', 'sort', 'page', 'nc', 'q'];
    return keys.some((key) => {
        const value = params.get(key);
        if (value == null || value === '') {
            return false;
        }
        if (key === 'page' && Number(value) <= 1) {
            return false;
        }
        return true;
    });
}

export function barePathCanonical(pathname = window.location.pathname) {
    return window.location.origin + pathname;
}

/** Utility / account routes that must stay noindex after client navigations. */
const UTILITY_ROOTS = new Set([
    'cart', 'checkout', 'login', 'register', 'forgot-password', 'reset-password',
    'search', 'account', 'compare', 'order', 'wishlist', '404',
]);

const THIN_ROOTS = new Set(['catalog', 'deal', 'brands', 'blog', 'm']);

const UTILITY_TITLES = {
    cart: 'Cart',
    checkout: 'Checkout',
    login: 'Login',
    register: 'Register',
    search: 'Search',
    account: 'My account',
    compare: 'Compare',
    wishlist: 'Wishlist',
    order: 'Order',
    'forgot-password': 'Forgot password',
    'reset-password': 'Reset password',
    404: 'Not found',
};

export function seoForPathname(pathname) {
    const path = String(pathname || '/');
    const root = path.replace(/^\//, '').split('/')[0] || '';
    const shop = window.__WENTO__?.shop || 'Shop';
    if (path === '/register/pending' || path.endsWith('/register/pending')) {
        return {
            title: `Waiting for approval | ${shop}`,
            description: 'Your account was created and sent for admin approval.',
            robots: 'noindex,nofollow',
            canonical: barePathCanonical(pathname),
            og_type: 'website',
            clear_og_image: true,
            twitter_card: 'summary',
            json_ld: null,
            hreflang: [],
        };
    }
    if (UTILITY_ROOTS.has(root)) {
        const label = UTILITY_TITLES[root] || root;
        return {
            title: `${label} | ${shop}`,
            description: window.__WENTO__?.seo?.description || '',
            robots: 'noindex,nofollow',
            canonical: barePathCanonical(pathname),
            og_type: 'website',
            clear_og_image: true,
            twitter_card: 'summary',
            json_ld: null,
            hreflang: [],
        };
    }
    if (THIN_ROOTS.has(root) && !String(pathname).slice(1).includes('/')) {
        return {
            title: `${root.charAt(0).toUpperCase()}${root.slice(1)} | ${shop}`,
            description: window.__WENTO__?.seo?.description || '',
            robots: 'noindex,follow',
            canonical: barePathCanonical(pathname),
            og_type: 'website',
            clear_og_image: true,
            twitter_card: 'summary',
            json_ld: null,
            hreflang: [],
        };
    }
    if (pathname === '/' || pathname === '') {
        return { boot: true };
    }
    return null;
}

export function applyUtilitySeoIfNeeded(pathname) {
    const seo = seoForPathname(pathname);
    if (!seo) {
        return;
    }
    if (seo.boot) {
        applyBootSeo();
        return;
    }
    applySeoHead(seo);
}
