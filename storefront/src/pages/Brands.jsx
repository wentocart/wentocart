import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { mediaUrlOrDefault, DEFAULT_IMAGES } from '../lib/format.js';
import { useStore } from '../state/StoreState.jsx';
import { applyThinShellSeo } from '../lib/seoHead.js';

/** True when the API only has the generic product placeholder, not a real logo. */
function hasRealLogo(brand) {
    const src = String(brand?.logo_url || brand?.logo || '').trim();
    if (!src) {
        return false;
    }
    return !Object.values(DEFAULT_IMAGES).includes(src);
}

export default function Brands() {
    useEffect(() => { applyThinShellSeo('Brands'); }, []);

    const store = useStore();
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get('/catalog/home').then((r) => setRows(r.data.brands || [])).catch(() => setRows([]));
    }, []);

    const brands = rows || store.shop?.brands || [];

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-container py-10">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: t('storefront.brands.title', 'Brands') }]} />

            <header className="shop-brands-head">
                <div>
                    <p className="shop-brands-eyebrow">{t('storefront.brands.eyebrow', 'Our brand partners')}</p>
                    <h1>{t('storefront.brands.title', 'Brands')}</h1>
                    <p className="shop-brands-lead">{t('storefront.brands.lead', 'Shop the catalog by brand. Each brand page uses the same product cards as category and search.')}</p>
                </div>
                {brands.length > 0 ? (
                    <p className="shop-brands-total">
                        {brands.length} {brands.length === 1
                            ? t('storefront.brands.one_brand', 'brand')
                            : t('storefront.brands.many_brands', 'brands')}
                    </p>
                ) : null}
            </header>

            {brands.length === 0 ? (
                <div className="mt-8">
                    <EmptyState title={t('storefront.brands.empty', 'No brands yet')} action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} />
                </div>
            ) : (
                <div className="shop-brands-grid">
                    {brands.map((brand) => (
                        <Link
                            key={brand.id || brand.slug}
                            to={brand.url || `/brand/${brand.slug}`}
                            state={{ entityType: 'brand' }}
                            className="shop-brand-tile"
                        >
                            <span className="shop-brand-tile__media">
                                <img
                                    src={hasRealLogo(brand)
                                        ? mediaUrlOrDefault(brand.logo_url || brand.logo, 'md')
                                        : DEFAULT_IMAGES.md}
                                    alt={brand.name || 'Brand'}
                                    loading="lazy"
                                    className="shop-brand-tile__img"
                                />
                            </span>
                            <span className="shop-brand-tile__body">
                                <span className="shop-brand-tile__name">{brand.name}</span>
                            </span>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
