import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { productPath } from '../lib/links.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import ProductImage from '../components/ProductImage.jsx';
import { Icon } from '../components/Icons.jsx';
import { useStore } from '../state/StoreState.jsx';
import { firePurchase } from '../components/CookieConsent.jsx';

function formatOrderDate(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function firstNameFromOrder(order) {
    const ship = order.shipping_address || {};
    if (ship.first_name) {
        return ship.first_name;
    }
    if (order.customer?.first_name) {
        return order.customer.first_name;
    }
    const full = order.customer?.name || [ship.first_name, ship.last_name].filter(Boolean).join(' ');
    if (full) {
        return String(full).trim().split(/\s+/)[0];
    }
    const email = String(order.email || '');
    return email.includes('@') ? email.split('@')[0] : '';
}

export default function OrderSuccess() {
    const { number } = useParams();
    const location = useLocation();
    const store = useStore();
    const [order, setOrder] = useState(location.state?.order || null);
    const [error, setError] = useState('');

    useEffect(() => {
        store.reloadCart();
        if (order?.number === number) {
            return;
        }
        api.get(`/checkout/thanks/${number}`)
            .then((r) => setOrder(r.data.order))
            .catch(() => {
                if (store.customer) {
                    api.get(`/account/orders/${number}`).then((r) => setOrder(r.data.order)).catch(() => setError(t('storefront.account.order_missing', 'We could not load that order.')));
                } else {
                    setError(t('storefront.account.order_missing', 'We could not load that order.'));
                }
            });
    }, [number]);

    useEffect(() => {
        if (order?.number) {
            firePurchase(order);
        }
    }, [order?.number, order?.payment_status]);

    const items = order?.items || [];
    const itemCount = useMemo(
        () => items.reduce((sum, item) => sum + Number(item.qty || 0), 0),
        [items],
    );

    if (error) {
        return (
            <div className="shop-container py-16">
                <EmptyState
                    title={error}
                    action={<Link to="/account/orders" className="shop-btn shop-btn-primary">{t('storefront.account.my_orders', 'My orders')}</Link>}
                />
            </div>
        );
    }
    if (!order) {
        return <PageSkeleton />;
    }

    const currency = order.currency_code;
    const name = firstNameFromOrder(order);
    const paymentName = order.payment_method?.name || order.payment_status || '—';
    const shippingName = order.shipping_method?.name || t('storefront.checkout.shipping', 'Shipping');
    const shippingExtra = order.shipping_method?.description || order.shipping_method?.eta || '';
    const shippingTotal = Number(order.shipping_total || 0);
    const subtotal = Number(order.subtotal ?? (Number(order.grand_total || 0) - shippingTotal + Number(order.discount_total || 0) - Number(order.tax_total || 0)));
    const ordersHref = store.customer ? `/account/orders/${order.number}` : '/login';

    return (
        <section className="shop-thanks-stage">
            <div className="shop-container shop-thanks-shell">
                <div className="shop-thanks-layout">
                    <div className="shop-thanks-main">
                        <div className="shop-thanks-hero">
                            <span className="shop-thanks-check" aria-hidden="true">
                                <Icon name="check" className="h-8 w-8" />
                            </span>
                            <p className="shop-thanks-eyebrow">{t('storefront.checkout.placed_success', 'Order placed successfully!')}</p>
                            <h1>
                                {name
                                    ? t('storefront.checkout.thanks_name', 'Thank you, {name}!').replace('{name}', name)
                                    : t('storefront.checkout.thanks', 'Thank you!')}
                            </h1>
                            <p className="shop-thanks-lede">
                                {t('storefront.checkout.received_body', "We've received your order and it is now being processed. A confirmation email has been sent to")}
                                {' '}
                                <strong>{order.email}</strong>.
                            </p>
                        </div>

                        <div className="shop-thanks-meta">
                            <div className="shop-thanks-meta-item">
                                <span className="shop-thanks-meta-icon"><Icon name="package" className="h-5 w-5" /></span>
                                <div>
                                    <em>{t('storefront.checkout.order_id', 'Order ID')}</em>
                                    <strong>{order.number}</strong>
                                </div>
                            </div>
                            <div className="shop-thanks-meta-item">
                                <span className="shop-thanks-meta-icon"><Icon name="calendar" className="h-5 w-5" /></span>
                                <div>
                                    <em>{t('storefront.checkout.order_date', 'Order Date')}</em>
                                    <strong>{formatOrderDate(order.created_at || order.placed_at)}</strong>
                                </div>
                            </div>
                            <div className="shop-thanks-meta-item">
                                <span className="shop-thanks-meta-icon"><Icon name="card" className="h-5 w-5" /></span>
                                <div>
                                    <em>{t('storefront.checkout.payment_method', 'Payment Method')}</em>
                                    <strong>{paymentName}</strong>
                                </div>
                            </div>
                            <div className="shop-thanks-meta-item">
                                <span className="shop-thanks-meta-icon"><Icon name="truck" className="h-5 w-5" /></span>
                                <div>
                                    <em>{t('storefront.checkout.shipping_method', 'Shipping Method')}</em>
                                    <strong>
                                        {shippingName}
                                        {shippingExtra ? `, ${shippingExtra}` : ''}
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <div className="shop-thanks-actions">
                            <Link to="/catalog" className="shop-btn shop-btn-primary">
                                {t('storefront.checkout.continue', 'Continue Shopping')}
                            </Link>
                            <Link to={ordersHref} className="shop-btn shop-btn-outline">
                                {t('storefront.checkout.view_orders', 'View My Orders')}
                            </Link>
                        </div>
                    </div>

                    <aside className="shop-thanks-summary">
                        <header className="shop-thanks-summary-head">
                            <h2>{t('storefront.checkout.order_summary', 'Order Summary')}</h2>
                            <span>
                                {itemCount === 1
                                    ? t('storefront.cart.item_one', '1 item')
                                    : t('storefront.cart.item_count', '{n} items').replace('{n}', String(itemCount))}
                            </span>
                        </header>

                        <ul className="shop-thanks-items">
                            {items.map((item) => {
                                const product = item.product || {};
                                const href = productPath(product);
                                const lineTotal = item.total ?? (Number(item.unit_price || 0) * Number(item.qty || 0));

                                return (
                                    <li key={item.id || `${item.product_id}-${item.sku}`}>
                                        <Link to={href} className="shop-thanks-thumb" state={{ entityType: 'product' }}>
                                            <ProductImage product={{
                                                name: item.name,
                                                cover: product.cover_path || product.cover,
                                                cover_url: product.cover_url,
                                            }} />
                                        </Link>
                                        <div className="shop-thanks-item-body">
                                            <Link to={href} state={{ entityType: 'product' }}>{item.name}</Link>
                                            <p>{t('storefront.cart.qty', 'Qty')}: {item.qty}</p>
                                        </div>
                                        <strong>{formatMoney(lineTotal, currency)}</strong>
                                    </li>
                                );
                            })}
                        </ul>

                        <dl className="shop-thanks-rows">
                            <div>
                                <dt>{t('storefront.cart.subtotal', 'Subtotal')}</dt>
                                <dd>{formatMoney(Number.isFinite(subtotal) ? subtotal : order.grand_total, currency)}</dd>
                            </div>
                            <div>
                                <dt>{t('storefront.checkout.shipping', 'Shipping')}</dt>
                                <dd>
                                    <span className="shop-thanks-ship-label">{shippingName}</span>
                                    {' '}
                                    {shippingTotal <= 0
                                        ? <em>{t('storefront.cart.free', 'Free')}</em>
                                        : formatMoney(shippingTotal, currency)}
                                </dd>
                            </div>
                            {Number(order.discount_total || 0) > 0 ? (
                                <div>
                                    <dt>{t('storefront.cart.discount', 'Discount')}</dt>
                                    <dd className="is-good">-{formatMoney(order.discount_total, currency)}</dd>
                                </div>
                            ) : null}
                            {Number(order.tax_total || 0) > 0 ? (
                                <div>
                                    <dt>{t('storefront.cart.tax', 'Tax')}</dt>
                                    <dd>{formatMoney(order.tax_total, currency)}</dd>
                                </div>
                            ) : null}
                            <div className="is-total">
                                <dt>{t('storefront.checkout.total_paid', 'Total Paid')}</dt>
                                <dd>{formatMoney(order.grand_total, currency)}</dd>
                            </div>
                        </dl>
                    </aside>
                </div>

                <ul className="shop-thanks-trust">
                    <li>
                        <Icon name="help" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.checkout.trust_help_title', 'Need help?')}</strong>
                            <span>{t('storefront.checkout.trust_help_body', 'Contact our support')}</span>
                        </div>
                    </li>
                    <li>
                        <Icon name="return" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.checkout.trust_returns_title', 'Easy returns')}</strong>
                            <span>{t('storefront.checkout.trust_returns_body', '7-day return policy')}</span>
                        </div>
                    </li>
                    <li>
                        <Icon name="shield" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.checkout.trust_secure_title', 'Secure payment')}</strong>
                            <span>{t('storefront.checkout.trust_secure_body', '100% secure & safe')}</span>
                        </div>
                    </li>
                    <li>
                        <Icon name="truck" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.checkout.trust_delivery_title', 'Fast delivery')}</strong>
                            <span>{t('storefront.checkout.trust_delivery_body', 'On time, every time')}</span>
                        </div>
                    </li>
                </ul>
            </div>
        </section>
    );
}
