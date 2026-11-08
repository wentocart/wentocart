export function emptyShopTranslation(languageCode) {
    return {
        language_code: languageCode,
        description: '',
        store_policy: '',
        shipping_policy: '',
        return_policy: '',
        vacation_message: '',
    };
}

export function buildShopTranslations(seller, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyShopTranslation(language.code);
    });

    const rows = seller?.translations;
    if (Array.isArray(rows)) {
        rows.forEach((row) => {
            if (!row?.language_code) {
                return;
            }
            out[row.language_code] = {
                ...emptyShopTranslation(row.language_code),
                ...row,
                language_code: row.language_code,
            };
        });
    } else if (rows && typeof rows === 'object') {
        Object.entries(rows).forEach(([code, row]) => {
            out[code] = {
                ...emptyShopTranslation(code),
                ...(row || {}),
                language_code: code,
            };
        });
    }

    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyShopTranslation(defaultLanguage);
    }

    if ((!rows || (Array.isArray(rows) && rows.length === 0)) && seller) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            description: seller.description || '',
            store_policy: seller.store_policy || '',
            shipping_policy: seller.shipping_policy || '',
            return_policy: seller.return_policy || '',
            vacation_message: seller.vacation_message || '',
        };
    }

    return out;
}

export function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}
