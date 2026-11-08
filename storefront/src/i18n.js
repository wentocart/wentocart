let messages = window.__WENTO__?.i18n?.messages || {};

export function setI18nMessages(next) {
    messages = next && typeof next === 'object' ? next : {};
}

/**
 * Storefront UI copy. New screens must call t with a dotted key and an English
 * literal so International → Translations picks the phrase up automatically.
 */
export function t(key, fallback) {
    const value = messages[key];
    if (value != null && String(value) !== '') {
        return String(value);
    }
    return fallback ?? key;
}

export function bootI18n() {
    setI18nMessages(window.__WENTO__?.i18n?.messages);
    return window.__WENTO__?.i18n || { locale: 'en', languages: [], messages: {} };
}
