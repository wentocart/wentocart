export function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function emptyBrandTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        short_description: '',
        tagline: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
    };
}

export function buildBrandTranslations(brand, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyBrandTranslation(language.code);
    });

    const rows = brand?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyBrandTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    } else if (rows && typeof rows === 'object') {
        Object.entries(rows).forEach(([code, row]) => {
            out[code] = {
                ...emptyBrandTranslation(code),
                ...(row || {}),
                language_code: code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyBrandTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && brand) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: brand.name || '',
            slug: brand.slug || '',
            short_description: brand.short_description || '',
            tagline: brand.tagline || '',
            description: brand.description || '',
            meta_title: brand.meta_title || '',
            meta_description: brand.meta_description || '',
            meta_keywords: brand.meta_keywords || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
