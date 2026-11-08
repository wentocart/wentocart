import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { translatedName } from '../i18nName.js';
import { useStore } from '../state/StoreState.jsx';
import { formatMoney, saleUnitLabel, shopName } from '../lib/format.js';
import { productPath } from '../lib/links.js';
import { CartPageSkeleton, EmptyState } from '../components/Feedback.jsx';
import ProductImage from '../components/ProductImage.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { Icon } from '../components/Icons.jsx';
import { classicPdpUi } from '../classicAssets.js';
import { couponsEnabled, isCatalogMode, pricesHidden, quantityNoticesFrom, quantityRuleLine } from '../lib/commerce.js';

const DEFAULT_FREE_SHIP = 75;

function itemOptions(item, hidePrices = false) {
    const priced = (amount) => (hidePrices ? '' : formatMoney(amount));
    const lines = Array.isArray(item.customization) ? item.customization : [];
    const options = lines
        .map((row) => {
            const display = row?.display ?? row?.value;
            if (display == null || display === '') {
                return null;
            }
            const value = Array.isArray(display) ? display.join(', ') : String(display);
            return { label: row.label || '', value };
        })
        .filter(Boolean);
    if (item.booking_date) {
        const start = String(item.booking_date).slice(0, 10);
        const end = item.booking_end_date ? String(item.booking_end_date).slice(0, 10) : null;
        options.push({
            label: end ? t('storefront.cart.rental_period', 'Rental period') : t('storefront.cart.booking_date', 'Booking date'),
            value: end ? `${start} → ${end}` : start,
        });
        if (item.rental_duration) {
            options.push({ label: t('storefront.cart.rental_duration', 'Duration'), value: String(item.rental_duration) });
        }
        const deposit = Number(item.rental_data?.deposit || 0);
        if (deposit > 0) {
            options.push({ label: t('storefront.cart.rental_deposit', 'Deposit'), value: priced(deposit) });
        }
        const cleaning = Number(item.rental_data?.cleaning_fee || 0);
        if (cleaning > 0) {
            options.push({ label: t('storefront.cart.rental_cleaning', 'Cleaning fee'), value: priced(cleaning) });
        }
    }
    if (item.preorder_data?.final_payment || item.preorder_data?.kind === 'balance_payment') {
        options.push({
            label: t('storefront.cart.preorder_final_payment', 'Pre-order final payment'),
            value: item.preorder_data.source_order_number
                ? `#${item.preorder_data.source_order_number}`
                : t('storefront.cart.preorder_yes', 'Yes'),
        });
        const due = Number(item.preorder_data.remaining_balance || item.unit_price || 0);
        if (due > 0) {
            options.push({
                label: t('storefront.cart.preorder_amount_due', 'Amount due'),
                value: priced(due),
            });
        }
        const paid = Number(item.preorder_data.already_paid || 0);
        if (paid > 0) {
            options.push({
                label: t('storefront.account.preorder_paid', 'Paid'),
                value: priced(paid),
            });
        }
    } else if (item.preorder_data?.enabled) {
        options.push({
            label: t('storefront.cart.preorder', 'Pre-order'),
            value: item.preorder_data.status
                ? String(item.preorder_data.status).replace(/_/g, ' ')
                : t('storefront.cart.preorder_yes', 'Yes'),
        });
        if (item.preorder_data.release_at) {
            options.push({
                label: t('storefront.cart.preorder_release', 'Expected release'),
                value: String(item.preorder_data.release_at).slice(0, 10),
            });
        }
        const remaining = Number(item.preorder_data.remaining_balance || 0);
        if (remaining > 0) {
            options.push({
                label: t('storefront.cart.preorder_remaining', 'Remaining balance'),
                value: priced(remaining),
            });
        }
    }
    return options;
}

