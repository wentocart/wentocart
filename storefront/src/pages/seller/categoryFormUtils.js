export const CATEGORY_TRANSLATABLE_FIELDS = [
    'name',
    'slug',
    'description',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'canonical_url',
];

export function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function seoPreviewUrl(canonical, shopUrl, slug) {
    if (canonical) {
        return canonical;
    }
    const base = (shopUrl || '').replace(/\/$/, '');
    return `${base}/${slug || 'category-slug'}`;
}

export function emptyCategoryTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
        canonical_url: '',
    };
}

export function buildCategoryTranslations(category, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyCategoryTranslation(language.code);
    });

    const rows = category?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyCategoryTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    } else if (rows && typeof rows === 'object') {
        Object.entries(rows).forEach(([code, row]) => {
            out[code] = {
                ...emptyCategoryTranslation(code),
                ...(row || {}),
                language_code: code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyCategoryTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && category) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: category.name || '',
            slug: category.slug || '',
            description: category.description || '',
            meta_title: category.meta_title || '',
            meta_description: category.meta_description || '',
            meta_keywords: category.meta_keywords || '',
            canonical_url: category.canonical_url || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
