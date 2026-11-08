import { t } from '../i18n.js';

export function shopCommerce(shop) {
    return shop?.commerce || {};
}

export function isCatalogMode(shop) {
    return shop?.products?.catalog_mode === true;
}

/**
 * Catalog mode with "show price" off hides prices for everyone.
 * Hide prices from guests hides them only while nobody is signed in.
 */
export function pricesHidden(shop, customer) {
    const products = shop?.products || {};
    if (products.catalog_mode === true && products.show_price === false) {
        return true;
    }
    if (products.hide_prices_guests === true && !customer) {
        return true;
    }

    return false;
}

/** Guest-only hide: prompt to sign in. Catalog mode stays blank for every visitor. */
export function guestPricePrompt(shop, customer) {
    const products = shop?.products || {};
    if (products.catalog_mode === true && products.show_price === false) {
        return false;
    }

    return products.hide_prices_guests === true && !customer;
}

export function wishlistEnabled(shop) {
    return shop?.commerce?.search?.wishlist_enabled !== false;
}

/** Product Settings → Product comparison (side-by-side compare page + header icon). */
export function compareEnabled(shop) {
    return shop?.products?.compare_enabled !== false;
}

export function reviewsEnabled(shop) {
    return shop?.commerce?.reviews?.enabled !== false;
}

export function merchantRepliesEnabled(shop) {
    return shop?.commerce?.reviews?.merchant_replies !== false;
}

export function reviewsWho(shop) {
    return shop?.commerce?.reviews?.who || 'registered';
}

export function reviewsMedia(shop) {
    return shop?.commerce?.reviews?.media || 'images';
}

export function reviewsMinLength(shop) {
    return Number(shop?.commerce?.reviews?.min_length ?? 500);
}

/**
 * Format a product/shop rating for FO display.
 * Whole numbers: "5". Decimals only when needed: "4.5" (no trailing zeros).
 */
export function formatRating(value) {
    if (value == null || value === '') {
        return null;
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return null;
    }
    const rounded = Math.round(n * 10) / 10;
    if (Number.isInteger(rounded)) {
        return String(rounded);
    }
    return String(rounded);
}

/** True when the API returned a real product rating from approved reviews. */
export function hasProductRating(product) {
    const average = product?.rating?.average;
    const count = Number(product?.rating?.count || 0);
    if (average == null || average === '') {
        return false;
    }
    const n = Number(average);
    return Number.isFinite(n) && n > 0 && count > 0;
}

export function couponsEnabled(shop) {
    return shop?.commerce?.cart?.coupons_enabled !== false;
}

export function suggestionsEnabled(shop) {
    return shop?.commerce?.search?.suggestions !== false;
}

export function recentlyViewedEnabled(shop) {
    return shop?.commerce?.search?.recently_viewed !== false;
}

export function termsRequired(shop) {
    return !!shop?.commerce?.cart?.terms_required;
}

export function returnsEnabled(shop) {
    return shop?.commerce?.returns?.enabled !== false;
}

export function returnWindowDays(shop) {
    return Number(shop?.commerce?.returns?.window_days ?? 14);
}

export function saleEnabled(shop) {
    return shop?.commerce?.pricing?.sale_enabled !== false;
}

export function compareAtEnabled(shop) {
    return shop?.commerce?.pricing?.compare_at_enabled !== false;
}

export function zeroPriceMode(shop) {
    return shop?.commerce?.pricing?.zero_price || 'allow';
}

export function invoicesEnabled(shop) {
    return shop?.commerce?.documents?.invoices_enabled !== false;
}

export function contactFormEnabled(shop) {
    return shop?.features?.contact_form !== false;
}

export function guestLookupEnabled(shop) {
    return shop?.features?.guest_lookup === true;
}

export function productVideoEnabled(shop) {
    return shop?.features?.product_video !== false;
}

/** Product Settings → Out of stock notification (customer stock alerts). */
export function outOfStockNotificationEnabled(shop) {
    return shop?.products?.out_of_stock_notification === true;
}

/**
 * Storefront sellable check (mirrors server stock rules for simple products / variants).
 * stock_status alone is not enough — qty can be 0 while status is still "in_stock".
 */
