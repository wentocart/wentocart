import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useStore } from '../state/StoreState.jsx';
import { discountPercent, productPrice } from '../lib/format.js';
import { brandPath, entityState, productPath } from '../lib/links.js';
import { t } from '../i18n.js';
import { Icon } from './Icons.jsx';
import Price from './Price.jsx';
import ProductImage from './ProductImage.jsx';
import ProductQuickActions from './ProductQuickActions.jsx';
import { guestPricePrompt, hasProductRating, isCatalogMode, pricesHidden, productCanAddToCart, reviewsEnabled, taxDisplay, wishlistEnabled } from '../lib/commerce.js';
import Stars from './Stars.jsx';
import PreorderCountdown from './PreorderCountdown.jsx';
import { showPreorderImageLabel } from '../lib/preorderCountdown.js';

export default function ProductCard({ product, priority = false, compact = false, variant = 'classic' }) {
    const store = useStore();
    const [busy, setBusy] = useState(false);
    const hidePrice = product?.show_price === false || pricesHidden(store.shop, store.customer);
    const pricePrompt = guestPricePrompt(store.shop, store.customer);
    const off = hidePrice ? null : discountPercent(product);
    const wished = store.wishlistIds.includes(product.id);
    const href = productPath(product);
    const brandHref = product.brand?.name ? brandPath(product.brand) : null;
    const listing = variant === 'listing';
    const ratingCount = Number(product.rating?.count || 0);
    const showRating = reviewsEnabled(store.shop) && hasProductRating(product);
    const price = hidePrice ? '' : productPrice(product);
    const was = hidePrice || store.shop?.commerce?.pricing?.compare_at_enabled === false
        ? null
        : (product.compare_price_formatted || null);
    const compareOn = store.shop?.products?.compare_enabled !== false;
    const comparing = compareOn && (store.compare || []).map(Number).includes(Number(product.id));
    const wishOn = wishlistEnabled(store.shop);
    const catalogOnly = isCatalogMode(store.shop);
    const contactPrice = !!product.contact_for_price;
    const compareLabel = comparing
        ? t('storefront.compare.remove', 'Remove from comparison')
        : t('storefront.pdp.add_to_compare', 'Add to Compare');

    const add = async (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (busy || !productCanAddToCart(product, store.shop)) {
            return;
        }
        setBusy(true);
        try {
            await store.addToCart(product.id, Number(product.min_qty) || 1);
        } catch {
            store.ping(t('storefront.cart.add_failed', 'Could not add to cart'), 'error');
        } finally {
            setBusy(false);
        }
    };

    if (listing) {
        const showAtc = !catalogOnly && !contactPrice;
        const showContact = contactPrice;
        const showActions = showAtc || showContact || wishOn || compareOn;

        return (
            <article className={`shop-list-card${comparing ? ' is-compared' : ''}`}>
                <div className="shop-list-card-media">
                    {off ? <span className="shop-list-card-badge">-{off}%</span> : null}
                    {product.flags?.new ? <span className="shop-product-new-badge">{t('storefront.search.new', 'New')}</span> : null}
                    {showPreorderImageLabel(product) ? (
                        <span className="shop-product-new-badge is-preorder">{t('storefront.pdp.preorder_badge', 'Pre-order')}</span>
                    ) : null}
                    <Link to={href} state={entityState('product')} className="shop-list-card-image">
                        <ProductImage product={product} priority={priority} fit="contain" />
                    </Link>
                </div>
                <div className="shop-list-card-body">
                    <Link to={href} state={entityState('product')} className="shop-list-card-name">
                        {product.name}
                    </Link>
                    {brandHref ? (
                        <Link to={brandHref} state={entityState('brand')} className="shop-list-card-brand">
                            {product.brand.name}
                        </Link>
                    ) : null}
                    <div className="shop-list-card-rating">
                        {showRating ? (
                            <>
                                <Stars value={product.rating.average} count={ratingCount} compact />
                                <span>({ratingCount.toLocaleString()})</span>
                            </>
                        ) : null}
                    </div>
                    <div className="shop-list-card-price">
                        {price ? <strong>{price}</strong> : null}
                        {off && was ? <s>{was}</s> : null}
                        {off ? <em>{off}% OFF</em> : null}
                        {!price && pricePrompt ? <span>{t('storefront.price.sign_in', 'Sign in to see the price')}</span> : null}
                        {price && taxDisplay(store.shop) === 'without_tax' ? (
                            <span className="shop-list-card-tax">{t('storefront.price.excl_tax', 'excl. tax')}</span>
                        ) : null}
                        {price && taxDisplay(store.shop) === 'with_tax' ? (
                            <span className="shop-list-card-tax">{t('storefront.price.incl_tax', 'incl. tax')}</span>
                        ) : null}
                        {price && taxDisplay(store.shop) === 'both'
                            && product.price_excl_formatted
                            && product.price_incl_formatted
                            && product.price_excl_formatted !== product.price_incl_formatted ? (
                            <span className="shop-list-card-tax">
                                {product.price_excl_formatted} {t('storefront.price.excl_tax', 'excl. tax')}
                                {' · '}
                                {product.price_incl_formatted} {t('storefront.price.incl_tax', 'incl. tax')}
                            </span>
                        ) : null}
                    </div>
                    <PreorderCountdown preorder={product.preorder} placement="listing" />
                    {showActions ? (
                        <div className="shop-list-card-actions">
                            {showContact ? (
                                <Link to="/contact" className="shop-list-card-atc">
                                    {t('storefront.pdp.contact_for_price', 'Contact us')}
                                </Link>
                            ) : null}
                            {showAtc ? (
                                <button
                                    type="button"
                                    className="shop-list-card-atc"
                                    disabled={busy || !productCanAddToCart(product, store.shop)}
                                    onClick={add}
                                >
                                    <Icon name="cart" className="h-4 w-4" />
                                    {busy
                                        ? t('storefront.cart.adding', 'Adding…')
                                        : (!productCanAddToCart(product, store.shop)
                                            ? t('storefront.pdp.out_of_stock', 'Out of stock')
                                            : t('storefront.cart.add_to_cart', 'Add to Cart'))}
                                </button>
                            ) : null}
                            {wishOn ? (
                                <button
                                    type="button"
                                    className={`shop-list-card-wish${wished ? ' is-on' : ''}`}
                                    aria-label={t('storefront.shell.wishlist', 'Wishlist')}
                                    title={t('storefront.shell.wishlist', 'Wishlist')}
                                    onClick={() => (store.customer
                                        ? store.toggleWish(product.id)
                                        : store.ping(t('storefront.account.sign_in_wishlist', 'Sign in to save items'), 'info'))}
                                >
                                    <Icon name="heart" className="h-4 w-4" />
                                </button>
                            ) : null}
                            {compareOn ? (
                                <button
                                    type="button"
                                    className={`shop-list-card-compare${comparing ? ' is-on' : ''}`}
                                    aria-label={compareLabel}
                                    aria-pressed={comparing}
                                    title={compareLabel}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        store.toggleCompare(product.id);
                                    }}
                                >
                                    <Icon name="compare" className="h-4 w-4" />
                                </button>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            </article>
        );
    }

    return (
        <article className={`cx-card group${comparing ? ' is-compared' : ''}`}>
            <div className="cx-card-media">
                <div className="cx-card-badges">
                    {off ? <span className="is-sale">-{off}%</span> : null}
                    {product.flags?.new ? <span className="is-new shop-product-new-badge">{t('storefront.search.new', 'New')}</span> : null}
                    {showPreorderImageLabel(product) ? (
                        <span className="is-preorder">{t('storefront.pdp.preorder_badge', 'Pre-order')}</span>
                    ) : null}
                </div>
                <div className="cx-card-hover">
                    <ProductQuickActions product={product} />
                    {compareOn ? (
                        <button
                            type="button"
                            className={comparing ? 'is-comparing' : undefined}
                            aria-label={compareLabel}
                            aria-pressed={comparing}
                            title={compareLabel}
                            onPointerDown={(e) => e.stopPropagation()}
                            onClick={() => store.toggleCompare(product.id)}
                        >
                            <Icon name="compare" className="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    ) : null}
                </div>
                <Link to={href} state={entityState('product')} className="block aspect-square">
                    <ProductImage product={product} priority={priority} fit="contain" className="cx-card-primary-image" />
                    {product.hover_url ? (
                        <img
                            src={product.hover_url}
                            alt={product.name || ''}
                            className="cx-card-hover-image"
                            loading="lazy"
                        />
                    ) : null}
                </Link>
            </div>
            <div className="cx-card-body">
                <Link to={href} state={entityState('product')} className={compact ? 'line-clamp-2 min-h-10 text-sm' : 'line-clamp-2 min-h-12'}>
                    {product.name}
                </Link>
                {brandHref ? (
                    <Link to={brandHref} state={entityState('brand')} className="cx-card-brand">
                        {product.brand.name}
                    </Link>
                ) : null}
                {showRating ? (
                    <div className="cx-card-rating">
                        <Stars value={product.rating.average} count={ratingCount} compact />
                        <span className="cx-card-rating-count">({ratingCount.toLocaleString()})</span>
                    </div>
                ) : null}
                <Price product={product} />
                <PreorderCountdown preorder={product.preorder} placement="listing" />
            </div>
        </article>
    );
}
