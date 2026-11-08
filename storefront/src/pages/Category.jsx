import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import api from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { EmptyState, CategoryPageSkeleton, ProductSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import { categoryPath, entityState } from '../lib/links.js';
import { useStore } from '../state/StoreState.jsx';
import { catalogSortOptions, defaultCatalogSort, pricesHidden } from '../lib/commerce.js';
import { defaultImageUrl } from '../lib/format.js';
import { applyNotFoundSeo, applySeoHead } from '../lib/seoHead.js';

function hasRichText(html) {
    return String(html || '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .length > 0;
}

function CategoryBranch({ nodes, depth = 0 }) {
    if (!nodes?.length) {
        return null;
    }
    return (
        <ul className={depth === 0 ? 'cx-cat-tree' : 'cx-cat-tree-children'}>
            {nodes.map((cat) => (
                <li key={cat.id || cat.url} className="cx-cat-tree-item">
                    <Link to={cat.url || categoryPath(cat)} state={entityState('category')} className="cx-cat-tree-link">
                        {cat.name}
                    </Link>
                    <CategoryBranch nodes={cat.children} depth={depth + 1} />
                </li>
            ))}
        </ul>
    );
}

/** Category logo URL; falls back to the storefront default when none is uploaded. */
function categoryMediaUrl(cat) {
    const url = cat?.image || cat?.thumb || '';
    if (!url) {
        return defaultImageUrl('md');
    }
    return url;
}

export default function Category({ slug, initial = null }) {
    const [params, setParams] = useSearchParams();
    const location = useLocation();
    const store = useStore();
    const hidePrices = pricesHidden(store.shop, store.customer);
    const defaultSort = defaultCatalogSort(store.shop);
    const visibleSorts = useMemo(
        () => catalogSortOptions(store.shop, { hidePrices }),
        [store.shop, hidePrices],
    );
    const [data, setData] = useState(initial);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(!initial);
    const seeded = useRef(Boolean(initial));
    const [drawer, setDrawer] = useState(false);
    const [view, setView] = useState('grid');

    // Stabilize on the query string — useSearchParams() can return a new object each render.
    const queryKey = params.toString();
    const query = useMemo(() => {
        const search = new URLSearchParams(queryKey);
        const requested = search.get('sort') || defaultSort;
        const allowed = new Set(visibleSorts.map((row) => row.value));
        const sort = allowed.has(requested) ? requested : defaultSort;

        return {
            brand: search.get('brand') || '',
            price: search.get('price') || '',
            rating: search.get('rating') || '',
            discount: search.get('discount') || '',
            color: search.get('color') || '',
            availability: search.get('availability') || '',
            values: search.get('values') || '',
            sort,
            page: search.get('page') || '1',
            nc: search.get('nc') || '',
        };
    }, [queryKey, defaultSort, visibleSorts]);

    useEffect(() => {
        if (seeded.current) {
            seeded.current = false;
            setData(initial);
            setLoading(false);
            const cat = initial?.category;
            if (cat) {
                const facetKeys = ['brand', 'price', 'rating', 'discount', 'color', 'availability', 'values', 'nc'];
                const search = new URLSearchParams(queryKey);
                const noisy = facetKeys.some((k) => search.get(k))
                    || (search.get('page') && Number(search.get('page')) > 1)
                    || (search.get('sort') && search.get('sort') !== defaultSort);
                const origin = window.location.origin;
                const canonicalPath = cat?.seo?.canonical || cat?.url || `/${slug}`;
                const canonical = canonicalPath.startsWith('http') ? canonicalPath : origin + (canonicalPath.startsWith('/') ? canonicalPath : `/${canonicalPath}`);
                applySeoHead({
                    title: cat?.seo?.title || cat?.name,
                    description: cat?.seo?.description || '',
                    canonical,
                    robots: noisy ? 'noindex,follow' : (cat?.seo?.robots || 'index,follow'),
                    og_type: cat?.seo?.og_type || 'website',
                    og_image: cat?.seo?.og_image || cat?.image || null,
                    clear_og_image: !(cat?.seo?.og_image || cat?.image),
                    json_ld: cat?.seo?.json_ld ?? null,
                    hreflang: cat?.seo?.hreflang || [],
                });
            }
            return undefined;
        }
        let cancelled = false;
        setLoading((current) => current || !data);
        setError('');
        const request = {
            brand: query.brand,
            price: query.price,
            rating: query.rating,
            discount: query.discount,
            color: query.color,
            availability: query.availability,
            values: query.values,
            sort: query.sort,
            page: query.page,
        };
        if (query.nc) {
            request.feature = { noise_cancellation: query.nc };
        }
        api.get(`/catalog/category/${slug}`, { params: request })
            .then((r) => {
                if (cancelled) {
                    return;
                }
                setData(r.data);
                const cat = r.data?.category;
                const facetKeys = ['brand', 'price', 'rating', 'discount', 'color', 'availability', 'values', 'nc'];
                const search = new URLSearchParams(queryKey);
                const noisy = facetKeys.some((k) => search.get(k))
                    || (search.get('page') && Number(search.get('page')) > 1)
                    || (search.get('sort') && search.get('sort') !== defaultSort);
                const origin = window.location.origin;
                const canonicalPath = cat?.seo?.canonical || cat?.url || `/${slug}`;
                const canonical = canonicalPath.startsWith('http') ? canonicalPath : origin + (canonicalPath.startsWith('/') ? canonicalPath : `/${canonicalPath}`);
                applySeoHead({
                    title: cat?.seo?.title || cat?.name,
                    description: cat?.seo?.description || '',
                    canonical,
                    robots: noisy ? 'noindex,follow' : (cat?.seo?.robots || 'index,follow'),
                    og_type: cat?.seo?.og_type || 'website',
                    og_image: cat?.seo?.og_image || cat?.image || null,
                    clear_og_image: !(cat?.seo?.og_image || cat?.image),
                    json_ld: cat?.seo?.json_ld ?? null,
                    hreflang: cat?.seo?.hreflang || [],
                });
            })
            .catch((err) => {
                if (cancelled) {
                    return;
                }
                const status = err?.response?.status;
                if (status === 429) {
                    setError(t('storefront.category.busy', 'The catalog is busy. Please wait a moment and try again.'));
                    return;
                }
                setError(t('storefront.category.missing', 'This category could not be found.'));
                applyNotFoundSeo();
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [slug, query, queryKey, defaultSort, store.shop?.currency]);

    // Marker class on <body> so category-only CSS tweaks do not leak into other pages.
    useLayoutEffect(() => {
        document.body.classList.add('shop-cat-page');
        return () => document.body.classList.remove('shop-cat-page');
    }, []);

    const setFilter = (key, value) => {
        const next = new URLSearchParams(params);
        if (!value) {
            next.delete(key);
        } else {
            next.set(key, value);
        }
        next.delete('page');
        setParams(next, { replace: true, state: location.state });
    };

    const clearAll = () => setParams({}, { replace: true, state: location.state });
    const products = data?.products?.data || [];
    const meta = data?.products;
    const hasFilters = ['brand', 'price', 'rating', 'discount', 'color', 'availability', 'nc', 'values'].some((key) => params.get(key));
    const category = data?.category;
    const descriptionHtml = String(category?.description || '').trim();
    const hasDescription = hasRichText(descriptionHtml);
    const categoryHeroImage = categoryMediaUrl(category);

    const filters = (
        <Filters
            data={data}
            query={query}
            setFilter={setFilter}
            clearAll={clearAll}
            hasFilters={hasFilters}
            hidePrices={hidePrices}
        />
    );

    if (!data && loading) {
        return <CategoryPageSkeleton />;
    }
    if (!data && error) {
        return (
            <div className="shop-container py-16">
                <EmptyState title={error} />
            </div>
        );
    }

    return (
        <div className="shop-cat">
            <div className="shop-container shop-cat-inner">
                <Breadcrumbs items={data?.breadcrumbs || [{ name: 'Home', url: '/' }]} />

                <header className={`shop-cat-hero${categoryHeroImage ? '' : ' is-text-only'}`}>
                    <div className="shop-cat-hero-copy">
                        <h1>{category?.name || t('storefront.category.title', 'Category')}</h1>
                        {meta?.total != null ? (
                            <p className="shop-cat-hero-meta">
                                {meta.total} {t('storefront.catalog.products', 'products')}
                            </p>
                        ) : null}
                        {hasDescription ? (
                            <div
                                className="shop-cat-hero-desc shop-prose shop-pdp-rich"
                                dangerouslySetInnerHTML={{ __html: descriptionHtml }}
                            />
                        ) : null}
                    </div>
                    {categoryHeroImage ? (
                        <div className="shop-cat-hero-media" aria-hidden={!category?.name}>
                            <img
                                src={categoryHeroImage}
                                alt={category?.name || ''}
                                loading="eager"
                                fetchPriority="high"
                            />
                        </div>
                    ) : null}
                </header>
            </div>

            {data?.children?.length ? (
                <section className="cx-category-band" aria-label={t('storefront.category.subcategories', 'Subcategories')}>
                    <div className="shop-container cx-sec">
                        <div className="cx-sec-title">
                            <h2>{t('storefront.category.subcategories', 'Subcategories')}</h2>
                        </div>
                        <CategoryBranch nodes={data.children} />
                    </div>
                </section>
            ) : null}

            <div className="shop-container shop-cat-inner">
                <div className="shop-cat-layout">
                    <aside className="shop-cat-aside hidden lg:block">{filters}</aside>

                    <div className="shop-cat-main">
                        <div className="shop-cat-toolbar">
                            <p className="shop-cat-count">
                                {meta
                                    ? `${t('storefront.category.showing', 'Showing')} ${meta.from || 0} – ${meta.to || 0} ${t('storefront.category.of', 'of')} ${meta.total} ${t('storefront.catalog.products', 'products')}`
                                    : ''}
                            </p>
                            <div className="shop-cat-toolbar-actions">
                                <button type="button" className="shop-cat-filter-btn lg:hidden" onClick={() => setDrawer(true)}>
                                    <Icon name="filter" className="h-4 w-4" />
                                    {t('storefront.category.all_filters', 'Filter')}
                                </button>
                                <label className="shop-cat-sort">
                                    <span>{t('storefront.category.sort_by', 'Sort by')}</span>
                                    <select value={query.sort} onChange={(e) => setFilter('sort', e.target.value)}>
                                        {visibleSorts.map((row) => (
                                            <option key={row.value} value={row.value}>
                                                {t(`storefront.category.sort_${row.labelKey}`, row.label)}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="shop-cat-views" role="group" aria-label={t('storefront.category.view', 'View')}>
                                    <button
                                        type="button"
                                        className={view === 'grid' ? 'is-on' : ''}
                                        onClick={() => setView('grid')}
                                        aria-label={t('storefront.category.grid', 'Grid view')}
                                    >
                                        <Icon name="grid" className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        className={view === 'list' ? 'is-on' : ''}
                                        onClick={() => setView('list')}
                                        aria-label={t('storefront.category.list', 'List view')}
                                    >
                                        <Icon name="list" className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        {loading && !products.length ? <ProductSkeleton count={8} /> : null}
                        {error ? <EmptyState title={error} /> : null}
                        {!loading && !error && products.length === 0 ? (
                            <EmptyState
                                title={t('storefront.category.empty', 'No products match these filters')}
                                body={t('storefront.category.empty_body', 'Clear filters to see everything in this category.')}
                                action={<button type="button" className="shop-btn shop-btn-primary" onClick={clearAll}>{t('storefront.category.clear', 'Clear all filters')}</button>}
                            />
                        ) : null}
                        {!loading && products.length > 0 ? (
                            <div className={view === 'list' ? 'shop-cat-grid is-list' : 'shop-cat-grid'}>
                                {products.map((p, i) => (
                                    <ProductCard
                                        key={p.id}
                                        product={p}
                                        priority={i < 4}
                                        {...(view === 'list' ? { variant: 'listing' } : { compact: true })}
                                    />
                                ))}
                            </div>
                        ) : null}

                        {meta?.last_page > 1 ? (
                            <div className="shop-cat-pages">
                                {Array.from({ length: meta.last_page }).map((_, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        className={String(i + 1) === String(meta.current_page) ? 'is-on' : ''}
                                        onClick={() => setFilter('page', String(i + 1))}
                                    >
                                        {i + 1}
                                    </button>
                                ))}
                            </div>
                        ) : null}
                    </div>
                </div>

                {(data?.modules?.after || []).length ? (
                    <div className="mt-8">
                        <ModuleBlocks slots={data.modules.after} />
                    </div>
                ) : null}
            </div>

            {drawer ? (
                <div className="shop-cat-drawer" onClick={() => setDrawer(false)}>
                    <div className="shop-cat-drawer-panel" onClick={(e) => e.stopPropagation()}>
                        <div className="shop-cat-drawer-head">
                            <h2>{t('storefront.category.all_filters', 'All Filters')}</h2>
                            <button type="button" onClick={() => setDrawer(false)} aria-label={t('storefront.common.close', 'Close')}>
                                <Icon name="close" />
                            </button>
                        </div>
                        {filters}
                    </div>
                </div>
            ) : null}
        </div>
    );
}


function Filters({ data, query, setFilter, clearAll, hasFilters, hidePrices = false }) {
    const [scrollerOn, setScrollerOn] = useState(false);
    const priceTimer = useRef(null);
    const setPrice = (next) => {
        if (priceTimer.current) {
            clearTimeout(priceTimer.current);
        }
        priceTimer.current = setTimeout(() => setFilter('price', next), 280);
    };

    if (!data) {
        return <div className="skeleton h-80 rounded-2xl" />;
    }

    const boundMin = Math.floor(Number(data.price?.min) || 0);
    const boundMax = Math.ceil(Number(data.price?.max) || 0) || 100;
    const [minRaw, maxRaw] = (query.price || '').split('-');
    const priceMin = minRaw !== undefined && minRaw !== '' ? Number(minRaw) : boundMin;
    const priceMax = maxRaw !== undefined && maxRaw !== '' ? Number(maxRaw) : boundMax;
    return (
        <div
            className={`shop-cat-filters${scrollerOn ? ' is-scroller-on' : ''}`}
            onMouseEnter={() => setScrollerOn(true)}
            onMouseLeave={() => setScrollerOn(false)}
        >
            <div className="shop-cat-filters-head">
                <h3>{t('storefront.category.filters', 'Filters')}</h3>
                {hasFilters ? (
                    <button type="button" onClick={clearAll}>{t('storefront.category.clear_all', 'Clear all')}</button>
                ) : null}
            </div>

            {hidePrices ? null : (
            <section className="shop-cat-filter">
                <h4>{t('storefront.category.price_range', 'Price range')}</h4>
                <input
                    className="shop-cat-price-range"
                    type="range"
                    min={boundMin}
                    max={boundMax}
                    value={Number.isFinite(priceMax) ? priceMax : boundMax}
                    onChange={(e) => setPrice(`${priceMin}-${e.target.value}`)}
                    aria-label={t('storefront.category.max_price', 'Maximum price')}
                />
                <div className="shop-cat-price-inputs">
                    <label>
                        <span>$</span>
                        <input
                            type="number"
                            value={Number.isFinite(priceMin) ? priceMin : ''}
                            onChange={(e) => setPrice(`${e.target.value}-${priceMax}`)}
                        />
                    </label>
                    <span className="shop-cat-price-sep">–</span>
                    <label>
                        <span>$</span>
                        <input
                            type="number"
                            value={Number.isFinite(priceMax) ? priceMax : ''}
                            onChange={(e) => setPrice(`${priceMin}-${e.target.value}`)}
                        />
                    </label>
                </div>
            </section>
            )}

            {data.brands?.length ? (
                <section className="shop-cat-filter">
                    <h4>{t('storefront.category.brand', 'Brand')}</h4>
                    <div className="shop-cat-checks">
                        {data.brands.map((brand) => {
                            const on = query.brand.split(',').filter(Boolean).includes(brand.slug);
                            return (
                                <label key={brand.id} className={on ? 'is-on' : ''}>
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={() => {
                                            const current = query.brand ? query.brand.split(',').filter(Boolean) : [];
                                            const next = current.includes(brand.slug)
                                                ? current.filter((s) => s !== brand.slug)
                                                : [...current, brand.slug];
                                            setFilter('brand', next.join(','));
                                        }}
                                    />
                                    <span>{brand.name}</span>
                                    <em>({brand.count})</em>
                                </label>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            <section className="shop-cat-filter">
                <h4>{t('storefront.category.discount', 'Discount')}</h4>
                <div className="shop-cat-checks">
                    {[10, 20, 30, 50].map((n) => (
                        <label key={n} className={query.discount === String(n) ? 'is-on' : ''}>
                            <input
                                type="radio"
                                name="discount"
                                checked={query.discount === String(n)}
                                onChange={() => setFilter('discount', String(n))}
                            />
                            <span>{n}% {t('storefront.category.and_above', 'and above')}</span>
                        </label>
                    ))}
                </div>
            </section>

            <section className="shop-cat-filter">
                <h4>{t('storefront.category.rating', 'Rating')}</h4>
                <div className="shop-cat-checks">
                    {[4, 3, 2].map((n) => (
                        <label key={n} className={query.rating === String(n) ? 'is-on' : ''}>
                            <input
                                type="radio"
                                name="rating"
                                checked={query.rating === String(n)}
                                onChange={() => setFilter('rating', String(n))}
                            />
                            <span className="shop-cat-stars" aria-hidden="true">
                                {Array.from({ length: 5 }).map((_, i) => (
                                    <span key={i} className={i < n ? 'is-on' : ''}>★</span>
                                ))}
                            </span>
                            <span>{t('storefront.category.stars_above', 'stars & above')}</span>
                        </label>
                    ))}
                </div>
            </section>

            <section className="shop-cat-filter">
                <h4>{t('storefront.category.availability', 'Availability')}</h4>
                <div className="shop-cat-checks">
                    <label className={query.availability === 'in_stock' ? 'is-on' : ''}>
                        <input
                            type="checkbox"
                            checked={query.availability === 'in_stock'}
                            onChange={(e) => setFilter('availability', e.target.checked ? 'in_stock' : '')}
                        />
                        <span>{t('storefront.category.in_stock', 'In stock')}</span>
                    </label>
                </div>
            </section>

            {data.filters?.map((feature) => (
                <section className="shop-cat-filter" key={feature.id}>
                    <h4>{feature.name}</h4>
                    <div className="shop-cat-checks">
                        {feature.values.map((value) => {
                            const selected = (query.values || '').split(',').filter(Boolean);
                            const on = selected.includes(String(value.id));
                            return (
                                <label key={value.id} className={on ? 'is-on' : ''}>
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={() => {
                                            const next = on
                                                ? selected.filter((id) => id !== String(value.id))
                                                : [...selected, String(value.id)];
                                            setFilter('values', next.join(','));
                                        }}
                                    />
                                    <span>{value.value}</span>
                                </label>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
