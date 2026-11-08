let messages = {};

export function setI18nMessages(next) {
    messages = next && typeof next === 'object' ? next : {};
}

/**
 * UI copy. New screens must call t with a dotted key and an English literal
 * so International → Translations can pick the phrase up automatically.
 */
export function t(key, fallback, replacements) {
    const value = messages[key];
    let text = (value != null && String(value) !== '') ? String(value) : (fallback ?? key);
    if (replacements && typeof replacements === 'object') {
        Object.entries(replacements).forEach(([name, val]) => {
            text = text.replaceAll(`{${name}}`, String(val));
        });
    }
    return text;
}

export function useI18nFromPage(i18n) {
    setI18nMessages(i18n?.messages);
    return t;
}

/** Current language row, then default-language row, then the first row. */
export function pickTranslation(rows, i18n) {
    const list = Array.isArray(rows) ? rows : [];
    const current = i18n?.locale || i18n?.current;
    const fallback = i18n?.default;

    return list.find((row) => row.language_code === current)
        || list.find((row) => row.language_code === fallback)
        || list[0]
        || {};
}

/** Row used when creating or editing content (always the shop default). */
export function pickDefaultTranslation(rows, i18n) {
    const list = Array.isArray(rows) ? rows : [];

    return list.find((row) => row.language_code === i18n?.default) || list[0] || {};
}
