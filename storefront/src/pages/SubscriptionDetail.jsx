import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ProductImage from '../components/ProductImage.jsx';
import SubscriptionActions from '../components/SubscriptionActions.jsx';
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

function Fact({ label, children }) {
    if (children == null || children === '') {
        return null;
    }

    return (
        <>
            <dt>{label}</dt>
            <dd>{children}</dd>
        </>
    );
}

function FactsCard({ title, children }) {
    return (
        <section className="shop-od-card">
            <header className="shop-od-card-head">
                <h2>{title}</h2>
            </header>
            <dl className="shop-subscriptions-dl">{children}</dl>
        </section>
    );
}

function invoiceKindLabel(kind) {
    if (kind === 'original') {
        return t('storefront.account.original_order', 'Original order');
    }

    return t('storefront.account.renewal_order', 'Renewal order');
}

function RenewalInvoices({ invoices }) {
    const rows = Array.isArray(invoices) ? invoices.filter((order) => order?.number) : [];

    return (
        <section className="shop-od-card shop-subscriptions-invoices">
            <header className="shop-od-card-head">
                <h2>{t('storefront.account.renewal_invoices', 'Renewal invoices')}</h2>
            </header>
            {rows.length === 0 ? (
                <p className="shop-subscriptions-invoices-empty">
                    {t('storefront.account.no_renewal_invoices', 'No invoices yet for this subscription.')}
                </p>
            ) : (
                <div className="shop-subscriptions-invoices-table-wrap">
                    <table className="shop-subscriptions-invoices-table">
                        <thead>
                            <tr>
                                <th>{t('storefront.account.order', 'Order')}</th>
                                <th>{t('storefront.account.type', 'Type')}</th>
                                <th>{t('storefront.account.payment_status', 'Payment Status')}</th>
                                <th>{t('storefront.account.total', 'Total')}</th>
                                <th>{t('storefront.account.date', 'Date')}</th>
                                <th>{t('storefront.common.action', 'Action')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((order) => (
                                <tr key={`${order.kind || 'order'}-${order.id || order.number}`}>
                                    <td data-label={t('storefront.account.order', 'Order')}>
                                        <span className="shop-subscriptions-invoice-number">#{order.number}</span>
                                    </td>
                                    <td data-label={t('storefront.account.type', 'Type')}>
                                        {invoiceKindLabel(order.kind)}
                                    </td>
                                    <td data-label={t('storefront.account.payment_status', 'Payment Status')}>
                                        <span className={`shop-order-status is-${subscriptionPaymentTone(order.payment_status)}`}>
                                            <i />
                                            {subscriptionPaymentLabel(order.payment_status)}
                                        </span>
                                    </td>
                                    <td data-label={t('storefront.account.total', 'Total')}>
                                        {order.grand_total != null
                                            ? formatMoney(order.grand_total, order.currency_code)
                                            : '—'}
                                    </td>
                                    <td data-label={t('storefront.account.date', 'Date')}>
                                        {formatSubscriptionDate(order.created_at) || '—'}
                                    </td>
                                    <td data-label={t('storefront.common.action', 'Action')}>
                                        <Link to={`/account/orders/${order.number}`} className="shop-od-action is-compact">
                                            <Icon name="eye" className="h-3.5 w-3.5" />
                                            {t('storefront.account.view_order', 'View order')}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

export default function SubscriptionDetail() {
    const { id } = useParams();
    const [row, setRow] = useState(null);
    const [missing, setMissing] = useState(false);

    const load = useCallback(() => {
        api.get(`/account/subscriptions/${id}`)
            .then((r) => {
                setRow(r.data.subscription || null);
                setMissing(!r.data.subscription);
            })
            .catch(() => {
                setRow(null);
                setMissing(true);
            });
    }, [id]);

    useEffect(() => {
        setRow(null);
        setMissing(false);
        load();
    }, [load]);

    if (missing) {
        return (
            <EmptyState
                title={t('storefront.account.subscription_not_found', 'Subscription not found.')}
                action={<Link to="/account/subscriptions" className="shop-btn shop-btn-primary">{t('storefront.account.subscriptions', 'Subscriptions')}</Link>}
            />
        );
    }

    if (!row) {
        return <PageSkeleton />;
    }

    const name = row.product || t('storefront.account.subscription', 'Subscription');
    const href = row.product_slug ? productPath({ slug: row.product_slug }) : null;
    const paymentLabel = subscriptionPaymentLabel(row.payment_status);
    const cancellation = row.cancel_at_period_end
        ? t('storefront.account.ends_after_period', 'Ends after this period')
        : formatSubscriptionDate(row.cancelled_at);

    const invoices = Array.isArray(row.invoice_orders) && row.invoice_orders.length
        ? row.invoice_orders
        : [
            ...(row.order_number ? [{
                id: row.order_id,
                number: row.order_number,
                created_at: row.order_created_at,
                payment_status: row.payment_status,
                kind: 'original',
                grand_total: row.price,
                currency_code: row.currency_code,
            }] : []),
            ...((Array.isArray(row.renewal_orders) ? row.renewal_orders : []).map((order) => ({
                ...order,
                kind: 'renewal',
            }))),
        ];

    return (
        <div className="shop-od shop-subscriptions shop-subscriptions-detail">
            <nav className="shop-od-crumb" aria-label="Breadcrumb">
                <Link to="/account/subscriptions">{t('storefront.account.subscriptions', 'Subscriptions')}</Link>
                <Icon name="chevron" className="h-3.5 w-3.5" />
                <span>{name}</span>
            </nav>

            <header className="shop-od-head">
                <div>
                    <div className="shop-od-title-row">
                        <h1>{t('storefront.account.subscription_details', 'Subscription details')}</h1>
                        <span className={`shop-order-status is-${subscriptionStatusTone(row.status)}`}>
                            <i />
                            {subscriptionStatusLabel(row.status)}
                        </span>
                    </div>
                </div>
                <div className="shop-od-actions">
                    <Link to="/account/subscriptions" className="shop-od-action is-compact">
                        <Icon name="arrow-left" className="h-3.5 w-3.5" />
                        {t('storefront.common.back', 'Back')}
                    </Link>
                </div>
            </header>

            <section className="shop-od-card">
                <div className="shop-subscriptions-hero">
                    <div className="shop-subscriptions-hero-img">
                        <ProductImage
                            product={{ cover_url: row.product_cover_url, name }}
                            alt={name}
                            size="md"
                            width={88}
                            height={88}
                        />
                    </div>
                    <div className="shop-subscriptions-hero-copy">
                        <p className="shop-subscriptions-hero-kicker">{t('storefront.account.product', 'Product')}</p>
                        {href ? (
                            <Link to={href} state={entityState('product')} className="shop-preorders-name">
                                {name}
                            </Link>
                        ) : (
                            <span className="shop-preorders-name">{name}</span>
                        )}
                        {row.plan ? (
                            <span className="shop-subscriptions-hero-plan">{row.plan}</span>
                        ) : null}
                    </div>
                    <div className="shop-subscriptions-hero-aside">
                        {row.price != null ? (
                            <p className="shop-subscriptions-hero-price">{formatMoney(row.price, row.currency_code)}</p>
                        ) : null}
                        {row.billing_summary ? (
                            <p className="shop-subscriptions-hero-interval">{row.billing_summary}</p>
                        ) : null}
                    </div>
                </div>
            </section>

            <div className="shop-od-pair">
                <FactsCard title={t('storefront.account.subscription_information', 'Subscription information')}>
                    <Fact label={t('storefront.account.subscription_plan', 'Subscription Plan')}>{row.plan}</Fact>
                    <Fact label={t('storefront.account.status', 'Status')}>{subscriptionStatusLabel(row.status)}</Fact>
                    <Fact label={t('storefront.account.start_date', 'Start date')}>{formatSubscriptionDate(row.started_at)}</Fact>
                    <Fact label={t('storefront.account.duration', 'Duration')}>{row.duration_summary}</Fact>
                    <Fact label={t('storefront.account.quantity', 'Quantity')}>
                        {row.qty != null ? String(row.qty) : null}
                    </Fact>
                    <Fact label={t('storefront.account.cancellation', 'Cancellation')}>{cancellation}</Fact>
                    <Fact label={t('storefront.account.ended_at', 'Ended')}>{formatSubscriptionDate(row.ended_at)}</Fact>
                </FactsCard>

                <FactsCard title={t('storefront.account.billing_information', 'Billing information')}>
                    <Fact label={t('storefront.account.price', 'Price')}>
                        {row.price != null ? formatMoney(row.price, row.currency_code) : null}
                    </Fact>
                    <Fact label={t('storefront.account.billing_interval', 'Billing Interval')}>{row.billing_summary}</Fact>
                    <Fact label={t('storefront.account.trial_period', 'Trial period')}>{row.trial_summary}</Fact>
                    <Fact label={t('storefront.account.trial_ends', 'Trial end date')}>{formatSubscriptionDate(row.trial_ends_at)}</Fact>
                    <Fact label={t('storefront.account.next_billing', 'Next Billing')}>{formatSubscriptionDate(row.next_renewal_at)}</Fact>
                    <Fact label={t('storefront.account.completed_cycles', 'Completed cycles')}>
                        {row.cycles_completed != null ? String(row.cycles_completed) : null}
                    </Fact>
                    <Fact label={t('storefront.account.payment_status', 'Payment Status')}>
                        <span className={`shop-order-status is-${subscriptionPaymentTone(row.payment_status)}`}>
                            <i />
                            {paymentLabel}
                        </span>
                    </Fact>
                </FactsCard>
            </div>

            <section className="shop-od-card">
                <header className="shop-od-card-head">
                    <h2>{t('storefront.account.actions', 'Actions')}</h2>
                </header>
                <SubscriptionActions row={row} onDone={load} />
            </section>

            <RenewalInvoices invoices={invoices} />
        </div>
    );
}
