import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import api, { csrf } from '../api.js';
import { compareIds as readCompare, toggleCompare as writeCompare, setInstallNamespace } from '../lib/storage.js';
import { setPriceFormat } from '../lib/format.js';
import { t } from '../i18n.js';
import { subscribeShopSession } from './shopSession.js';
import { completeCartAdd, isCatalogMode, wishlistEnabled } from '../lib/commerce.js';

import { useGrowl } from '../components/Growl.jsx';

const StoreContext = createContext(null);

export function StoreProvider({ children }) {
    const growl = useGrowl();
    const [shop, setShop] = useState(null);
    const [shopError, setShopError] = useState(false);
    const [cart, setCart] = useState(null);
    const [customer, setCustomer] = useState(undefined);
    const [wishlistIds, setWishlistIds] = useState([]);
    const [compare, setCompare] = useState(readCompare());
    const [cartOpen, setCartOpen] = useState(false);

    const loadCart = (params = {}) => api.get('/cart', {
        params,
    }).then((r) => setCart(r.data)).catch(() => setCart({ cart: { items: [] }, totals: {} }));
    const loadMe = () => api.get('/auth/me').then((r) => setCustomer(r.data.customer || null)).catch(() => setCustomer(null));
    const loadWishlist = () => api.get('/account/wishlist').then((r) => {
        const rows = r.data.wishlist || [];
        setWishlistIds(rows.map((p) => p.id));
    }).catch(() => setWishlistIds([]));

    const loadShop = () => api.get('/shop').then((r) => {
        const installId = r.data?.shop?.install_id || r.data?.install_id || '';
        setInstallNamespace(installId);
        setCompare(readCompare());
        setShop(r.data);
    }).catch(() => setShopError(true));

    useEffect(() => {
        loadShop();
        loadCart();
        loadMe();
    }, []);

    useEffect(() => subscribeShopSession(() => {
        loadShop();
        loadCart();
    }), []);

    useEffect(() => {
        if (customer) {
            loadWishlist();
        }
    }, [customer]);

    if (shop?.commerce?.pricing) {
        setPriceFormat(shop.commerce.pricing);
    }

    const ping = (message, type = 'success') => {
        if (growl && growl.push) {
            growl.push({ type, message });
        }
    };

    const openCart = () => setCartOpen(true);
    const closeCart = () => setCartOpen(false);

    const value = useMemo(() => ({
        shop,
        shopError,
        cart,
        customer,
        wishlistIds,
        compare,
        cartOpen,
        cartCount: cart?.cart?.items?.reduce((sum, item) => sum + Number(item.qty || 0), 0) || 0,
        reloadCart: loadCart,
        applyCart: setCart,
        reloadShop: loadShop,
        reloadMe: loadMe,
        openCart,
        closeCart,
        ping,
        setCustomer,
        async addToCart(productId, qty = 1, options = {}) {
            if (isCatalogMode(shop)) {
                ping('This shop is catalog-only', 'error');
                return cart;
            }
            await csrf();
            const { data } = await api.post('/cart', { product_id: productId, qty });
            setCart(data);
            completeCartAdd({
                ping,
                openCart: () => setCartOpen(true),
                data,
                open: options.open !== false,
                notify: options.notify,
            });
            return data;
        },
        async toggleWish(productId) {
            if (!wishlistEnabled(shop)) {
                ping('Wishlist is disabled', 'error');
                return false;
            }
            await csrf();
            const { data } = await api.post('/account/wishlist', { product_id: productId });
            setWishlistIds((ids) => (data.wishlisted ? [...new Set([...ids, productId])] : ids.filter((id) => id !== productId)));
            ping(data.wishlisted ? 'Saved to wishlist' : 'Removed from wishlist');
            return data.wishlisted;
        },
        toggleCompare(id) {
            if (shop?.products?.compare_enabled === false) {
                ping(t('storefront.compare.disabled', 'Product comparison is disabled'), 'error');
                return compare;
            }
            const max = shop?.products?.compare_max || 4;
            const result = writeCompare(id, max);
            const next = Array.isArray(result?.ids) ? result.ids : compare;
            if (result?.action !== 'limit' && result?.action !== 'noop') {
                setCompare(next);
            }
            if (result?.action === 'added') {
                ping(t('storefront.compare.added', 'Added to compare'));
            } else if (result?.action === 'removed') {
                ping(t('storefront.compare.removed', 'Removed from compare'));
            } else if (result?.action === 'limit') {
                const limit = result.limit || max;
                ping(
                    t(
                        'storefront.compare.limit_reached',
                        'You can compare up to :max products at a time. Remove a product from your comparison list to add another.',
                    ).replace(':max', String(limit)),
                    'warning',
                );
            }
            return next;
        },
    }), [shop, shopError, cart, customer, wishlistIds, compare, cartOpen]);

    return (
        <StoreContext.Provider value={value}>
            {children}
        </StoreContext.Provider>
    );
}

export function useStore() {
    return useContext(StoreContext);
}
