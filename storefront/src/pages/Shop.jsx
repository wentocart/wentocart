import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import ProductCard from '../components/ProductCard.jsx';
import Stars from '../components/Stars.jsx';
import { EmptyState, ProductSkeleton } from '../components/Feedback.jsx';
import { FormField, FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import StarRatingInput from '../components/StarRatingInput.jsx';
import { SellerModalShell } from '../components/SellerModal.jsx';
import { SocialBrandIcon } from '../components/SocialBrandIcon.jsx';
import { useStore } from '../state/StoreState.jsx';
import { formatRating, pricesHidden } from '../lib/commerce.js';
import '../multivendor.css';

const productSorts = [
    { value: 'price_asc', labelKey: 'price_low', label: 'Price: low to high' },
    { value: 'price_desc', labelKey: 'price_high', label: 'Price: high to low' },
    { value: 'newest', labelKey: 'newest', label: 'Newest' },
];

const productTabs = [
    { value: 'all', labelKey: 'all_products', label: 'All products' },
    { value: 'bestsellers', labelKey: 'best_sellers', label: 'Best sellers' },
    { value: 'discounted', labelKey: 'discounted', label: 'Discounted' },
];

const SEARCH_MIN = 3;
const SEARCH_DEBOUNCE_MS = 400;
const PRODUCT_LOADER_MIN_MS = 800;

function readProductQuery() {
    const params = new URLSearchParams(window.location.search);
    return {
        q: params.get('q') || '',
        sort: params.get('sort') || 'price_asc',
        tab: params.get('tab') || 'all',
        page: params.get('page') || '1',
    };
}

function writeProductQuery(query) {
    const params = new URLSearchParams();
    if (query.q) {
        params.set('q', query.q);
    }
    if (query.sort && query.sort !== 'price_asc') {
        params.set('sort', query.sort);
    }
    if (query.tab && query.tab !== 'all') {
        params.set('tab', query.tab);
    }
    if (query.page && query.page !== '1') {
        params.set('page', query.page);
    }
    const qs = params.toString();
    const url = qs ? `${window.location.pathname}?${qs}` : window.location.pathname;
    window.history.replaceState(window.history.state, '', url);
}

function productQueryParams(query, reviewPage = 1) {
    return {
        q: query.q || undefined,
        sort: query.sort,
        tab: query.tab,
        page: query.page,
        review_page: reviewPage > 1 ? reviewPage : undefined,
    };
}

function SellerRatingBars({ distribution, total }) {
    const counts = {};
    if (distribution && typeof distribution === 'object') {
        Object.entries(distribution).forEach(([key, value]) => {
            counts[Number(key)] = Number(value) || 0;
        });
    }
    const max = Math.max(total || 0, 1);

    return (
        <div className="shop-public-reviews-bars" aria-hidden={total <= 0 || undefined}>
            {[5, 4, 3, 2, 1].map((star) => {
                const count = Number(counts[star] || 0);
                const pct = Math.round((count / max) * 100);
                return (
                    <div key={star} className="shop-public-reviews-bars__row">
                        <span>{star} ★</span>
                        <div className="shop-public-reviews-bars__track">
                            <i style={{ width: `${pct}%` }} />
                        </div>
                        <em>{count}</em>
                    </div>
                );
            })}
        </div>
    );
}

export default function Shop() {
    const { slug } = useParams();
    const store = useStore();
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [contact, setContact] = useState({
        name: store.customer?.name || '',
        email: store.customer?.email || '',
        body: '',
    });
    const [review, setReview] = useState({ rating: 0, title: '', body: '' });
    const [contactOpen, setContactOpen] = useState(false);
    const [contactNotice, setContactNotice] = useState('');
    const [contactBusy, setContactBusy] = useState(false);
    const [reviewNotice, setReviewNotice] = useState('');
    const [reviewRatingError, setReviewRatingError] = useState('');
    const [reviewBusy, setReviewBusy] = useState(false);
    const [reviewPage, setReviewPage] = useState(1);
    const [productsLoading, setProductsLoading] = useState(false);
    const [showProductLoader, setShowProductLoader] = useState(false);
    const shopReady = useRef(false);
    const scrollLock = useRef(null);
    const loaderStartedAt = useRef(null);
    const multivendorOn = store.shop?.features?.multivendor !== false;
    const hidePrices = pricesHidden(store.shop, store.customer);
    const visibleProductSorts = hidePrices
        ? productSorts.filter((row) => !String(row.value).startsWith('price'))
        : productSorts;

    const [productQuery, setProductQuery] = useState(readProductQuery);
    const [searchInput, setSearchInput] = useState(() => readProductQuery().q);

    const searchPending = useMemo(() => {
        const trimmed = searchInput.trim();
        if (trimmed.length >= SEARCH_MIN) {
            return trimmed !== productQuery.q;
        }
        return Boolean(productQuery.q) && trimmed.length < SEARCH_MIN;
    }, [searchInput, productQuery.q]);

    const productBusy = productsLoading || searchPending;

    useEffect(() => {
        if (productBusy) {
            loaderStartedAt.current = Date.now();
            setShowProductLoader(true);
            return undefined;
        }

        if (loaderStartedAt.current == null) {
            return undefined;
        }

        const remaining = PRODUCT_LOADER_MIN_MS - (Date.now() - loaderStartedAt.current);
        const timer = window.setTimeout(() => {
            loaderStartedAt.current = null;
            setShowProductLoader(false);
        }, Math.max(0, remaining));

        return () => window.clearTimeout(timer);
    }, [productBusy]);

    const patchProductQuery = useCallback((mutate) => {
        if (shopReady.current) {
            scrollLock.current = window.scrollY;
        }
        setProductQuery((prev) => {
            const next = { ...prev };
            mutate(next);
            writeProductQuery(next);
            return next;
        });
    }, []);

    useEffect(() => {
        shopReady.current = false;
        const initial = readProductQuery();
        setProductQuery(initial);
        setSearchInput(initial.q);
        setData(null);
        setReviewPage(1);
    }, [slug]);

    useEffect(() => {
        setSearchInput(productQuery.q);
    }, [productQuery.q]);

    useEffect(() => {
        const trimmed = searchInput.trim();
        const nextQ = trimmed.length >= SEARCH_MIN ? trimmed : '';
        if (nextQ === productQuery.q) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            patchProductQuery((next) => {
                next.q = nextQ;
                next.page = '1';
            });
        }, SEARCH_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [searchInput, productQuery.q, patchProductQuery]);

    useEffect(() => {
        if (store.shop && !store.shop.features?.multivendor) {
            setError(t('storefront.shop.missing', 'This shop is not available.'));
            setData(null);
            return undefined;
        }
        setError('');
        if (!shopReady.current) {
            setData(null);
        } else {
            setProductsLoading(true);
        }
        api.get(`/catalog/seller/${slug}`, {
            params: productQueryParams(productQuery, reviewPage),
        })
            .then((r) => setData(r.data))
            .catch(() => setError(t('storefront.shop.missing', 'This shop is not available.')))
            .finally(() => {
                shopReady.current = true;
                setProductsLoading(false);
                if (scrollLock.current != null) {
                    const y = scrollLock.current;
                    scrollLock.current = null;
                    window.requestAnimationFrame(() => {
                        window.scrollTo(0, y);
                    });
                }
            });
        return undefined;
    }, [slug, productQuery, reviewPage, store.shop?.features?.multivendor]);

    if (store.shop && !multivendorOn) {
        return (
            <div className="shop-public-page">
                <div className="shop-container shop-public-page__inner">
                    <EmptyState title={t('storefront.shop.missing', 'This shop is not available.')} action={<Link to="/" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} />
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="shop-public-page">
                <div className="shop-container shop-public-page__inner">
                    <EmptyState title={error} action={<Link to="/" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} />
                </div>
            </div>
        );
    }
    if (!data) {
        return (
            <div className="shop-public-page">
                <div className="shop-container shop-public-page__inner py-10">
                    <ProductSkeleton />
                </div>
            </div>
        );
    }

    const shop = data.shop;
    const products = data.products?.data || [];
    const productMeta = data.products?.meta;
    const tabCounts = productMeta?.tabs || { all: 0, bestsellers: 0, discounted: 0 };
    const reviewsPayload = data.reviews?.data ? data.reviews : { data: data.reviews || [], meta: null };
    const reviews = reviewsPayload.data || [];
    const reviewsMeta = reviewsPayload.meta;
    const social = [
        shop.facebook_url && { key: 'facebook', href: shop.facebook_url, label: 'Facebook', brand: 'facebook' },
        shop.twitter_url && { key: 'twitter', href: shop.twitter_url, label: 'X', brand: 'twitter' },
        shop.instagram_url && { key: 'instagram', href: shop.instagram_url, label: 'Instagram', brand: 'instagram' },
        shop.youtube_url && { key: 'youtube', href: shop.youtube_url, label: 'YouTube', brand: 'youtube' },
    ].filter(Boolean);

    const submitContact = async (e) => {
        e.preventDefault();
        setContactBusy(true);
        setContactNotice('');
        try {
            await csrf();
            await api.post(`/catalog/seller/${slug}/contact`, contact);
            setContact((current) => ({ ...current, body: '' }));
            setContactNotice(t('storefront.shop.contact_sent', 'Message sent. The seller can reply from the seller dashboard.'));
        } catch (err) {
            setContactNotice(err.response?.data?.message || t('storefront.shop.contact_failed', 'Could not send the message.'));
        } finally {
            setContactBusy(false);
        }
    };

    const submitReview = async (e) => {
        e.preventDefault();
        if (!review.rating || review.rating < 1) {
            setReviewRatingError(t('storefront.shop.rating_required', 'Please select a rating.'));
            return;
        }
        setReviewBusy(true);
        setReviewNotice('');
        setReviewRatingError('');
        try {
            await csrf();
            await api.post('/account/seller-reviews', { ...review, seller_id: shop.id });
            setReviewNotice(t('storefront.shop.review_pending', 'Review submitted. It is published after store review.'));
            setReview({ rating: 0, title: '', body: '' });
            setData((current) => ({ ...current, can_review: false }));
        } catch (err) {
            setReviewNotice(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || t('storefront.shop.review_failed', 'Could not save the review.'));
        } finally {
            setReviewBusy(false);
        }
    };

    const formatMemberSince = (value) => {
        if (!value) {
            return null;
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        return date.toLocaleDateString(undefined, { month: '2-digit', day: '2-digit', year: 'numeric' });
    };

    const productsCount = shop.products_count ?? data.products?.meta?.total ?? 0;
    const soldCount = shop.sold_count ?? 0;
    const reviewCount = shop.rating?.count ?? 0;
    const memberSince = formatMemberSince(shop.member_since);
    const shopDetails = [
        shop.city ? `${shop.city}${shop.country_code ? `, ${shop.country_code}` : ''}` : (shop.country_code || ''),
        shop.contact_email,
        shop.contact_phone,
    ].filter(Boolean);
    const hasAboutContent = Boolean(shop.description || shopDetails.length);
    const hasProductFilters = Boolean(productQuery.q) || productQuery.tab !== 'all';
    const catalogEmpty = productsCount === 0 && !hasProductFilters;

    const setProductTab = (tab) => {
        patchProductQuery((next) => {
            next.tab = tab;
            next.page = '1';
        });
    };

    const setProductPage = (page) => {
        patchProductQuery((next) => {
            next.page = String(page);
        });
    };

    const setProductSort = (sort) => {
        patchProductQuery((next) => {
            next.sort = sort;
            next.page = '1';
        });
    };

    return (
        <div className="shop-public-page">
            <div className="shop-container shop-public-page__inner shop-public-page__stack">
                <section className="shop-public-card shop-public-hero" aria-label={shop.shop_name}>
                    <div className="shop-public-hero__cover">
                        {shop.banner_url ? (
                            <img src={shop.banner_url} alt="" className="shop-public-hero__cover-image" />
                        ) : (
                            <div className="shop-public-hero__cover-fallback" aria-hidden="true" />
                        )}
                    </div>

                    <div className="shop-public-hero__panel">
                        <div className="shop-public-hero__body">
                            <div className="shop-public-hero__profile-col" aria-label={t('storefront.shop.seller_profile', 'Seller profile')}>
                                <div className="shop-public-hero__avatar-wrap">
                                    {shop.logo_url ? (
                                        <img src={shop.logo_url} alt="" className="shop-public-hero__avatar" />
                                    ) : (
                                        <div className="shop-public-hero__avatar shop-public-hero__avatar--fallback" aria-hidden="true">
                                            {(shop.shop_name || '?').trim().charAt(0).toUpperCase()}
                                        </div>
                                    )}
                                </div>

                                {social.length > 0 ? (
                                    <div className="shop-public-hero__social">
                                        {social.map((item) => (
                                            <a
                                                key={item.key}
                                                href={item.href}
                                                className={`shop-public-hero__social-icon shop-public-hero__social-icon--${item.brand}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                aria-label={item.label}
                                                title={item.label}
                                            >
                                                <SocialBrandIcon brand={item.brand} />
                                            </a>
                                        ))}
                                    </div>
                                ) : null}

                                <button
                                    type="button"
                                    className="shop-public-hero__contact-btn"
                                    onClick={() => {
                                        setContactNotice('');
                                        setContactOpen(true);
                                    }}
                                >
                                    <Icon name="mail" className="h-[1.1rem] w-[1.1rem]" />
                                    {t('storefront.shop.contact_shop', 'Shop Contact')}
                                </button>
                            </div>

                            <div className="shop-public-hero__content">
                                <h1 className="shop-public-hero__title">{shop.shop_name}</h1>

                                <div className="shop-public-hero__stats-grid" aria-label={t('storefront.shop.shop_stats', 'Shop statistics')}>
                                    <div className="shop-public-hero__stat">
                                        <Icon name="grid" className="shop-public-hero__stat-icon" />
                                        <span className="shop-public-hero__stat-label">{t('storefront.shop.products_label', 'Products:')}</span>
                                        <span className="shop-public-hero__stat-badge shop-public-hero__stat-badge--products">{productsCount}</span>
                                    </div>
                                    {memberSince ? (
                                        <div className="shop-public-hero__stat shop-public-hero__stat--plain">
                                            <Icon name="calendar" className="shop-public-hero__stat-icon" />
                                            <span className="shop-public-hero__stat-label">{t('storefront.shop.member_since', 'Member since')}:</span>
                                            <span className="shop-public-hero__stat-value">{memberSince}</span>
                                        </div>
                                    ) : null}
                                    <div className="shop-public-hero__stat">
                                        <Icon name="cart" className="shop-public-hero__stat-icon" />
                                        <span className="shop-public-hero__stat-label">{t('storefront.shop.sold_label', 'Sold:')}</span>
                                        <span className="shop-public-hero__stat-badge shop-public-hero__stat-badge--sold">{soldCount}</span>
                                    </div>
                                    <div className="shop-public-hero__stat shop-public-hero__stat--plain">
                                        <Icon name="star" className="shop-public-hero__stat-icon shop-public-hero__stat-icon--star" />
                                        {reviewCount > 0 ? (
                                            <>
                                                <Stars value={shop.rating.average} compact />
                                                <span className="shop-public-hero__stat-value">
                                                    {formatRating(shop.rating.average)} · {reviewCount} {t('storefront.shop.reviews', 'reviews')}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="shop-public-hero__stat-value">{t('storefront.shop.no_reviews_yet', 'No reviews yet')}</span>
                                        )}
                                    </div>
                                </div>

                                {hasAboutContent ? (
                                    <div className="shop-public-hero__about">
                                        <h2 className="shop-public-hero__about-title">{t('storefront.shop.about', 'About this shop')}</h2>
                                        {shop.description ? (
                                            <div className="shop-pdp-rich prose max-w-none shop-public-hero__description" dangerouslySetInnerHTML={{ __html: shop.description }} />
                                        ) : null}
                                        {shopDetails.length ? (
                                            <ul className="shop-public-hero__details">
                                                {shopDetails.map((line) => (
                                                    <li key={line}>{line}</li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </div>
                                ) : null}

                                {shop.vacation_enabled ? (
                                    <div className="shop-public-hero__vacation shop-pdp-rich prose max-w-none">
                                        {shop.vacation_message ? (
                                            <div dangerouslySetInnerHTML={{ __html: shop.vacation_message }} />
                                        ) : (
                                            t('storefront.shop.vacation', 'This shop is on vacation. Products are listed but cannot be ordered.')
                                        )}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </section>

                <section className="shop-public-card shop-public-products" aria-label={t('storefront.shop.products', 'Products from this shop')}>
                    <div className="shop-public-products__head">
                        <div className="shop-public-products__tabs" role="tablist" aria-label={t('storefront.shop.product_tabs', 'Product views')}>
                            {productTabs.map((tab) => (
                                <button
                                    key={tab.value}
                                    type="button"
                                    role="tab"
                                    aria-selected={productQuery.tab === tab.value}
                                    className={productQuery.tab === tab.value ? 'is-active' : ''}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => setProductTab(tab.value)}
                                >
                                    {t(`storefront.shop.tab_${tab.labelKey}`, tab.label)} ({tabCounts[tab.value] ?? 0})
                                </button>
                            ))}
                        </div>

                        <div className="shop-public-products__toolbar">
                            <input
                                type="search"
                                className="shop-input shop-public-products__search"
                                value={searchInput}
                                onChange={(e) => setSearchInput(e.target.value)}
                                placeholder={t('storefront.shop.search_products', 'Search in this shop')}
                                aria-label={t('storefront.shop.search_products', 'Search in this shop')}
                            />
                            <label className="shop-public-products__sort">
                                <span className="sr-only">{t('storefront.category.sort_by', 'Sort by')}</span>
                                <select
                                    className="shop-input"
                                    value={productQuery.sort}
                                    onChange={(e) => setProductSort(e.target.value)}
                                >
                                    {visibleProductSorts.map((row) => (
                                        <option key={row.value} value={row.value}>
                                            {t(`storefront.shop.sort_${row.labelKey}`, row.label)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </div>

                    <div className="shop-public-products__results" aria-busy={showProductLoader || undefined}>
                        {products.length > 0 ? (
                            <div className={`shop-public-products__grid-wrap${showProductLoader ? ' is-loading' : ''}`}>
                                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                                    {products.map((product, i) => (
                                        <ProductCard key={product.id} product={product} priority={i < 4} />
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        {!showProductLoader && products.length === 0 ? (
                            <div className="shop-public-products__empty">
                                <p className="shop-public-products__empty-label">
                                    {t('storefront.shop.no_products_found', 'No products found')}
                                </p>
                                <p className="shop-public-products__empty-body">
                                    {catalogEmpty
                                        ? t('storefront.shop.no_products', 'No products listed yet.')
                                        : t('storefront.shop.no_products_filtered', 'No products match your filters.')}
                                </p>
                            </div>
                        ) : null}

                        {showProductLoader ? (
                            <div className="shop-public-products__loader" role="status" aria-live="polite">
                                <span className="shop-public-products__spinner" aria-hidden="true" />
                                <span>{t('storefront.shop.loading_products', 'Loading products…')}</span>
                            </div>
                        ) : null}

                        {!showProductLoader && productMeta?.last_page > 1 ? (
                        <nav className="shop-public-products__pagination" aria-label={t('storefront.shop.pagination', 'Product pages')}>
                            <button
                                type="button"
                                className="shop-public-products__page-btn"
                                disabled={productMeta.current_page <= 1}
                                onClick={() => setProductPage(productMeta.current_page - 1)}
                            >
                                {t('storefront.shop.prev', 'Previous')}
                            </button>
                            {Array.from({ length: productMeta.last_page }).map((_, i) => {
                                const page = i + 1;
                                return (
                                    <button
                                        key={page}
                                        type="button"
                                        className={`shop-public-products__page-btn${page === productMeta.current_page ? ' is-active' : ''}`}
                                        onClick={() => setProductPage(page)}
                                        aria-current={page === productMeta.current_page ? 'page' : undefined}
                                    >
                                        {page}
                                    </button>
                                );
                            })}
                            <button
                                type="button"
                                className="shop-public-products__page-btn"
                                disabled={productMeta.current_page >= productMeta.last_page}
                                onClick={() => setProductPage(productMeta.current_page + 1)}
                            >
                                {t('storefront.shop.next', 'Next')}
                            </button>
                        </nav>
                        ) : null}
                    </div>
                </section>

                {(shop.store_policy || shop.shipping_policy || shop.return_policy) ? (
                    <section className="shop-public-policies">
                        {shop.store_policy ? (
                            <article className="shop-public-card shop-public-policy">
                                <h2 className="shop-public-section-title">{t('storefront.shop.store_policy', 'Store policy')}</h2>
                                <div className="shop-pdp-rich prose max-w-none shop-public-policy__body" dangerouslySetInnerHTML={{ __html: shop.store_policy }} />
                            </article>
                        ) : null}
                        {shop.shipping_policy ? (
                            <article className="shop-public-card shop-public-policy">
                                <h2 className="shop-public-section-title">{t('storefront.shop.shipping_policy', 'Shipping policy')}</h2>
                                <div className="shop-pdp-rich prose max-w-none shop-public-policy__body" dangerouslySetInnerHTML={{ __html: shop.shipping_policy }} />
                            </article>
                        ) : null}
                        {shop.return_policy ? (
                            <article className="shop-public-card shop-public-policy">
                                <h2 className="shop-public-section-title">{t('storefront.shop.return_policy', 'Return policy')}</h2>
                                <div className="shop-pdp-rich prose max-w-none shop-public-policy__body" dangerouslySetInnerHTML={{ __html: shop.return_policy }} />
                            </article>
                        ) : null}
                    </section>
                ) : null}

                {contactOpen ? (
                    <SellerModalShell
                        title={t('storefront.shop.contact', 'Contact this shop')}
                        subtitle={shop.shop_name}
                        onClose={() => {
                            if (!contactBusy) {
                                setContactOpen(false);
                            }
                        }}
                        closeDisabled={contactBusy}
                        footer={(
                            <>
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-ghost shop-btn--sm"
                                    onClick={() => setContactOpen(false)}
                                    disabled={contactBusy}
                                >
                                    {t('storefront.seller.cancel', 'Cancel')}
                                </button>
                                <button
                                    type="submit"
                                    form="shop-contact-form"
                                    className="shop-btn shop-btn-primary shop-btn--sm"
                                    disabled={contactBusy}
                                    aria-busy={contactBusy || undefined}
                                >
                                    {contactBusy
                                        ? t('storefront.seller.working', 'Working…')
                                        : t('storefront.shop.send', 'Send message')}
                                </button>
                            </>
                        )}
                    >
                        <form id="shop-contact-form" className="shop-public-form" onSubmit={submitContact}>
                            <FormField label={t('storefront.account.name', 'Name')} required>
                                <input className="shop-input" value={contact.name} onChange={(e) => setContact((c) => ({ ...c, name: e.target.value }))} required />
                            </FormField>
                            <FormField label={t('storefront.account.email', 'Email')} required>
                                <input type="email" className="shop-input" value={contact.email} onChange={(e) => setContact((c) => ({ ...c, email: e.target.value }))} required />
                            </FormField>
                            <FormField label={t('storefront.shop.message', 'Message')} required>
                                <textarea className="shop-input" rows={4} value={contact.body} onChange={(e) => setContact((c) => ({ ...c, body: e.target.value }))} required />
                            </FormField>
                            <FormNotice tone="info">{contactNotice}</FormNotice>
                        </form>
                    </SellerModalShell>
                ) : null}

                <section id="shop-reviews" className="shop-public-card shop-public-reviews-section">
                    <div className="shop-public-panel__head">
                        <h2 className="shop-public-section-title">
                            {t('storefront.shop.seller_reviews', 'Seller reviews')}
                            {reviewCount ? ` (${reviewCount})` : ''}
                        </h2>
                    </div>

                    <div className="shop-public-reviews-summary">
                        <div className="shop-public-reviews-score">
                            <strong>{shop.rating?.average != null ? (formatRating(shop.rating.average) ?? '—') : '—'}</strong>
                            <Stars value={shop.rating?.average} count={reviewCount} />
                            <span className="shop-public-reviews-score__count">
                                {reviewCount} {t('storefront.shop.reviews', 'reviews')}
                            </span>
                        </div>
                        <SellerRatingBars distribution={shop.rating?.distribution} total={reviewCount} />
                    </div>

                    <div className="shop-public-reviews-compose shop-public-reviews-compose--form">
                        <h3 className="shop-public-reviews-compose__title">
                            {t('storefront.shop.write_review', 'Write a Review')}
                        </h3>
                        {!store.customer ? (
                            <p className="shop-public-muted">
                                <Link to="/login" className="shop-public-link">{t('storefront.account.sign_in', 'Sign in')}</Link>
                                {' '}{t('storefront.shop.review_login', 'to review this seller after a purchase.')}
                            </p>
                        ) : data.can_review ? (
                            <form id="shop-review-form" className="shop-public-reviews-form" onSubmit={submitReview} noValidate>
                                <StarRatingInput
                                    label={t('storefront.shop.your_rating', 'Your rating')}
                                    value={review.rating}
                                    onChange={(rating) => {
                                        setReview((current) => ({ ...current, rating }));
                                        setReviewRatingError('');
                                    }}
                                    required
                                    error={reviewRatingError}
                                />
                                <FormField label={t('storefront.product.review_title', 'Title')}>
                                    <input
                                        className="shop-input"
                                        value={review.title}
                                        onChange={(e) => setReview((c) => ({ ...c, title: e.target.value }))}
                                        placeholder={t('storefront.shop.review_title_ph', 'Summarize your experience')}
                                    />
                                </FormField>
                                <FormField label={t('storefront.product.review_body', 'Review')}>
                                    <textarea
                                        className="shop-input shop-public-reviews-form__textarea"
                                        rows={3}
                                        value={review.body}
                                        onChange={(e) => setReview((c) => ({ ...c, body: e.target.value }))}
                                        placeholder={t('storefront.shop.review_body_ph', 'Tell others about this shop')}
                                    />
                                </FormField>
                                <div className="shop-public-reviews-form__actions">
                                    <button
                                        type="submit"
                                        className="shop-btn shop-btn-primary"
                                        disabled={reviewBusy}
                                        aria-busy={reviewBusy || undefined}
                                    >
                                        {reviewBusy
                                            ? t('storefront.seller.working', 'Working…')
                                            : t('storefront.shop.submit_review', 'Submit seller review')}
                                    </button>
                                </div>
                                <FormNotice tone="info">{reviewNotice}</FormNotice>
                            </form>
                        ) : (
                            <p className="shop-public-muted">{t('storefront.shop.review_ineligible', 'Seller reviews are available after a shipped purchase from this shop (one review per shop).')}</p>
                        )}
                    </div>

                    <div className="shop-public-reviews-list">
                        <h3 className="shop-public-reviews-list__title">
                            {t('storefront.shop.all_reviews', 'All reviews')}
                        </h3>
                        {reviews.length > 0 ? (
                            <ul className="shop-public-reviews">
                                {reviews.map((row) => (
                                    <li key={row.id} className="shop-public-review">
                                        <div className="shop-public-review__head">
                                            <div className="shop-public-review__identity">
                                                <p className="shop-public-review__author">{row.customer}</p>
                                                {row.created_at ? (
                                                    <time className="shop-public-review__date" dateTime={row.created_at}>{row.created_at}</time>
                                                ) : null}
                                            </div>
                                            <Stars value={row.rating} compact />
                                        </div>
                                        {row.verified_purchase ? (
                                            <p className="shop-public-review__verified">{t('storefront.pdp.verified', 'Verified Buyer')}</p>
                                        ) : null}
                                        {row.title ? <p className="shop-public-review__title">{row.title}</p> : null}
                                        {row.body ? <p className="shop-public-review__body">{row.body}</p> : null}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="shop-public-muted">{t('storefront.shop.no_reviews', 'No published seller reviews yet.')}</p>
                        )}

                        {reviewsMeta?.last_page > 1 ? (
                            <nav className="shop-public-products__pagination shop-public-reviews-list__pagination" aria-label={t('storefront.shop.review_pages', 'Review pages')}>
                                <button
                                    type="button"
                                    className="shop-public-products__page-btn"
                                    disabled={reviewsMeta.current_page <= 1}
                                    onClick={() => setReviewPage(reviewsMeta.current_page - 1)}
                                >
                                    {t('storefront.shop.prev', 'Previous')}
                                </button>
                                {Array.from({ length: reviewsMeta.last_page }).map((_, i) => {
                                    const page = i + 1;
                                    return (
                                        <button
                                            key={page}
                                            type="button"
                                            className={`shop-public-products__page-btn${page === reviewsMeta.current_page ? ' is-active' : ''}`}
                                            onClick={() => setReviewPage(page)}
                                            aria-current={page === reviewsMeta.current_page ? 'page' : undefined}
                                        >
                                            {page}
                                        </button>
                                    );
                                })}
                                <button
                                    type="button"
                                    className="shop-public-products__page-btn"
                                    disabled={reviewsMeta.current_page >= reviewsMeta.last_page}
                                    onClick={() => setReviewPage(reviewsMeta.current_page + 1)}
                                >
                                    {t('storefront.shop.next', 'Next')}
                                </button>
                            </nav>
                        ) : null}
                    </div>
                </section>
            </div>
        </div>
    );
}
