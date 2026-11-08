import { useMemo, useState } from 'react';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { formatMoney, defaultImageUrl, mediaUrlOrDefault } from '../lib/format.js';
import { useStore } from '../state/StoreState.jsx';
import { pricesHidden, completeCartAdd, quantityRuleLine, snapOrderQty } from '../lib/commerce.js';

function childInStock(row) {
    if (row.in_stock === false) {
        return false;
    }
    if (row.manage_stock === false) {
        return true;
    }
    if (row.allow_backorder || String(row.stock_status || '').toLowerCase() === 'on_backorder') {
        return true;
    }
    return (Number(row.stock_qty) || 0) > 0;
}

function packPriceFor(product, selected, picked) {
    const mode = product.pricing_mode || 'fixed';
    if (mode === 'fixed') {
        return Number(product.price) || 0;
    }
    let total = selected.reduce(
        (sum, row) => sum + (Number(row.selling_price) || 0) * (picked[row.id] || row.quantity || 1),
        0,
    );
    const value = Number(product.pricing_discount_value) || 0;
    if (mode === 'sum_discount') {
        if ((product.pricing_discount_type || 'fixed') === 'percent') {
            total *= (1 - (Math.min(100, Math.max(0, value)) / 100));
        } else {
            total -= Math.max(0, Math.min(total, value));
        }
    }
    if (mode === 'sum_adjustment') {
        total += value;
    }
    return Math.max(0, Math.round(total * 100) / 100);
}

