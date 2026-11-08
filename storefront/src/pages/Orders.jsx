import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ProductImage from '../components/ProductImage.jsx';

const TABS = [
    { key: 'all', label: 'All' },
    { key: 'processing', label: 'Processing' },
    { key: 'shipped', label: 'Shipped' },
    { key: 'delivered', label: 'Delivered' },
    { key: 'cancelled', label: 'Cancelled' },
];

const JOURNEY = ['processing', 'shipped', 'delivered'];

function statusBucket(status) {
    const s = String(status || '').toLowerCase();
    if (['cancelled', 'canceled', 'refunded', 'void'].includes(s)) {
        return 'cancelled';
    }
    if (['completed', 'delivered', 'done'].includes(s)) {
        return 'delivered';
    }
    if (['shipped', 'transit', 'in_transit'].includes(s)) {
        return 'shipped';
    }
    if (['pending', 'processing', 'pack', 'paid', 'new'].includes(s)) {
        return 'processing';
    }
    return s || 'processing';
}

function statusTone(bucket) {
    if (bucket === 'delivered') return 'delivered';
    if (bucket === 'shipped') return 'shipped';
    if (bucket === 'cancelled') return 'cancelled';
    return 'processing';
}

function statusLabel(bucket, raw) {
    if (bucket === 'delivered') return t('storefront.account.status_delivered', 'Delivered');
    if (bucket === 'shipped') return t('storefront.account.status_shipped', 'Shipped');
    if (bucket === 'cancelled') return t('storefront.account.status_cancelled', 'Cancelled');
    if (bucket === 'processing') return t('storefront.account.status_processing', 'Processing');
    return raw || bucket;
}

function tabLabel(key) {
    if (key === 'all') return t('storefront.account.tab_all', 'All Orders');
    if (key === 'processing') return t('storefront.account.status_processing', 'Processing');
    if (key === 'shipped') return t('storefront.account.status_shipped', 'Shipped');
    if (key === 'delivered') return t('storefront.account.status_delivered', 'Delivered');
    return t('storefront.account.status_cancelled', 'Cancelled');
}