export function productHasSellableStock(item, shop) {
    if (!item) {
        return false;
    }
    const inventory = shop?.commerce?.inventory;
    if (inventory?.enabled === false || inventory?.allow_negative === true) {
        return true;
    }
    if (item.can_add_to_cart === false) {
        return false;
    }
    const status = String(item.stock_status || '').toLowerCase();
    if (status === 'out_of_stock') {
        return false;
    }
    if (item.manage_stock === false) {
        return true;
    }
    if (item.stock_qty != null && item.stock_qty !== '') {
        if ((Number(item.stock_qty) || 0) > 0.009) {
            return true;
        }
        return status === 'on_backorder' || !!item.allow_backorder
            || item.backorder_status === 'allow';
    }
    return status !== 'out_of_stock';
}

/** Whether the storefront should enable Add to cart (vs show a disabled control). */
export function productCanAddToCart(item, shop) {
    if (!item || item.available_for_order === false || item.contact_for_price) {
        return false;
    }
    if (item.can_add_to_cart === false) {
        return false;
    }
    return productHasSellableStock(item, shop);
}

/** Align a typed quantity to the product min, step, and max. */
export function snapOrderQty(value, minQty, stepQty, maxQty) {
    const min = Number(minQty) > 0 ? Number(minQty) : 1;
    const step = Number(stepQty) > 0 ? Number(stepQty) : 1;
    const max = maxQty != null && maxQty !== '' && Number(maxQty) > 0 ? Number(maxQty) : null;
    let qty = Number(value);
    if (!Number.isFinite(qty)) {
        qty = min;
    }
    qty = Math.max(min, qty);
    const steps = Math.ceil(Math.round(((qty - min) / step) * 1e6) / 1e6);
    qty = min + (Math.max(0, steps) * step);
    if (max != null && qty > max) {
        const down = Math.floor(((max - min) / step) + 1e-9);
        qty = min + (Math.max(0, down) * step);
    }

    return +qty.toFixed(3);
}

export function formatOrderQty(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '';
    }
    const rounded = Math.round(number * 1000) / 1000;
    return Number.isInteger(rounded) ? String(rounded) : String(rounded);
}

function fillQty(text, values) {
    return Object.entries(values).reduce(
        (line, [key, value]) => line.split(`{${key}}`).join(value ?? ''),
        text,
    );
}

/** Customer-facing explanation when the cart quantity was changed to fit the rules. */
export function quantityAdjustmentMessage(notice) {
    if (!notice?.code) {
        return '';
    }
    const values = {
        min: formatOrderQty(notice.min),
        step: formatOrderQty(notice.step),
        max: formatOrderQty(notice.max),
        before: formatOrderQty(notice.before),
        after: formatOrderQty(notice.after),
    };
    if (notice.code === 'at_max') {
        return fillQty(t(
            'storefront.qty.at_max',
            'Maximum quantity is {max}. This product is already at that limit in your cart, so no more was added.',
        ), values);
    }
    if (notice.code === 'no_increase') {
        return fillQty(t(
            'storefront.qty.no_increase',
            'No more can be added. With minimum {min} and increments of {step}, your cart quantity stays at {after}.',
        ), values);
    }
    if (notice.code === 'capped' && Number(notice.before) > 0) {
        return fillQty(t(
            'storefront.qty.capped',
            'You already have {before} in your cart. The maximum is {max}, so the quantity is now {after}.',
        ), values);
    }
    if (notice.code === 'capped') {
        return fillQty(t(
            'storefront.qty.capped_new',
            'Maximum quantity is {max}, so {after} were added.',
        ), values);
    }
    if (notice.code === 'minimum') {
        return fillQty(t(
            'storefront.qty.minimum',
            'Minimum quantity is {min}, so the quantity is now {after}.',
        ), values);
    }

    return fillQty(t(
        'storefront.qty.step',
        'Quantity changes in steps of {step}. The quantity is now {after}.',
    ), values);
}

