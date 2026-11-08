import { Link } from 'react-router-dom';
import { Icon } from './Icons.jsx';
import ProductImage from './ProductImage.jsx';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { entityState, productPath } from '../lib/links.js';
import { invoicesEnabled } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';

const JOURNEY = [
    { key: 'placed', icon: 'check' },
    { key: 'processing', icon: 'package' },
    { key: 'shipped', icon: 'truck' },
    { key: 'delivered', icon: 'home' },
];

function statusBucket(status) {
    const s = String(status || '').toLowerCase();
    if (['cancelled', 'canceled', 'refunded', 'void'].includes(s)) return 'cancelled';
    if (['completed', 'delivered', 'done'].includes(s)) return 'delivered';
    if (['shipped', 'transit', 'in_transit'].includes(s)) return 'shipped';
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
        addr.company,
        addr.line1,
        addr.line2,
        [addr.city, addr.state || addr.region_code, addr.postal_code].filter(Boolean).join(', '),
        addr.country_code,
    ].filter(Boolean);
    return { name, lines, phone: addr.phone };
}

function paymentLabel(status) {
    const s = String(status || '').toLowerCase();
    if (['paid', 'captured', 'complete', 'completed'].includes(s)) return t('storefront.account.payment_paid', 'Paid');
    if (['pending', 'awaiting', 'unpaid'].includes(s)) return t('storefront.account.payment_pending', 'Pending');
    if (['failed', 'declined'].includes(s)) return t('storefront.account.payment_failed', 'Failed');
    if (['refunded', 'partially_refunded'].includes(s)) return t('storefront.account.payment_refunded', 'Refunded');
    return status || '—';
}

function paymentTone(status) {
    const s = String(status || '').toLowerCase();
    if (['paid', 'captured', 'complete', 'completed'].includes(s)) return 'paid';
    if (['failed', 'declined'].includes(s)) return 'failed';
    if (['refunded', 'partially_refunded'].includes(s)) return 'refunded';
    return 'pending';
}

