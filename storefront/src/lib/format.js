import { shopSession } from '../state/shopSession.js';
import { webPath } from '../webPath.js';

export function boot() {
    return window.__WENTO__ || {};
}

export function shopName() {
    return boot().shop || 'Store';
}

export function themeSettings() {
    return boot().theme?.settings || {};
}

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

export function formatMoney(amount, currency) {
    if (amount == null || amount === '') {
        return '';
    }
    const code = currency || shopSession().currency || boot().currency || 'USD';
    const fallback = (() => {
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).resolvedOptions().maximumFractionDigits;
        } catch {
            return 2;
        }
    })();
    const decimals = priceFormat.decimals == null ? fallback : priceFormat.decimals;
    const value = Number(amount);
    const rounded = Number.isFinite(value) ? roundMoney(value, decimals, priceFormat.rounding) : 0;
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: code,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(rounded);
    } catch {
        return `${code} ${rounded.toFixed(decimals)}`;
    }
}

export function saleUnitLabel(product) {
    if (!product?.sale_unit || product.sale_unit === 'piece') {
        return '';
    }

    return product.sale_unit_label || product.sale_unit_code || '';
}

export function productPrice(product) {
    if (product?.show_price === false) {
        return '';
    }
    const base = product?.price_formatted || formatMoney(product?.price);
    const unit = saleUnitLabel(product);

    return unit ? `${base} / ${unit}` : base;
}

export function mediaUrl(path) {
    if (!path) {
        return null;
    }
    const raw = String(path);
    if (raw.startsWith('blob:')) {
        return raw;
    }
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) {
        return webPath(raw);
    }

    return webPath(`/storage/${raw}`);
}

export const DEFAULT_IMAGES = {
    sm: '/images/default/p-sm-default.png',
    md: '/images/default/p-md-default.png',
    lg: '/images/default/p-lg-default.png',
};

/** True when the URL is empty or the shared catalog placeholder (not a real asset). */
export function isDefaultCatalogImage(path) {
    const raw = String(path || '').trim();
    if (!raw) {
        return true;
    }

    return /\/images\/default\//i.test(raw)
        || /p-(sm|md|lg)-default\.(png|jpe?g|webp|svg)(?:\?|#|$)/i.test(raw);
}

export function defaultImageUrl(size = 'md') {
    return webPath(DEFAULT_IMAGES[size] || DEFAULT_IMAGES.md);
}

export function mediaUrlOrDefault(path, size = 'md') {
    return mediaUrl(path) || defaultImageUrl(size);
}

export function discountPercent(product) {
    if (product?.discount_percent) {
        return product.discount_percent;
    }
    const price = Number(product?.price);
    const compare = Number(product?.compare_price);
    if (!price || !compare || compare <= price) {
        return null;
    }
    return Math.round((1 - price / compare) * 100);
}
