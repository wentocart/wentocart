import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { goSafeRedirect } from '../lib/safeRedirect.js';
import { webPath } from '../webPath.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import { entityState, productPath } from '../lib/links.js';

const PAYMENT_META = {
    fully_paid: { tone: 'delivered', label: 'Fully Paid' },
    partially_paid: { tone: 'processing', label: 'Partially Paid' },
    deposit_paid: { tone: 'processing', label: 'Partially Paid' },
    payment_pending: { tone: 'processing', label: 'Payment Pending' },
    payment_failed: { tone: 'cancelled', label: 'Payment Failed' },
    cancelled: { tone: 'cancelled', label: 'Cancelled' },
};

const PREORDER_META = {
    awaiting_release: { tone: 'processing', label: 'Awaiting Release' },
    ready: { tone: 'processing', label: 'Ready' },
    fulfilled: { tone: 'delivered', label: 'Fulfilled' },
    cancelled: { tone: 'cancelled', label: 'Cancelled' },
};

function paymentLabel(status) {
    if (status === 'fully_paid') return t('storefront.account.preorder_fully_paid', 'Fully Paid');
    if (status === 'partially_paid' || status === 'deposit_paid') return t('storefront.account.preorder_partially_paid', 'Partially Paid');
    if (status === 'payment_pending') return t('storefront.account.preorder_payment_pending', 'Payment Pending');
    if (status === 'payment_failed') return t('storefront.account.preorder_payment_failed', 'Payment Failed');
    if (status === 'cancelled') return t('storefront.account.status_cancelled', 'Cancelled');

    return PAYMENT_META[status]?.label || status || '—';
}

function preorderLabel(status) {
    if (status === 'awaiting_release') return t('storefront.account.preorder_awaiting_release', 'Awaiting Release');
    if (status === 'ready') return t('storefront.account.preorder_ready', 'Ready');
    if (status === 'fulfilled') return t('storefront.account.preorder_fulfilled', 'Fulfilled');
    if (status === 'cancelled') return t('storefront.account.status_cancelled', 'Cancelled');

    return PREORDER_META[status]?.label || status || '—';
}

function statusTone(status, map) {
    return map[status]?.tone || 'processing';
}

function formatQty(value) {
    const qty = Number(value);
    if (!Number.isFinite(qty)) {
        return String(value ?? '—');
    }

    return Number.isInteger(qty) ? String(qty) : String(qty);
}

