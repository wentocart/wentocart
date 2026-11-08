/**
 * Storefront language and currency for this visit.
 * The server saves the choice in a cookie and puts it on window.__WENTO__
 * for the next full page load. While the shop stays open, every page reads
 * this session instead of the address bar.
 */
function bootValue(key, fallback) {
    const value = typeof window !== 'undefined' ? window.__WENTO__?.[key] : '';
    const text = String(value || '').trim();
    return text || fallback;
}

let state = {
    currency: bootValue('currency', 'USD').toUpperCase(),
    locale: bootValue('locale', 'en'),
};

const listeners = new Set();

export function shopSession() {
    return state;
}

export function setShopSession(patch) {
    const next = { ...state };
    if (patch.currency) {
        next.currency = String(patch.currency).trim().toUpperCase();
    }
    if (patch.locale) {
        next.locale = String(patch.locale).trim();
    }
    if (next.currency === state.currency && next.locale === state.locale) {
        return;
    }
    state = next;
    if (typeof window !== 'undefined' && window.__WENTO__) {
        window.__WENTO__.currency = state.currency;
        window.__WENTO__.locale = state.locale;
    }
    listeners.forEach((listener) => listener(state));
}

export function subscribeShopSession(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

function clearCurrencyQuery() {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('currency')) {
            return;
        }
        url.searchParams.delete('currency');
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    } catch {
        // Leave a broken address alone.
    }
}

clearCurrencyQuery();
