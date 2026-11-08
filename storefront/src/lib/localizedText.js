/** Theme text may be a legacy string or a locale map { en: '…', fr: '…' }. */
export function pickLocalizedText(value, locale, fallback = '') {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        const code = String(locale || '').toLowerCase();
        const short = code.slice(0, 2);
        const direct = value[code] || value[short] || value[code.replace('-', '_')];
        if (typeof direct === 'string' && direct.trim() !== '') {
            return direct;
        }
        const first = Object.values(value).find((row) => typeof row === 'string' && row.trim() !== '');
        return first || fallback;
    }
    if (typeof value === 'string' && value.trim() !== '') {
        return value;
    }

    return fallback;
}
