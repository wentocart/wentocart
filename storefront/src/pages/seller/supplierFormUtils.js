export function emptySupplierTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

export function buildSupplierTranslations(supplier, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptySupplierTranslation(language.code);
    });

    const rows = supplier?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptySupplierTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptySupplierTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && supplier) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: supplier.name || '',
            description: supplier.description || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
