import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';

export default function Supplier({ slug, initial = null }) {
    // Mounted by SmartPage it receives `slug` as a prop; mounted directly by the
    // `/supplier/:slug` route it must read the param instead.
    const params = useParams();
    const raw = slug || params.slug || '';
    const resolved = raw.replace(/^supplier\//, '');
    const [data, setData] = useState(initial);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (initial) {
            setData(initial);
            return undefined;
        }
        if (!resolved) {
            setError(true);
            return;
        }
        api.get(`/catalog/supplier/${resolved}`)
            .then((r) => setData(r.data))
            .catch(() => setError(true));
    }, [resolved]);

    if (error) {
        return <div className="shop-container py-16"><EmptyState title={t('storefront.supplier.unavailable', 'This supplier is not available.')} action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} /></div>;
    }
    if (!data) {
        return <PageSkeleton />;
    }

    const products = data.products?.data || [];

    return (
        <div className="shop-container py-8">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: data.supplier.name }]} />
            <h1 className="mt-4 text-3xl font-extrabold tracking-tight">{data.supplier.name}</h1>
            {data.supplier.description ? <p className="mt-2 max-w-2xl text-neutral-500">{data.supplier.description}</p> : null}
            {products.length === 0 ? (
                <div className="mt-8"><EmptyState title={t('storefront.supplier.empty', 'No products from this supplier yet.')} /></div>
            ) : (
                <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                    {products.map((p) => <ProductCard key={p.id} product={p} />)}
                </div>
            )}
        </div>
    );
}
