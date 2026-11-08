import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import api from '../api.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { productPrice } from '../lib/format.js';
import { productPath } from '../lib/links.js';
import { pricesHidden } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import { Link } from 'react-router-dom';

export default function SharedWishlist() {
    const store = useStore();
    const { token } = useParams();
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get(`/wishlist/shared/${token}`).then((r) => setRows(r.data.wishlist || [])).catch(() => setRows([]));
    }, [token]);

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-wishlist">
            <header className="shop-wishlist-head">
                <h1>{t('storefront.account.shared_wishlist', 'Shared wishlist')}</h1>
            </header>
            {rows.length === 0 ? (
                <p>{t('storefront.account.wishlist_empty', 'This list is empty.')}</p>
            ) : (
                <ul>
                    {rows.map((product) => (
                        <li key={product.id}>
                            <Link to={productPath(product)}>{product.name}</Link>
                            {pricesHidden(store.shop, store.customer) || product.show_price === false ? null : (
                                <span>{productPrice(product)}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