function Row({ label, value, strong }) {
    return (
        <div className={strong ? 'is-total' : ''}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

export default function OrderReceipt({ order, invoiceBusy = false, onInvoice }) {
    const store = useStore();
    if (!order) return null;

    const bucket = statusBucket(order.status);
    const tone = bucket;
    const step = journeyStep(bucket);
    const ship = formatAddress(order.shipping_address);
    const bill = formatAddress(order.billing_address);
    const items = order.items || [];
    const discount = Number(order.discount_total || 0);
    const paymentName = order.payment_method?.name || '—';
    const payTone = paymentTone(order.payment_status);
    const money = (amount) => formatMoney(amount, order.currency_code);
    const shipments = order.shipments || [];

    return (
        <section className="shop-od shop-track-detail">
            <header className="shop-od-head">
                <div>
                    <div className="shop-od-title-row">
                        <h2>{t('storefront.account.order', 'Order')} {order.number}</h2>
                        <span className={`shop-order-status is-${tone}`}>
                            <i />
                            {statusLabel(bucket, order.status)}
                        </span>
                    </div>
                    <p className="shop-od-placed">
                        {t('storefront.account.placed_on', 'Placed on')} {formatPlaced(order.created_at)}
                        {order.email ? ` · ${order.email}` : ''}
                    </p>
                </div>
            </header>

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
                                        <Icon name={done ? 'check' : node.icon} className="h-4 w-4" />
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
                        <strong>{money(order.grand_total)}</strong>
                    </div>
                    <div>
                        <em>{t('storefront.account.payment_status', 'Payment Status')}</em>
                        <strong className={`shop-od-pay is-${payTone}`}>{paymentLabel(order.payment_status)}</strong>
                    </div>
                </div>
            </section>

            <div className="shop-od-grid">
                <section className="shop-od-card shop-od-items">
                    <header className="shop-od-card-head">
                        <h2>
                            {t('storefront.account.items', 'Items')}
                            <span>({items.length})</span>
                        </h2>
                    </header>
                    <ul className="shop-od-item-list">
                        {items.map((item) => {
                            const product = item.product || {};
                            const href = product.id || product.url || product.slug ? productPath(product) : null;
                            return (
                                <li key={item.id || `${item.sku}-${item.name}`}>
                                    <div className="shop-od-item-thumb">
                                        <ProductImage product={{
                                            name: item.name,
                                            cover: product.cover,
                                            cover_url: product.cover_url,
                                        }} />
                                    </div>
                                    <div className="shop-od-item-body">
                                        {href && href !== '/catalog' ? (
                                            <Link to={href} state={entityState('product')}>
                                                <p className="shop-od-item-name">{item.name}</p>
                                            </Link>
                                        ) : (
                                            <p className="shop-od-item-name">{item.name}</p>
                                        )}
                                        <p className="shop-od-item-meta">
                                            {item.sku ? `${t('storefront.cart.sku', 'SKU')}: ${item.sku} · ` : ''}
                                            {t('storefront.cart.qty', 'Qty')}: {item.qty}
                                            {item.unit_price != null ? ` · ${money(item.unit_price)}` : ''}
                                        </p>
                                        {item.booking_date ? (
                                            <p className="shop-od-item-meta">
                                                {item.booking_end_date
                                                    ? `${t('storefront.cart.rental_period', 'Rental')}: ${item.booking_date} → ${item.booking_end_date}`
                                                    : `${t('storefront.cart.booking_date', 'Booking date')}: ${item.booking_date}`}
                                                {item.rental_duration ? ` · ${t('storefront.cart.rental_duration', 'Duration')}: ${item.rental_duration}` : ''}
                                            </p>
                                        ) : null}
                                    </div>
                                    <strong className="shop-od-item-price">{money(item.total)}</strong>
                                </li>
                            );
                        })}
                    </ul>
                </section>

                <aside className="shop-od-card shop-od-summary">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.cart.summary', 'Order Summary')}</h2>
                    </header>
                    <dl className="shop-od-summary-rows">
                        <Row label={t('storefront.cart.subtotal', 'Subtotal')} value={money(order.subtotal)} />
                        <Row label={t('storefront.cart.shipping', 'Shipping')} value={money(order.shipping_total)} />
                        <Row label={t('storefront.cart.discount', 'Discount')} value={money(order.discount_total)} />
                        {Number(order.tax_total || 0) > 0 ? (
                            <Row label={t('storefront.cart.tax', 'Tax')} value={money(order.tax_total)} />
                        ) : null}
                        {Number(order.fee_total || 0) > 0 ? (
                            <Row label={t('storefront.cart.fees', 'Fees')} value={money(order.fee_total)} />
                        ) : null}
                        {Number(order.refunded_total || 0) > 0 ? (
                            <Row label={t('storefront.account.refunded', 'Refunded')} value={money(order.refunded_total)} />
                        ) : null}
                        <Row label={t('storefront.cart.total', 'Total')} value={money(order.grand_total)} strong />
                    </dl>
                    {discount > 0 ? (
                        <p className="shop-od-saved">
                            {t('storefront.account.you_saved', 'You saved')} {money(discount)} {t('storefront.account.on_this_order', 'on this order')}
                        </p>
                    ) : null}
                    {invoicesEnabled(store.shop) && order.can_invoice && onInvoice ? (
                        <button type="button" className="shop-od-invoice" disabled={invoiceBusy} onClick={onInvoice}>
                            <Icon name="download" className="h-4 w-4" />
                            {invoiceBusy
                                ? t('storefront.common.please_wait', 'Please wait…')
                                : t('storefront.account.download_invoice', 'Download invoice')}
                        </button>
                    ) : null}
                </aside>
            </div>

            <div className="shop-od-pair">
                <section className="shop-od-card">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.checkout.shipping_address', 'Shipping Address')}</h2>
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
                <section className="shop-od-card">
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
                        {bill ? (
                            <div className="shop-od-pay-span">
                                <em>{t('storefront.checkout.billing', 'Billing')}</em>
                                {bill.name ? <strong>{bill.name}</strong> : null}
                                {bill.lines.map((line) => <p key={line}>{line}</p>)}
                            </div>
                        ) : null}
                    </dl>
                </section>
            </div>

            {shipments.length ? (
                <section className="shop-od-card">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.shipment', 'Shipment')}</h2>
                    </header>
                    <ul className="shop-od-sellers">
                        {shipments.map((row) => (
                            <li key={row.id || row.tracking_number}>
                                <div>
                                    <strong>{row.carrier || row.shipping_method || t('storefront.account.shipment', 'Shipment')}</strong>
                                    <em className="capitalize">{row.status}</em>
                                    <p>
                                        {row.tracking_number
                                            ? (row.tracking_url
                                                ? <a href={row.tracking_url} target="_blank" rel="noreferrer">{row.tracking_number}</a>
                                                : row.tracking_number)
                                            : t('storefront.account.tracking_unavailable', 'Tracking not available yet.')}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {(order.seller_orders || []).length ? (
                <section className="shop-od-card">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.seller_shipments', 'Seller shipments')}</h2>
                    </header>
                    <ul className="shop-od-sellers">
                        {order.seller_orders.map((row) => (
                            <li key={row.id}>
                                <div>
                                    <strong>{row.shop_name}</strong>
                                    <em className="capitalize">{row.status}</em>
                                    {row.shipment ? (
                                        <p>
                                            {row.shipment.status}
                                            {row.shipment.tracking_number ? ` · ${row.shipment.tracking_number}` : ''}
                                        </p>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {(order.returns || []).length ? (
                <section className="shop-od-card">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.account.returns', 'Returns')}</h2>
                    </header>
                    <ul className="shop-od-returns">
                        {order.returns.map((row) => (
                            <li key={row.number}>
                                <strong>{row.number}</strong>
                                <span className="capitalize">{row.status}</span>
                                <em>{money(row.refund_total)}</em>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {order.customer_note ? (
                <section className="shop-od-card">
                    <header className="shop-od-card-head">
                        <h2>{t('storefront.checkout.note', 'Order note')}</h2>
                    </header>
                    <p className="shop-od-muted">{order.customer_note}</p>
                </section>
            ) : null}
        </section>
    );
}
