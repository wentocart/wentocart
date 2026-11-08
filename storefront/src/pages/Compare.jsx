import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { compareIds } from '../lib/storage.js';
import { formatMoney } from '../lib/format.js';
import { entityState, productPath } from '../lib/links.js';
import { compareEnabled, pricesHidden } from '../lib/commerce.js';
import { EmptyState } from '../components/Feedback.jsx';
import { useStore } from '../state/StoreState.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import ProductImage from '../components/ProductImage.jsx';
import ProductQuickActions from '../components/ProductQuickActions.jsx';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';

function plainText(html) {
    return String(html || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function stockLabel(product) {
    const status = String(product?.stock_status || '').toLowerCase();
    if (status === 'in_stock' || status === 'instock') {
        return t('storefront.compare.in_stock', 'In stock');
    }
    if (status === 'out_of_stock' || status === 'outofstock') {
        return t('storefront.compare.out_of_stock', 'Out of stock');
    }
    if (status === 'on_backorder' || status === 'backorder') {
        return t('storefront.compare.on_backorder', 'On backorder');
    }
    if (product?.available_for_order === false) {
        return t('storefront.compare.unavailable', 'Unavailable');
    }
    return status ? status.replace(/_/g, ' ') : '';
}

const TYPE_LABELS = {
    simple: () => t('storefront.compare.type_simple', 'Simple'),
    combination: () => t('storefront.compare.type_combination', 'Combination'),
    virtual: () => t('storefront.compare.type_virtual', 'Virtual / digital'),
    service: () => t('storefront.compare.type_service', 'Service'),
    gift_card: () => t('storefront.compare.type_gift_card', 'Gift card'),
};

function productTypeLabel(type) {
    const key = String(type || '').trim();
    if (!key) return '';
    if (TYPE_LABELS[key]) return TYPE_LABELS[key]();
    return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fieldValue(product, key) {
    switch (key) {
        case 'name':
            return product?.name || '';
        case 'price':
            if (product?.show_price === false) {
                return '';
            }
            return product?.price_formatted || (product?.price != null ? formatMoney(product.price) : '');
        case 'brand':
            return product?.brand?.name || '';
        case 'size':
            return (product?.dimensions || []).filter(Boolean).join(', ');
        case 'color':
            return (product?.colors || []).map((c) => c?.name).filter(Boolean).join(', ');
        case 'condition':
            return product?.condition || product?.display_condition || '';
        case 'availability':
            return stockLabel(product);
        case 'shipping': {
            if (product?.requires_shipping === false) {
                return t('storefront.compare.no_shipping', 'No shipping required');
            }
            const methods = (product?.shipping_methods || []).map((m) => m?.name).filter(Boolean);
            if (methods.length) {
                return methods.join(', ');
            }
            return product?.requires_shipping ? t('storefront.compare.ships', 'Shipping available') : '';
        }
        case 'type':
            return productTypeLabel(product?.type);
        case 'subscription': {
            if (product?.subscription_enabled) {
                return t('storefront.compare.subscription_available', 'Available');
            }
            return t('storefront.compare.subscription_unavailable', 'Not available');
        }
        case 'preorder': {
            const preorder = product?.preorder;
            if (preorder && (preorder.active || preorder.enabled)) {
                return t('storefront.compare.preorder_available', 'Available');
            }
            if (product?.flags?.preorder) {
                return t('storefront.compare.preorder_available', 'Available');
            }
            return t('storefront.compare.preorder_unavailable', 'Not available');
        }
        case 'weight': {
            if (product?.weight == null || product.weight === '') {
                return '';
            }
            const unit = String(product.weight_class || '').trim();
            return unit ? `${product.weight} ${unit}` : String(product.weight);
        }
        case 'description':
            return plainText(product?.short_description || product?.description || '');
        default:
            return '';
    }
}

function hasValue(product, key) {
    if (key === 'image') {
        return Boolean(product?.cover_url || product?.cover);
    }
    // Yes/No style fields always resolve so enabled rows stay visible for comparison.
    if (key === 'subscription' || key === 'preorder') {
        return true;
    }
    return String(fieldValue(product, key) || '').trim() !== '';
}

function availabilityTone(product) {
    const status = String(product?.stock_status || '').toLowerCase();
    if (status === 'in_stock' || status === 'instock') return 'ok';
    if (status === 'out_of_stock' || status === 'outofstock') return 'bad';
    if (status === 'on_backorder' || status === 'backorder') return 'warn';
    return 'muted';
}

function CompareValue({ product, fieldKey }) {
    const store = useStore();
    const value = fieldKey === 'price' && (pricesHidden(store.shop, store.customer) || product?.show_price === false)
        ? ''
        : fieldValue(product, fieldKey);
    if (!String(value || '').trim()) {
        return <span className="shop-compare-empty">—</span>;
    }
    if (fieldKey === 'availability') {
        return (
            <span className={`shop-compare-stock shop-compare-stock--${availabilityTone(product)}`}>
                {value}
            </span>
        );
    }
    if (fieldKey === 'description') {
        return <p className="shop-compare-desc">{value}</p>;
    }
    return <span className="shop-compare-text">{value}</span>;
}

export default function Compare() {
    const store = useStore();
    const [products, setProducts] = useState([]);
    const [fields, setFields] = useState([]);
    const [loading, setLoading] = useState(false);
    const ids = store.compare.length ? store.compare : compareIds();
    const comparisonOn = compareEnabled(store.shop);

    useEffect(() => {
        if (!comparisonOn || ids.length < 2) {
            setProducts([]);
            setFields([]);
            setLoading(false);
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        api.get('/catalog/compare', { params: { ids } })
            .then((r) => {
                if (cancelled) return;
                setProducts(r.data.products || []);
                setFields(Array.isArray(r.data.fields) ? r.data.fields : []);
            })
            .catch(() => {
                if (cancelled) return;
                setProducts([]);
                setFields([]);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
    }, [comparisonOn, ids.join(',')]);

    const enabled = useMemo(
        () => (Array.isArray(fields) ? fields.filter((f) => f && f.key) : []),
        [fields],
    );
    // Name + image are fixed header fields (always on); price follows BO checklist.
    const showImage = true;
    const showName = true;
    const showPrice = enabled.some((f) => f.key === 'price');
    const bodyRows = useMemo(
        () => enabled.filter((f) => !['name', 'image', 'price'].includes(f.key)
            && products.some((p) => hasValue(p, f.key))),
        [enabled, products],
    );

    const crumbs = [
        { name: t('storefront.shell.home', 'Home'), url: '/' },
        { name: t('storefront.shell.compare', 'Compare') },
    ];

    if (!comparisonOn) {
        return (
            <div className="shop-compare">
                <Breadcrumbs items={crumbs} />
                <div className="shop-compare-empty-wrap">
                    <EmptyState
                        title={t('storefront.compare.disabled', 'Product comparison is disabled')}
                        body={t('storefront.compare.disabled_body', 'An administrator has turned off product comparison for this store.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>}
                    />
                </div>
            </div>
        );
    }

    if (ids.length < 2) {
        return (
            <div className="shop-compare">
                <Breadcrumbs items={crumbs} />
                <header className="shop-compare-head">
                    <div>
                        <h1>
                            <Icon name="compare" className="h-5 w-5" aria-hidden="true" />
                            {t('storefront.shell.compare', 'Compare')}
                        </h1>
                        <p>{t('storefront.compare.lead', 'Compare products side by side before you buy.')}</p>
                    </div>
                </header>
                <div className="shop-compare-empty-wrap">
                    <EmptyState
                        title={t('storefront.compare.need_two', 'Compare needs two products')}
                        body={t('storefront.compare.body', 'Use Compare on a product page, then return here.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>}
                    />
                </div>
            </div>
        );
    }

    const colCount = Math.max(products.length, 1);

    return (
        <div className="shop-compare">
            <Breadcrumbs items={crumbs} />
            <header className="shop-compare-head">
                <div>
                    <h1>
                        <Icon name="compare" className="h-5 w-5" aria-hidden="true" />
                        {t('storefront.shell.compare', 'Compare')}
                        {products.length > 0 ? <span>({products.length})</span> : null}
                    </h1>
                    <p>{t('storefront.compare.lead', 'Compare products side by side before you buy.')}</p>
                </div>
                <Link to="/catalog" className="shop-compare-browse">
                    {t('storefront.compare.add_more', 'Add more products')}
                </Link>
            </header>

            <div className="shop-compare-panel shop-fade-up" aria-busy={loading || undefined}>
                <div className="shop-compare-scroll">
                    <table
                        className="shop-compare-table"
                        style={{ '--compare-cols': colCount }}
                    >
                        <caption className="sr-only">
                            {t('storefront.compare.caption', 'Product comparison table')}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" className="shop-compare-corner">
                                    <span className="sr-only">
                                        {t('storefront.compare.features', 'Features')}
                                    </span>
                                </th>
                                {products.map((product) => {
                                    const href = productPath(product) || product.url || '/';
                                    const price = pricesHidden(store.shop, store.customer) || product.show_price === false
                                        ? ''
                                        : (product.price_formatted || (product.price != null ? formatMoney(product.price) : ''));
                                    const removeLabel = t('storefront.compare.remove', 'Remove from comparison');
                                    return (
                                        <th key={product.id} scope="col" className="shop-compare-product">
                                            <div className="shop-compare-card">
                                                <button
                                                    type="button"
                                                    className="shop-compare-remove"
                                                    aria-label={removeLabel}
                                                    title={removeLabel}
                                                    onClick={() => store.toggleCompare(product.id)}
                                                >
                                                    <Icon name="close" className="h-3.5 w-3.5" aria-hidden="true" />
                                                </button>
                                                {showImage ? (
                                                    <Link
                                                        to={href}
                                                        state={entityState('product')}
                                                        className="shop-compare-media"
                                                        aria-label={product.name}
                                                    >
                                                        <ProductImage product={product} fit="contain" size="md" />
                                                    </Link>
                                                ) : null}
                                                <div className="shop-compare-card-body">
                                                    {showName ? (
                                                        <Link
                                                            to={href}
                                                            state={entityState('product')}
                                                            className="shop-compare-name"
                                                        >
                                                            {product.name}
                                                        </Link>
                                                    ) : null}
                                                    {showPrice ? (
                                                        <div className="shop-compare-price">{price}</div>
                                                    ) : null}
                                                    <ProductQuickActions
                                                        product={product}
                                                        className="shop-compare-actions"
                                                    />
                                                </div>
                                            </div>
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {products.length === 0 && !loading ? (
                                <tr>
                                    <td
                                        className="shop-compare-no-rows"
                                        colSpan={2}
                                    >
                                        {t('storefront.compare.load_failed', 'These products could not be loaded for comparison.')}
                                    </td>
                                </tr>
                            ) : null}
                            {bodyRows.map((row, index) => (
                                <tr
                                    key={row.key}
                                    className={index % 2 === 0 ? 'shop-compare-row is-alt' : 'shop-compare-row'}
                                >
                                    <th scope="row" className="shop-compare-label">
                                        {row.label}
                                    </th>
                                    {products.map((product) => (
                                        <td key={product.id} className="shop-compare-value">
                                            <CompareValue product={product} fieldKey={row.key} />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
