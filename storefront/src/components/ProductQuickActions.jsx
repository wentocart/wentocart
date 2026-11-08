import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useStore } from '../state/StoreState.jsx';
import { entityState, productPath } from '../lib/links.js';
import { t } from '../i18n.js';
import { Icon } from './Icons.jsx';
import { isCatalogMode, productCanAddToCart, wishlistEnabled } from '../lib/commerce.js';

/**
 * Icon-only cart / wishlist / quick-view actions shared by listing cards and compare.
 * Quick view uses the same product-page entry as ProductCard (no separate modal).
 */
export default function ProductQuickActions({ product, className = '' }) {
    const store = useStore();
    const [busy, setBusy] = useState(false);
    const href = productPath(product);
    const wished = store.wishlistIds.includes(product.id);
    const wishOn = wishlistEnabled(store.shop);
    const catalogOnly = isCatalogMode(store.shop);
    const contactPrice = !!product.contact_for_price;
    const canCart = !catalogOnly && !contactPrice;

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

    const wish = (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (store.customer) {
            store.toggleWish(product.id);
            return;
        }
        store.ping(t('storefront.account.sign_in_wishlist', 'Sign in to save items'), 'info');
    };

    return (
        <div className={`shop-product-quick-actions ${className}`.trim()}>
            {canCart ? (
                <button
                    type="button"
                    disabled={busy || !productCanAddToCart(product, store.shop)}
                    onPointerDown={(e) => e.stopPropagation()}
                    onClick={add}
                    aria-label={t('storefront.cart.add_to_cart', 'Add to Cart')}
                    title={t('storefront.cart.add_to_cart', 'Add to Cart')}
                >
                    <Icon name="cart" className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            ) : null}
            <Link
                to={href}
                state={entityState('product')}
                aria-label={t('storefront.shell.quick_view', 'Quick view')}
                title={t('storefront.shell.quick_view', 'Quick view')}
                onPointerDown={(e) => e.stopPropagation()}
            >
                <Icon name="zoom" className="h-3.5 w-3.5" aria-hidden="true" />
            </Link>
            {wishOn ? (
                <button
                    type="button"
                    aria-label={t('storefront.shell.wishlist', 'Wishlist')}
                    title={t('storefront.shell.wishlist', 'Wishlist')}
                    className={wished ? 'is-on' : ''}
                    onPointerDown={(e) => e.stopPropagation()}
                    onClick={wish}
                >
                    <Icon name="heart" className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            ) : null}
        </div>
    );
}
