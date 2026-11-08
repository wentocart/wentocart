import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { translatedName } from '../i18nName.js';
import { formatMoney } from '../lib/format.js';
import { pricesHidden, quantityRuleLine } from '../lib/commerce.js';
import { productPath } from '../lib/links.js';
import { useStore } from '../state/StoreState.jsx';
import ProductImage from './ProductImage.jsx';
import { Icon } from './Icons.jsx';

const DEFAULT_FREE_SHIP = 75;

function itemVariantLabel(item, hidePrices = false) {
    const parts = [];
    const lines = Array.isArray(item.customization) ? item.customization : [];
    lines.forEach((row) => {
        const display = row?.display || row?.value;
        if (display == null || display === '') {
            return;
        }
        parts.push(Array.isArray(display) ? display.join(', ') : String(display));
    });
    const plan = item.subscription_plan?.name || item.subscriptionPlan?.name;
    if (plan) {
        parts.push(plan);
    }
    if (item.booking_date) {
        const start = String(item.booking_date).slice(0, 10);
        const end = item.booking_end_date ? String(item.booking_end_date).slice(0, 10) : null;
        if (end) {
            parts.push(`${t('storefront.cart.rental_period', 'Rental')}: ${start} → ${end}`);
        } else {
            parts.push(`${t('storefront.cart.booking_date', 'Booking date')}: ${start}`);
        }
        if (item.rental_duration) {
            parts.push(`${t('storefront.cart.rental_duration', 'Duration')}: ${item.rental_duration}`);
        }
        const deposit = Number(item.rental_data?.deposit || 0);
        if (deposit > 0) {
            parts.push(`${t('storefront.cart.rental_deposit', 'Deposit')}: ${hidePrices ? '' : formatMoney(deposit)}`);
        }
    }
    return parts.filter(Boolean).join(' / ');
}

