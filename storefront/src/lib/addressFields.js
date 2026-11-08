import { t } from '../i18n.js';

export function countryRow(countries, code) {
    return (countries || []).find((row) => row.code === code) || null;
}

export function stateRequiredFor(country, settings = {}) {
    const mode = settings.state_mode || 'country_rules';
    const hasRegions = (country?.regions || []).length > 0;
    if (mode === 'optional') {
        return false;
    }
    if (mode === 'required') {
        return true;
    }
    return hasRegions;
}

export function postalMeta(country, settings = {}) {
    const mode = settings.postal_mode || 'country_rules';
    const postal = country?.postal || {};
    const pattern = postal.pattern || '';
    const example = postal.example || '';
    if (mode === 'optional') {
        return { required: false, pattern, example };
    }
    if (mode === 'required') {
        return { required: true, pattern, example };
    }
    return { required: postal.required !== false, pattern, example };
}

export function localAddressErrors(address, countries, settings = {}, requiredAddress = true) {
    if (!requiredAddress) {
        return {};
    }
    const country = countryRow(countries, address?.country_code);
    const errors = {};
    if (!String(address?.first_name || '').trim()) {
        errors.first_name = t('storefront.checkout.first_name_required', 'Enter a first name.');
    }
    if (!String(address?.line1 || '').trim()) {
        errors.line1 = t('storefront.checkout.line1_required', 'Enter a street address.');
    }
    if (!String(address?.city || '').trim()) {
        errors.city = t('storefront.checkout.city_required', 'Enter a city.');
    }
    if (stateRequiredFor(country, settings) && !String(address?.state || '').trim()) {
        errors.state = t('storefront.checkout.state_required', 'Select a state.');
    }
    const postal = postalMeta(country, settings);
    const code = String(address?.postal_code || '').trim();
    if (postal.required && !code) {
        errors.postal_code = t('storefront.checkout.postal_required', 'Enter a postal code.');
    } else if (code && postal.pattern) {
        let ok = true;
        try {
            ok = new RegExp(postal.pattern, 'i').test(code);
        } catch {
            ok = true;
        }
        if (!ok) {
            errors.postal_code = postal.example
                ? t('storefront.checkout.postal_example', 'Enter a valid postal code, for example {example}.').replace('{example}', postal.example)
                : t('storefront.checkout.postal_invalid', 'Enter a valid postal code for this country.');
        }
    }
    return errors;
}

export function hasFieldErrors(bag) {
    return !!bag && Object.values(bag).some(Boolean);
}

export function apiAddressErrors(payload) {
    const bag = payload?.errors && typeof payload.errors === 'object' ? payload.errors : {};
    const read = (key) => {
        const value = bag[key];
        if (Array.isArray(value)) {
            return value.find(Boolean) || '';
        }
        return value || '';
    };
    const take = (prefix) => {
        const row = {};
        ['first_name', 'line1', 'city', 'state', 'postal_code'].forEach((field) => {
            const message = read(prefix ? `${prefix}.${field}` : field);
            if (message) {
                row[field] = message;
            }
        });
        return row;
    };
    const known = new Set();
    ['shipping_address', 'billing_address', ''].forEach((prefix) => {
        ['first_name', 'line1', 'city', 'state', 'postal_code'].forEach((field) => {
            known.add(prefix ? `${prefix}.${field}` : field);
        });
    });
    const rest = Object.entries(bag)
        .filter(([key]) => !known.has(key))
        .flatMap(([, value]) => (Array.isArray(value) ? value : [value]))
        .filter(Boolean);

    return {
        shipping: take('shipping_address'),
        billing: take('billing_address'),
        account: take(''),
        rest,
    };
}
