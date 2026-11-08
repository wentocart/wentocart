export function emptyFeatureValueTranslation(languageCode) {
    return {
        language_code: languageCode,
        value: '',
    };
}

export function buildFeatureValueTranslations(featureValue, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyFeatureValueTranslation(language.code);
    });

    const rows = featureValue?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyFeatureValueTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyFeatureValueTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && featureValue) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            value: featureValue.value || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
