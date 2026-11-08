/**
 * Storefront path helpers — prefer API `url` fields, then slug fallbacks.
 */
import { webPath } from '../webPath.js';

/** Reject protocol-relative and non-path schemes used as open redirects. */
function safeInternalPath(url, fallback = '/catalog') {
    const raw = String(url || '').trim();
    if (!raw || raw.startsWith('//') || /^[a-z][a-z0-9+.-]*:/i.test(raw)) {
        return fallback;
    }
    const path = raw.startsWith('/') ? raw : `/${raw}`;
    if (path.startsWith('//')) {
        return fallback;
    }
    return path;
}

/** React Router location.state so SmartPage can paint the right page shadow immediately. */
export function entityState(type) {
    return type ? { entityType: type } : undefined;
}

export function productPath(product) {
    if (!product) {
        return '/catalog';
    }
    const url = String(product.url || '').trim();
    if (url) {
        return safeInternalPath(url, '/catalog');
    }
    const slug = String(product.slug || '').trim();
    return slug ? `/${slug.replace(/^\/+/, '')}` : '/catalog';
}

export function brandPath(brand) {
    if (!brand) {
        return '/brands';
    }
    const url = String(brand.url || '').trim();
    if (url) {
        return safeInternalPath(url, '/brands');
    }
    const slug = String(brand.slug || brand.id || '').trim();
    return slug ? `/brand/${slug.replace(/^\/+/, '')}` : '/brands';
}

export function categoryPath(category) {
    if (!category) {
        return '/catalog';
    }
    const url = String(category.url || '').trim();
    if (url) {
        return safeInternalPath(url, '/catalog');
    }
    const slug = String(category.slug || '').trim();
    return slug ? `/${slug.replace(/^\/+/, '')}` : '/catalog';
}

export function flattenCategories(items = [], out = []) {
    (items || []).forEach((cat) => {
        if (!cat) {
            return;
        }
        out.push(cat);
        if (cat.children?.length) {
            flattenCategories(cat.children, out);
        }
    });
    return out;
}

