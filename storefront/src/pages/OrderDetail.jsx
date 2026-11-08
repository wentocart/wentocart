import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import { invoicesEnabled, quantityNoticesFrom, returnsEnabled, returnWindowDays } from '../lib/commerce.js';
import ProductImage from '../components/ProductImage.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import { useStore } from '../state/StoreState.jsx';
import { entityState, productPath } from '../lib/links.js';
import { firePurchase } from '../components/CookieConsent.jsx';
import { webPath } from '../webPath.js';

function OrderDownloadThumb({ file }) {
    const label = String(file.file_type_label || '').trim() || 'FILE';
    const preview = file.is_image && file.preview_url && file.available
        ? webPath(file.preview_url)
        : null;

    if (preview) {
        return (
            <div className="shop-od-dl-thumb shop-od-dl-thumb-image">
                <img src={preview} alt="" loading="lazy" decoding="async" />
            </div>
        );
    }

    return (
        <div className="shop-od-dl-thumb shop-od-dl-thumb-type" aria-hidden="true">
            <span>{label}</span>
        </div>
    );
}

const JOURNEY = [
    { key: 'placed', icon: 'check', label: 'Order Placed' },
    { key: 'processing', icon: 'package', label: 'Processing' },
    { key: 'shipped', icon: 'truck', label: 'Shipped' },
    { key: 'delivered', icon: 'home', label: 'Delivered' },
];

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

function journeyStep(bucket) {
    if (bucket === 'cancelled') return -1;
    if (bucket === 'delivered') return 3;
    if (bucket === 'shipped') return 2;
    if (bucket === 'processing') return 1;
    return 0;
}

