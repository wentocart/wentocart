import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import ProductImage from '../components/ProductImage.jsx';
import { t } from '../i18n.js';
import {
    formatSubscriptionDate,
    subscriptionPaymentLabel,
    subscriptionPaymentTone,
    subscriptionStatusLabel,
    subscriptionStatusTone,
} from '../lib/accountSubscriptions.js';
import { formatMoney } from '../lib/format.js';
import { entityState, productPath } from '../lib/links.js';

export default function Subscriptions() {
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get('/account/subscriptions')
            .then((r) => setRows(r.data.subscriptions || []))
            .catch(() => setRows([]));
    }, []);

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-orders shop-subscriptions">
            <header className="shop-orders-head">
                <div>
                    <p className="shop-orders-kicker">{t('storefront.account.subscriptions', 'Subscriptions')}</p>
                    <h1>{t('storefront.account.subscriptions_title', 'My subscriptions')}</h1>
                    <p>
                        {t(
                            'storefront.account.subscriptions_body',
                            'Pause, skip a cycle, change plan, or cancel. When a saved payment method is on file and auto-renew is enabled, renewals are charged automatically; otherwise you receive a payment link.',
                        )}
                    </p>
                </div>
                <div className="shop-orders-summary" aria-hidden="true">
                    <span className="shop-orders-summary-count">{rows.length}</span>
                    <span className="shop-orders-summary-label">{t('storefront.account.subscriptions', 'Subscriptions')}</span>
                </div>
            </header>

            {rows.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_subscriptions', 'No subscriptions yet.')}
                        body={t('storefront.account.no_subscriptions_body', 'When you subscribe to a product, it will appear here.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>}
                    />
                </div>
            ) : (
                <div className="shop-module-table-wrap">
                    <table className="shop-module-table shop-subscriptions-table">
                        <thead>
                            <tr>
                                <th>{t('storefront.account.product', 'Product')}</th>
                                <th>{t('storefront.account.subscription_plan', 'Subscription Plan')}</th>
                                <th>{t('storefront.account.price', 'Price')}</th>
                                <th>{t('storefront.account.billing_interval', 'Billing Interval')}</th>
                                <th>{t('storefront.account.status', 'Status')}</th>
                                <th>{t('storefront.account.payment_status', 'Payment Status')}</th>
                                <th>{t('storefront.account.next_billing', 'Next Billing')}</th>
                                <th>{t('storefront.account.actions', 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const href = row.product_slug ? productPath({ slug: row.product_slug }) : null;
                                const name = row.product || t('storefront.account.subscription', 'Subscription');

                                return (
                                    <tr key={row.id}>
                                        <td data-label={t('storefront.account.product', 'Product')}>
                                            <div className="shop-subscriptions-product">
                                                <div className="shop-subscriptions-thumb">
                                                    <ProductImage
                                                        product={{ cover_url: row.product_cover_url, name }}
                                                        alt={name}
                                                        size="sm"
                                                        width={44}
                                                        height={44}
                                                    />
                                                </div>
                                                <div>
                                                    {href ? (
                                                        <Link to={href} state={entityState('product')} className="shop-preorders-name">
                                                            {name}
                                                        </Link>
                                                    ) : (
                                                        <span className="shop-preorders-name">{name}</span>
                                                    )}
                                                    {row.order_number ? (
                                                        <Link to={`/account/orders/${row.order_number}`} className="shop-preorders-order">
                                                            #{row.order_number}
                                                        </Link>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label={t('storefront.account.subscription_plan', 'Subscription Plan')}>
                                            {row.plan || '—'}
                                        </td>
                                        <td data-label={t('storefront.account.price', 'Price')}>
                                            {row.price != null ? formatMoney(row.price, row.currency_code) : '—'}
                                        </td>
                                        <td data-label={t('storefront.account.billing_interval', 'Billing Interval')}>
                                            {row.billing_summary || '—'}
                                        </td>
                                        <td data-label={t('storefront.account.status', 'Status')}>
                                            <span className={`shop-order-status is-${subscriptionStatusTone(row.status)}`}>
                                                <i />
                                                {subscriptionStatusLabel(row.status)}
                                            </span>
                                            {row.cancel_at_period_end ? (
                                                <div className="shop-preorders-order">
                                                    {t('storefront.account.ends_after_period', 'Ends after this period')}
                                                </div>
                                            ) : null}
                                        </td>
                                        <td data-label={t('storefront.account.payment_status', 'Payment Status')}>
                                            <span className={`shop-order-status is-${subscriptionPaymentTone(row.payment_status)}`}>
                                                <i />
                                                {subscriptionPaymentLabel(row.payment_status)}
                                            </span>
                                        </td>
                                        <td data-label={t('storefront.account.next_billing', 'Next Billing')}>
                                            {formatSubscriptionDate(row.next_renewal_at) || '—'}
                                        </td>
                                        <td data-label={t('storefront.account.actions', 'Actions')}>
                                            <Link to={`/account/subscriptions/${row.id}`} className="shop-btn shop-btn-ghost">
                                                {t('storefront.account.view', 'View')}
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
