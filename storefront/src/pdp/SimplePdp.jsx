import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../api.js';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import StockAlertGuestModal from '../components/StockAlertGuestModal.jsx';
import { Icon } from '../components/Icons.jsx';
import { formatMoney, discountPercent, defaultImageUrl, mediaUrlOrDefault, saleUnitLabel } from '../lib/format.js';
import { brandPath, entityState } from '../lib/links.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { formatRating, guestPricePrompt, isCatalogMode, pricesHidden, returnsEnabled, reviewsEnabled, wishlistEnabled, outOfStockNotificationEnabled, productHasSellableStock, quantityCartLine, completeCartAdd, quantityRuleLine, snapOrderQty, taxDisplay, toStorefrontAmount } from '../lib/commerce.js';
import {
    classicPdpEnhance,
} from '../classicAssets.js';
import OptionPicker, { optionGroups } from './OptionPicker.jsx';
import ProductGallery from './ProductGallery.jsx';
import SafeHtml from '../components/SafeHtml.jsx';
import PreorderCountdown from '../components/PreorderCountdown.jsx';
import { showPreorderImageLabel } from '../lib/preorderCountdown.js';
import './classic-pdp.css';

function extraFor(field, value, basePrice) {
    const amount = (type, raw) => {
        const n = Number(raw) || 0;
        if (type === 'percent') {
            return Math.round(basePrice * (n / 100) * 100) / 100;
        }
        if (type === 'fixed') {
            return n;
        }
        return 0;
    };
    let extra = 0;
    const used = Array.isArray(value) ? value.length > 0 : String(value || '').length > 0;
    if (used) {
        extra += amount(field.price_type, field.price_amount);
    }
    if (['select', 'radio', 'checkbox'].includes(field.type)) {
        const picked = Array.isArray(value) ? value : [value];
        (field.options || []).forEach((opt) => {
            if (picked.map(String).includes(String(opt.value)) || picked.map(String).includes(String(opt.label))) {
                extra += amount(opt.price_type || 'fixed', opt.price);
            }
        });
    }
    return extra;
}

function FieldControl({ field, value, file, onValue, onFile }) {
    const help = field.help_text ? <p className="text-sm text-neutral-500 mt-1">{field.help_text}</p> : null;
    const label = (
        <label className="block text-sm font-medium mb-1">
            {field.label}
            {field.is_required ? <span className="text-red-600"> *</span> : null}
        </label>
    );

    if (field.type === 'textarea') {
        return (
            <div>
                {label}
                <textarea className="shop-input" rows="3" placeholder={field.placeholder || ''} maxLength={field.max_length || undefined} value={value} onChange={(e) => onValue(e.target.value)} />
                {help}
            </div>
        );
    }
    if (field.type === 'select') {
        return (
            <div>
                {label}
                <select className="shop-input" value={value} onChange={(e) => onValue(e.target.value)}>
                    <option value="">{field.placeholder || 'Select'}</option>
                    {(field.options || []).map((opt) => (
                        <option key={opt.value || opt.label} value={opt.value || opt.label}>{opt.label}</option>
                    ))}
                </select>
                {help}
            </div>
        );
    }
    if (field.type === 'radio') {
        return (
            <div>
                {label}
                <div className="shop-choices">
                    {(field.options || []).map((opt) => {
                        const val = opt.value || opt.label;
                        return (
                            <button key={val} type="button" className={`shop-choice ${String(value) === String(val) ? 'is-on' : ''}`} onClick={() => onValue(val)}>
                                {opt.label}
                            </button>
                        );
                    })}
                </div>
                {help}
            </div>
        );
    }
    if (field.type === 'checkbox') {
        const picked = Array.isArray(value) ? value : [];
        return (
            <div>
                {label}
                <div className="space-y-1">
                    {(field.options || []).map((opt) => {
                        const val = opt.value || opt.label;
                        return (
                            <label key={val} className="flex gap-2 items-center text-sm">
                                <input
                                    type="checkbox"
                                    checked={picked.map(String).includes(String(val))}
                                    onChange={(e) => {
                                        onValue(e.target.checked ? [...picked, val] : picked.filter((item) => String(item) !== String(val)));
                                    }}
                                />
                                {opt.label}
                            </label>
                        );
                    })}
                </div>
                {help}
            </div>
        );
    }
    if (field.type === 'file' || field.type === 'image') {
        return (
            <div>
                {label}
                <input type="file" accept={field.type === 'image' ? 'image/*' : undefined} onChange={(e) => onFile(e.target.files?.[0] || null)} />
                {file ? <p className="text-sm mt-1">{file.name}</p> : null}
                {help}
            </div>
        );
    }

    return (
        <div>
            {label}
            <input className="shop-input" placeholder={field.placeholder || ''} maxLength={field.max_length || undefined} value={value} onChange={(e) => onValue(e.target.value)} />
            {help}
        </div>
    );
}

function galleryFrom(product) {
    const images = Array.isArray(product.images) ? product.images : [];
    if (images.length) {
        return images.map((img, index) => ({
            id: img.id || index,
            thumb: img.src_url || img.large_url || mediaUrlOrDefault(product.cover_url || product.cover, 'md'),
            large: img.large_url || img.src_url || mediaUrlOrDefault(product.cover_url || product.cover, 'lg'),
            alt: img.alt || product.name,
        }));
    }
    if (product.cover_url || product.cover) {
        return [{
            id: 'cover',
            thumb: mediaUrlOrDefault(product.cover_url || product.cover, 'md'),
            large: mediaUrlOrDefault(product.cover_url || product.cover, 'lg'),
            alt: product.name,
        }];
    }
    return [{
        id: 'default',
        thumb: defaultImageUrl('md'),
        large: defaultImageUrl('lg'),
        alt: product.name,
    }];
}