function formatPlaced(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatAddress(addr) {
    if (!addr || typeof addr !== 'object') return null;
    const name = [addr.first_name, addr.last_name].filter(Boolean).join(' ').trim();
    const lines = [
        addr.line1,
        addr.line2,
        [addr.city, addr.state || addr.region, addr.postal_code].filter(Boolean).join(', '),
        addr.country_code || addr.country,
    ].filter(Boolean);
    return { name, lines, phone: addr.phone || addr.phone_number };
}

function paymentLabel(status) {
    const s = String(status || '').toLowerCase();
    if (['paid', 'captured', 'complete', 'completed'].includes(s)) {
        return t('storefront.account.payment_paid', 'Paid');
    }
    if (['pending', 'awaiting', 'unpaid'].includes(s)) {
        return t('storefront.account.payment_pending', 'Pending');
    }
    if (['failed', 'declined'].includes(s)) {
        return t('storefront.account.payment_failed', 'Failed');
    }
    if (['refunded', 'partially_refunded'].includes(s)) {
        return t('storefront.account.payment_refunded', 'Refunded');
    }
    return status || '—';
}

function paymentTone(status) {
    const s = String(status || '').toLowerCase();
    if (['paid', 'captured', 'complete', 'completed'].includes(s)) return 'paid';
    if (['failed', 'declined'].includes(s)) return 'failed';
    if (['refunded', 'partially_refunded'].includes(s)) return 'refunded';
    return 'pending';
}

export default function OrderDetail() {
    const { number } = useParams();
    const [searchParams] = useSearchParams();
    const orderId = searchParams.get('id');
    const orderQuery = orderId ? { params: { id: orderId } } : {};
    const orderQs = orderId ? `?id=${encodeURIComponent(orderId)}` : '';
    const store = useStore();
    const [order, setOrder] = useState(null);
    const [sellerOrders, setSellerOrders] = useState([]);
    const [returns, setReturns] = useState([]);
    const [withdrawals, setWithdrawals] = useState([]);
    const [canRequestWithdrawal, setCanRequestWithdrawal] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [returnOpen, setReturnOpen] = useState(false);
    const [returnReason, setReturnReason] = useState('');
    const [returnQtys, setReturnQtys] = useState({});
    const [withdrawalOpen, setWithdrawalOpen] = useState(false);
    const [withdrawalReason, setWithdrawalReason] = useState('');
    const [buyingId, setBuyingId] = useState(null);
    const [reordering, setReordering] = useState(false);

    const load = () => api.get(`/account/orders/${number}`, orderQuery).then((r) => {
        setOrder(r.data.order);
        setSellerOrders(r.data.seller_orders || []);
        setReturns(r.data.returns || []);
        setWithdrawals(r.data.eu_withdrawals || []);
        setCanRequestWithdrawal(!!r.data.can_request_eu_withdrawal);
    }).catch(() => setError(t('storefront.account.order_missing', 'We could not load that order.')));

    useEffect(() => { load(); }, [number, orderId]);

    useEffect(() => {
        if (order?.number) {
            firePurchase(order);
        }
    }, [order?.number, order?.payment_status]);

    const openReturn = () => {
        const next = {};
        (order?.items || []).forEach((item) => {
            const qty = Number(item.returnable_qty || 0);
            if (qty > 0) {
                next[item.id] = qty;
            }
        });
        setReturnQtys(next);
        setReturnOpen(true);
    };

    const requestShopReturn = async () => {
        setBusy(true);
        setError('');
        setNotice('');
        const returnCfg = store.shop?.commerce?.returns || {};
        const reasonRequired = returnCfg.reason_required !== false;
        const allowPartial = returnCfg.partial !== false;
        try {
            await csrf();
            const reason = returnReason.trim();
            if (reasonRequired && reason === '') {
                setError(t('storefront.account.return_reason_required', 'Enter a reason for this return.'));
                return;
            }
            const items = (order.items || [])
                .filter((item) => Number(item.returnable_qty || 0) > 0)
                .map((item) => ({
                    order_item_id: item.id,
                    qty: allowPartial
                        ? Number(returnQtys[item.id] ?? item.returnable_qty ?? item.qty ?? 1)
                        : Number(item.returnable_qty || item.qty || 1),
                }))
                .filter((item) => item.qty > 0);
            if (!items.length) {
                setError(t('storefront.account.return_none', 'No returnable items on this order.'));
                return;
            }
            await api.post(`/account/orders/${number}/returns${orderQs}`, {
                reason: reason || 'Customer request',
                items,
            });
            setNotice(t('storefront.account.return_sent', 'Return request submitted.'));
            await load();
        } catch (err) {
            setError(err?.response?.data?.message || t('storefront.account.return_failed', 'Could not submit return.'));
        } finally {
            setBusy(false);
        }
    };

    const requestReturn = async (sellerOrder) => {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await csrf();
            const items = (sellerOrder.items || [])
                .filter((item) => Number(item.returnable_qty || 0) > 0)
                .map((item) => ({
                    seller_order_item_id: item.id,
                    qty: Number(item.returnable_qty || item.qty || 1),
                }));
            if (!items.length) {
                setError(t('storefront.account.return_none', 'No returnable items on this seller order.'));
                return;
            }
            await api.post(`/account/orders/${number}/returns${orderQs}`, {
                seller_order_id: sellerOrder.id,
                reason: 'Customer request',
                items,
            });
            setNotice(t('storefront.account.return_sent', 'Return request submitted.'));
            await load();
        } catch (err) {
            setError(err?.response?.data?.message || t('storefront.account.return_failed', 'Could not submit return.'));
        } finally {
            setBusy(false);
        }
    };

    const requestEuWithdrawal = async () => {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await csrf();
            await api.post(`/account/orders/${number}/withdrawal${orderQs}`, {
                reason: withdrawalReason.trim() || undefined,
            });
            setNotice(t('storefront.account.withdrawal_sent', 'EU withdrawal request submitted.'));
            setWithdrawalOpen(false);
            setWithdrawalReason('');
            await load();
        } catch (err) {
            setError(
                err?.response?.data?.message
                    || err?.response?.data?.errors?.order?.[0]
                    || t('storefront.account.withdrawal_failed', 'Could not submit EU withdrawal.'),
            );
        } finally {
            setBusy(false);
        }
    };

    const cancelOrder = async () => {
        if (!window.confirm(t('storefront.account.cancel_confirm', 'Cancel this order?'))) {
            return;
        }
        setBusy(true);
        setError('');
        setNotice('');
        try {
            await csrf();
            await api.post(`/account/orders/${number}/cancel${orderQs}`);
            setNotice(t('storefront.account.cancel_ok', 'Order cancelled.'));
            await load();
        } catch (err) {
            setError(err?.response?.data?.message || t('storefront.account.cancel_failed', 'Could not cancel this order.'));
        } finally {
            setBusy(false);
        }
    };

    const buyAgain = async (item) => {
        const productId = item?.product_id || item?.product?.id;
        if (!productId || buyingId) return;
        setBuyingId(item.id || productId);
        setError('');
        try {
            await store.addToCart(productId, item.qty || 1);
            setNotice('');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.account.buy_again_failed', 'Could not add this item again.'));
        } finally {
            setBuyingId(null);
        }
    };

    const reorderAll = async () => {
        if (reordering) return;
        setReordering(true);
        setError('');
        setNotice('');
        try {
            await csrf();
            const { data } = await api.post(`/account/orders/${number}/reorder${orderQs}`);
            await store.reloadCart?.();
            const limited = quantityNoticesFrom(data);
            limited.forEach((message) => store.ping?.(message, 'warning'));
            setNotice(limited[0] || t('storefront.account.reordered', '{n} item(s) added to your cart.').replace('{n}', String(data.added || 0)));
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.account.reorder_failed', 'Could not add these items to the cart.'));
        } finally {
            setReordering(false);
        }
    };

    if (error && !order) {
        return <EmptyState title={error} action={<Link to="/account/orders" className="shop-btn shop-btn-primary">{t('storefront.account.my_orders', 'My orders')}</Link>} />;
    }
    if (!order) {
        return <PageSkeleton />;
    }

    const bucket = statusBucket(order.status);
    const tone = statusTone(bucket);
    const step = journeyStep(bucket);
    const ship = formatAddress(order.shipping_address);
    const bill = formatAddress(order.billing_address);
    const items = order.items || [];
    const orderDownloads = Array.isArray(order.downloads) && order.downloads.length
        ? order.downloads
        : items.flatMap((item) => item.downloads || []);
    const canReturn = returnsEnabled(store.shop) && ['paid', 'partially_refunded'].includes(order.payment_status);
    const discount = Number(order.discount_total || 0);
    const paymentName = order.payment_method?.name || order.payment_details?.method || '—';
    const payTone = paymentTone(order.payment_status);

    return (
        <div className="shop-od">
            <nav className="shop-od-crumb" aria-label="Breadcrumb">
                <Link to="/account/orders">{t('storefront.account.my_orders', 'My orders')}</Link>
                <Icon name="chevron" className="h-3.5 w-3.5" />
                <span>{t('storefront.account.order', 'Order')} {order.number}</span>
            </nav>

            <header className="shop-od-head">
                <div>
                    <div className="shop-od-title-row">
                        <h1>{t('storefront.account.order', 'Order')} {order.number}</h1>
                        <span className={`shop-order-status is-${tone}`}>
                            <i />
                            {statusLabel(bucket, order.status)}
                        </span>
                    </div>
                    <p className="shop-od-placed">
                        {t('storefront.account.placed_on', 'Placed on')} {formatPlaced(order.created_at)}
                        {order.checkout_reference && order.checkout_reference !== order.number ? ` · ${order.checkout_reference}` : ''}
                    </p>
                    {(order.checkout_orders || []).length > 1 ? (
                        <p className="shop-od-placed">
                            {(order.checkout_orders || []).map((row, index) => (
                                <span key={row.id || row.number}>
                                    {index > 0 ? ' · ' : ''}
                                    {row.is_current ? (
                                        <strong>#{row.number}</strong>
                                    ) : (
                                        <Link to={`/account/orders/${row.number}${row.id ? `?id=${row.id}` : ''}`}>#{row.number}</Link>
                                    )}
                                </span>
                            ))}
                        </p>
                    ) : null}
                </div>
                <div className="shop-od-actions">
                    <Link to="/account/help" className="shop-od-action">
                        <Icon name="help" className="h-4 w-4" />
                        {t('storefront.account.contact_support', 'Contact Support')}
                    </Link>
                    {order.can_cancel ? (
                        <button
                            type="button"
                            className="shop-od-action is-danger"
                            disabled={busy}
                            onClick={cancelOrder}
                        >
                            <Icon name="close" className="h-4 w-4" />
                            {t('storefront.account.cancel_order', 'Cancel order')}
                        </button>
                    ) : null}
                    <button
                        type="button"
                        className="shop-od-action is-primary"
                        disabled={reordering}
                        onClick={reorderAll}
                    >
                        <Icon name="cart" className="h-4 w-4" />
                        {reordering
                            ? t('storefront.account.adding', 'Adding…')
                            : t('storefront.account.reorder', 'Reorder')}
                    </button>
                </div>
            </header>

            <FormNotice tone="error">{error}</FormNotice>
            <FormNotice tone="success">{notice}</FormNotice>

            <section className={`shop-od-track shop-fade-up is-${tone}`}>
                {bucket === 'cancelled' ? (
                    <p className="shop-od-cancelled-note">
                        {t('storefront.account.order_cancelled_note', 'This order was cancelled. If you were charged, a refund will follow your payment method’s timeline.')}
                    </p>
                ) : (
                    <ol className="shop-od-steps">
                        {JOURNEY.map((node, i) => {
                            const done = step > i;
                            const current = step === i;
                            return (
                                <li key={node.key} className={done ? 'is-done' : current ? 'is-current' : ''}>
                                    <span className="shop-od-step-dot">
                                        <Icon name={done || current ? (done ? 'check' : node.icon) : node.icon} className="h-4 w-4" />
                                    </span>
                                    <strong>
                                        {node.key === 'placed'
                                            ? t('storefront.account.step_placed', 'Order Placed')
                                            : statusLabel(node.key)}
                                    </strong>
                                    {i === 0 ? <em>{formatPlaced(order.created_at)}</em> : null}
                                    {current && i > 0 ? <em>{t('storefront.account.step_current', 'In progress')}</em> : null}
                                </li>
                            );
                        })}
                    </ol>
                )}

                <div className="shop-od-meta">
                    <div>
                        <em>{t('storefront.account.order_id', 'Order ID')}</em>
                        <strong>#{order.number}</strong>
                    </div>
                    <div>
                        <em>{t('storefront.checkout.payment', 'Payment method')}</em>
                        <strong>{paymentName}</strong>
                    </div>
                    <div>
                        <em>{t('storefront.cart.total', 'Order Total')}</em>
                        <strong>{formatMoney(order.grand_total, order.currency_code)}</strong>
                    </div>
                    <div>
                        <em>{t('storefront.account.payment_status', 'Payment Status')}</em>
                        <strong className={`shop-od-pay is-${payTone}`}>{paymentLabel(order.payment_status)}</strong>
                    </div>
                </div>
            </section>

            <div className="shop-od-grid">
                <section className="shop-od-card shop-od-items shop-fade-up" style={{ animationDelay: '40ms' }}>
                    <header className="shop-od-card-head">
                        <h2>
                            {t('storefront.account.items', 'Items')}
                            <span>({items.length})</span>
                        </h2>
                    </header>
                    <ul className="shop-od-item-list">
                        {items.map((item) => {
                            const product = item.product || {};
                            const href = product?.id || product?.url || product?.slug
                                ? productPath(product)
                                : null;
                            const name = (
                                <p className="shop-od-item-name">{item.name}</p>
                            );
                            return (
                                <li key={item.id}>
                                    <div className="shop-od-item-thumb">
                                        <ProductImage product={{
                                            name: item.name,
                                            cover: product.cover_path || product.cover,
                                            cover_url: product.cover_url,
                                        }} />
                                    </div>
                                    <div className="shop-od-item-body">
                                        {href && href !== '/catalog' ? (
                                            <Link to={href} state={entityState('product')}>{name}</Link>
                                        ) : name}
                                        <p className="shop-od-item-meta">
                                            {t('storefront.cart.qty', 'Qty')}: {item.qty}
                                        </p>
                                        {item.booking_date ? (
                                            <p className="shop-od-item-meta">
                                                {item.booking_end_date
                                                    ? `${t('storefront.cart.rental_period', 'Rental')}: ${String(item.booking_date).slice(0, 10)} → ${String(item.booking_end_date).slice(0, 10)}`
                                                    : `${t('storefront.cart.booking_date', 'Booking date')}: ${String(item.booking_date).slice(0, 10)}`}
                                                {item.rental_duration ? ` · ${t('storefront.cart.rental_duration', 'Duration')}: ${item.rental_duration}` : ''}
                                                {item.rental_data?.deposit > 0
                                                    ? ` · ${t('storefront.cart.rental_deposit', 'Deposit')}: ${formatMoney(item.rental_data.deposit)}`
                                                    : ''}
                                                {item.rental_data?.deposit_status
                                                    ? ` (${item.rental_data.deposit_status})`
                                                    : ''}
                                            </p>
                                        ) : null}
                                        {item.preorder_data?.enabled ? (
                                            <p className="shop-od-item-meta">
                                                {t('storefront.cart.preorder', 'Pre-order')}
                                                {(item.preorder?.line_status || item.preorder_data.status)
                                                    ? `: ${String(item.preorder?.line_status || item.preorder_data.status).replace(/_/g, ' ')}`
                                                    : ''}
                                                {item.preorder_data.release_at
                                                    ? ` · ${t('storefront.cart.preorder_release', 'Release')}: ${String(item.preorder_data.release_at).slice(0, 10)}`
                                                    : ''}
                                                {item.preorder
                                                    ? ` · ${t('storefront.account.preorder_paid', 'Paid')}: ${formatMoney(item.preorder.paid, order.currency_code)} · ${t('storefront.account.preorder_remaining', 'Remaining')}: ${formatMoney(item.preorder.remaining, order.currency_code)}`
                                                    : (item.preorder_data.remaining_balance > 0
                                                        ? ` · ${t('storefront.cart.preorder_remaining', 'Remaining')}: ${formatMoney(item.preorder_data.remaining_balance)}`
                                                        : '')}
                                            </p>
                                        ) : null}
                                        {(item.product_id || product.id) ? (
                                            <button
                                                type="button"
                                                className="shop-od-buy-again"
                                                disabled={!!buyingId}
                                                onClick={() => buyAgain(item)}
                                            >
                                                <Icon name="return" className="h-3.5 w-3.5" />
                                                {buyingId === (item.id || product.id)
                                                    ? t('storefront.account.adding', 'Adding…')
                                                    : t('storefront.account.buy_again', 'Buy Again')}
                                            </button>
                                        ) : null}
                                    </div>
                                    <strong className="shop-od-item-price">{formatMoney(item.total, order.currency_code)}</strong>
                                </li>
                            );
                        })}
                    </ul>
                </section>

                <aside className="shop-od-card shop-od-summary shop-fade-up" style={{ animationDelay: '80ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.cart.summary', 'Order Summary')}</h2>
                    </header>
                    <dl className="shop-od-summary-rows">
                        <Row label={t('storefront.cart.subtotal', 'Subtotal')} value={formatMoney(order.subtotal, order.currency_code)} />
                        <Row label={t('storefront.cart.shipping', 'Shipping')} value={formatMoney(order.shipping_total, order.currency_code)} />
                        <Row label={t('storefront.cart.discount', 'Discount')} value={formatMoney(order.discount_total, order.currency_code)} />
                        {Number(order.tax_total || 0) > 0 ? (
                            <Row label={t('storefront.cart.tax', 'Tax')} value={formatMoney(order.tax_total, order.currency_code)} />
                        ) : null}
                        {Number(order.refunded_total || 0) > 0 ? (
                            <Row label={t('storefront.account.refunded', 'Refunded')} value={formatMoney(order.refunded_total, order.currency_code)} />
                        ) : null}
                        <Row label={t('storefront.cart.total', 'Total')} value={formatMoney(order.grand_total, order.currency_code)} strong />
                    </dl>
                    {discount > 0 ? (
                        <p className="shop-od-saved">
                            {t('storefront.account.you_saved', 'You saved')} {formatMoney(discount, order.currency_code)} {t('storefront.account.on_this_order', 'on this order')}
                        </p>
                    ) : null}
                    {invoicesEnabled(store.shop) && order.can_invoice ? (
                    <a href={webPath(`/api/v1/account/orders/${order.number}/invoice${order.id ? `?id=${order.id}` : ''}`)} className="shop-od-invoice">
                        <Icon name="mail" className="h-4 w-4" />
                        {t('storefront.account.download_invoice', 'Download invoice')}
                    </a>
                    ) : invoicesEnabled(store.shop) ? (
                    <Link to="/account/help" className="shop-od-invoice">
                        <Icon name="mail" className="h-4 w-4" />
                        {t('storefront.account.request_invoice', 'Request invoice')}
                    </Link>
                    ) : null}
                    {returnsEnabled(store.shop) ? (
                        <p className="shop-od-muted">
                            {t('storefront.account.return_window', 'Returns accepted within {n} days.')
                                .replace('{n}', String(returnWindowDays(store.shop)))}
                        </p>
                    ) : null}
                </aside>
            </div>

            {(order.modules?.detail || []).length ? (
                <section className="shop-od-card shop-fade-up">
                    <ModuleBlocks slots={order.modules.detail} />
                </section>
            ) : null}

            {orderDownloads.length ? (
                <section className="shop-od-card shop-od-downloads-section shop-fade-up" style={{ animationDelay: '90ms' }}>
                    <header className="shop-od-card-head">
                        <h2>
                            {t('storefront.account.downloads', 'Downloads')}
                            <span>({orderDownloads.length})</span>
                        </h2>
                    </header>
                    <div
                        className="shop-od-dl-table"
                        role="table"
                        aria-label={t('storefront.account.downloads', 'Downloads')}
                    >
                        <div className="shop-od-dl-head" role="row">
                            <div className="shop-od-dl-hcell" role="columnheader">
                                {t('storefront.product.document_col_file', 'File')}
                            </div>
                            <div className="shop-od-dl-hcell" role="columnheader">
                                {t('storefront.product.document_col_title', 'Title')}
                            </div>
                            <div className="shop-od-dl-hcell" role="columnheader">
                                {t('storefront.product.document_col_description', 'Description')}
                            </div>
                            <div className="shop-od-dl-hcell" role="columnheader">
                                {t('storefront.product.document_col_filename', 'File name')}
                            </div>
                            <div className="shop-od-dl-hcell" role="columnheader">
                                {t('storefront.product.document_col_size', 'Size')}
                            </div>
                            <div className="shop-od-dl-hcell shop-od-dl-hcell-action" role="columnheader">
                                <span className="sr-only">{t('storefront.account.download', 'Download')}</span>
                            </div>
                        </div>
                        <ul className="shop-od-dl-list">
                            {orderDownloads.map((file) => {
                                const title = String(file.name || '').trim()
                                    || String(file.original_filename || file.filename || '').trim()
                                    || t('storefront.product.document', 'Document');
                                const description = String(file.description || '').trim();
                                const originalName = String(file.original_filename || file.filename || '').trim();
                                const typeLabel = String(file.file_type_label || '').trim();
                                const sizeLabel = String(file.file_size_label || '').trim();
                                const downloadLabel = t('storefront.account.download', 'Download');
                                const downloadHref = file.available
                                    ? webPath(file.download_url || `/api/v1/account/downloads/${file.token}`)
                                    : null;

                                return (
                                    <li key={file.id || file.token} className="shop-od-dl-row" role="row">
                                        <div className="shop-od-dl-cell shop-od-dl-cell-file" role="cell">
                                            <OrderDownloadThumb file={file} />
                                        </div>
                                        <div className="shop-od-dl-cell shop-od-dl-cell-title" role="cell">
                                            <strong className="shop-od-dl-title">{title}</strong>
                                            {typeLabel ? (
                                                <span className="shop-od-dl-type">{typeLabel}</span>
                                            ) : null}
                                        </div>
                                        <div className="shop-od-dl-cell shop-od-dl-cell-description" role="cell">
                                            {description || '—'}
                                        </div>
                                        <div className="shop-od-dl-cell shop-od-dl-cell-filename" role="cell" title={originalName || undefined}>
                                            {originalName || '—'}
                                        </div>
                                        <div className="shop-od-dl-cell shop-od-dl-cell-size" role="cell">
                                            {sizeLabel || '—'}
                                        </div>
                                        <div className="shop-od-dl-cell shop-od-dl-cell-action" role="cell">
                                            {downloadHref ? (
                                                <a
                                                    href={downloadHref}
                                                    className="shop-od-dl-icon-btn"
                                                    title={downloadLabel}
                                                    aria-label={`${downloadLabel} ${title}`}
                                                >
                                                    <Icon name="download" className="h-4 w-4" />
                                                </a>
                                            ) : (
                                                <span
                                                    className="shop-od-download-expired"
                                                    title={t('storefront.account.download_unavailable', 'No downloads left')}
                                                >
                                                    —
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </section>
            ) : null}

            <div className="shop-od-pair">
                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '100ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.checkout.shipping_address', 'Shipping Address')}</h2>
                        <Link to="/account/addresses" className="shop-od-card-action">{t('storefront.account.manage', 'Manage')}</Link>
                    </header>
                    {ship ? (
                        <div className="shop-od-address">
                            {ship.name ? <strong>{ship.name}</strong> : null}
                            {ship.lines.map((line) => <p key={line}>{line}</p>)}
                            {ship.phone ? (
                                <p className="shop-od-address-phone">
                                    <Icon name="phone" className="h-3.5 w-3.5" />
                                    {ship.phone}
                                </p>
                            ) : null}
                            {order.shipping_method?.name ? (
                                <p className="shop-od-ship-method">{order.shipping_method.name}</p>
                            ) : null}
                        </div>
                    ) : (
                        <p className="shop-od-muted">{t('storefront.account.no_address', 'No shipping address on this order.')}</p>
                    )}
                </section>

                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '120ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.checkout.payment', 'Payment Details')}</h2>
                    </header>
                    <dl className="shop-od-pay-grid">
                        <div>
                            <em>{t('storefront.checkout.payment', 'Method')}</em>
                            <strong>{paymentName}</strong>
                        </div>
                        <div>
                            <em>{t('storefront.account.payment_status', 'Status')}</em>
                            <strong className={`shop-od-pay is-${payTone}`}>{paymentLabel(order.payment_status)}</strong>
                        </div>
                        {bill?.name ? (
                            <div className="shop-od-pay-span">
                                <em>{t('storefront.checkout.billing', 'Billing')}</em>
                                <strong>{bill.name}</strong>
                                {bill.lines.slice(0, 2).map((line) => <p key={line}>{line}</p>)}
                            </div>
                        ) : null}
                    </dl>
                </section>
            </div>

            {canRequestWithdrawal || withdrawals.length ? (
                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '130ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.eu_withdrawal', 'EU withdrawal')}</h2>
                        {canRequestWithdrawal ? (
                            <button type="button" className="shop-od-return" disabled={busy} onClick={() => setWithdrawalOpen((v) => !v)}>
                                <Icon name="return" className="h-4 w-4" />
                                {t('storefront.account.request_withdrawal', 'Request withdrawal')}
                            </button>
                        ) : null}
                    </header>
                    <p className="shop-od-muted">
                        {t('storefront.account.eu_withdrawal_hint', 'Consumer cooling-off for physical goods within 14 days of delivery (or purchase if not yet delivered).')}
                    </p>
                    {withdrawals.length ? (
                        <ul className="mt-3 space-y-2 text-sm">
                            {withdrawals.map((row) => (
                                <li key={row.id}>
                                    <strong>{row.reference}</strong>
                                    {' · '}
                                    <span className="capitalize">{row.status}</span>
                                    {row.reason ? ` · ${row.reason}` : ''}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {withdrawalOpen ? (
                        <div className="mt-4 space-y-3">
                            <textarea
                                className="shop-input"
                                rows={3}
                                value={withdrawalReason}
                                placeholder={t('storefront.account.withdrawal_reason', 'Optional reason')}
                                onChange={(e) => setWithdrawalReason(e.target.value)}
                            />
                            <button type="button" className="shop-od-return" disabled={busy} onClick={requestEuWithdrawal}>
                                {t('storefront.account.submit_withdrawal', 'Submit withdrawal')}
                            </button>
                        </div>
                    ) : null}
                </section>
            ) : null}

            {!sellerOrders.length && canReturn && ['shipped', 'completed', 'processing', 'delivered'].includes(String(order.status || '').toLowerCase()) ? (
                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '140ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.returns', 'Returns')}</h2>
                        <button type="button" className="shop-od-return" disabled={busy} onClick={openReturn}>
                            <Icon name="return" className="h-4 w-4" />
                            {t('storefront.account.request_return', 'Request return')}
                        </button>
                    </header>
                    {returnOpen ? (
                        <div className="mt-4 space-y-3">
                            {(store.shop?.commerce?.returns?.reason_required !== false) ? (
                                <textarea
                                    className="shop-input"
                                    rows={3}
                                    value={returnReason}
                                    placeholder={t('storefront.account.return_reason', 'Why are you returning this?')}
                                    onChange={(e) => setReturnReason(e.target.value)}
                                />
                            ) : null}
                            {store.shop?.commerce?.returns?.partial !== false ? (
                                (order.items || []).filter((item) => Number(item.returnable_qty || 0) > 0).map((item) => (
                                    <label key={item.id} className="flex items-center justify-between gap-3 text-sm">
                                        <span>{item.name}</span>
                                        <input
                                            className="shop-input w-24"
                                            type="number"
                                            min="0"
                                            max={Number(item.returnable_qty || 0)}
                                            value={returnQtys[item.id] ?? item.returnable_qty}
                                            onChange={(e) => setReturnQtys((current) => ({
                                                ...current,
                                                [item.id]: e.target.value,
                                            }))}
                                        />
                                    </label>
                                ))
                            ) : (
                                <p className="text-sm text-[var(--shop-muted)]">{t('storefront.account.return_full_only', 'This shop accepts a full return of every item.')}</p>
                            )}
                            <button type="button" className="shop-od-return" disabled={busy} onClick={requestShopReturn}>
                                {t('storefront.account.submit_return', 'Submit return')}
                            </button>
                        </div>
                    ) : null}
                </section>
            ) : null}

            {sellerOrders.length ? (
                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '140ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.seller_shipments', 'Seller shipments')}</h2>
                    </header>
                    <ul className="shop-od-sellers">
                        {sellerOrders.map((row) => (
                            <li key={row.id}>
                                <div>
                                    <strong>{row.shop_name}</strong>
                                    <em className="capitalize">{row.status}</em>
                                    {row.shipment ? (
                                        <p>
                                            {t('storefront.account.shipment', 'Shipment')}: {row.shipment.status}
                                            {row.shipment.tracking_number ? ` · ${row.shipment.tracking_number}` : ` · ${t('storefront.account.tracking_unavailable', 'Tracking not available yet.')}`}
                                        </p>
                                    ) : null}
                                </div>
                                {canReturn && !['shipped', 'completed'].includes(row.status) ? (
                                    <p className="text-sm text-[var(--shop-muted)]">{t('storefront.account.return_after_ship', 'A return can be requested after this seller ships the order.')}</p>
                                ) : null}
                                {canReturn && ['shipped', 'completed'].includes(row.status) ? (
                                    <button
                                        type="button"
                                        className="shop-od-return"
                                        disabled={busy}
                                        onClick={() => requestReturn(row)}
                                    >
                                        <Icon name="return" className="h-4 w-4" />
                                        {t('storefront.account.request_return', 'Request return')}
                                    </button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {returns.length ? (
                <section className="shop-od-card shop-fade-up" style={{ animationDelay: '160ms' }}>
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.returns', 'Returns')}</h2>
                    </header>
                    <ul className="shop-od-returns">
                        {returns.map((row) => (
                            <li key={row.id}>
                                <strong>{row.number}</strong>
                                <span className="capitalize">{row.status}</span>
                                <em>{formatMoney(row.refund_total, order.currency_code)}</em>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            <div className="shop-od-trust" aria-hidden="true">
                <span><Icon name="help" className="h-4 w-4" />{t('storefront.account.need_help', 'Need Help?')}</span>
                <span><Icon name="return" className="h-4 w-4" />{t('storefront.account.easy_returns', 'Easy Returns')}</span>
                <span><Icon name="shield" className="h-4 w-4" />{t('storefront.account.secure_payments', 'Secure Payments')}</span>
                <span><Icon name="truck" className="h-4 w-4" />{t('storefront.account.fast_delivery', 'Fast Delivery')}</span>
            </div>
        </div>
    );
}

function Row({ label, value, strong }) {
    return (
        <div className={strong ? 'is-total' : ''}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}
