import { Link } from 'react-router-dom';
import { productPrice } from '../lib/format.js';
import { entityState, productPath } from '../lib/links.js';
import { formatRating, guestPricePrompt, pricesHidden } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import { t } from '../i18n.js';
import { Icon } from './Icons.jsx';
import ProductImage from './ProductImage.jsx';

function highlightMatch(text, query) {
    const source = String(text || '');
    const q = String(query || '').trim();
    if (!q) {
        return source;
    }
    const index = source.toLowerCase().indexOf(q.toLowerCase());
    if (index < 0) {
        return source;
    }

    return (
        <>
            {source.slice(0, index)}
            <mark>{source.slice(index, index + q.length)}</mark>
            {source.slice(index + q.length)}
        </>
    );
}

function suggestionLabels(products, query, limit = 6) {
    const seen = new Set();
    const labels = [];
    const q = query.trim();
    const needle = q.toLowerCase();
    if (q) {
        labels.push(q);
        seen.add(needle);
    }
    products.forEach((product) => {
        const name = String(product?.name || '').trim();
        if (!name) {
            return;
        }
        const key = name.toLowerCase();
        if (seen.has(key)) {
            return;
        }
        if (needle && !key.includes(needle)) {
            return;
        }
        seen.add(key);
        labels.push(name);
    });

    return labels.slice(0, limit);
}

function SuggestStars({ value = 0 }) {
    const rating = Number(value) || 0;
    const filled = Math.round(rating);

    return (
        <span className="cx-suggest-stars" aria-hidden="true">
            {Array.from({ length: 5 }).map((_, i) => (
                <span key={i} className={i < filled ? 'is-on' : ''}>★</span>
            ))}
        </span>
    );
}

export default function SearchSuggest({
    query,
    products = [],
    total = null,
    loading = false,
    onClose,
    onPickSuggestion,
}) {
    const store = useStore();
    const hidePrice = pricesHidden(store.shop, store.customer);
    const pricePrompt = guestPricePrompt(store.shop, store.customer);
    const q = String(query || '').trim();
    if (!q) {
        return null;
    }

    const suggestions = suggestionLabels(products, q);
    const productRows = products.slice(0, 5);
    const hasProducts = productRows.length > 0;
    const resultsHref = `/search?q=${encodeURIComponent(q)}`;

    return (
        <div className="cx-suggest" role="listbox" aria-label={t('storefront.search.suggestions', 'Search suggestions')}>
            <div className="cx-suggest-grid">
                <section className="cx-suggest-col">
                    <h3 className="cx-suggest-heading">{t('storefront.search.suggestions', 'Suggestions')}</h3>
                    <ul className="cx-suggest-list">
                        {suggestions.map((label) => (
                            <li key={label}>
                                <button
                                    type="button"
                                    className="cx-suggest-query"
                                    onClick={() => onPickSuggestion?.(label)}
                                >
                                    <span className="cx-suggest-query-ico">
                                        <Icon name="search" className="h-4 w-4" />
                                    </span>
                                    <span className="cx-suggest-query-text">{highlightMatch(label, q)}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Link className="cx-suggest-all" to={resultsHref} onClick={onClose}>
                        {t('storefront.search.view_all_for', 'View all results for')}
                        {' '}
                        &lsquo;
                        {q}
                        &rsquo;
                    </Link>
                </section>

                <section className="cx-suggest-col is-products">
                    <div className="cx-suggest-products-head">
                        <h3 className="cx-suggest-heading">{t('storefront.search.products', 'Products')}</h3>
                        {total != null ? (
                            <span className="cx-suggest-count">
                                {total}
                                {' '}
                                {t('storefront.catalog.products', 'products')}
                            </span>
                        ) : null}
                    </div>
                    {loading && !hasProducts ? (
                        <div className="cx-suggest-empty">{t('storefront.search.searching', 'Searching…')}</div>
                    ) : null}
                    {!loading && !hasProducts ? (
                        <div className="cx-suggest-empty">{t('storefront.search.no_quick_products', 'No products match yet. View all results or browse categories.')}</div>
                    ) : null}
                    {hasProducts ? (
                        <ul className="cx-suggest-products">
                            {productRows.map((product) => {
                                const rating = product.rating?.average;
                                const count = product.rating?.count || 0;
                                const price = hidePrice || product.show_price === false ? '' : productPrice(product);
                                const was = product.compare_price_formatted
                                    || (product.compare_price ? product.compare_price_formatted : null);
                                const hasSale = Boolean(product.discount_percent && was);

                                return (
                                    <li key={product.id}>
                                        <Link
                                            className="cx-suggest-product"
                                            to={productPath(product)}
                                            state={entityState('product')}
                                            onClick={onClose}
                                        >
                                            <span className="cx-suggest-thumb">
                                                <ProductImage product={product} fit="contain" />
                                            </span>
                                            <span className="cx-suggest-body">
                                                <span className="cx-suggest-name">{highlightMatch(product.name, q)}</span>
                                                {price ? (
                                                    <span className="cx-suggest-price-row">
                                                        <span className="cx-suggest-price">{price}</span>
                                                        {hasSale ? <span className="cx-suggest-was">{was}</span> : null}
                                                    </span>
                                                ) : (pricePrompt ? (
                                                    <span className="cx-suggest-price">{t('storefront.price.sign_in', 'Sign in to see the price')}</span>
                                                ) : null)}
                                                <span className="cx-suggest-bottom">
                                                    {rating != null && count > 0 ? (
                                                        <span className="cx-suggest-rating" title={`${formatRating(rating)} (${count})`}>
                                                            <SuggestStars value={rating} />
                                                            <span className="cx-suggest-score">{formatRating(rating)}</span>
                                                            <span className="cx-suggest-reviews">({count})</span>
                                                        </span>
                                                    ) : (
                                                        <span className="cx-suggest-badge">{t('storefront.search.no_reviews', 'No reviews')}</span>
                                                    )}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : null}
                </section>
            </div>

            <div className="cx-suggest-foot">
                <div className="cx-suggest-foot-copy">
                    <Icon name="help" className="h-5 w-5" />
                    <span>
                        {t('storefront.search.cant_find', "Can't find what you're looking for?")}
                        {' '}
                        {t('storefront.search.browse_or_support', 'Browse categories or contact support.')}
                    </span>
                </div>
                <Link className="cx-suggest-foot-btn" to="/catalog" onClick={onClose}>
                    {t('storefront.search.view_categories', 'View all categories')}
                    <Icon name="chevron" className="h-4 w-4" />
                </Link>
            </div>
        </div>
    );
}