export default function SimplePdp({ product: rawProduct, actionSlots = [], trustSlots = [], buybox = {}, rentalBooking = null, belowPrice = null, onOpenReviews = null }) {
    const store = useStore();
    const location = useLocation();
    const [searchParams, setSearchParams] = useSearchParams();
    const product = useMemo(() => classicPdpEnhance(rawProduct), [rawProduct]);
    const hidePrice = buybox.hide_price === true || pricesHidden(store.shop, store.customer) || product.show_price === false;
    const hideQty = buybox.hide_qty === true;
    const hideCart = buybox.hide_cart === true;
    const gallery = useMemo(() => galleryFrom(product), [product]);
    const [activeImage, setActiveImage] = useState(0);
    const [qty, setQty] = useState(() => snapOrderQty(
        Math.max(Number(rawProduct?.min_qty) || 1, Number(rawProduct?.preorder?.min_quantity) || 0),
        rawProduct?.min_qty,
        rawProduct?.qty_step,
        rawProduct?.max_qty,
    ));
    const comboGroups = useMemo(() => optionGroups(product), [product]);
    const comboVariants = (product.combination_variants || []).filter((row) => row.is_enabled !== false);
    const ipaFromUrl = searchParams.get('ipa') || searchParams.get('combination') || '';
    const [picks, setPicks] = useState({});
    const [bookingDate, setBookingDate] = useState('');
    const needsBooking = !rentalBooking && (
        product.requires_booking === true
        || (product.type === 'service' && product.service?.requires_booking)
    );
    const isRental = String(product.type || '') === 'rental' || !!rentalBooking;
    const [msg, setMsg] = useState('');
    const [msgTone, setMsgTone] = useState('ok');
    const [busy, setBusy] = useState(false);
    const [stockAlertVariantIds, setStockAlertVariantIds] = useState(() => (
        Array.isArray(rawProduct?.stock_alert?.subscribed_variant_ids)
            ? rawProduct.stock_alert.subscribed_variant_ids.map((id) => Number(id))
            : []
    ));
    const [stockAlertGuestOpen, setStockAlertGuestOpen] = useState(false);
    const [notifySubscribed, setNotifySubscribed] = useState(Boolean(rawProduct?.preorder?.notify_me_subscribed));
    const [notifyModal, setNotifyModal] = useState(null);
    const [notifyBusy, setNotifyBusy] = useState(false);
    const [color, setColor] = useState(product.classic_colors?.[0]?.name || product.colors?.[0]?.name || '');
    const [dimension, setDimension] = useState(
        product.classic_dimensions?.includes('60x90cm')
            ? '60x90cm'
            : (product.classic_dimensions?.[0] || '')
    );

    const fields = product.customization_enabled ? (product.customization_fields || []) : [];
    const defaults = useMemo(() => {
        const start = {};
        fields.forEach((field) => {
            if (field.type === 'checkbox') {
                start[field.id] = (field.options || []).filter((opt) => opt.is_default).map((opt) => opt.value || opt.label);
            } else if (['select', 'radio'].includes(field.type)) {
                const selected = (field.options || []).find((opt) => opt.is_default);
                start[field.id] = selected ? (selected.value || selected.label) : '';
            } else {
                start[field.id] = field.default_value || '';
            }
        });
        return start;
    }, [product.id, fields.length]);

    const [values, setValues] = useState(defaults);
    const [files, setFiles] = useState({});

    useEffect(() => {
        setActiveImage(0);
        setQty(snapOrderQty(
            Math.max(Number(product.min_qty) || 1, Number(product.preorder?.min_quantity) || 0),
            product.min_qty,
            product.qty_step,
            product.max_qty,
        ));
        setValues(defaults);
        setFiles({});
        setColor(product.classic_colors?.[0]?.name || product.colors?.[0]?.name || '');
        setDimension(
            product.classic_dimensions?.includes('60x90cm')
                ? '60x90cm'
                : (product.classic_dimensions?.[0] || '')
        );
        setBookingDate('');
        setStockAlertVariantIds(
            Array.isArray(product.stock_alert?.subscribed_variant_ids)
                ? product.stock_alert.subscribed_variant_ids.map((id) => Number(id))
                : [],
        );
        setNotifySubscribed(Boolean(product.preorder?.notify_me_subscribed));
        setNotifyModal(null);
        const fromUrl = comboVariants.find((row) => String(row.id) === String(ipaFromUrl));
        const def = fromUrl || comboVariants.find((row) => row.is_default) || comboVariants[0];
        if (def && comboGroups.length) {
            const next = {};
            comboGroups.forEach((group, index) => {
                const id = (def.attribute_value_ids || [])[index];
                if (id) {
                    next[group.id] = id;
                }
            });
            setPicks(next);
        } else {
            setPicks({});
        }
    }, [product.id]);

    const priceCatalog = Number(product.price_catalog ?? product.price_excl ?? product.price) || 0;
    const extra = fields.reduce((sum, field) => {
        const value = ['file', 'image'].includes(field.type) ? (files[field.id] ? files[field.id].name : '') : values[field.id];
        return sum + extraFor(field, value, priceCatalog);
    }, 0);
    const plans = product.subscription_enabled ? (product.subscription_plans || []) : [];
    const option = product.purchase_option || 'one_time';
    const allowOneTime = !product.subscription_enabled || option === 'one_time' || option === 'both';
    const allowSub = product.subscription_enabled && (option === 'subscription' || option === 'both') && plans.length > 0;
    const defaultPlan = plans.find((plan) => plan.is_default) || plans[0];
    const [mode, setMode] = useState(allowSub ? 'subscription' : 'one_time');
    const [planId, setPlanId] = useState(defaultPlan?.id || '');
    useEffect(() => {
        const next = (product.subscription_enabled ? (product.subscription_plans || []) : []).find((plan) => plan.is_default)
            || (product.subscription_enabled ? (product.subscription_plans || [])[0] : null);
        setPlanId(next?.id || '');
        const nextAllowSub = !!product.subscription_enabled
            && ((product.purchase_option || 'one_time') === 'subscription' || (product.purchase_option || 'one_time') === 'both')
            && (product.subscription_plans || []).length > 0;
        setMode(nextAllowSub ? 'subscription' : 'one_time');
    }, [product.id, product.subscription_enabled, product.purchase_option]);
    const selectedPlan = plans.find((plan) => String(plan.id) === String(planId)) || defaultPlan;
    const selectedVariant = comboGroups.length
        ? comboVariants.find((row) => comboGroups.every((group) => (row.attribute_value_ids || []).map(String).includes(String(picks[group.id] || ''))))
        : null;
    const qtyBreak = (product.qty_prices || [])
        .filter((row) => qty >= Number(row.min_qty) && (row.max_qty == null || qty <= Number(row.max_qty)))
        .sort((a, b) => Number(b.min_qty) - Number(a.min_qty))[0];
    const catalogUnit = qtyBreak
        ? Number(qtyBreak.price_catalog ?? qtyBreak.price)
        : priceCatalog;
    const variantUnit = selectedVariant
        ? (selectedVariant.selling_price != null && selectedVariant.selling_price !== ''
            ? Number(selectedVariant.selling_price)
            : (selectedVariant.price != null && selectedVariant.price !== '' ? Number(selectedVariant.price) : null))
        : null;
    const unit = mode === 'subscription' && selectedPlan
        ? Number(selectedPlan.price) || 0
        : (variantUnit ?? catalogUnit);
    const totalCatalog = unit + extra;
    const total = toStorefrontAmount(totalCatalog, product, store.shop);
    const displayCatalogUnit = toStorefrontAmount(catalogUnit, product, store.shop);
    const inventoryOpen = store.shop?.commerce?.inventory?.enabled === false
        || store.shop?.commerce?.inventory?.allow_negative === true;
    const variantInStock = selectedVariant ? productHasSellableStock(selectedVariant, store.shop) : true;
    const parentInStock = inventoryOpen
        ? productHasSellableStock(product, store.shop)
        : (product.can_add_to_cart === false
            || product.stock_alert?.out_of_stock === true
            ? false
            : productHasSellableStock(product, store.shop));
    const preorder = product.preorder || null;
    const preorderActive = Boolean(preorder?.active || preorder?.can_purchase);
    const inStock = preorderActive
        ? true
        : (comboGroups.length ? (!!selectedVariant && variantInStock) : parentInStock);
    const colorOptions = product.classic_colors?.length
        ? product.classic_colors
        : (product.colors || []).map((row) => ({ name: row.name, hex: row.hex || '#d1d5db' }));

    const add = async () => {
        setBusy(true);
        setMsg('');
        try {
            if (comboGroups.length && !selectedVariant?.id) {
                setMsg(t('storefront.pdp.choose_combination', 'Choose a combination before adding to cart.'));
                return;
            }
            await csrf();
            const data = new FormData();
            data.append('product_id', product.id);
            data.append('qty', qty);
            if (selectedVariant?.id) {
                data.append('combination_id', selectedVariant.id);
            }
            if (needsBooking && bookingDate) {
                data.append('booking_date', bookingDate);
            }
            if (isRental && rentalBooking) {
                if (rentalBooking.start) {
                    data.append('booking_date', rentalBooking.start);
                }
                if (rentalBooking.end) {
                    data.append('booking_end_date', rentalBooking.end);
                }
                if (rentalBooking.duration) {
                    data.append('rental_duration', rentalBooking.duration);
                }
            }
            if (mode === 'subscription' && selectedPlan) {
                data.append('subscription_plan_id', selectedPlan.id);
            }
            fields.forEach((field) => {
                if (['file', 'image'].includes(field.type)) {
                    if (files[field.id]) {
                        data.append(`customization_files[${field.id}]`, files[field.id]);
                    }
                    return;
                }
                const value = values[field.id];
                if (Array.isArray(value)) {
                    value.forEach((item) => data.append(`customization[${field.id}][]`, item));
                } else if (value != null && value !== '') {
                    data.append(`customization[${field.id}]`, value);
                }
            });
            const { data: payload } = await api.post('/cart', data);
            store.applyCart?.(payload);
            const { notices, blocked } = completeCartAdd({
                ping: store.ping,
                openCart: store.openCart,
                data: payload,
            });
            if (blocked && notices.length) {
                setMsgTone('warning');
                setMsg(notices.join(' '));
            } else {
                setMsg('');
            }
        } catch (err) {
            const bag = err.response?.data?.errors || {};
            const first = Object.values(bag)[0];
            setMsgTone('warning');
            setMsg((Array.isArray(first) ? first[0] : first) || err.response?.data?.message || t('storefront.pdp.add_failed', 'Could not add to cart'));
        } finally {
            setBusy(false);
        }
    };

    const stockAlertEnabled = outOfStockNotificationEnabled(store.shop) || product.stock_alert?.enabled === true;
    const stockAlertVariantKey = comboGroups.length ? Number(selectedVariant?.id || 0) : 0;
    const stockAlertSubscribed = stockAlertVariantIds.includes(stockAlertVariantKey);

    const markStockAlertSubscribed = () => {
        setStockAlertVariantIds((prev) => (
            prev.includes(stockAlertVariantKey) ? prev : [...prev, stockAlertVariantKey]
        ));
    };

    const subscribeStockAlert = async () => {
        if (comboGroups.length && !selectedVariant?.id) {
            setMsg(t('storefront.pdp.choose_combination', 'Choose a combination before adding to cart.'));
            return;
        }
        if (!store.customer) {
            setStockAlertGuestOpen(true);
            return;
        }
        setBusy(true);
        setMsg('');
        try {
            await csrf();
            const body = {};
            if (selectedVariant?.id) {
                body.product_variant_id = selectedVariant.id;
            }
            await api.post(`/catalog/product/${product.id}/customer-stock-alert`, body);
            markStockAlertSubscribed();
            store.ping?.(t('storefront.pdp.stock_alert_subscribed', 'Subscribed to stock alert'));
        } catch (err) {
            const bag = err.response?.data?.errors || {};
            const first = Object.values(bag)[0];
            setMsg((Array.isArray(first) ? first[0] : first) || err.response?.data?.message || t('storefront.pdp.stock_alert_failed', 'Could not subscribe to stock alert.'));
        } finally {
            setBusy(false);
        }
    };

    const unsubscribeStockAlert = async () => {
        if (!store.customer) return;
        setBusy(true);
        setMsg('');
        try {
            await csrf();
            const params = {};
            if (selectedVariant?.id) {
                params.product_variant_id = selectedVariant.id;
            }
            await api.delete(`/catalog/product/${product.id}/customer-stock-alert`, { params });
            setStockAlertVariantIds((prev) => prev.filter((id) => id !== stockAlertVariantKey));
            store.ping?.(t('storefront.pdp.stock_alert_unsubscribed', 'Stock alert removed'));
        } catch (err) {
            setMsg(err.response?.data?.message || t('storefront.pdp.stock_alert_unsub_failed', 'Could not unsubscribe.'));
        } finally {
            setBusy(false);
        }
    };

    const openNotifyModal = () => {
        if (!store.customer) {
            store.ping?.(t('storefront.pdp.sign_in_notify', 'Sign in to subscribe to notifications'));
            return;
        }
        setNotifyModal(notifySubscribed ? 'unsubscribe' : 'subscribe');
    };

    const confirmNotifySubscribe = async () => {
        setNotifyBusy(true);
        setMsg('');
        try {
            await csrf();
            const res = await api.post(`/catalog/product/${product.id}/preorder-notify`);
            setNotifySubscribed(Boolean(res.data?.notify_me_subscribed ?? true));
            setNotifyModal(null);
            setMsg(res.data?.message || t('storefront.pdp.notify_subscribed', 'You will be notified when this product becomes available.'));
        } catch (err) {
            const first = err.response?.data?.errors
                ? Object.values(err.response.data.errors)[0]
                : null;
            setMsg((Array.isArray(first) ? first[0] : first) || err.response?.data?.message || t('storefront.pdp.notify_failed', 'Could not subscribe to notifications.'));
            setNotifyModal(null);
        } finally {
            setNotifyBusy(false);
        }
    };

    const confirmNotifyUnsubscribe = async () => {
        setNotifyBusy(true);
        setMsg('');
        try {
            await csrf();
            const res = await api.delete(`/catalog/product/${product.id}/preorder-notify`);
            setNotifySubscribed(false);
            setNotifyModal(null);
            setMsg(res.data?.message || t('storefront.pdp.notify_unsubscribed', 'You have unsubscribed from availability notifications.'));
        } catch (err) {
            setMsg(err.response?.data?.message || t('storefront.pdp.notify_unsubscribe_failed', 'Could not unsubscribe.'));
            setNotifyModal(null);
        } finally {
            setNotifyBusy(false);
        }
    };

    const wish = async () => {
        if (!store.customer) {
            store.ping?.(t('storefront.pdp.sign_in_wish', 'Sign in to save items'));
            return;
        }
        await store.toggleWish(product.id);
        setMsg(t('storefront.pdp.wishlist_updated', 'Wishlist updated'));
    };

    const compare = () => {
        store.toggleCompare(product.id);
        setMsg(t('storefront.pdp.compare_updated', 'Compare list updated'));
    };

    const step = Number(product.qty_step) || 1;
    const minQty = Math.max(
        Number(product.min_qty) || step || 1,
        preorderActive && preorder?.min_quantity ? Number(preorder.min_quantity) : 1,
    );
    const maxFromProduct = product.max_qty != null && product.max_qty !== '' ? Number(product.max_qty) : null;
    const maxFromPreorder = (() => {
        if (!preorderActive || preorder?.max_quantity == null) {
            return null;
        }
        if (preorder.customer_qty_remaining != null) {
            return Number(preorder.customer_qty_remaining);
        }
        return Number(preorder.max_quantity);
    })();
    const maxQty = [maxFromProduct, maxFromPreorder].filter((v) => v != null && !Number.isNaN(v)).reduce(
        (acc, v) => (acc == null ? v : Math.min(acc, v)),
        null,
    );
    const cartQty = (store.cart?.cart?.items || []).reduce((sum, item) => {
        if (Number(item.product_id) !== Number(product.id) || item.parent_product_id) {
            return sum;
        }
        if (selectedVariant?.id && Number(item.combination_id) !== Number(selectedVariant.id)) {
            return sum;
        }
        return sum + (Number(item.qty) || 0);
    }, 0);
    const qtyRuleText = quantityRuleLine(minQty, step, maxQty);
    const cartQtyText = quantityCartLine(cartQty, maxQty, minQty, step);
    const unitLabel = saleUnitLabel(product);
    const off = discountPercent(product);
    const wished = store.wishlistIds?.includes(product.id);
    const brandHref = product.brand?.name ? brandPath(product.brand) : null;
    const reviewCount = product.rating?.count || 0;

    return (
        <>
        <div className="shop-pdp2">
            <div>
            <ProductGallery
                gallery={gallery}
                productName={product.name}
                off={off}
                isNew={!!product.flags?.new}
                showPreorderLabel={showPreorderImageLabel(product)}
                activeImage={activeImage}
                onActiveImage={setActiveImage}
            />
            {product.video_embed ? (
                <div className="shop-pdp2-video">
                    <iframe
                        src={product.video_embed}
                        title={product.name}
                        allow="accelerometer; autoplay; encrypted-media; picture-in-picture"
                        allowFullScreen
                    />
                </div>
            ) : null}
            </div>

            <div className="shop-pdp2-buy">
                <h1>{product.name}</h1>

                <div className="shop-pdp2-meta">
                    {brandHref ? (
                        <span>
                            {t('storefront.pdp.brand', 'Brand')}:{' '}
                            <Link to={brandHref} state={entityState('brand')}>{product.brand.name}</Link>
                        </span>
                    ) : null}
                    {product.sku ? (
                        <span>{t('storefront.pdp.sku', 'SKU')}: {product.sku}</span>
                    ) : null}
                </div>

                {reviewsEnabled(store.shop) ? (
                    <button
                        type="button"
                        className="shop-pdp2-rating-btn"
                        onClick={() => {
                            if (typeof onOpenReviews === 'function') {
                                onOpenReviews();
                            }
                        }}
                        aria-label={
                            product.rating?.average != null && reviewCount > 0
                                ? t(
                                    'storefront.pdp.rating_goto_reviews',
                                    '{rating} average rating, {count} reviews. Go to reviews.',
                                )
                                    .replace('{rating}', formatRating(product.rating.average) || String(product.rating.average))
                                    .replace('{count}', String(reviewCount))
                                : t('storefront.pdp.goto_reviews_empty', 'No reviews yet. Go to reviews')
                        }
                    >
                        <span className="shop-pdp2-rating-btn__star" aria-hidden="true">★</span>
                        <span className="shop-pdp2-rating-btn__value">
                            {product.rating?.average != null && reviewCount > 0
                                ? (formatRating(product.rating.average) ?? String(product.rating.average))
                                : t('storefront.pdp.no_reviews_yet', 'No reviews yet')}
                        </span>
                        <span className="shop-pdp2-rating-btn__sep" aria-hidden="true">|</span>
                        <span className="shop-pdp2-rating-btn__count">
                            {reviewCount > 0
                                ? (reviewCount === 1
                                    ? t('storefront.pdp.one_review', '1 Review')
                                    : t('storefront.pdp.n_reviews', '{count} Reviews').replace('{count}', String(reviewCount)))
                                : t('storefront.pdp.be_first_review', 'Be the first to review')}
                        </span>
                    </button>
                ) : null}

                {preorderActive ? (
                    <div className="shop-pdp2-preorder is-active">
                        {preorder.show_badge !== false ? (
                            <span className="shop-pdp2-preorder__badge">{t('storefront.pdp.preorder_badge', 'Pre-order')}</span>
                        ) : null}
                        {preorder.message ? <p className="shop-pdp2-preorder__msg">{preorder.message}</p> : null}
                        <PreorderCountdown preorder={preorder} placement="pdp" />
                        {preorder.end_at ? (
                            <p className="shop-pdp2-preorder__meta">
                                {t('storefront.pdp.preorder_ends', 'Pre-order ends')}: {String(preorder.end_at).replace('T', ' ')}
                            </p>
                        ) : null}
                        {preorder.show_release_date && preorder.release_at ? (
                            <p className="shop-pdp2-preorder__meta">
                                {t('storefront.pdp.preorder_release', 'Expected release')}: {String(preorder.release_at).replace('T', ' ')}
                            </p>
                        ) : null}
                        {preorder.show_delivery_date && preorder.expected_delivery_at ? (
                            <p className="shop-pdp2-preorder__meta">
                                {t('storefront.pdp.preorder_delivery', 'Expected delivery')}: {String(preorder.expected_delivery_at).replace('T', ' ')}
                            </p>
                        ) : null}
                        {preorder.payment_type === 'deposit' ? (
                            <p className="shop-pdp2-preorder__meta">
                                {t('storefront.pdp.preorder_deposit_note', 'Pay a deposit now; remaining balance due later.')}
                                {!hidePrice && preorder.remaining_per_unit != null
                                    ? ` ${t('storefront.pdp.preorder_remaining', 'Remaining per unit')}: ${formatMoney(preorder.remaining_per_unit)}`
                                    : ''}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {product.short_description ? (
                    <SafeHtml
                        className="shop-pdp2-short shop-pdp-rich"
                        html={product.short_description}
                    />
                ) : null}

                {(colorOptions.length > 0 || (product.classic_dimensions || product.dimensions || []).length > 0 || fields.length > 0) ? (
                    <div className="shop-pdp2-options">
                        {colorOptions.length > 0 ? (
                            <div>
                                <div className="shop-pdp2-label">
                                    {t('storefront.pdp.color', 'Color')}: <strong>{color || colorOptions[0].name}</strong>
                                </div>
                                <div className="shop-pdp2-swatches" role="radiogroup" aria-label={t('storefront.pdp.color', 'Color')}>
                                    {colorOptions.map((opt) => (
                                        <button
                                            key={opt.name}
                                            type="button"
                                            role="radio"
                                            aria-checked={color === opt.name}
                                            title={opt.name}
                                            className={color === opt.name ? 'is-on' : ''}
                                            style={{ backgroundColor: opt.hex || '#d1d5db' }}
                                            onClick={() => setColor(opt.name)}
                                        />
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {(product.classic_dimensions || []).length > 0 ? (
                            <div>
                                <div className="shop-pdp2-label">
                                    {t('storefront.pdp.size', 'Size')}: <strong>{dimension}</strong>
                                </div>
                                <div className="shop-pdp2-sizes">
                                    {product.classic_dimensions.map((opt) => (
                                        <button
                                            key={opt}
                                            type="button"
                                            className={dimension === opt ? 'is-on' : ''}
                                            onClick={() => setDimension(opt)}
                                        >
                                            {opt}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {fields.map((field) => (
                            <FieldControl
                                key={field.id}
                                field={field}
                                value={values[field.id] ?? ''}
                                file={files[field.id] || null}
                                onValue={(next) => setValues((current) => ({ ...current, [field.id]: next }))}
                                onFile={(next) => setFiles((current) => ({ ...current, [field.id]: next }))}
                            />
                        ))}
                    </div>
                ) : null}

                {allowSub ? (
                    <div className="shop-pdp2-subscribe">
                        {product.subscription_message ? (
                            <p className="shop-pdp2-subscribe__msg">{product.subscription_message}</p>
                        ) : null}
                        {allowOneTime && allowSub ? (
                            <>
                                <label>
                                    <input type="radio" name="purchase-mode" checked={mode === 'one_time'} onChange={() => setMode('one_time')} />
                                    {t('storefront.pdp.one_time', 'One-time purchase')}{hidePrice ? '' : ` — ${formatMoney(toStorefrontAmount(priceCatalog, product, store.shop))}`}
                                </label>
                                <label>
                                    <input type="radio" name="purchase-mode" checked={mode === 'subscription'} onChange={() => setMode('subscription')} />
                                    {t('storefront.pdp.subscribe', 'Subscribe')}
                                </label>
                            </>
                        ) : allowSub ? (
                            <div className="shop-pdp2-subscribe__label">{t('storefront.pdp.subscribe', 'Subscribe')}</div>
                        ) : null}
                        {mode === 'subscription' && allowSub ? (
                            <div className="shop-pdp2-subscribe__plans" role="radiogroup" aria-label={t('storefront.pdp.subscription_plans', 'Subscription plans')}>
                                {plans.map((plan) => {
                                    const selected = String(plan.id) === String(selectedPlan?.id);
                                    const discount = hidePrice ? null : (plan.discount_type === 'percent' && Number(plan.discount_value) > 0
                                        ? `${Number(plan.discount_value)}%`
                                        : (plan.discount_type === 'fixed' && Number(plan.discount_value) > 0
                                            ? formatMoney(plan.discount_value)
                                            : null));
                                    return (
                                        <button
                                            key={plan.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={selected}
                                            className={`shop-pdp2-subscribe__plan${selected ? ' is-selected' : ''}`}
                                            onClick={() => setPlanId(plan.id)}
                                        >
                                            <span className="shop-pdp2-subscribe__plan-head">
                                                <strong>{plan.name}</strong>
                                            </span>
                                            {hidePrice ? null : <span className="shop-pdp2-subscribe__plan-price">{formatMoney(plan.price)}</span>}
                                            {plan.billing_summary ? (
                                                <span className="shop-pdp2-subscribe__plan-meta">{plan.billing_summary}</span>
                                            ) : null}
                                            {plan.trial_enabled && plan.trial_summary && plan.trial_summary !== 'No' ? (
                                                <span className="shop-pdp2-subscribe__plan-meta">
                                                    {t('storefront.pdp.trial', 'Trial')}: {plan.trial_summary}
                                                </span>
                                            ) : null}
                                            {plan.duration_summary ? (
                                                <span className="shop-pdp2-subscribe__plan-meta">{plan.duration_summary}</span>
                                            ) : null}
                                            {discount ? (
                                                <span className="shop-pdp2-subscribe__plan-meta">
                                                    {t('storefront.pdp.discount', 'Discount')}: {discount}
                                                </span>
                                            ) : null}
                                        </button>
                                    );
                                })}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {!hidePrice && product.show_price !== false && product.price != null ? (
                    <div className="shop-pdp2-price">
                        <strong>{formatMoney(total)}</strong>
                        {unitLabel ? (
                            <span className="shop-pdp2-extra">
                                {formatMoney(displayCatalogUnit)} / {unitLabel}
                            </span>
                        ) : null}
                        {product.compare_price && Number(product.compare_price) > Number(product.price) ? (
                            <s>{product.compare_price_formatted || formatMoney(product.compare_price)}</s>
                        ) : null}
                        {product.omnibus_lowest_price != null && product.omnibus_window_days ? (
                            <span className="shop-pdp2-extra">
                                {t(
                                    'storefront.pdp.omnibus_lowest',
                                    'Lowest price in the last {days} days: {price}',
                                )
                                    .replace('{days}', String(product.omnibus_window_days))
                                    .replace(
                                        '{price}',
                                        product.omnibus_lowest_price_formatted
                                            || formatMoney(product.omnibus_lowest_price),
                                    )}
                            </span>
                        ) : null}
                        {off ? <em>{off}% OFF</em> : null}
                        {taxDisplay(store.shop) === 'without_tax' ? (
                            <span className="shop-pdp2-extra">{t('storefront.price.excl_tax', 'excl. tax')}</span>
                        ) : null}
                        {taxDisplay(store.shop) === 'with_tax' ? (
                            <span className="shop-pdp2-extra">{t('storefront.price.incl_tax', 'incl. tax')}</span>
                        ) : null}
                        {taxDisplay(store.shop) === 'both' && product.price_excl_formatted && product.price_incl_formatted
                            && product.price_excl_formatted !== product.price_incl_formatted ? (
                            <span className="shop-pdp2-extra">
                                {product.price_excl_formatted} {t('storefront.price.excl_tax', 'excl. tax')}
                                {' · '}
                                {product.price_incl_formatted} {t('storefront.price.incl_tax', 'incl. tax')}
                            </span>
                        ) : null}
                        {extra > 0 ? (
                            <span className="shop-pdp2-extra">
                                {t('storefront.pdp.incl_custom', 'incl. +{amount} custom').replace(
                                    '{amount}',
                                    formatMoney(toStorefrontAmount(extra, product, store.shop)),
                                )}
                            </span>
                        ) : null}
                    </div>
                ) : (guestPricePrompt(store.shop, store.customer) ? (
                    <p className="text-sm text-[var(--shop-muted)]">{t('storefront.price.sign_in', 'Sign in to see the price')}</p>
                ) : null)}

                {belowPrice}

                {product.type === 'service' ? (
                    <p className="shop-pdp2-delivery">
                        <Icon name="calendar" className="h-4 w-4" />
                        {product.requires_booking || product.service?.requires_booking
                            ? t('storefront.pdp.service_delivery_book', 'Book a date — no physical shipping required')
                            : t('storefront.pdp.service_delivery_none', 'Service item — no physical shipping required')}
                    </p>
                ) : (
                    <p className="shop-pdp2-delivery">
                        <Icon name="truck" className="h-4 w-4" />
                        {product.delivery_from && product.delivery_to
                            ? t('storefront.pdp.delivered_between', 'Delivery: within {from}-{to} days')
                                .replace('{from}', String(product.delivery_from))
                                .replace('{to}', String(product.delivery_to))
                            : t('storefront.pdp.delivered_default', 'Delivery: typically 2–5 business days')}
                    </p>
                )}

                {isCatalogMode(store.shop) || product.contact_for_price ? (
                    <p className="shop-pdp2-unavailable">
                        {product.contact_for_price
                            ? <Link to="/contact">{t('storefront.pdp.contact_for_price', 'Contact us for price')}</Link>
                            : t('storefront.pdp.catalog_only', 'This shop is catalog-only.')}
                    </p>
                ) : product.available_for_order !== false && (allowOneTime || allowSub) ? (
                    <div className="shop-pdp2-actions">
                        {comboGroups.length ? (
                            <OptionPicker
                                groups={comboGroups}
                                picks={picks}
                                onPick={(groupId, valueId) => {
                                    const next = { ...picks, [groupId]: valueId };
                                    setPicks(next);
                                    const match = comboVariants.find((row) => comboGroups.every((group) => (
                                        (row.attribute_value_ids || []).map(String).includes(String(group.id === groupId ? valueId : next[group.id] || ''))
                                    )));
                                    const nextParams = new URLSearchParams(searchParams);
                                    if (match?.id) {
                                        nextParams.set('ipa', String(match.id));
                                        nextParams.delete('combination');
                                    } else {
                                        nextParams.delete('ipa');
                                    }
                                    setSearchParams(nextParams, { replace: true, state: location.state });
                                }}
                            />
                        ) : null}
                        {isRental && rentalBooking ? (
                            <div className="shop-field space-y-2">
                                <label className="shop-field">
                                    <span className="shop-field-label">{t('storefront.pdp.rental_start', 'Start date')}</span>
                                    <input
                                        className="shop-input"
                                        type="date"
                                        required
                                        value={rentalBooking.start || ''}
                                        onChange={(e) => rentalBooking.setStart(e.target.value)}
                                    />
                                </label>
                                <label className="shop-field">
                                    <span className="shop-field-label">{t('storefront.pdp.rental_duration', 'Duration')}</span>
                                    <input
                                        className="shop-input"
                                        type="number"
                                        min={rentalBooking.min}
                                        max={rentalBooking.max}
                                        required
                                        value={rentalBooking.duration}
                                        onChange={(e) => {
                                            const next = Number(e.target.value) || rentalBooking.min;
                                            rentalBooking.setDuration(Math.min(rentalBooking.max, Math.max(rentalBooking.min, next)));
                                        }}
                                    />
                                </label>
                                <label className="shop-field">
                                    <span className="shop-field-label">{t('storefront.pdp.rental_end', 'End date')}</span>
                                    <input
                                        className="shop-input"
                                        type="date"
                                        required
                                        value={rentalBooking.end || ''}
                                        onChange={(e) => {
                                            rentalBooking.setEnd(e.target.value);
                                            if (rentalBooking.start && e.target.value) {
                                                const a = new Date(`${rentalBooking.start}T12:00:00`);
                                                const b = new Date(`${e.target.value}T12:00:00`);
                                                const days = Math.max(1, Math.round((b - a) / 86400000) + 1);
                                                rentalBooking.setDuration(Math.min(rentalBooking.max, Math.max(rentalBooking.min, days)));
                                            }
                                        }}
                                    />
                                </label>
                            </div>
                        ) : null}
                        {needsBooking ? (
                            <div className="shop-pdp2-booking">
                                <label className="shop-field">
                                    <span className="shop-field-label">{t('storefront.pdp.booking_date', 'Booking date')}</span>
                                    <input
                                        className="shop-input"
                                        type="date"
                                        required
                                        value={bookingDate}
                                        onChange={(e) => setBookingDate(e.target.value)}
                                    />
                                </label>
                            </div>
                        ) : null}
                        <div className="shop-pdp2-purchase">
                            {!hideQty ? (
                            <div
                                className="shop-pdp2-qty"
                                aria-label={unitLabel
                                    ? t('storefront.pdp.quantity_unit', 'Quantity ({unit})').replace('{unit}', unitLabel)
                                    : t('storefront.pdp.quantity', 'Quantity')}
                            >
                                <button
                                    type="button"
                                    onClick={() => setQty((q) => snapOrderQty(Number(q) - step, minQty, step, maxQty))}
                                    disabled={Number(qty) <= minQty}
                                    aria-label="-"
                                >−</button>
                                <input
                                    type="number"
                                    min={minQty}
                                    step={step}
                                    max={maxQty || undefined}
                                    value={qty}
                                    onChange={(e) => setQty(snapOrderQty(e.target.value, minQty, step, maxQty))}
                                />
                                <button
                                    type="button"
                                    onClick={() => setQty((q) => snapOrderQty(Number(q) + step, minQty, step, maxQty))}
                                    disabled={maxQty != null && Number(qty) >= maxQty}
                                    aria-label="+"
                                >+</button>
                            </div>
                            ) : null}
                            {!hideCart ? (
                            <button type="button" className="shop-pdp2-cart" disabled={busy || !inStock} onClick={add}>
                                <Icon name="cart" className="h-4 w-4" />
                                {busy
                                    ? t('storefront.cart.adding', 'Adding…')
                                    : (preorderActive
                                        ? (preorder?.cta_label || t('storefront.pdp.preorder_now', 'Pre-order now'))
                                        : (!inStock
                                            ? t('storefront.pdp.out_of_stock', 'Out of stock')
                                            : t('storefront.pdp.add_to_cart', 'Add to Cart')))}
                            </button>
                            ) : null}
                            {!inStock && stockAlertEnabled ? (
                                stockAlertSubscribed ? (
                                    store.customer ? (
                                        <button
                                            type="button"
                                            className="shop-pdp2-stock-alert-btn is-subscribed"
                                            disabled={busy}
                                            onClick={unsubscribeStockAlert}
                                            title={t('storefront.pdp.stock_alert_subscribed', 'Subscribed to stock alert')}
                                        >
                                            <Icon name="bell" className="h-4 w-4" />
                                            {t('storefront.pdp.stock_alert_unsubscribe', 'Unsubscribe')}
                                        </button>
                                    ) : (
                                        <span className="shop-pdp2-stock-alert-btn is-subscribed" role="status">
                                            <Icon name="bell" className="h-4 w-4" />
                                            {t('storefront.pdp.stock_alert_subscribed', 'Subscribed to stock alert')}
                                        </span>
                                    )
                                ) : (
                                    <button
                                        type="button"
                                        className="shop-pdp2-stock-alert-btn"
                                        disabled={busy}
                                        onClick={subscribeStockAlert}
                                    >
                                        <Icon name="bell" className="h-4 w-4" />
                                        {t('storefront.pdp.notify_when_available', 'Notify me when available')}
                                    </button>
                                )
                            ) : null}
                            {preorderActive && preorder?.notify_me_enabled ? (
                                <button
                                    type="button"
                                    className={`shop-pdp2-notify${notifySubscribed ? ' is-on' : ''}`}
                                    disabled={busy || notifyBusy}
                                    onClick={openNotifyModal}
                                >
                                    {notifySubscribed
                                        ? t('storefront.pdp.notified', 'Notified')
                                        : t('storefront.pdp.notify_me_btn', 'Notify Me')}
                                </button>
                            ) : null}
                            <ModuleBlocks slots={actionSlots.filter((slot) => (slot.variant || 'primary') === 'primary')} inline />
                        </div>
                        {qtyRuleText || cartQtyText ? (
                            <p className="shop-qty-rules">
                                {qtyRuleText}
                                {qtyRuleText && cartQtyText ? ' ' : null}
                                {cartQtyText}
                            </p>
                        ) : null}
                        {!inStock && !preorderActive ? (
                            <p className="shop-pdp2-unavailable">
                                {t('storefront.pdp.out_of_stock_reason', 'Out of stock. This product cannot be added to the cart.')}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <p className="shop-pdp2-unavailable">{t('storefront.pdp.not_available', 'This product is not available for order.')}</p>
                )}
                <div className="shop-pdp2-secondary">
                    {store.shop?.products?.compare_enabled !== false ? (
                        <button type="button" onClick={compare}>
                            <Icon name="compare" className="h-4 w-4" />
                            {t('storefront.pdp.add_to_compare', 'Add to Compare')}
                        </button>
                    ) : null}
                    {wishlistEnabled(store.shop) ? (
                    <button type="button" className={wished ? 'is-on' : ''} onClick={wish}>
                        <Icon name="heart" className="h-4 w-4" />
                        {t('storefront.pdp.add_to_wishlist', 'Add to Wishlist')}
                    </button>
                    ) : null}
                    <ModuleBlocks slots={actionSlots.filter((slot) => (slot.variant || 'primary') !== 'primary')} inline />
                </div>

                {msg ? <p className={`shop-pdp2-msg${msgTone === 'warning' ? ' is-warning' : ''}`}>{msg}</p> : null}

                <div className="shop-pdp2-trust">
                    <div><Icon name="lock" className="h-5 w-5" /><span>{t('storefront.pdp.secure_payments', 'Secure Payments')}</span></div>
                    {returnsEnabled(store.shop) ? (
                        <div><Icon name="return" className="h-5 w-5" /><span>{t('storefront.pdp.easy_returns', 'Easy Returns')}</span></div>
                    ) : null}
                    <div><Icon name="truck" className="h-5 w-5" /><span>{t('storefront.pdp.free_shipping_hint', 'Free Shipping over $75')}</span></div>
                    <div><Icon name="help" className="h-5 w-5" /><span>{t('storefront.pdp.support_247', '24/7 Support')}</span></div>
                </div>
                {(trustSlots || []).length ? <ModuleBlocks slots={trustSlots} /> : null}
            </div>
        </div>
        <StockAlertGuestModal
            open={stockAlertGuestOpen}
            productId={product.id}
            productVariantId={selectedVariant?.id || null}
            onClose={() => setStockAlertGuestOpen(false)}
            onSubscribed={markStockAlertSubscribed}
        />
        {notifyModal && typeof document !== 'undefined' ? createPortal(
            <div className="cx-modal" role="dialog" aria-modal="true" aria-label={t('storefront.pdp.notify_me_btn', 'Notify Me')}>
                <button type="button" className="cx-modal-backdrop" aria-label={t('storefront.common.close', 'Close')} onClick={() => setNotifyModal(null)} />
                <div className="cx-modal-panel is-compact">
                    <header className="cx-modal-head">
                        <h2>{notifyModal === 'unsubscribe'
                            ? t('storefront.pdp.notified', 'Notified')
                            : t('storefront.pdp.notify_me_btn', 'Notify Me')}</h2>
                        <button type="button" className="cx-modal-close" onClick={() => setNotifyModal(null)} aria-label={t('storefront.common.close', 'Close')}>×</button>
                    </header>
                    <div className="cx-modal-body">
                        {notifyModal === 'unsubscribe' ? (
                            <>
                                <p className="cx-modal-message">
                                    {t('storefront.pdp.notify_already', 'You have already subscribed to notifications for this product.')}
                                </p>
                                <p className="cx-modal-message">
                                    {t('storefront.pdp.notify_unsubscribe_hint', 'Please click to unsubscribe.')}
                                </p>
                                <div className="shop-pdp2-notify-actions">
                                    <button type="button" className="cx-modal-action" disabled={notifyBusy} onClick={confirmNotifyUnsubscribe}>
                                        {t('storefront.pdp.unsubscribe', 'Unsubscribe')}
                                    </button>
                                    <button type="button" className="shop-pdp2-notify-cancel" disabled={notifyBusy} onClick={() => setNotifyModal(null)}>
                                        {t('storefront.common.cancel', 'Cancel')}
                                    </button>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="cx-modal-message">
                                    {t('storefront.pdp.notify_confirm', 'Do you want to subscribe to notifications for this product?')}
                                </p>
                                <div className="shop-pdp2-notify-actions">
                                    <button type="button" className="cx-modal-action" disabled={notifyBusy} onClick={confirmNotifySubscribe}>
                                        {t('storefront.common.yes', 'Yes')}
                                    </button>
                                    <button type="button" className="shop-pdp2-notify-cancel" disabled={notifyBusy} onClick={() => setNotifyModal(null)}>
                                        {t('storefront.common.no', 'No')}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>,
            document.body,
        ) : null}
        </>
    );
}
