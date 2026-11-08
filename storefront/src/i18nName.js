/** Pick catalog entity name for the active shop locale. */
export function translatedName(entity, locale, fallback = '') {
    const rows = entity?.translations;
    if (!Array.isArray(rows) || rows.length === 0) {
        return entity?.name || fallback;
    }
    const code = locale || window.__WENTO__?.locale || '';
    const hit = rows.find((row) => row.language_code === code)
        || rows.find((row) => row.language_code === (window.__WENTO__?.default_locale || 'en'))
        || rows[0];
    return hit?.name || entity?.name || fallback;
}