function formatPlaced(value) {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function addressLabel(ship) {
    if (!ship || typeof ship !== 'object') {
        return '—';
    }
    return ship.label || ship.city || [ship.line1, ship.country_code].filter(Boolean).join(', ') || '—';
}

function journeyIndex(bucket) {
    const i = JOURNEY.indexOf(bucket);
    return i < 0 ? -1 : i;
}

export default function Orders() {
    const [orders, setOrders] = useState(null);
    const [tab, setTab] = useState('all');
    const [q, setQ] = useState('');

    useEffect(() => {
        api.get('/account/orders', { params: { per_page: 50 } })
            .then((r) => setOrders(r.data.orders?.data || r.data.orders || []))
            .catch(() => setOrders([]));
    }, []);

    const counts = useMemo(() => {
        const next = { all: 0, processing: 0, shipped: 0, delivered: 0, cancelled: 0 };
        (orders || []).forEach((order) => {
            const bucket = statusBucket(order.status);
            next.all += 1;
            if (next[bucket] !== undefined) {
                next[bucket] += 1;
            }
        });
        return next;
    }, [orders]);

    const filtered = useMemo(() => {
        const list = orders || [];
        const query = q.trim().toLowerCase();
        return list.filter((order) => {
            const bucket = statusBucket(order.status);
            if (tab !== 'all' && bucket !== tab) {
                return false;
            }
            if (!query) {
                return true;
            }
            const hay = [
                order.number,
                order.status,
                ...(order.items || []).map((item) => item.name),
            ].join(' ').toLowerCase();
            return hay.includes(query);
        });
    }, [orders, tab, q]);

    if (!orders) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-orders">
            <header className="shop-orders-head">
                <div>
                    <p className="shop-orders-kicker">{t('storefront.account.orders', 'Orders')}</p>
                    <h1>{t('storefront.account.my_orders', 'My orders')}</h1>
                    <p>{t('storefront.account.orders_body', 'View and track all your orders in one place.')}</p>
                </div>
                <div className="shop-orders-summary" aria-hidden="true">
                    <span className="shop-orders-summary-count">{counts.all}</span>
                    <span className="shop-orders-summary-label">{t('storefront.account.total_orders', 'Total')}</span>
                </div>
            </header>

            <div className="shop-orders-toolbar">
                <nav className="shop-orders-tabs" aria-label={t('storefront.account.order_filters', 'Order filters')}>
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            className={tab === item.key ? 'is-active' : ''}
                            onClick={() => setTab(item.key)}
                        >
                            <span>{tabLabel(item.key)}</span>
                            <em>{counts[item.key] ?? 0}</em>
                        </button>
                    ))}
                </nav>
                <label className="shop-orders-search">
                    <Icon name="search" className="h-4 w-4" />
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('storefront.account.search_order', 'Search order...')}
                        aria-label={t('storefront.account.search_order', 'Search order...')}
                    />
                </label>
            </div>

            {orders.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_orders', 'No orders yet.')}
                        body={t('storefront.account.no_orders_body', "Looks like you haven't placed any orders yet.")}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>}
                    />
                </div>
            ) : filtered.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_orders_match', 'No matching orders')}
                        body={t('storefront.account.no_orders_match_body', 'Try another filter or search term.')}
                    />
                </div>
            ) : (
                <div className="shop-orders-list">
                    {filtered.map((order, index) => {
                        const bucket = statusBucket(order.status);
                        const tone = statusTone(bucket);
                        const items = order.items || [];
                        const preview = items.slice(0, 3);
                        const more = Math.max(0, items.length - preview.length);
                        const first = items[0];
                        const ship = order.shipping_address || {};
                        const paymentName = order.payment_method?.name
                            || order.payment_details?.method
                            || order.payment_status
                            || '—';
                        const step = journeyIndex(bucket);
                        const canTrack = bucket === 'shipped' || bucket === 'delivered';

                        return (
                            <article
                                key={order.id}
                                className={`shop-order-card is-${tone} shop-fade-up`}
                                style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}
                            >
                                <div className="shop-order-rail" aria-hidden="true" />

                                <header className="shop-order-card-head">
                                    <div className="shop-order-id-block">
                                        <strong>
                                            <Icon name="package" className="h-4 w-4" />
                                            #{order.number}
                                        </strong>
                                        <em>
                                            {t('storefront.account.placed_on', 'Placed on')} {formatPlaced(order.created_at)}
                                            {order.checkout_reference && order.checkout_reference !== order.number ? ` · ${order.checkout_reference}` : ''}
                                        </em>
                                    </div>
                                    <div className="shop-order-card-head-right">
                                        <span className={`shop-order-status is-${tone}`}>
                                            <i />
                                            {statusLabel(bucket, order.status)}
                                        </span>
                                        <strong className="shop-order-total">{formatMoney(order.grand_total, order.currency_code)}</strong>
                                    </div>
                                </header>

                                {bucket !== 'cancelled' ? (
                                    <ol className="shop-order-journey" aria-label={t('storefront.account.order_progress', 'Order progress')}>
                                        {JOURNEY.map((key, i) => (
                                            <li
                                                key={key}
                                                className={
                                                    step > i ? 'is-done' : step === i ? 'is-current' : ''
                                                }
                                            >
                                                <span />
                                                <em>{statusLabel(key)}</em>
                                            </li>
                                        ))}
                                    </ol>
                                ) : null}

                                <div className="shop-order-card-body">
                                    <div className="shop-order-product">
                                        <div className="shop-order-thumbs" aria-hidden="true">
                                            {preview.length ? preview.map((item, i) => {
                                                const product = item.product || {};
                                                return (
                                                    <div key={item.id || `${order.id}-${i}`} className="shop-order-thumb" style={{ zIndex: preview.length - i }}>
                                                        <ProductImage product={{
                                                            name: item.name,
                                                            cover: product.cover_path || product.cover,
                                                            cover_url: product.cover_url,
                                                        }} />
                                                    </div>
                                                );
                                            }) : (
                                                <div className="shop-order-thumb is-empty">
                                                    <Icon name="bag" className="h-5 w-5" />
                                                </div>
                                            )}
                                            {more > 0 ? <span className="shop-order-thumb-more">+{more}</span> : null}
                                        </div>
                                        <div>
                                            <p className="shop-order-name">{first?.name || t('storefront.account.items', 'Items')}</p>
                                            <p className="shop-order-qty">
                                                {items.length} {items.length === 1
                                                    ? t('storefront.account.item', 'item')
                                                    : t('storefront.account.items_count', 'items')}
                                                {first?.qty ? ` · ${t('storefront.cart.qty', 'Qty')} ${first.qty}` : ''}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="shop-order-meta">
                                        <div>
                                            <em>{t('storefront.checkout.payment', 'Payment')}</em>
                                            <strong>{paymentName}</strong>
                                        </div>
                                        <div>
                                            <em>{t('storefront.checkout.shipping_address', 'Ship to')}</em>
                                            <strong>{addressLabel(ship)}</strong>
                                        </div>
                                    </div>

                                    <div className="shop-order-actions">
                                        {canTrack ? (
                                        <Link to={`/account/orders/${order.number}${order.id ? `?id=${order.id}` : ''}`} className="shop-order-track">
                                                <Icon name="truck" className="h-4 w-4" />
                                                {t('storefront.account.track_order', 'Track')}
                                            </Link>
                                        ) : null}
                                        <Link to={`/account/orders/${order.number}${order.id ? `?id=${order.id}` : ''}`} className="shop-order-details">
                                            {t('storefront.account.view_details', 'View details')}
                                            <Icon name="chevron" className="h-4 w-4" />
                                        </Link>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
