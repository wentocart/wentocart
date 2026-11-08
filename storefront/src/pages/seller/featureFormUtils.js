export function emptyFeatureTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

export function buildFeatureTranslations(feature, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyFeatureTranslation(language.code);
    });

    const rows = feature?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyFeatureTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyFeatureTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && feature) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: feature.name || '',
            description: feature.description || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
