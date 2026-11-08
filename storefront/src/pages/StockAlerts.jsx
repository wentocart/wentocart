import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { entityState, productPath } from '../lib/links.js';
import { outOfStockNotificationEnabled } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ProductImage from '../components/ProductImage.jsx';

function statusLabel(status) {
    if (status === 'notified') return t('storefront.account.stock_alert_notified', 'Notified');
    return t('storefront.account.stock_alert_pending', 'Pending');
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function variantLabel(row) {
    if (!row?.product_variant_id && !row?.variant) return '';
    return row.variant?.label || row.variant?.sku || (row.product_variant_id ? `#${row.product_variant_id}` : '');
}

export default function StockAlerts() {
    const store = useStore();
    const enabled = outOfStockNotificationEnabled(store.shop);
    const [payload, setPayload] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [page, setPage] = useState(1);

    const load = (targetPage = 1) => {
        if (!enabled) {
            setPayload({ data: [], current_page: 1, last_page: 1, total: 0 });
            return;
        }
        api.get('/account/stock-alerts', { params: { page: targetPage, per_page: 10 } })
            .then((r) => {
                setPayload({
                    data: r.data.alerts || [],
                    current_page: r.data.meta?.current_page || 1,
                    last_page: r.data.meta?.last_page || 1,
                    total: r.data.meta?.total || 0,
                });
                setPage(r.data.meta?.current_page || 1);
            })
            .catch(() => setPayload({ data: [], current_page: 1, last_page: 1, total: 0 }));
    };

    useEffect(() => {
        load(1);
    }, [enabled]);

    const unsubscribe = async (row) => {
        if (busyId) return;
        setBusyId(row.id);
        try {
            await csrf();
            await api.delete(`/account/stock-alerts/${row.id}`);
            store.ping?.(t('storefront.account.stock_alert_removed', 'Stock alert removed'));
            load(page);
        } catch (err) {
            store.ping?.(err.response?.data?.message || t('storefront.account.stock_alert_remove_failed', 'Could not remove stock alert.'), 'error');
        } finally {
            setBusyId(null);
        }
    };

    if (!payload) {
        return <PageSkeleton />;
    }

    const rows = payload.data || [];
    const lastPage = Math.max(1, Number(payload.last_page) || 1);
    const current = Number(payload.current_page) || 1;
    const total = Number(payload.total) || rows.length;

    return (
        <div className="shop-orders shop-stock-alerts">
            <header className="shop-orders-head">
                <div>
                    <p className="shop-orders-kicker">{t('storefront.account.stock_alerts', 'Stock Alerts')}</p>
                    <h1>{t('storefront.account.stock_alerts_title', 'My stock alerts')}</h1>
                    <p>{t('storefront.account.stock_alerts_body', 'Products you asked to be notified about when they are back in stock.')}</p>
                </div>
                <div className="shop-orders-summary" aria-hidden="true">
                    <span className="shop-orders-summary-count">{total}</span>
                    <span className="shop-orders-summary-label">{t('storefront.account.stock_alerts', 'Stock Alerts')}</span>
                </div>
            </header>

            {!enabled ? (
                <p className="shop-notice" role="status">
                    {t('storefront.account.stock_alerts_disabled', 'Stock alerts are currently disabled for this store.')}
                </p>
            ) : null}

            {rows.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_stock_alerts', 'No stock alerts yet.')}
                        body={t('storefront.account.no_stock_alerts_body', 'When a product is out of stock, use Notify me when available on the product page.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>}
                    />
                </div>
            ) : (
                <>
                    <div className="shop-module-table-wrap">
                        <table className="shop-module-table">
                            <thead>
                                <tr>
                                    <th>{t('storefront.account.product', 'Product')}</th>
                                    <th>{t('storefront.account.subscribed', 'Subscribed')}</th>
                                    <th>{t('storefront.account.availability', 'Availability')}</th>
                                    <th>{t('storefront.account.status', 'Status')}</th>
                                    <th>{t('storefront.account.actions', 'Actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const product = row.product || {};
                                    const href = product.slug || product.id ? productPath(product) : null;
                                    const name = product.name || t('storefront.account.items', 'Items');
                                    const variant = variantLabel(row);
                                    const imageProduct = {
                                        name,
                                        cover: product.cover_path || product.image,
                                        cover_url: product.cover_path || product.image,
                                    };

                                    return (
                                        <tr key={row.id}>
                                            <td data-label={t('storefront.account.product', 'Product')}>
                                                <div className="shop-stock-alerts-product">
                                                    <div className="shop-stock-alerts-thumb">
                                                        <ProductImage product={imageProduct} alt={name} size="sm" fit="cover" />
                                                    </div>
                                                    <div className="shop-stock-alerts-meta">
                                                        {href ? (
                                                            <Link to={href} state={entityState('product')} className="shop-preorders-name">
                                                                {name}
                                                            </Link>
                                                        ) : (
                                                            <span className="shop-preorders-name">{name}</span>
                                                        )}
                                                        {variant ? (
                                                            <span className="shop-stock-alerts-variant">{variant}</span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label={t('storefront.account.subscribed', 'Subscribed')}>{formatDate(row.subscribed_at)}</td>
                                            <td data-label={t('storefront.account.availability', 'Availability')}>
                                                {row.available
                                                    ? t('storefront.account.in_stock', 'In stock')
                                                    : t('storefront.account.out_of_stock', 'Out of stock')}
                                            </td>
                                            <td data-label={t('storefront.account.status', 'Status')}>
                                                <span className={`shop-order-status is-${row.status === 'notified' ? 'delivered' : 'processing'}`}>
                                                    <i />
                                                    {statusLabel(row.status)}
                                                </span>
                                            </td>
                                            <td data-label={t('storefront.account.actions', 'Actions')}>
                                                {row.status === 'pending' ? (
                                                    <button
                                                        type="button"
                                                        className="shop-btn shop-btn-ghost"
                                                        disabled={busyId === row.id}
                                                        onClick={() => unsubscribe(row)}
                                                    >
                                                        {busyId === row.id
                                                            ? t('storefront.common.please_wait', 'Please wait')
                                                            : t('storefront.account.unsubscribe', 'Unsubscribe')}
                                                    </button>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="shop-btn shop-btn-ghost shop-stock-alerts-delete"
                                                        disabled={busyId === row.id}
                                                        onClick={() => unsubscribe(row)}
                                                        aria-label={t('storefront.common.delete', 'Delete')}
                                                        title={t('storefront.common.delete', 'Delete')}
                                                    >
                                                        <Icon name="trash" className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {lastPage > 1 ? (
                        <div className="shop-orders-pager">
                            <button type="button" className="shop-btn shop-btn-ghost" disabled={current <= 1} onClick={() => load(current - 1)}>
                                {t('storefront.common.prev', 'Previous')}
                            </button>
                            <span>{current} / {lastPage}</span>
                            <button type="button" className="shop-btn shop-btn-ghost" disabled={current >= lastPage} onClick={() => load(current + 1)}>
                                {t('storefront.common.next', 'Next')}
                            </button>
                        </div>
                    ) : null}
                </>
            )}
        </div>
    );
}