export default function Preorders() {
    const [page, setPage] = useState(1);
    const [payload, setPayload] = useState(null);
    const [payingId, setPayingId] = useState(null);
    const [payError, setPayError] = useState('');

    const load = (targetPage) => {
        api.get('/account/preorders', { params: { page: targetPage, per_page: 10 } })
            .then((r) => {
                setPayload(r.data.preorders || { data: [], current_page: 1, last_page: 1, total: 0 });
            })
            .catch(() => {
                setPayload({ data: [], current_page: 1, last_page: 1, total: 0 });
            });
    };

    useEffect(() => {
        load(page);
    }, [page]);

    const completePayment = async (row) => {
        setPayError('');
        setPayingId(row.id);
        try {
            await csrf();
            const r = await api.post(`/account/preorders/${row.id}/pay`);
            const redirect = r.data.redirect_url;
            if (redirect) {
                const target = /^https?:\/\//i.test(redirect) ? redirect : webPath(redirect);
                if (goSafeRedirect(target)) {
                    return;
                }
            }
            load(page);
        } catch (err) {
            const status = err.response?.status;
            const message = status === 429
                ? t('storefront.account.preorder_pay_throttled', 'Too many payment attempts. Please wait a minute and try again.')
                : (err.response?.data?.message
                    || err.response?.data?.errors?.payment?.[0]
                    || t('storefront.account.preorder_pay_failed', 'Payment could not be completed. Please try again.'));
            setPayError(message);
            load(page);
        } finally {
            setPayingId(null);
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
        <div className="shop-orders shop-preorders">
            <header className="shop-orders-head">
                <div>
                    <p className="shop-orders-kicker">{t('storefront.account.preorders', 'Pre-orders')}</p>
                    <h1>{t('storefront.account.preorders_title', 'My pre-orders')}</h1>
                    <p>{t('storefront.account.preorders_body', 'Track paid and remaining amounts for each pre-order product you purchased.')}</p>
                </div>
                <div className="shop-orders-summary" aria-hidden="true">
                    <span className="shop-orders-summary-count">{total}</span>
                    <span className="shop-orders-summary-label">{t('storefront.account.preorders', 'Pre-orders')}</span>
                </div>
            </header>

            {payError ? <p className="shop-notice shop-notice--error" role="alert">{payError}</p> : null}

            {rows.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_preorders', 'No pre-orders yet.')}
                        body={t('storefront.account.no_preorders_body', 'When you buy a pre-order product, it will appear here.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>}
                    />
                </div>
            ) : (
                <>
                    <div className="shop-module-table-wrap">
                        <table className="shop-module-table shop-preorders-table">
                            <thead>
                                <tr>
                                    <th>{t('storefront.account.product', 'Product')}</th>
                                    <th>{t('storefront.cart.qty', 'Quantity')}</th>
                                    <th>{t('storefront.account.preorder_total', 'Total Amount')}</th>
                                    <th>{t('storefront.account.preorder_paid', 'Paid')}</th>
                                    <th>{t('storefront.account.preorder_remaining', 'Remaining')}</th>
                                    <th>{t('storefront.account.payment_status', 'Payment Status')}</th>
                                    <th>{t('storefront.account.preorder_status', 'Pre-order Status')}</th>
                                    <th>{t('storefront.account.actions', 'Actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const product = row.product || {};
                                    const href = product.url || product.slug ? productPath(product) : null;
                                    const name = product.name || t('storefront.account.items', 'Items');
                                    const paymentStatus = row.payment_status || row.status;
                                    const preorderStatus = row.preorder_status || row.line_status;
                                    const currency = row.currency_code;
                                    const remaining = Number(row.remaining || 0);
                                    const cancelled = paymentStatus === 'cancelled' || preorderStatus === 'cancelled';
                                    const showPay = remaining > 0 && !cancelled;
                                    const payEnabled = !!row.can_complete_payment;

                                    return (
                                        <tr key={row.id}>
                                            <td data-label={t('storefront.account.product', 'Product')}>
                                                <div className="shop-preorders-product">
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
                                            </td>
                                            <td data-label={t('storefront.cart.qty', 'Quantity')}>{formatQty(row.quantity)}</td>
                                            <td data-label={t('storefront.account.preorder_total', 'Total Amount')}>{formatMoney(row.price, currency)}</td>
                                            <td data-label={t('storefront.account.preorder_paid', 'Paid')}>{formatMoney(row.paid, currency)}</td>
                                            <td data-label={t('storefront.account.preorder_remaining', 'Remaining')}>{formatMoney(row.remaining, currency)}</td>
                                            <td data-label={t('storefront.account.payment_status', 'Payment Status')}>
                                                <span className={`shop-order-status is-${statusTone(paymentStatus, PAYMENT_META)}`}>
                                                    <i />
                                                    {paymentLabel(paymentStatus)}
                                                </span>
                                            </td>
                                            <td data-label={t('storefront.account.preorder_status', 'Pre-order Status')}>
                                                <span className={`shop-order-status is-${statusTone(preorderStatus, PREORDER_META)}`}>
                                                    <i />
                                                    {preorderLabel(preorderStatus)}
                                                </span>
                                            </td>
                                            <td data-label={t('storefront.account.actions', 'Actions')}>
                                                {showPay ? (
                                                    <button
                                                        type="button"
                                                        className="shop-btn shop-btn-primary"
                                                        disabled={!payEnabled || payingId === row.id}
                                                        title={!payEnabled ? t('storefront.account.preorder_pay_when_available', 'Complete Payment is available when this pre-order is released or back in stock.') : undefined}
                                                        onClick={() => payEnabled && completePayment(row)}
                                                    >
                                                        {payingId === row.id
                                                            ? t('storefront.common.please_wait', 'Please wait')
                                                            : t('storefront.account.complete_payment', 'Complete Payment')}
                                                    </button>
                                                ) : remaining <= 0 && paymentStatus !== 'cancelled' ? (
                                                    <span className="shop-preorders-order">
                                                        {t('storefront.account.preorder_fully_paid', 'Fully Paid')}
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {lastPage > 1 ? (
                        <div className="shop-module-pager">
                            <span>
                                {t('storefront.account.page', 'Page')} {current} / {lastPage}
                            </span>
                            <div className="shop-module-pages">
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-ghost"
                                    disabled={current <= 1}
                                    onClick={() => setPage(current - 1)}
                                >
                                    <Icon name="chevron-left" className="h-4 w-4" />
                                    {t('storefront.common.prev', 'Previous')}
                                </button>
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-ghost"
                                    disabled={current >= lastPage}
                                    onClick={() => setPage(current + 1)}
                                >
                                    {t('storefront.common.next', 'Next')}
                                    <Icon name="chevron-right" className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    ) : null}
                </>
            )}
        </div>
    );
}