export function quantityNoticesFrom(data) {
    const list = Array.isArray(data?.quantity_notices) ? data.quantity_notices : [];
    return list.map(quantityAdjustmentMessage).filter(Boolean);
}

/**
 * True when the add did not increase cart qty (at max / no valid step left).
 * Soft snaps (step / minimum / capped with a higher after) still count as added.
 */
export function cartAddWasBlocked(data) {
    const list = Array.isArray(data?.quantity_notices) ? data.quantity_notices : [];

    return list.some((notice) => {
        const code = String(notice?.code || '');
        if (code === 'at_max' || code === 'no_increase') {
            return true;
        }
        const before = Number(notice?.before);
        const after = Number(notice?.after);
        if (Number.isFinite(before) && Number.isFinite(after)) {
            return after <= before + 1e-9;
        }

        return false;
    });
}

/**
 * After a cart add API response (HTTP success).
 * Opens the mini-cart when qty was added (including soft step/min/cap snaps).
 * Soft snaps do not growl — the drawer is enough. Blocked adds (no increase /
 * already at max) toast a warning and leave the drawer closed.
 * Clean adds open the drawer without a success toast.
 *
 * @param {{
 *   ping?: (message: string, type?: string) => void,
 *   openCart?: () => void,
 *   data?: object,
 *   open?: boolean,
 *   notify?: boolean,
 * }} opts
 * @returns {{ opened: boolean, notices: string[], blocked: boolean }}
 */
export function completeCartAdd({ ping, openCart, data, open = true, notify } = {}) {
    const notices = quantityNoticesFrom(data);
    const blocked = cartAddWasBlocked(data);
    const opened = open !== false && !blocked;
    const allowNotify = notify !== false;

    if (opened) {
        openCart?.();
    }

    if (allowNotify) {
        if (blocked && notices.length) {
            notices.forEach((message) => ping?.(message, 'warning'));
        } else if (!opened) {
            ping?.(t('storefront.pdp.added', 'Added to cart'));
        }
    }

    return { opened, notices, blocked };
}

/** Largest qty on the min/step grid that does not exceed max (null max → null). */
export function maxOrderQtyOnGrid(minQty, stepQty, maxQty) {
    const min = Number(minQty) > 0 ? Number(minQty) : 1;
    const step = Number(stepQty) > 0 ? Number(stepQty) : 1;
    const max = maxQty != null && maxQty !== '' && Number(maxQty) > 0 ? Number(maxQty) : null;
    if (max == null) {
        return null;
    }
    const down = Math.floor(((max - min) / step) + 1e-9);

    return +(min + (Math.max(0, down) * step)).toFixed(3);
}

export function quantityRuleLine(minQty, stepQty, maxQty) {
    const min = Number(minQty) > 0 ? Number(minQty) : 1;
    const step = Number(stepQty) > 0 ? Number(stepQty) : 1;
    const max = maxQty != null && maxQty !== '' && Number(maxQty) > 0 ? Number(maxQty) : null;
    if (min <= 1 && step <= 1 && max == null) {
        return '';
    }
    const parts = [
        fillQty(t('storefront.qty.rule_min', 'Minimum {min}'), { min: formatOrderQty(min) }),
        fillQty(t('storefront.qty.rule_step', 'Increments of {step}'), { step: formatOrderQty(step) }),
    ];
    if (max != null) {
        parts.push(fillQty(t('storefront.qty.rule_max', 'Maximum {max}'), { max: formatOrderQty(max) }));
    }

    return parts.join(' · ');
}

export function quantityCartLine(inCart, maxQty, minQty = 1, stepQty = 1) {
    const qty = Number(inCart) || 0;
    if (qty <= 0) {
        return '';
    }
    const ceiling = maxOrderQtyOnGrid(minQty, stepQty, maxQty);
    if (ceiling != null && qty + 0.0001 >= ceiling) {
        return fillQty(t(
            'storefront.qty.in_cart_max',
            '{qty} already in your cart, which is the maximum. More cannot be added.',
        ), { qty: formatOrderQty(qty) });
    }
    if (ceiling != null) {
        return fillQty(t(
            'storefront.qty.in_cart_room',
            '{qty} already in your cart. You can add {room} more (maximum {max}).',
        ), {
            qty: formatOrderQty(qty),
            room: formatOrderQty(Math.max(0, ceiling - qty)),
            max: formatOrderQty(ceiling),
        });
    }

    return fillQty(t('storefront.qty.in_cart', '{qty} already in your cart.'), { qty: formatOrderQty(qty) });
}