export default function MiniCart() {
    const store = useStore();
    const hidePrices = pricesHidden(store.shop, store.customer);
    const money = (amount, currency) => (hidePrices ? '' : formatMoney(amount, currency));
    const open = !!store.cartOpen;
    const [busyId, setBusyId] = useState(null);
    const items = store.cart?.cart?.items || [];
    const totals = store.cart?.totals || {};
    const count = store.cartCount || 0;
    const discount = Number(totals.discount || 0);
    const pricesIncludeTax = totals.prices_include_tax != null
        ? !!totals.prices_include_tax
        : (store.shop?.commerce?.pricing?.storefront_display || 'with_tax') !== 'without_tax';
    const subtotal = Number(
        pricesIncludeTax && totals.display_subtotal != null ? totals.display_subtotal : totals.subtotal || 0,
    );
    const shipping = Number(totals.shipping || 0);
    const taxAmount = Number(totals.tax || 0);
    const freeAt = Number(store.shop?.free_shipping_threshold) > 0
        ? Number(store.shop.free_shipping_threshold)
        : DEFAULT_FREE_SHIP;
    const progressBase = Math.max(0, subtotal - discount);
    const freeUnlocked = freeAt > 0 && progressBase >= freeAt;
    const lineMap = useMemo(() => {
        const map = {};
        (totals.lines || []).forEach((line) => {
            map[line.cart_item_id] = line;
        });
        return map;
    }, [totals.lines]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                store.closeCart?.();
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [open, store]);

    const remove = async (id) => {
        setBusyId(id);
        try {
            await csrf();
            await api.delete(`/cart/items/${id}`);
            await store.reloadCart();
        } finally {
            setBusyId(null);
        }
    };

    const update = async (id, qty) => {
        setBusyId(id);
        try {
            await csrf();
            const { data } = await api.patch(`/cart/items/${id}`, { qty });
            store.applyCart?.(data);
            // Drawer already shows qty rules and the updated value — skip snap toasts here.
        } catch (err) {
            store.ping?.(err?.response?.data?.message || t('storefront.cart.update_failed', 'Could not update this quantity.'), 'error');
        } finally {
            setBusyId(null);
        }
    };

    const couponLabel = totals.discount_code || totals.discount_name || null;

    return (
        <div className={`shop-minicart${open ? ' is-open' : ''}`} aria-hidden={!open}>
            <button
                type="button"
                className="shop-minicart-backdrop"
                aria-label={t('storefront.cart.close', 'Close cart')}
                tabIndex={open ? 0 : -1}
                onClick={() => store.closeCart?.()}
            />
            <aside
                className="shop-minicart-panel"
                role="dialog"
                aria-modal="true"
                aria-label={t('storefront.shell.my_cart', 'My Cart')}
            >
                <header className="shop-minicart-head">
                    <h2>
                        {t('storefront.shell.my_cart', 'My Cart')}
                        <span>({count})</span>
                    </h2>
                    <button
                        type="button"
                        className="shop-minicart-close"
                        aria-label={t('storefront.cart.close', 'Close cart')}
                        onClick={() => store.closeCart?.()}
                    >
                        <Icon name="close" className="h-5 w-5" />
                    </button>
                </header>

                <div className="shop-minicart-body">
                    {items.length === 0 ? (
                        <div className="shop-minicart-empty">
                            <Icon name="cart" className="h-10 w-10" />
                            <strong>{t('storefront.cart.empty', 'Your cart is empty')}</strong>
                            <p>{t('storefront.cart.empty_body', 'Browse the catalog and add something you like.')}</p>
                            <button type="button" className="shop-btn shop-btn-primary" onClick={() => store.closeCart?.()}>
                                {t('storefront.cart.continue', 'Continue shopping')}
                            </button>
                        </div>
                    ) : (
                        <ul className="shop-minicart-list">
                            {items.map((item) => {
                                const product = item.product || {};
                                const name = translatedName(product, store.shop?.locale, `Item ${item.product_id}`);
                                const line = lineMap[item.id];
                                const unit = line?.display_unit_price ?? line?.unit_price ?? item.unit_price;
                                const lineTotal = line?.display_line_total
                                    ?? line?.line_total
                                    ?? (Number(unit) * Number(item.qty));
                                const href = productPath(product);
                                const busy = busyId === item.id;
                                const variant = itemVariantLabel(item, hidePrices);
                                const qtyStep = Number(product.qty_step) > 0 ? Number(product.qty_step) : 1;
                                const minQty = Number(product.min_qty) > 0 ? Number(product.min_qty) : qtyStep;
                                const maxQty = product.max_qty != null && product.max_qty !== '' && Number(product.max_qty) > 0
                                    ? Number(product.max_qty)
                                    : null;

                                return (
                                    <li key={item.id} className={`shop-minicart-item${busy ? ' is-busy' : ''}`}>
                                        <Link
                                            to={href}
                                            className="shop-minicart-thumb"
                                            state={{ entityType: 'product' }}
                                            onClick={() => store.closeCart?.()}
                                        >
                                            <ProductImage product={{
                                                name,
                                                cover: product.cover_path || product.cover,
                                                cover_url: product.cover_url,
                                            }} />
                                        </Link>
                                        <div className="shop-minicart-item-body">
                                            <div className="shop-minicart-item-top">
                                                <div>
                                                    <Link to={href} state={{ entityType: 'product' }} onClick={() => store.closeCart?.()}>
                                                        {name}
                                                    </Link>
                                                    {variant ? <p className="shop-minicart-variant">{variant}</p> : null}
                                                </div>
                                                <button
                                                    type="button"
                                                    className="shop-minicart-remove"
                                                    disabled={busy}
                                                    aria-label={t('storefront.cart.remove', 'Remove')}
                                                    onClick={() => remove(item.id)}
                                                >
                                                    <Icon name="trash" className="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                            <p className="shop-minicart-price">{money(lineTotal)}</p>
                                            <div className="shop-minicart-qty" role="group" aria-label={t('storefront.cart.qty', 'Quantity')}>
                                                <button
                                                    type="button"
                                                    disabled={busy || item.qty <= minQty}
                                                    aria-label={t('storefront.cart.decrease', 'Decrease quantity')}
                                                    onClick={() => update(item.id, Number(item.qty) - qtyStep, product)}
                                                >
                                                    −
                                                </button>
                                                <span>{item.qty}</span>
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    aria-label={t('storefront.cart.increase', 'Increase quantity')}
                                                    onClick={() => update(item.id, Number(item.qty) + qtyStep)}
                                                >
                                                    +
                                                </button>
                                            </div>
                                            {quantityRuleLine(minQty, qtyStep, maxQty) ? (
                                                <p className="shop-qty-rules">{quantityRuleLine(minQty, qtyStep, maxQty)}</p>
                                            ) : null}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {items.length > 0 ? (
                    <footer className="shop-minicart-foot">
                        <div className="shop-minicart-rows">
                            <div>
                                <span>
                                    {t('storefront.cart.subtotal_items', 'Subtotal ({n} items)')
                                        .replace('{n}', String(count))}
                                </span>
                                <strong>{money(subtotal)}</strong>
                            </div>
                            {discount > 0 ? (
                                <div className="is-discount">
                                    <span>
                                        {t('storefront.cart.discount', 'Discount')}
                                        {couponLabel ? <em className="shop-minicart-coupon">{couponLabel}</em> : null}
                                    </span>
                                    <strong>-{money(discount)}</strong>
                                </div>
                            ) : null}
                            <div>
                                <span>{t('storefront.cart.shipping', 'Shipping')}</span>
                                <strong className={freeUnlocked ? 'is-free' : ''}>
                                    {freeUnlocked
                                        ? t('storefront.cart.shipping_free', 'Free')
                                        : (shipping > 0
                                            ? money(shipping)
                                            : t('storefront.checkout.calc_shipping', 'At checkout'))}
                                </strong>
                            </div>
                            {!pricesIncludeTax && taxAmount > 0 ? (
                                <div>
                                    <span>{t('storefront.cart.tax', 'Tax')}</span>
                                    <strong>{money(taxAmount)}</strong>
                                </div>
                            ) : null}
                            <div className="is-total">
                                <span>{t('storefront.cart.total', 'Total')}</span>
                                <strong>{money(totals.grand_total)}</strong>
                            </div>
                        </div>
                        <p className="shop-minicart-note">
                            {pricesIncludeTax
                                ? t('storefront.cart.taxes_included_note', 'Taxes included and shipping calculated at checkout.')
                                : t('storefront.cart.taxes_excluded_note', 'Tax and shipping calculated at checkout.')}
                        </p>
                        <div className="shop-minicart-actions">
                            <Link to="/cart" className="shop-minicart-view" onClick={() => store.closeCart?.()}>
                                <Icon name="cart" className="h-4 w-4" />
                                {t('storefront.cart.view_cart', 'View Cart')}
                            </Link>
                            <Link to="/checkout" className="shop-minicart-checkout" onClick={() => store.closeCart?.()}>
                                <Icon name="lock" className="h-4 w-4" />
                                {t('storefront.cart.checkout_securely', 'Checkout Securely')}
                            </Link>
                        </div>
                    </footer>
                ) : null}
            </aside>
        </div>
    );
}