function normalizeLabel(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

/** Map Classic demo chrome labels → seeded category names/slugs. */
const CATEGORY_ALIASES = {
    accessories: ['accessories'],
    'home appliances': ['home living', 'kitchen', 'home'],
    laptops: ['laptops'],
    mobiles: ['phones', 'mobiles'],
    'smart devices': ['wearables', 'smart devices', 'electronics'],
    televisions: ['electronics', 'decor'],
    gaming: ['toys', 'electronics', 'audio'],
    'smart watch': ['wearables'],
    'power banks': ['phones', 'electronics'],
    'air buds': ['audio'],
    cameras: ['electronics'],
    printer: ['office', 'electronics'],
    'games console': ['toys', 'electronics'],
    'screen guards': ['phones'],
    'screen protector': ['phones'],
    'mobile displays': ['phones'],
    'smart gloves': ['wearables'],
    'bluetooth hats': ['audio', 'wearables'],
    'headphone stands': ['audio'],
    'headphone cushions': ['audio'],
    'mobile chargers': ['phones'],
    'cable drop clips': ['phones', 'electronics'],
    'mobile flashes': ['phones'],
    'phone converters': ['phones'],
    'stickers chips': ['phones'],
    'anti dust plugs': ['phones'],
    headphone: ['audio'],
    headphones: ['audio'],
    watch: ['wearables'],
    speaker: ['audio'],
    phone: ['phones'],
    camera: ['electronics'],
    drone: ['toys', 'electronics'],
};

/**
 * Resolve a human label to a category URL from the shop category tree.
 * Falls back to catalog search when nothing matches.
 */
export function resolveCategoryLink(categories, label, fallbackSearch = true) {
    const needle = normalizeLabel(label);
    if (!needle) {
        return '/catalog';
    }
    if (needle === 'news') {
        return '/blog';
    }

    const flat = flattenCategories(categories);
    const aliases = CATEGORY_ALIASES[needle] || [needle];

    for (const alias of aliases) {
        const hit = flat.find((cat) => {
            const name = normalizeLabel(cat.name);
            const slug = normalizeLabel(cat.slug).replace(/\s+/g, '-');
            const aliasNorm = normalizeLabel(alias);
            const aliasSlug = aliasNorm.replace(/\s+/g, '-');
            return name === aliasNorm
                || slug === aliasSlug
                || name.includes(aliasNorm)
                || aliasNorm.includes(name);
        });
        if (hit) {
            return categoryPath(hit);
        }
    }

    return fallbackSearch ? `/search?q=${encodeURIComponent(label)}` : '/catalog';
}

const CMS_PATHS = new Set([
    'about', 'shipping', 'privacy', 'terms', 'contact', 'help', 'blog',
    'deal', 'new-arrivals', 'compare', 'cart', 'login', 'register',
    'stores', 'faq', 'returns', 'order-tracking', 'payment-security', 'warranty',
]);

const ALWAYS_LIVE_PATHS = new Set([
    'catalog', 'new-arrivals', 'deal', 'search', 'compare', 'cart', 'login', 'register',
    'brands', 'help', 'order-status', 'blog',
]);

/** Generic listing URLs that have nothing to open on an empty (no-demo) catalog. */
export function isCatalogListingPath(url) {
    const raw = String(url || '').trim();
    if (!raw || raw === '#' || raw === '/') {
        return true;
    }
    const path = raw.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    return path === '/catalog' || path === 'catalog';
}

export function shopHasCatalog({ categories = [], products = [] } = {}) {
    if (flattenCategories(categories).length > 0) {
        return true;
    }
    return (Array.isArray(products) ? products : []).some(Boolean);
}

/**
 * True when a theme/hero/promo URL resolves to a real storefront page or category.
 * Used to hide CTAs that would 404 on a clean (no-demo) install.
 */
export function configuredLinkExists(configured, categories = []) {
    const raw = String(configured || '').trim();
    if (!raw || raw === '/' || raw === '/catalog') {
        return true;
    }
    if (/^https?:\/\//i.test(raw) || raw.startsWith('mailto:') || raw.startsWith('tel:')) {
        return true;
    }
    const path = safeInternalPath(raw, '/catalog');
    if (path.startsWith('/search') || path.startsWith('/account/') || path.startsWith('/brand/') || path.startsWith('/supplier/')) {
        return true;
    }
    if (/^\/\d+-[a-z0-9-]+\/?$/i.test(path)) {
        return true;
    }
    const bare = path.match(/^\/([a-z0-9-]+)\/?$/i);
    if (!bare) {
        return true;
    }
    const slug = bare[1].toLowerCase();
    if (CMS_PATHS.has(slug) || ALWAYS_LIVE_PATHS.has(slug)) {
        return true;
    }
    const fromTree = resolveCategoryLink(categories, slug.replace(/-/g, ' '), false);
    if (fromTree && fromTree !== '/catalog') {
        return true;
    }
    const flat = flattenCategories(categories);
    return Boolean(flat.find((cat) => String(cat.slug || '').toLowerCase() === slug));
}

/** Prefer a concrete entity URL over a generic /catalog theme default. */
export function preferEntityUrl(configured, entityUrl, fallback = '/catalog') {
    const configuredPath = String(configured || '').trim();
    const entity = String(entityUrl || '').trim();
    if (entity && (!configuredPath || configuredPath === '/catalog' || configuredPath === '/')) {
        return safeInternalPath(entity, fallback);
    }
    if (configuredPath) {
        return safeInternalPath(configuredPath, fallback);
    }
    return fallback;
}


/**
 * Storefront menu / CMS nav URLs — pass through safe paths (incl. `/`).
 * Do not remap Home through catalog/product special-casing in resolveConfiguredLink.
 */
export function resolveMenuLink(configured, fallback = '/') {
    const raw = String(configured || '').trim();
    if (!raw) {
        return fallback;
    }
    if (/^https?:\/\//i.test(raw) || raw.startsWith('mailto:') || raw.startsWith('tel:')) {
        return raw;
    }
    if (raw.startsWith('//') || /^javascript:/i.test(raw) || /^[a-z][a-z0-9+.-]*:/i.test(raw)) {
        return fallback;
    }
    if (raw === '/') {
        return '/';
    }
    // Bare entity roots carry no slug — send them to the listing shell.
    if (/^\/brand\/?$/i.test(raw)) {
        return '/brands';
    }
    if (/^\/supplier\/?$/i.test(raw)) {
        return '/brands';
    }
    return safeInternalPath(raw, fallback);
}

export function isExternalMenuUrl(url) {
    const raw = String(url || '').trim();
    return /^https?:\/\//i.test(raw) || raw.startsWith('mailto:') || raw.startsWith('tel:');
}

/**
 * Map theme / chrome URLs onto live shop paths:
 * - empty or /catalog → product URL when available
 * - /search?q=… → best matching category
 * - bare slugs (/audio, /electronics) → canonical category.url from the tree
 * - CMS + account paths stay as-is
 */
export function resolveConfiguredLink(configured, {
    categories = [],
    product = null,
    fallback = '/catalog',
    hasCatalog = true,
} = {}) {
    const raw = String(configured || '').trim();
    const productHref = productPath(product);
    const emptyFallback = hasCatalog ? fallback : '#';

    if (raw === '#') {
        return emptyFallback;
    }

    if (!raw || raw === '/' || raw === '/catalog') {
        if (productHref && productHref !== '/catalog') {
            return productHref;
        }
        const catalog = resolveCategoryLink(categories, 'catalog', false);
        return catalog !== '/catalog' ? catalog : emptyFallback;
    }

    if (raw.startsWith('//')) {
        return emptyFallback;
    }

    if (/^https?:\/\//i.test(raw) || /\.xml($|\?)/i.test(raw) || raw.startsWith('mailto:') || raw.startsWith('tel:')) {
        return raw;
    }

    if (raw.startsWith('/search?q=')) {
        const q = decodeURIComponent(raw.slice('/search?q='.length).replace(/\+/g, ' '));
        return resolveCategoryLink(categories, q);
    }

    const path = safeInternalPath(raw, fallback);
    // A bare /brand or /supplier has no entity slug — fall through to the listing page.
    if (/^\/brand\/?$/i.test(path)) {
        return '/brands';
    }
    if (/^\/supplier\/?$/i.test(path)) {
        return '/brands';
    }
    if (path.startsWith('/brand/') || path.startsWith('/account/') || path.startsWith('/supplier/')) {
        return path;
    }

    // Already canonical id-slug product/category (/86-northline-daypack, /59-audio).
    if (/^\/\d+-[a-z0-9-]+\/?$/i.test(path)) {
        return path.replace(/\/$/, '') || path;
    }

    const bare = path.match(/^\/([a-z0-9-]+)\/?$/i);
    if (bare) {
        const slug = bare[1].toLowerCase();
        if (CMS_PATHS.has(slug)) {
            return `/${slug}`;
        }
        const fromTree = resolveCategoryLink(categories, slug.replace(/-/g, ' '), false);
        if (fromTree && fromTree !== '/catalog') {
            return fromTree;
        }
        const flat = flattenCategories(categories);
        const hit = flat.find((cat) => String(cat.slug || '').toLowerCase() === slug);
        if (hit) {
            return categoryPath(hit);
        }
        if (!hasCatalog) {
            return '#';
        }
    }

    if (!hasCatalog && isCatalogListingPath(path)) {
        return '#';
    }

    return path;
}

/** Rewrite theme HTML <a href> so empty-catalog CTAs stay on # and subfolder shops keep their base path. */
export function rewriteThemeHtmlHrefs(html, { hasCatalog = true, categories = [] } = {}) {
    return String(html || '').replace(/\bhref=(["'])([^"']*)\1/gi, (match, quote, href) => {
        const raw = String(href || '').trim();
        if (!raw || raw === '#' || raw.startsWith('mailto:') || raw.startsWith('tel:') || raw.startsWith('javascript:')) {
            return `href=${quote}#${quote}`;
        }
        if (/^https?:\/\//i.test(raw) || raw.startsWith('//')) {
            return match;
        }
        const next = resolveConfiguredLink(raw, { categories, hasCatalog, fallback: hasCatalog ? '/catalog' : '#' });
        if (!next || next === '#') {
            return `href=${quote}#${quote}`;
        }

        return `href=${quote}${webPath(next)}${quote}`;
    });
}