export function taxDisplay(shop) {
    return shop?.commerce?.pricing?.storefront_display || 'with_tax';
}

/** True when main storefront prices should include tax (with_tax or both). */
export function pricesIncludeTax(shop) {
    return taxDisplay(shop) !== 'without_tax';
}

/**
 * Map a catalog-input amount into the storefront display space using the product's
 * excl/incl pair from the catalog API (same tax class / shop-country rules).
 */
export function toStorefrontAmount(catalogAmount, product, shop) {
    const amount = Number(catalogAmount);
    if (!Number.isFinite(amount)) {
        return 0;
    }
    const excl = Number(product?.price_excl);
    const incl = Number(product?.price_incl);
    const catalog = Number(product?.price_catalog ?? product?.price_excl ?? product?.price);
    const mode = taxDisplay(shop);
    if (!Number.isFinite(excl) || !Number.isFinite(incl) || !Number.isFinite(catalog) || catalog <= 0) {
        return amount;
    }
    if (Math.abs(excl - incl) < 0.00001) {
        return amount;
    }
    if (mode === 'without_tax') {
        return amount * (excl / catalog);
    }

    return amount * (incl / catalog);
}

export function canWriteReview(shop, customer, eligibility = null) {
    if (!reviewsEnabled(shop)) {
        return false;
    }
    if (!customer) {
        return reviewsWho(shop) === 'everyone';
    }
    const who = reviewsWho(shop);
    if (who === 'verified') {
        if (eligibility && typeof eligibility.allowed === 'boolean') {
            return eligibility.allowed;
        }
        // Until eligibility loads, allow showing the form; API enforces verified buyers.
        return true;
    }
    return true;
}

export function reviewLoginRequired(shop, customer) {
    return reviewsEnabled(shop) && !customer && reviewsWho(shop) !== 'everyone';
}

/** Fallback mirrors SearchCatalogSettings::SORT_OPTIONS when shop bootstrap omits sort_options. */
export const CATALOG_SORT_OPTIONS = [
    { value: 'relevance', labelKey: 'relevance', label: 'Relevance' },
    { value: 'popularity', labelKey: 'popularity', label: 'Popularity' },
    { value: 'newest', labelKey: 'newest', label: 'Newest' },
    { value: 'price_asc', labelKey: 'price_asc', label: 'Price: Low to High' },
    { value: 'price_desc', labelKey: 'price_desc', label: 'Price: High to Low' },
    { value: 'rating', labelKey: 'rating', label: 'Rating' },
    { value: 'discount', labelKey: 'discount', label: 'Biggest Discount' },
];

export function catalogSortOptions(shop, { hidePrices = false } = {}) {
    const fromShop = shop?.commerce?.search?.sort_options;
    const rows = Array.isArray(fromShop) && fromShop.length
        ? fromShop.map((row) => ({
            value: String(row.value || ''),
            labelKey: String(row.value || ''),
            label: String(row.label || row.value || ''),
        })).filter((row) => row.value)
        : CATALOG_SORT_OPTIONS.slice();

    const seen = new Set();
    const unique = rows.filter((row) => {
        if (seen.has(row.value)) {
            return false;
        }
        seen.add(row.value);
        return true;
    });

    if (!hidePrices) {
        return unique;
    }

    return unique.filter((row) => !String(row.value).startsWith('price'));
}

export function defaultCatalogSort(shop) {
    const raw = String(shop?.commerce?.search?.default_sort || 'popularity');
    const allowed = new Set(catalogSortOptions(shop).map((row) => row.value));

    return allowed.has(raw) ? raw : 'popularity';
}

export function isZeroPrice(product) {
    const amount = Number(product?.price ?? product?.selling_price ?? 0);
    return Number.isFinite(amount) && amount <= 0;
}