export default function Cart() {
    const store = useStore();
    const hidePrices = pricesHidden(store.shop, store.customer);
    const money = (amount, currency) => (hidePrices ? '' : formatMoney(amount, currency));
    const [code, setCode] = useState('');
    const [giftCode, setGiftCode] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [couponBusy, setCouponBusy] = useState(false);
    const [giftBusy, setGiftBusy] = useState(false);
    const data = store.cart;

    useEffect(() => { store.reloadCart(); }, []);

    const items = data?.cart?.items || [];
    const sellers = data?.cart?.sellers || data?.sellers || [];
    const totals = data?.totals || {};
    const adjustments = totals.adjustments || [];
    const modules = data?.modules || {};
    const lineMap = useMemo(() => {
        const map = {};
        (totals.lines || []).forEach((line) => {
            map[line.cart_item_id] = line;
        });
        return map;
    }, [totals.lines]);

    const groups = useMemo(() => {
        if (!items.length) {
            return [];
        }
        if (sellers.length > 0) {
            const byId = Object.fromEntries(items.map((item) => [item.id, item]));
            return sellers.map((group) => ({
                ...group,
                lines: (group.item_ids || []).map((id) => byId[id]).filter(Boolean),
            })).filter((group) => group.lines.length > 0);
        }
        return [{
            seller_id: null,
            shop_name: shopName() || t('storefront.cart.store', 'Store'),
            is_marketplace: false,
            lines: items,
            subtotal: totals.subtotal ?? null,
        }];
    }, [items, sellers, totals.subtotal]);

    if (!data) {
        return <CartPageSkeleton />;
    }

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
            quantityNoticesFrom(data).forEach((message) => store.ping?.(message, 'warning'));
        } catch (err) {
            store.ping?.(err?.response?.data?.message || t('storefront.cart.update_failed', 'Could not update this quantity.'), 'error');
        } finally {
            setBusyId(null);
        }
    };

    const applyCoupon = async (e) => {
        e.preventDefault();
        if (!code.trim() || couponBusy) {
            return;
        }
        setCouponBusy(true);
        await csrf();
        try {
            await api.post('/cart/coupon', { code: code.trim() });
            await store.reloadCart();
            store.ping?.(t('storefront.cart.coupon_applied', 'Coupon applied'));
            setCode('');
        } catch {
            store.ping?.(t('storefront.cart.coupon_invalid', 'This coupon cannot be applied.'), 'error');
        } finally {
            setCouponBusy(false);
        }
    };

    const applyGift = async (e) => {
        e.preventDefault();
        if (!giftCode.trim() || giftBusy) {
            return;
        }
        setGiftBusy(true);
        await csrf();
        try {
            await api.post('/cart/gift-card', { code: giftCode.trim() });
            await store.reloadCart();
            store.ping?.(t('storefront.cart.gift_applied', 'Gift card applied'));
            setGiftCode('');
        } catch (err) {
            const message = err?.response?.data?.message
                || err?.response?.data?.errors?.gift_card?.[0]
                || err?.response?.data?.errors?.gift_card_code?.[0]
                || t('storefront.cart.gift_invalid', 'This gift card cannot be applied.');
            store.ping?.(message, 'error');
        } finally {
            setGiftBusy(false);
        }
    };

    const removeGift = async () => {
        if (giftBusy) {
            return;
        }
        setGiftBusy(true);
        await csrf();
        try {
            await api.delete('/cart/gift-card');
            await store.reloadCart();
            store.ping?.(t('storefront.cart.gift_removed', 'Gift card removed'));
        } catch (err) {
            const message = err?.response?.data?.message
                || t('storefront.cart.gift_remove_failed', 'Could not remove the gift card.');
            store.ping?.(message, 'error');
        } finally {
            setGiftBusy(false);
        }
    };

    const discount = Number(totals.discount || 0);
    const pricesIncludeTax = totals.prices_include_tax != null
        ? !!totals.prices_include_tax
        : (store.shop?.commerce?.pricing?.storefront_display || 'with_tax') !== 'without_tax';
    const subtotal = Number(
        pricesIncludeTax && totals.display_subtotal != null ? totals.display_subtotal : totals.subtotal || 0,
    );
    const shipping = Number(totals.shipping || 0);
    const points = Math.max(0, Math.round(Number(totals.grand_total || 0)));
    const couponLabel = totals.discount_code || totals.discount_name || null;
    const freeAt = Number(store.shop?.free_shipping_threshold) > 0
        ? Number(store.shop.free_shipping_threshold)
        : DEFAULT_FREE_SHIP;
    const progressBase = Math.max(0, subtotal - discount);
    const freeUnlocked = progressBase >= freeAt && progressBase > 0;

    return (
        <section className="shop-cart-stage">
            <div className="shop-container shop-cart-layout">
                <div className="shop-cart-main">
                    <header className="shop-cart-page-head">
                        <h1>{t('storefront.cart.title', 'Your Cart')}</h1>
                        <Breadcrumbs items={[
                            { name: t('storefront.shell.home', 'Home'), url: '/' },
                            { name: t('storefront.shell.cart', 'Cart'), url: null },
                        ]} />
                    </header>

                    {items.length === 0 ? (
                        <div className="shop-cart-empty">
                            <EmptyState
                                title={t('storefront.cart.empty', 'Your cart is empty')}
                                body={t('storefront.cart.empty_body', 'Browse the catalog and add something you like.')}
                                action={(
                                    <Link to="/catalog" className="shop-btn shop-btn-primary">
                                        {t('storefront.cart.continue', 'Continue shopping')}
                                    </Link>
                                )}
                            />
                        </div>
                    ) : (
                        <div className="shop-cart-groups">
                            {groups.map((group) => (
                                <section key={group.seller_id ?? 'store'} className="shop-cart-group">
                                    {group.is_marketplace || groups.length > 1 ? (
                                        <header className="shop-cart-group-head">
                                            <div>
                                                <h2>
                                                    {group.is_marketplace
                                                        ? t('storefront.cart.sold_by', 'Sold by {name}').replace('{name}', group.shop_name)
                                                        : group.shop_name}
                                                </h2>
                                                {group.shop_slug && store.shop?.features?.multivendor ? (
                                                    <Link className="shop-cart-shop-link" to={`/shop/${group.shop_slug}`}>
                                                        {t('storefront.cart.view_shop', 'View shop')}
                                                    </Link>
                                                ) : null}
                                            </div>
                                            <Link to="/catalog" className="shop-cart-continue">
                                                {t('storefront.cart.continue', 'Continue Shopping')}
                                            </Link>
                                        </header>
                                    ) : null}

                                    <div className="shop-cart-table">
                                        <div className="shop-cart-table-head" aria-hidden="true">
                                            <span>{t('storefront.cart.col_product', 'Product')}</span>
                                            <span>{t('storefront.cart.col_price', 'Price')}</span>
                                            <span>{t('storefront.cart.col_qty', 'Quantity')}</span>
                                            <span>{t('storefront.cart.col_total', 'Total')}</span>
                                            <span />
                                        </div>

                                        <div className="shop-cart-lines">
                                            {group.lines.map((item) => {
                                                const product = item.product || {};
                                                const name = translatedName(product, store.shop?.locale, `Item ${item.product_id}`);
                                                const line = lineMap[item.id];
                                                const unit = line?.display_unit_price ?? line?.unit_price ?? item.unit_price;
                                                const lineTotal = line?.display_line_total
                                                    ?? line?.line_total
                                                    ?? (Number(unit) * Number(item.qty));
                                                const inStock = String(product.stock_status || 'in_stock').toLowerCase() !== 'out_of_stock';
                                                const href = productPath(product);
                                                const busy = busyId === item.id;
                                                const options = itemOptions(item, hidePrices);
                                                const plan = item.subscription_plan?.name || item.subscriptionPlan?.name;
                                                const unitLabel = saleUnitLabel(product);
                                                const qtyStep = Number(product.qty_step) > 0 ? Number(product.qty_step) : 1;
                                                const minQty = Number(product.min_qty) > 0 ? Number(product.min_qty) : qtyStep;
                                                const maxQty = product.max_qty != null && product.max_qty !== '' && Number(product.max_qty) > 0
                                                    ? Number(product.max_qty)
                                                    : null;
                                                const finalPayment = !!(item.preorder_data?.final_payment || item.preorder_data?.kind === 'balance_payment');

                                                return (
                                                    <article key={item.id} className={`shop-cart-line${busy ? ' is-busy' : ''}`}>
                                                        <div className="shop-cart-product">
                                                            <Link to={href} className="shop-cart-thumb" state={{ entityType: 'product' }}>
                                                                <ProductImage product={{
                                                                    name,
                                                                    cover: product.cover_path || product.cover,
                                                                    cover_url: product.cover_url,
                                                                }} />
                                                            </Link>
                                                            <div className="shop-cart-product-info">
                                                                <Link to={href} state={{ entityType: 'product' }} className="shop-cart-name">
                                                                    {name}
                                                                </Link>
                                                                {options.map((opt) => (
                                                                    <p key={`${opt.label}-${opt.value}`} className="shop-cart-opt">
                                                                        {opt.label ? <span>{opt.label}: </span> : null}
                                                                        {opt.value}
                                                                    </p>
                                                                ))}
                                                                {plan ? (
                                                                    <p className="shop-cart-opt">
                                                                        <span>{t('storefront.cart.subscription', 'Plan')}: </span>
                                                                        {plan}
                                                                    </p>
                                                                ) : null}
                                                                {(modules.lines?.[item.id] || modules.lines?.[String(item.id)] || []).length ? (
                                                                    <ModuleBlocks slots={modules.lines[item.id] || modules.lines[String(item.id)]} />
                                                                ) : null}
                                                                <p className={`shop-cart-stock${inStock ? ' is-in' : ' is-out'}`}>
                                                                    {inStock
                                                                        ? t('storefront.cart.in_stock', 'In Stock')
                                                                        : t('storefront.cart.out_of_stock', 'Out of stock')}
                                                                </p>
                                                                {freeUnlocked ? (
                                                                    <p className="shop-cart-ship-eligible">
                                                                        <Icon name="truck" className="h-3.5 w-3.5" />
                                                                        {t('storefront.cart.eligible_free_ship', 'Eligible for FREE shipping')}
                                                                    </p>
                                                                ) : null}
                                                            </div>
                                                        </div>

                                                        <p className="shop-cart-unit" data-label={t('storefront.cart.col_price', 'Price')}>
                                                            {money(unit)}{unitLabel ? ` / ${unitLabel}` : ''}
                                                        </p>

                                                        <div className="shop-cart-qty-wrap" data-label={t('storefront.cart.col_qty', 'Quantity')}>
                                                            <div className="shop-cart-qty" role="group" aria-label={t('storefront.cart.qty', 'Quantity')}>
                                                                <button
                                                                    type="button"
                                                                    aria-label={t('storefront.cart.decrease', 'Decrease quantity')}
                                                                    disabled={busy || finalPayment || item.qty <= minQty}
                                                                    onClick={() => update(item.id, +(Number(item.qty) - qtyStep).toFixed(3))}
                                                                >
                                                                    −
                                                                </button>
                                                                <input
                                                                    type="number"
                                                                    min={minQty}
                                                                    step={qtyStep}
                                                                    max={maxQty || undefined}
                                                                    value={item.qty}
                                                                    disabled={busy || finalPayment}
                                                                    onChange={(e) => update(item.id, Number(e.target.value) || minQty)}
                                                                />
                                                                <button
                                                                    type="button"
                                                                    aria-label={t('storefront.cart.increase', 'Increase quantity')}
                                                                    disabled={busy || finalPayment}
                                                                    onClick={() => update(item.id, Number(item.qty) + qtyStep)}
                                                                >
                                                                    +
                                                                </button>
                                                            </div>
                                                            {quantityRuleLine(minQty, qtyStep, maxQty) ? (
                                                                <p className="shop-qty-rules">{quantityRuleLine(minQty, qtyStep, maxQty)}</p>
                                                            ) : null}
                                                            {unitLabel ? <p className="shop-cart-ship-eligible">{unitLabel}</p> : null}
                                                        </div>

                                                        <p className="shop-cart-line-total" data-label={t('storefront.cart.col_total', 'Total')}>
                                                            {money(lineTotal)}
                                                        </p>

                                                        <button
                                                            type="button"
                                                            className="shop-cart-remove"
                                                            disabled={busy}
                                                            aria-label={t('storefront.cart.remove', 'Remove')}
                                                            onClick={() => remove(item.id)}
                                                        >
                                                            <Icon name="trash" className="h-4 w-4" />
                                                        </button>
                                                    </article>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <ul className="shop-cart-trust">
                                        <li>
                                            <span><Icon name="truck" className="h-4 w-4" /></span>
                                            <div>
                                                <strong>{t('storefront.auth.point_ship_title', 'Free Shipping')}</strong>
                                                <em>{t('storefront.auth.point_ship_text', 'On orders over $75')}</em>
                                            </div>
                                        </li>
                                        <li>
                                            <span><Icon name="return" className="h-4 w-4" /></span>
                                            <div>
                                                <strong>{t('storefront.auth.point_returns_title', '30-Day Returns')}</strong>
                                                <em>{t('storefront.cart.trust_returns', 'Shop with confidence')}</em>
                                            </div>
                                        </li>
                                        <li>
                                            <span><Icon name="shield" className="h-4 w-4" /></span>
                                            <div>
                                                <strong>{t('storefront.auth.point_secure_title', 'Secure Checkout')}</strong>
                                                <em>{t('storefront.cart.trust_secure', 'Protected payments')}</em>
                                            </div>
                                        </li>
                                    </ul>

                                    {group.subtotal != null ? (
                                        <p className="shop-cart-seller-sub">
                                            {t('storefront.cart.seller_subtotal', 'Seller subtotal')}
                                            <strong>{money(group.subtotal)}</strong>
                                        </p>
                                    ) : null}
                                </section>
                            ))}
                        </div>
                    )}
                </div>

                <aside className="shop-cart-summary">
                    <h2>{t('storefront.cart.summary', 'Order Summary')}</h2>
                    <dl className="shop-cart-rows">
                        <Row label={t('storefront.cart.subtotal', 'Subtotal')} value={money(subtotal)} />
                        <Row
                            label={(
                                <>
                                    {t('storefront.cart.discount', 'Discount')}
                                    {couponLabel && discount > 0 ? <em className="shop-cart-coupon-pill">{couponLabel}</em> : null}
                                </>
                            )}
                            value={discount > 0 ? `-${money(discount)}` : money(0)}
                            tone={discount > 0 ? 'good' : undefined}
                        />
                        <Row
                            label={t('storefront.cart.shipping', 'Shipping')}
                            value={freeUnlocked
                                ? t('storefront.cart.shipping_free', 'Free')
                                : (shipping > 0
                                    ? money(shipping)
                                    : t('storefront.checkout.calc_shipping', 'At checkout'))}
                            tone={freeUnlocked ? 'good' : undefined}
                        />
                        {!pricesIncludeTax ? (
                            <Row label={t('storefront.cart.tax', 'Tax')} value={money(totals.tax)} />
                        ) : null}
                        {Number(totals.gift_card_credit) > 0 ? (
                            <Row
                                label={(
                                    <span className="shop-cart-gift-applied">
                                        {`${t('storefront.cart.gift_card', 'Gift card')} ${totals.gift_card_code || ''}`}
                                        <button
                                            type="button"
                                            className="shop-cart-gift-remove"
                                            onClick={removeGift}
                                            disabled={giftBusy}
                                        >
                                            {t('storefront.cart.remove', 'Remove')}
                                        </button>
                                    </span>
                                )}
                                value={`-${money(totals.gift_card_credit)}`}
                                tone="good"
                            />
                        ) : null}
                        {adjustments.filter((a) => a.type === 'fee').map((a) => (
                            <Row key={a.code} label={a.label || a.code} value={money(a.amount)} />
                        ))}
                        <Row label={t('storefront.cart.total', 'Total')} value={money(totals.grand_total)} strong />
                        {totals.due_now != null && Number(totals.balance_due) > 0 ? (
                            <>
                                <Row label={t('storefront.cart.due_today', 'Due today')} value={money(totals.due_now)} strong />
                                <Row label={t('storefront.cart.balance_due', 'Balance due')} value={money(totals.balance_due)} />
                            </>
                        ) : null}
                    </dl>

                    {discount > 0 ? (
                        <p className="shop-cart-saved">
                            <Icon name="tag" className="h-4 w-4" />
                            {t('storefront.cart.you_saved', 'You saved {amount} on this order.')
                                .replace('{amount}', money(discount))}
                        </p>
                    ) : null}

                    <div className="shop-cart-modules">
                        <ModuleBlocks slots={modules.sidebar || []} />
                    </div>

                    {couponsEnabled(store.shop) ? (
                    <form className="shop-cart-coupon" onSubmit={applyCoupon}>
                        <input
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            placeholder={t('storefront.cart.coupon_placeholder', 'Enter promo code')}
                            aria-label={t('storefront.cart.coupon', 'Promo code')}
                        />
                        <button type="submit" disabled={couponBusy}>
                            {t('storefront.cart.apply', 'Apply')}
                        </button>
                    </form>
                    ) : null}

                    <form className="shop-cart-coupon" onSubmit={applyGift}>
                        <input
                            value={giftCode}
                            onChange={(e) => setGiftCode(e.target.value)}
                            placeholder={t('storefront.cart.gift_placeholder', 'Gift card code')}
                            aria-label={t('storefront.cart.gift_card', 'Gift card')}
                            disabled={giftBusy || Number(totals.gift_card_credit) > 0}
                        />
                        <button type="submit" disabled={giftBusy || !giftCode.trim() || Number(totals.gift_card_credit) > 0}>
                            {giftBusy ? t('storefront.cart.applying', 'Applying…') : t('storefront.cart.apply', 'Apply')}
                        </button>
                    </form>
                    {Number(totals.gift_card_credit) > 0 ? (
                        <p className="shop-cart-points">
                            {t(
                                'storefront.cart.gift_remaining',
                                'Payable after gift card: {amount}',
                            ).replace('{amount}', money(totals.grand_total))}
                        </p>
                    ) : null}

                    {isCatalogMode(store.shop) ? (
                        <p className="shop-cart-points">{t('storefront.pdp.catalog_only', 'This shop is catalog-only.')}</p>
                    ) : (
                    <Link
                        to="/checkout"
                        className={`shop-cart-checkout${items.length === 0 ? ' is-disabled' : ''}`}
                        aria-disabled={items.length === 0}
                        onClick={(e) => { if (items.length === 0) e.preventDefault(); }}
                    >
                        <Icon name="lock" className="h-4 w-4" />
                        {t('storefront.cart.checkout', 'Proceed to Checkout')}
                    </Link>
                    )}

                    {items.length > 0 ? (
                        <p className="shop-cart-points">
                            {t('storefront.cart.earn_points', 'You could earn {n} points with this order.')
                                .replace('{n}', String(points))}
                        </p>
                    ) : null}

                    <div className="shop-cart-accept">
                        <span>{t('storefront.cart.we_accept', 'We accept')}</span>
                        <img
                            src={classicPdpUi('payment')}
                            alt={t('storefront.pdp.payment_methods', 'Payment methods')}
                        />
                    </div>
                </aside>
            </div>

            {(store.cart?.crosssell || []).length > 0 ? (
                <div className="shop-container mt-8">
                    <h2 className="text-lg font-semibold mb-3">{t('storefront.cart.you_may_also', 'You may also like')}</h2>
                    <ul className="grid gap-4 sm:grid-cols-4">
                        {(store.cart.crosssell || []).map((product) => (
                            <li key={product.id}>
                                <Link to={productPath(product)} className="block">
                                    <ProductImage product={product} className="aspect-square w-full object-cover rounded" />
                                    <span className="mt-2 block text-sm">{translatedName(product, store.shop?.locale, product.sku)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <div className="shop-container">
                <aside className="shop-cart-confidence">
                    <div>
                        <span className="shop-cart-confidence-icon"><Icon name="shield" className="h-6 w-6" /></span>
                        <div>
                            <strong>{t('storefront.cart.confidence_title', 'Shop with confidence')}</strong>
                            <p>{t('storefront.cart.confidence_body', 'Your payment details are encrypted and never stored on our servers.')}</p>
                        </div>
                    </div>
                    <Link to="/help" className="shop-cart-confidence-link">
                        {t('storefront.cart.confidence_cta', 'Learn more about security')}
                    </Link>
                </aside>
            </div>
        </section>
    );
}

function Row({ label, value, strong, tone }) {
    return (
        <div className={`shop-cart-row${strong ? ' is-strong' : ''}${tone === 'good' ? ' is-good' : ''}`}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
