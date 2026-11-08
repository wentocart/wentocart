export function emptyAttributeTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

export function buildAttributeTranslations(attribute, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyAttributeTranslation(language.code);
    });

    const rows = attribute?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyAttributeTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyAttributeTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && attribute) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: attribute.name || '',
            description: attribute.description || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
