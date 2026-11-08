const KEY = 'wento.recently_viewed';
const COMPARE_KEY = 'wento.compare';

let installNamespace = '';

export function setInstallNamespace(id) {
    installNamespace = String(id || '').trim();
}

function compareStorageKey() {
    return installNamespace ? `${COMPARE_KEY}.${installNamespace}` : COMPARE_KEY;
}

export function readList(key, fallback = []) {
    try {
        const raw = localStorage.getItem(key);
        const parsed = raw ? JSON.parse(raw) : fallback;
        return Array.isArray(parsed) ? parsed : fallback;
    } catch {
        return fallback;
    }
}

export function writeList(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Ignore quota.
    }
}

export function recentlyViewed() {
    return readList(KEY);
}

export function rememberProduct(product) {
    if (!product?.id) {
        return;
    }
    const next = [product, ...recentlyViewed().filter((row) => row.id !== product.id)].slice(0, 8);
    writeList(KEY, next.map((row) => ({
        id: row.id,
        name: row.name,
        url: row.url,
        cover: row.cover,
        cover_url: row.cover_url,
        price: row.price,
        price_formatted: row.price_formatted,
        brand: row.brand,
    })));
}

function compareId(value) {
    const raw = value && typeof value === 'object' ? value.id : value;
    const id = Number(raw);
    return Number.isInteger(id) && id > 0 ? id : 0;
}

export function compareIds() {
    return [...new Set(readList(compareStorageKey()).map(compareId).filter(Boolean))];
}

/**
 * Toggle a product in the compare list.
 * When at capacity, does not add or remove anything (returns action: 'limit').
 *
 * @returns {{ ids: number[], action: 'added'|'removed'|'limit'|'noop', limit?: number }}
 */
export function toggleCompare(id, max = 4) {
    const numeric = compareId(id);
    const limit = Math.min(4, Math.max(2, Number(max) || 4));
    const ids = compareIds();
    if (!numeric) {
        return { ids, action: 'noop', limit };
    }
    if (ids.includes(numeric)) {
        const next = ids.filter((row) => row !== numeric);
        writeList(compareStorageKey(), next);
        return { ids: next, action: 'removed', limit };
    }
    if (ids.length >= limit) {
        return { ids, action: 'limit', limit };
    }
    const next = [...ids, numeric];
    writeList(compareStorageKey(), next);
    return { ids: next, action: 'added', limit };
}
