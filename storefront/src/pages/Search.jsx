import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { EmptyState, CategoryPageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { entityState, resolveCategoryLink } from '../lib/links.js';
import { buildSearchSummary, searchRedirectPath } from '../lib/searchSummary.js';

export default function Search() {
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const store = useStore();
    const q = params.get('q') || '';
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        setError('');
        setData(null);
        api.get('/catalog/search', { params: { q } })
            .then((r) => {
                const target = searchRedirectPath(r.data?.redirect);
                if (target) {
                    navigate(target, { replace: true });
                    return;
                }
                setData(r.data);
            })
            .catch(() => setError(t('storefront.search.failed', 'Search failed.')));
    }, [q, navigate]);

    const products = data?.data || [];
    const categories = useMemo(() => (store.shop?.categories || []).slice(0, 8), [store.shop?.categories]);
    const summary = useMemo(() => buildSearchSummary({
        query: q,
        total: data?.total,
        labels: {
            results: t('storefront.search.results', 'Search Results'),
            title: t('storefront.search.title', 'Search'),
            one: t('storefront.search.count_one', 'There is {count} product'),
            many: t('storefront.search.count_many', 'There are {count} products'),
            lead: t('storefront.search.lead', 'Find products by name, brand, or keyword.'),
        },
    }), [q, data?.total]);

    if (!data && !error) {
        return <CategoryPageSkeleton />;
    }

    return (
        <div className="shop-container py-8">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: t('storefront.search.title', 'Search') }]} />

            <header className="shop-search-summary" aria-labelledby="shop-search-summary-title">
                <h1 id="shop-search-summary-title" className="shop-search-summary-title">
                    {summary.hasQuery
                        ? (
                            <>
                                {summary.title}
                                {' '}
                                <span className="shop-search-summary-query">&ldquo;{summary.query}&rdquo;</span>
                            </>
                        )
                        : summary.title}
                </h1>
                <p className="shop-search-summary-count">{summary.countLabel}</p>
            </header>

            {categories.length ? (
                <div className="shop-search-chips" aria-label={t('storefront.search.popular_categories', 'Popular categories')}>
                    {categories.map((cat) => (
                        <Link
                            key={cat.id}
                            to={resolveCategoryLink(store.shop?.categories || [], cat.name) || `/c/${cat.slug || cat.id}`}
                            state={entityState('category')}
                            className="shop-search-chip"
                        >
                            {cat.name}
                        </Link>
                    ))}
                </div>
            ) : null}

            <div className="mt-6">
                {error ? <EmptyState title={error} /> : null}
                {data && products.length === 0 ? (
                    <EmptyState
                        title={t('storefront.search.empty', 'No matching products')}
                        body={t('storefront.search.empty_body', 'Try another word or browse categories.')}
                        action={(
                            <div className="flex flex-wrap items-center justify-center gap-3">
                                <Link to="/catalog" className="shop-btn shop-btn-primary">
                                    {t('storefront.search.view_categories', 'View all categories')}
                                </Link>
                                <Link to="/contact" className="shop-btn shop-btn-ghost">
                                    {t('storefront.search.contact_support', 'Contact support')}
                                </Link>
                            </div>
                        )}
                    />
                ) : null}
                {products.length > 0 ? (
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                        {products.map((p, i) => <ProductCard key={p.id} product={p} priority={i < 4} />)}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
