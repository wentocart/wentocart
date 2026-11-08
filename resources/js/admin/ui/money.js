/** Shared admin money formatter (Localization L2). */
let priceFormat = { decimals: null, rounding: 'none' };

export function setPriceFormat(pricing) {
    const decimals = Number(pricing?.decimal_precision);
    priceFormat = {
        decimals: Number.isFinite(decimals) ? Math.min(4, Math.max(0, decimals)) : null,
        rounding: ['none', 'nearest', 'up', 'down'].includes(pricing?.rounding) ? pricing.rounding : 'none',
    };
}

function roundMoney(amount, decimals, rounding) {
    const factor = 10 ** decimals;
    const scaled = amount * factor;
    if (rounding === 'up') {
        return Math.ceil(scaled - 1e-9) / factor;
    }
    if (rounding === 'down') {
        return Math.floor(scaled + 1e-9) / factor;
    }
    return Math.round(scaled) / factor;
}

export function formatMoney(amount, code = 'USD', options = {}) {
    const raw = Number(amount ?? 0);
    const currency = String(code || 'USD').toUpperCase();
    const decimals = options.decimals ?? priceFormat.decimals ?? (currency === 'JPY' ? 0 : 2);
    const rounding = options.rounding || priceFormat.rounding || 'none';
    const value = Number.isFinite(raw) ? roundMoney(raw, decimals, rounding) : 0;

    try {
        return new Intl.NumberFormat(
            currency === 'INR' ? 'en-IN' : 'en-US',
            {
                style: 'currency',
                currency,
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            },
        ).format(value);
    } catch {
        return `${currency} ${value.toFixed(decimals)}`;
    }
}

/** Alias used by pages that previously defined a local money(). */
export function money(amount, code = 'USD', options = {}) {
    return formatMoney(amount, code, options);
}
