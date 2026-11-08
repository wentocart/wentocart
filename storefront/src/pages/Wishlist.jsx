import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { discountPercent, productPrice, formatMoney } from '../lib/format.js';
import { brandPath, entityState, productPath } from '../lib/links.js';
import { useStore } from '../state/StoreState.jsx';
import { guestPricePrompt, pricesHidden } from '../lib/commerce.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ProductCard from '../components/ProductCard.jsx';
import ProductImage from '../components/ProductImage.jsx';
import Stars from '../components/Stars.jsx';

function inStock(product) {
    if (product?.available_for_order === false) {
        return false;
    }
    const status = String(product?.stock_status || '').toLowerCase();
    if (['out_of_stock', 'out-of-stock', 'unavailable'].includes(status)) {
        return false;
    }
    if (product?.stock_qty != null && Number(product.stock_qty) <= 0 && status !== 'in_stock') {
        return false;
    }
    return true;
}

export default function Wishlist() {
    const store = useStore();
    const [rows, setRows] = useState(null);
    const [suggestions, setSuggestions] = useState([]);
    const [busyId, setBusyId] = useState(null);
    const [movingAll, setMovingAll] = useState(false);

    const reload = () => api.get('/account/wishlist')
        .then((r) => setRows(r.data.wishlist || []))
        .catch(() => setRows([]));

    useEffect(() => {
        reload();
    }, [store.wishlistIds]);

    useEffect(() => {
        api.get('/catalog/home')
            .then((r) => {
                const pool = [...(r.data.featured || []), ...(r.data.new_arrivals || []), ...(r.data.bestsellers || [])];
                const seen = new Set();
                const next = [];
                for (const product of pool) {
                    if (!product?.id || seen.has(product.id)) continue;
                    if (store.wishlistIds.includes(product.id)) continue;
                    seen.add(product.id);
                    next.push(product);
                    if (next.length >= 4) break;
                }
                setSuggestions(next);
            })
            .catch(() => setSuggestions([]));
    }, [store.wishlistIds]);

    const stocked = useMemo(() => (rows || []).filter((p) => inStock(p) && p.available_for_order !== false), [rows]);

    const remove = async (product) => {
        if (busyId) return;
        setBusyId(product.id);
        try {
            await store.toggleWish(product.id);
        } finally {
            setBusyId(null);
        }
    };

    const addOne = async (product) => {
        if (busyId || !inStock(product)) return;
        setBusyId(product.id);
        try {
            await store.addToCart(product.id, 1);
        } catch (err) {
            store.ping?.(err.response?.data?.message || t('storefront.account.add_failed', 'Could not add to cart.'), 'error');
        } finally {
            setBusyId(null);
        }
    };

    const moveAll = async () => {
        if (movingAll || stocked.length === 0) return;
        setMovingAll(true);
        try {
            for (const product of stocked) {
                await store.addToCart(product.id, 1, { open: false, notify: false });
            }
            store.openCart?.();
            store.ping?.(t('storefront.account.moved_all_cart', 'Moved available items to cart'));
        } catch (err) {
            store.ping?.(err.response?.data?.message || t('storefront.account.add_failed', 'Could not add to cart.'), 'error');
        } finally {
            setMovingAll(false);
        }
    };

    const share = async () => {
        try {
            await csrf();
            const { data } = await api.post('/account/wishlist/share');
            const url = data.url || `${window.location.origin}/wishlist/shared/${data.token}`;
            if (navigator.share) {
                await navigator.share({
                    title: t('storefront.shell.wishlist', 'Wishlist'),
                    text: t('storefront.account.share_wishlist_text', 'Check out my wishlist'),
                    url,
                });
                return;
            }
            await navigator.clipboard.writeText(url);
            store.ping?.(t('storefront.account.link_copied', 'Wishlist link copied'));
        } catch {
            store.ping?.(t('storefront.account.share_failed', 'Could not share wishlist'), 'error');
        }
    };

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-wishlist">
            <header className="shop-wishlist-head">
                <div>
                    <h1>
                        <Icon name="heart" className="h-5 w-5" />
                        {t('storefront.account.my_wishlist', 'My Wishlist')}
                        {rows.length > 0 ? <span>({rows.length})</span> : null}
                    </h1>
                    <p>{t('storefront.account.wishlist_lead', 'Items you love, saved for later.')}</p>
                </div>
                {rows.length > 0 ? (
                    <div className="shop-wishlist-actions">
                        <button type="button" className="shop-wishlist-share" onClick={share}>
                            <Icon name="send" className="h-4 w-4" />
                            {t('storefront.account.share_wishlist', 'Share Wishlist')}
                        </button>
                        <button
                            type="button"
                            className="shop-wishlist-move-all"
                            disabled={movingAll || stocked.length === 0}
                            onClick={moveAll}
                        >
                            <Icon name="cart" className="h-4 w-4" />
                            {movingAll
                                ? t('storefront.common.please_wait', 'Please wait…')
                                : t('storefront.account.move_all_cart', 'Move All to Cart')}
                        </button>
                    </div>
                ) : null}
            </header>

            {rows.length === 0 ? (
                <div className="shop-wishlist-empty">
                    <div className="shop-wishlist-empty-art" aria-hidden="true">
                        <WishlistEmptyArt />
                    </div>
                    <h2>{t('storefront.account.empty_wishlist', 'Your wishlist is empty')}</h2>
                    <p>{t('storefront.account.wishlist_body', "Looks like you haven't saved anything yet. Start exploring and add items you love!")}</p>
                    <Link to="/catalog" className="shop-wishlist-cta">
                        {t('storefront.account.explore_products', 'Explore Products')}
                    </Link>
                </div>
            ) : (
                <section className="shop-wishlist-panel shop-fade-up">
                    <ul className="shop-wishlist-list">
                        {rows.map((product, index) => {
                            const href = productPath(product);
                            const brandHref = product.brand?.name ? brandPath(product.brand) : null;
                            const hidePrice = pricesHidden(store.shop, store.customer) || product.show_price === false;
                            const off = hidePrice ? null : discountPercent(product);
                            const was = hidePrice ? null : (product.compare_price_formatted
                                || (product.compare_price ? formatMoney(product.compare_price) : null));
                            const stockOk = inStock(product);
                            const busy = busyId === product.id;

                            return (
                                <li
                                    key={product.id}
                                    className="shop-wishlist-row"
                                    style={{ animationDelay: `${Math.min(index, 8) * 40}ms` }}
                                >
                                    <Link to={href} state={entityState('product')} className="shop-wishlist-thumb">
                                        {off ? <span className="shop-wishlist-sale">-{off}%</span> : null}
                                        <ProductImage product={product} fit="contain" />
                                    </Link>

                                    <div className="shop-wishlist-info">
                                        <Link to={href} state={entityState('product')} className="shop-wishlist-name">
                                            {product.name}
                                        </Link>
                                        {brandHref ? (
                                            <Link to={brandHref} state={entityState('brand')} className="shop-wishlist-brand">
                                                {product.brand.name}
                                            </Link>
                                        ) : null}
                                        <div className="shop-wishlist-rating">
                                            {product.rating?.average != null && Number(product.rating?.count || 0) > 0 ? (
                                                <>
                                                    <Stars value={product.rating.average} compact />
                                                    <span>({Number(product.rating.count).toLocaleString()})</span>
                                                </>
                                            ) : null}
                                        </div>
                                        <div className="shop-wishlist-price">
                                            {hidePrice ? (
                                                guestPricePrompt(store.shop, store.customer)
                                                    ? <span>{t('storefront.price.sign_in', 'Sign in to see the price')}</span>
                                                    : null
                                            ) : (
                                                <strong>{productPrice(product)}</strong>
                                            )}
                                            {was && off ? <em>{was}</em> : null}
                                        </div>
                                        <div className="shop-wishlist-meta">
                                            <span className={`shop-wishlist-stock ${stockOk ? 'is-in' : 'is-out'}`}>
                                                {stockOk
                                                    ? t('storefront.account.in_stock', 'In Stock')
                                                    : t('storefront.account.out_of_stock', 'Out of stock')}
                                            </span>
                                            {stockOk ? (
                                                <span className="shop-wishlist-ship">
                                                    <Icon name="truck" className="h-3.5 w-3.5" />
                                                    {t('storefront.account.free_delivery_note', 'Fast delivery')}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>

                                    <div className="shop-wishlist-row-actions">
                                        <button
                                            type="button"
                                            className="shop-wishlist-watch"
                                            disabled={busy}
                                            onClick={async () => {
                                                await csrf();
                                                await api.post('/account/price-alerts', { product_id: product.id });
                                                store.ping?.(t('storefront.account.price_watch', 'Watching for a price drop'), 'info');
                                            }}
                                        >
                                            <Icon name="bell" className="h-4 w-4" />
                                            {t('storefront.account.watch_price', 'Watch price')}
                                        </button>
                                        <button
                                            type="button"
                                            className="shop-wishlist-add"
                                            disabled={busy || !stockOk}
                                            onClick={() => addOne(product)}
                                        >
                                            <Icon name="cart" className="h-4 w-4" />
                                            {busy
                                                ? t('storefront.common.please_wait', 'Please wait…')
                                                : t('storefront.account.add_to_cart', 'Add to cart')}
                                        </button>
                                        <button
                                            type="button"
                                            className="shop-wishlist-remove"
                                            disabled={busy}
                                            aria-label={t('storefront.account.remove', 'Remove')}
                                            title={t('storefront.account.remove', 'Remove')}
                                            onClick={() => remove(product)}
                                        >
                                            <Icon name="trash" className="h-4 w-4" />
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}

            {suggestions.length > 0 ? (
                <section className="shop-wishlist-suggest shop-fade-up" style={{ animationDelay: '80ms' }}>
                    <header className="shop-wishlist-suggest-head">
                        <h2>{t('storefront.account.you_might_like', 'You might also like')}</h2>
                        <Link to="/catalog">{t('storefront.account.view_all', 'View all')} →</Link>
                    </header>
                    <div className="shop-wishlist-suggest-grid">
                        {suggestions.map((product) => (
                            <ProductCard key={product.id} product={product} compact />
                        ))}
                    </div>
                </section>
            ) : null}
        </div>
    );
}

function WishlistEmptyArt() {
    return (
        <svg viewBox="0 0 120 120" fill="none" className="shop-wishlist-svg">
            <circle cx="60" cy="60" r="56" fill="color-mix(in srgb, var(--shop-accent, #4545e6) 10%, #fff)" />
            <path
                d="M60 88s-22-13.5-22-30a12.5 12.5 0 0 1 22-7 12.5 12.5 0 0 1 22 7c0 16.5-22 30-22 30Z"
                fill="color-mix(in srgb, var(--shop-accent, #4545e6) 75%, #312e81)"
            />
            <circle cx="88" cy="34" r="10" fill="#fff" stroke="color-mix(in srgb, var(--shop-accent, #4545e6) 35%, #fff)" strokeWidth="2" />
            <path d="M88 29v10M83 34h10" stroke="var(--shop-accent, #4545e6)" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}
