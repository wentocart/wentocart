export function emptyAttributeValueTranslation(languageCode) {
    return {
        language_code: languageCode,
        value: '',
    };
}

export function buildAttributeValueTranslations(attributeValue, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyAttributeValueTranslation(language.code);
    });

    const rows = attributeValue?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyAttributeValueTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyAttributeValueTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && attributeValue) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            value: attributeValue.value || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
