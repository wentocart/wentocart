import { useMemo, useState } from 'react';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { formatMoney, defaultImageUrl, mediaUrlOrDefault } from '../lib/format.js';
import { useStore } from '../state/StoreState.jsx';
import { pricesHidden, completeCartAdd } from '../lib/commerce.js';

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

export default function GroupPdp({ product }) {
    const store = useStore();
    const hidePrice = pricesHidden(store.shop, store.customer) || product?.show_price === false;
    const components = product.components || [];
    const forceAll = String(product.selection_mode || 'all') === 'all';
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');
    const [activeImage, setActiveImage] = useState(0);
    const [picked, setPicked] = useState(() => {
        const next = {};
        components.forEach((row) => {
            if (forceAll || row.is_required || row.default_selected) {
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
        () => selected.reduce((sum, row) => sum + (Number(row.selling_price) || 0) * (picked[row.id] || row.quantity || 1), 0),
        [selected, picked],
    );

    const packPrice = useMemo(() => {
        const mode = product.pricing_mode || 'sum';
        if (mode === 'fixed') {
            return Number(product.price) || 0;
        }
        let total = rawSubtotal;
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
    }, [product.pricing_mode, product.pricing_discount_type, product.pricing_discount_value, product.price, rawSubtotal]);

    const canSubmit = selected.length > 0
        && selected.every((row) => childInStock(row))
        && (!forceAll || selected.length === components.length);

    const toggle = (row) => {
        if (forceAll || row.is_required) {
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

    const setQty = (row, qty) => {
        if (forceAll || row.qty_editable === false) {
            return;
        }
        const min = Number(row.min_quantity) || 1;
        const max = Number(row.max_quantity) || 99;
        const next = Math.min(max, Math.max(min, Number(qty) || min));
        setPicked((prev) => ({ ...prev, [row.id]: next }));
    };

    const add = async () => {
        setBusy(true);
        setMsg('');
        try {
            if (!canSubmit) {
                setMsg(t('storefront.pdp.group_select_required', 'Select available items from this group.'));
                return;
            }
            await csrf();
            const componentsPayload = selected.map((row) => ({
                product_id: row.id,
                qty: picked[row.id] || row.quantity || 1,
            }));
            const { data: payload } = await api.post('/cart', { product_id: product.id, qty: 1, components: componentsPayload });
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
    const showCompare = rawSubtotal > packPrice + 0.009;

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
                        {forceAll
                            ? t('storefront.pdp.group_all_required', 'All items in this group are included.')
                            : t('storefront.pdp.choose_items', 'Choose items')}
                    </p>

                    <ul className="space-y-2 mb-4">
                        {components.map((row) => {
                            const stockOk = childInStock(row);
                            const locked = forceAll || !!row.is_required;
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
                                            {row.name}
                                            {!hidePrice && row.selling_price != null ? ` · ${formatMoney(row.selling_price)}` : ''}
                                            {locked ? ` · ${t('storefront.pdp.required', 'Required')}` : ''}
                                            {!stockOk ? ` · ${t('storefront.pdp.out_of_stock', 'Out of stock')}` : ''}
                                        </span>
                                    </label>
                                    {checked && row.qty_editable !== false && !forceAll ? (
                                        <input
                                            type="number"
                                            min={row.min_quantity || 1}
                                            max={row.max_quantity || undefined}
                                            className="shop-input w-20"
                                            value={picked[row.id]}
                                            onChange={(e) => setQty(row, e.target.value)}
                                        />
                                    ) : checked ? (
                                        <span className="text-neutral-500">×{picked[row.id] || row.quantity || 1}</span>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>

                    {msg ? <p className="text-sm mb-3">{msg}</p> : null}
                    <button
                        type="button"
                        className="shop-btn shop-btn-primary shop-pdp2-cart"
                        disabled={busy || !canSubmit}
                        onClick={add}
                    >
                        {busy
                            ? t('storefront.pdp.adding', 'Adding…')
                            : t('storefront.pdp.add_selected', 'Add selected to cart')}
                    </button>
                </div>
            </div>
        </div>
    );
}