export default function BundlePdp({ product }) {
    const store = useStore();
    const hidePrice = pricesHidden(store.shop, store.customer) || product?.show_price === false;
    const components = product.components || [];
    const configurable = String(product.bundle_type || 'fixed') === 'configurable';
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');
    const minQty = Number(product.min_qty) > 0 ? Number(product.min_qty) : 1;
    const stepQty = Number(product.qty_step) > 0 ? Number(product.qty_step) : 1;
    const maxQty = product.max_qty != null && product.max_qty !== '' && Number(product.max_qty) > 0
        ? Number(product.max_qty)
        : null;
    const [qty, setQty] = useState(() => snapOrderQty(minQty, minQty, stepQty, maxQty));
    const [activeImage, setActiveImage] = useState(0);
    const [picked, setPicked] = useState(() => {
        const next = {};
        components.forEach((row) => {
            if (!configurable || row.is_required || row.default_selected) {
                next[row.id] = Math.max(1, Number(row.quantity) || 1);
            }
        });
        return next;
    });

    const gallery = useMemo(() => {
        const images = (product.images || []).map((img) => ({
            url: mediaUrlOrDefault(img.url || img.path, defaultImageUrl()),
            alt: img.alt || product.name,
        }));
        if (!images.length && product.cover_url) {
            images.push({ url: mediaUrlOrDefault(product.cover_url, defaultImageUrl()), alt: product.name });
        }
        if (!images.length) {
            images.push({ url: defaultImageUrl(), alt: product.name });
        }
        return images;
    }, [product.id, product.cover_url, product.images, product.name]);

    const selected = useMemo(
        () => components.filter((row) => picked[row.id] > 0),
        [components, picked],
    );

    const rawSubtotal = useMemo(
        () => selected.reduce(
            (sum, row) => sum + (Number(row.selling_price) || 0) * (picked[row.id] || row.quantity || 1),
            0,
        ),
        [selected, picked],
    );

    const packPrice = useMemo(
        () => packPriceFor(product, selected, picked),
        [product, selected, picked],
    );

    const requiredOk = components
        .filter((row) => row.is_required)
        .every((row) => picked[row.id] > 0 && childInStock(row));

    const selectedOk = selected.length > 0 && selected.every((row) => childInStock(row));
    const canSubmit = requiredOk && selectedOk;

    const toggle = (row) => {
        if (!configurable || row.is_required) {
            return;
        }
        if (!childInStock(row) && !picked[row.id]) {
            return;
        }
        setPicked((prev) => {
            const copy = { ...prev };
            if (copy[row.id]) {
                delete copy[row.id];
            } else {
                copy[row.id] = Math.max(1, Number(row.quantity) || 1);
            }
            return copy;
        });
    };

    const add = async () => {
        setBusy(true);
        setMsg('');
        try {
            if (!canSubmit) {
                setMsg(t('storefront.pdp.bundle_select_required', 'Select available items for this bundle.'));
                return;
            }
            await csrf();
            const componentsPayload = selected.map((row) => ({
                product_id: row.id,
                qty: picked[row.id] || row.quantity || 1,
            }));
            const { data: payload } = await api.post('/cart', {
                product_id: product.id,
                qty: Math.max(1, Number(qty) || 1),
                components: componentsPayload,
            });
            store.applyCart?.(payload);
            const { notices, blocked } = completeCartAdd({
                ping: store.ping,
                openCart: store.openCart,
                data: payload,
            });
            setMsg(blocked && notices.length ? notices.join(' ') : '');
        } catch (err) {
            const errors = err.response?.data?.errors;
            const firstError = errors ? Object.values(errors).flat()[0] : null;
            setMsg(firstError || err.response?.data?.message || t('storefront.pdp.add_failed', 'Could not add to cart'));
        } finally {
            setBusy(false);
        }
    };

    const current = gallery[activeImage] || gallery[0];
    const showCompare = (product.pricing_mode || 'fixed') !== 'fixed' && rawSubtotal > packPrice + 0.009;

    return (
        <div className="shop-pdp2">
            <div className="shop-pdp2-main">
                <div className="shop-pdp2-gallery">
                    <div className="shop-pdp2-hero">
                        <img src={current.url} alt={current.alt || product.name} />
                    </div>
                    {gallery.length > 1 ? (
                        <div className="shop-pdp2-thumbs">
                            {gallery.map((img, index) => (
                                <button
                                    key={`${img.url}-${index}`}
                                    type="button"
                                    className={index === activeImage ? 'is-on' : ''}
                                    onClick={() => setActiveImage(index)}
                                >
                                    <img src={img.url} alt="" />
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                <div className="shop-pdp2-buy">
                    <h1 className="shop-pdp2-title">{product.name}</h1>
                    {product.sku ? <p className="shop-pdp2-sku">{t('storefront.pdp.reference', 'Reference')}: {product.sku}</p> : null}

                    {hidePrice ? null : (
                    <div className="shop-pdp2-price">
                        <strong>{formatMoney(packPrice)}</strong>
                        {showCompare ? (
                            <span className="shop-pdp2-compare">{formatMoney(rawSubtotal)}</span>
                        ) : null}
                    </div>
                    )}

                    <p className="text-sm text-neutral-500 mb-3">
                        {configurable
                            ? t('storefront.pdp.bundle_choose', 'Choose optional items included with this bundle.')
                            : t('storefront.pdp.bundle_includes', 'This bundle includes')}
                    </p>

                    <ul className="space-y-2 mb-4">
                        {components.map((row) => {
                            const stockOk = childInStock(row);
                            const locked = !configurable || !!row.is_required;
                            const checked = !!picked[row.id];
                            return (
                                <li key={row.id || row.sku} className={`flex flex-wrap items-center gap-3 text-sm ${stockOk ? '' : 'opacity-60'}`}>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={checked}
                                            disabled={locked || (!stockOk && !checked)}
                                            onChange={() => toggle(row)}
                                        />
                                        <span>
                                            {row.quantity}× {row.name}
                                            {!hidePrice && row.selling_price != null ? ` · ${formatMoney(row.selling_price)}` : ''}
                                            {row.is_required ? ` · ${t('storefront.pdp.required', 'Required')}` : ''}
                                            {row.option_group ? ` · ${row.option_group}` : ''}
                                            {!stockOk ? ` · ${t('storefront.pdp.out_of_stock', 'Out of stock')}` : ''}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="flex items-center gap-3 mb-4">
                        <label className="text-sm">{t('storefront.pdp.qty', 'Qty')}</label>
                        <input
                            type="number"
                            min={minQty}
                            step={stepQty}
                            max={maxQty || undefined}
                            className="shop-input w-20"
                            value={qty}
                            onChange={(e) => setQty(snapOrderQty(e.target.value, minQty, stepQty, maxQty))}
                        />
                    </div>
                    {quantityRuleLine(minQty, stepQty, maxQty) ? (
                        <p className="shop-qty-rules">{quantityRuleLine(minQty, stepQty, maxQty)}</p>
                    ) : null}

                    {msg ? <p className="text-sm mb-3">{msg}</p> : null}
                    <button
                        type="button"
                        className="shop-btn shop-btn-primary shop-pdp2-cart"
                        disabled={busy || !canSubmit}
                        onClick={add}
                    >
                        {busy
                            ? t('storefront.pdp.adding', 'Adding…')
                            : t('storefront.pdp.add_to_cart', 'Add to cart')}
                    </button>
                </div>
            </div>
        </div>
    );
}
