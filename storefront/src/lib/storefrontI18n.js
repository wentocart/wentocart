import api, { csrf } from '../api.js';
import { setI18nMessages } from '../i18n.js';
import { setShopSession, shopSession } from '../state/shopSession.js';
import { shopBasePath } from '../webPath.js';

export function applyLocaleDirection(locale) {
    if (typeof document === 'undefined') {
        return;
    }
    const code = String(locale || 'en').toLowerCase();
    document.documentElement.lang = code;
    document.documentElement.dir = code.startsWith('ar') ? 'rtl' : 'ltr';
}

export async function switchStorefrontLocale(code) {
    const locale = String(code || '').trim();
    if (!locale || locale === shopSession().locale) {
        return;
    }
    let currency = '';
    try {
        await csrf();
        const switched = await api.post('/i18n/locale', { locale });
        currency = switched?.data?.currency || '';
        const { data } = await api.get(`/i18n/${encodeURIComponent(locale)}`);
        const messages = data?.messages || {};
        if (window.__WENTO__) {
            window.__WENTO__.i18n = {
                ...(window.__WENTO__.i18n || {}),
                locale,
                messages,
            };
        }
        setI18nMessages(messages);
        applyLocaleDirection(locale);
    } catch {
        return;
    }
    setShopSession(currency ? { locale, currency } : { locale });
    if (window.__WENTO__?.locale_url_mode === 'path') {
        window.location.href = pathModeLocaleUrl(locale);
    }
}

function hreflangRows() {
    const fromDom = [...document.querySelectorAll('link[rel="alternate"][hreflang]')].map((el) => ({
        lang: el.getAttribute('hreflang'),
        url: el.getAttribute('href'),
    })).filter((row) => row.lang && row.url);
    if (fromDom.length) {
        return fromDom;
    }

    return Array.isArray(window.__WENTO__?.hreflang) ? window.__WENTO__.hreflang : [];
}

function pathModeLocaleUrl(locale) {
    const wanted = String(locale || '').toLowerCase();
    const sibling = hreflangRows().find((row) => String(row.lang || '').toLowerCase() === wanted)?.url;
    if (sibling) {
        return sibling;
    }
    const prefix = String(window.__WENTO__?.locale_prefix || '').replace(/\/$/, '');
    let rest = window.location.pathname;
    if (prefix && rest.startsWith(prefix)) {
        rest = rest.slice(prefix.length) || '/';
    } else {
        rest = rest.replace(/^\/[a-z]{2}(?:-[A-Za-z]{2})?(?=\/|$)/i, '') || '/';
    }
    if (!rest.startsWith('/')) {
        rest = `/${rest}`;
    }

    return `${window.location.origin}${shopBasePath()}/${wanted}${rest === '/' ? '' : rest}${window.location.search}`;
}

export async function switchStorefrontCurrency(code) {
    const currency = String(code || '').trim().toUpperCase();
    if (!currency || currency === shopSession().currency) {
        return;
    }
    try {
        await csrf();
        await api.post('/i18n/currency', { currency });
    } catch {
        return;
    }
    setShopSession({ currency });
}
