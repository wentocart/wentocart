import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { formatMoney } from '../../lib/format.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import SellerListSettings from '../../components/SellerListSettings.jsx';
import { Field } from './SellerHome.jsx';
import { ListColHeader, ListPagination } from './sellerListShared.jsx';

export function SellerCommission() {
    const [data, setData] = useState(null);
    useEffect(() => {
        api.get('/account/seller/commission').then((r) => setData(r.data)).catch(() => setData({ rate: 0, commissions: [] }));
    }, []);
    if (!data) {
        return <PageSkeleton />;
    }
    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.commission', 'Seller Commission')}</h2>
            <p className="mv-page-help">{t('storefront.seller.commission_help', 'The store sets this rate. It is applied to your product lines on each order.')}</p>
            <div className="shop-card mt-5 p-5">
                <p className="mv-stat-label">{t('storefront.seller.current_rate', 'Current rate')}</p>
                <p className="mt-1 text-4xl font-extrabold">{data.rate}%</p>
            </div>
            <ul className="mv-list">
                {(data.commissions || []).map((row) => (
                    <li key={row.id} className="shop-card p-4 text-sm">
                        <p className="font-semibold">{row.name} · {row.rate}%</p>
                        {row.notes ? <p className="mt-1 mv-muted">{row.notes}</p> : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function earningStatusLabel(status) {
    const labels = {
        pending: t('storefront.seller.earning_pending', 'Pending'),
        available: t('storefront.seller.earning_available', 'Available'),
        paid: t('storefront.seller.earning_paid', 'Paid'),
        refunded: t('storefront.seller.earning_refunded', 'Refunded'),
    };
    return labels[status] || String(status || '—').replace(/_/g, ' ');
}

function earningStatusClass(status) {
    const map = {
        pending: 'is-pending',
        available: 'is-success',
        paid: 'is-info',
        refunded: 'is-rejected',
    };
    return map[status] || 'is-inactive';
}

export function SellerEarnings() {
    const [data, setData] = useState(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/account/seller/earnings', { params: { page } })
            .then((r) => setData(r.data))
            .catch(() => setData({ totals: {}, earnings: { data: [] } }))
            .finally(() => setLoading(false));
    }, [page]);

    useEffect(() => { load(); }, [load]);

    if (!data && loading) {
        return <PageSkeleton />;
    }

    const paginator = data?.earnings || null;
    const rows = paginator?.data || [];
    const colSpan = 8;

    return (
        <div>
            <div className="mv-toolbar">
                <h2 className="mv-page-title">
                    {t('storefront.seller.earnings', 'Seller Earning')}
                    {paginator?.total != null ? (
                        <span className="mv-page-title-count">{` (${Number(paginator.total).toLocaleString()})`}</span>
                    ) : null}
                </h2>
                <div className="mv-toolbar-actions">
                    <SellerListSettings onRefresh={load} />
                </div>
            </div>

            <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <Box label={t('storefront.seller.pending_balance', 'Pending')} value={formatMoney(data.totals?.pending || 0)} />
                <Box label={t('storefront.seller.available_balance', 'Available')} value={formatMoney(data.totals?.available || 0)} />
                <Box label={t('storefront.seller.paid_balance', 'Paid')} value={formatMoney(data.totals?.paid || 0)} />
                <Box label={t('storefront.seller.gross', 'Gross')} value={formatMoney(data.totals?.gross || 0)} />
                <Box label={t('storefront.seller.commission', 'Commission')} value={formatMoney(data.totals?.commission || 0)} />
                <Box label={t('storefront.seller.ledger_balance', 'Ledger balance')} value={formatMoney(data.totals?.ledger_balance || 0)} />
            </div>

            <div className="mv-list-panel mt-5">
                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-id">
                                    <ListColHeader label={t('storefront.seller.col_id', 'ID')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.col_order', 'Order')} />
                                </th>
                                <th className="mv-list-th-status">
                                    <ListColHeader label={t('storefront.seller.col_status', 'Status')} />
                                </th>
                                <th className="mv-list-th-center">
                                    <ListColHeader label={t('storefront.seller.gross', 'Gross')} />
                                </th>
                                <th className="mv-list-th-center">
                                    <ListColHeader label={t('storefront.seller.commission', 'Commission')} />
                                </th>
                                <th className="mv-list-th-center">
                                    <ListColHeader label={t('storefront.seller.net', 'Net')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.available_from', 'Available from')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.col_date', 'Date')} />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={colSpan}>
                                        <div className="mv-list-empty">
                                            <p>{t('storefront.seller.no_earnings', 'No earnings yet.')}</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : rows.map((row) => {
                                const created = row.created_at ? new Date(row.created_at) : null;
                                const availableAt = row.available_at ? new Date(row.available_at) : null;
                                const orderId = row.seller_order_id || row.seller_order?.id;
                                const orderNumber = row.seller_order?.number;

                                return (
                                    <tr key={row.id}>
                                        <td className="mv-list-td-id">{row.id}</td>
                                        <td>
                                            {orderId && orderNumber ? (
                                                <Link to={`/account/seller/orders/${orderId}`} className="mv-list-link">
                                                    #{orderNumber}
                                                </Link>
                                            ) : (
                                                orderNumber ? `#${orderNumber}` : '—'
                                            )}
                                        </td>
                                        <td className="mv-list-td-status">
                                            <span className={`mv-list-status ${earningStatusClass(row.status)}`}>
                                                {earningStatusLabel(row.status)}
                                            </span>
                                        </td>
                                        <td className="mv-list-td-center">
                                            <span className="mv-list-cell-strong">{formatMoney(row.gross_amount)}</span>
                                        </td>
                                        <td className="mv-list-td-center">
                                            <div className="mv-list-cell-stack">
                                                <div className="mv-list-cell-strong">{formatMoney(row.commission_amount)}</div>
                                                {row.commission_rate != null ? (
                                                    <div className="mv-list-cell-sub">{Number(row.commission_rate).toFixed(2)}%</div>
                                                ) : null}
                                            </div>
                                        </td>
                                        <td className="mv-list-td-center">
                                            <span className="mv-list-cell-strong">{formatMoney(row.net_amount)}</span>
                                        </td>
                                        <td>
                                            {availableAt ? (
                                                <div className="mv-list-cell-stack">
                                                    <div className="mv-list-cell-strong">
                                                        {availableAt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                                                    </div>
                                                </div>
                                            ) : '—'}
                                        </td>
                                        <td>
                                            {created ? (
                                                <div className="mv-list-cell-stack">
                                                    <div className="mv-list-cell-strong">
                                                        {created.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                                                    </div>
                                                    <div className="mv-list-cell-sub">
                                                        {created.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
                                                    </div>
                                                </div>
                                            ) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="mv-list-footer">
                    <span>
                        {paginator?.total != null
                            ? `${Number(paginator.total).toLocaleString()} ${t('storefront.seller.earnings_total', 'earnings')}`
                            : null}
                    </span>
                    <ListPagination paginator={paginator} onPage={setPage} />
                </div>
            </div>
        </div>
    );
}

export function SellerLedger({ titleKey = 'ledger' } = {}) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    useEffect(() => {
        const url = titleKey === 'transactions' ? '/account/seller/transactions' : '/account/seller/ledger';
        api.get(url).then((r) => setData(r.data)).catch(() => {
            setData({ balance: 0, entries: { data: [] } });
            setError(t('storefront.seller.load_failed', 'Could not load transactions.'));
        });
    }, [titleKey]);
    if (!data) {
        return <PageSkeleton />;
    }
    const rows = data.entries?.data || [];
    const title = titleKey === 'transactions'
        ? t('storefront.seller.transactions', 'Transactions')
        : t('storefront.seller.ledger', 'Seller Ledger');
    return (
        <div>
            <h2 className="mv-page-title">{title}</h2>
            <p className="mv-page-help">{t('storefront.seller.ledger_help', 'Immutable credits and debits for your shop balance. Earnings credit the ledger when they become available for payout.')}</p>
            <FormNotice tone="error">{error}</FormNotice>
            <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Box label={t('storefront.seller.pending_balance', 'Pending')} value={formatMoney(data.pending || 0)} />
                <Box label={t('storefront.seller.available_balance', 'Available')} value={formatMoney(data.available || 0)} />
                <Box label={t('storefront.seller.paid_balance', 'Paid')} value={formatMoney(data.paid || 0)} />
                <Box label={t('storefront.seller.ledger_balance', 'Ledger balance')} value={formatMoney(data.balance || 0)} />
            </div>
            {rows.length === 0 ? (
                <div className="mv-body"><EmptyState title={t('storefront.seller.no_ledger', 'No ledger entries yet.')} /></div>
            ) : (
                <ul className="mv-list">
                    {rows.map((row) => (
                        <li key={row.id} className="shop-card mv-row p-4 text-sm">
                            <div>
                                <p className="font-semibold capitalize">{row.entry_type?.replaceAll('_', ' ')}</p>
                                <p className="mv-muted">
                                    {row.seller_order?.number || row.reference}
                                    {row.description ? ` · ${row.description}` : ''}
                                </p>
                            </div>
                            <p className={`font-semibold ${row.direction === 'debit' ? 'text-red-600' : 'text-emerald-700'}`}>
                                {row.direction === 'debit' ? '−' : '+'}{formatMoney(row.amount)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export function SellerRevenue() {
    const [rows, setRows] = useState(null);
    useEffect(() => {
        api.get('/account/seller/revenue').then((r) => setRows(r.data.revenues.data || [])).catch(() => setRows([]));
    }, []);
    if (!rows) {
        return <PageSkeleton />;
    }
    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.revenue', 'Seller Revenue')}</h2>
            {rows.length === 0 ? (
                <div className="mv-body"><EmptyState title={t('storefront.seller.no_revenue', 'No revenue recorded yet.')} /></div>
            ) : (
                <ul className="mv-list">
                    {rows.map((row) => (
                        <li key={row.id} className="shop-card mv-row p-4 text-sm">
                            <div>
                                <p className="font-semibold">{row.period_date}</p>
                                <p className="mv-muted">{row.order_count} {t('storefront.seller.orders', 'Orders')} · {row.item_count} {t('storefront.account.items', 'items')}</p>
                            </div>
                            <div className="text-right">
                                <p className="font-semibold">{formatMoney(row.net_total)}</p>
                                <p className="mv-muted">{t('storefront.seller.gross', 'Gross')} {formatMoney(row.gross_total)}</p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Box({ label, value }) {
    return (
        <div className="shop-card p-5">
            <p className="mv-stat-label">{label}</p>
            <p className="mt-2 text-2xl font-extrabold">{value}</p>
        </div>
    );
}

export function SellerPayouts() {
    const [data, setData] = useState(null);
    const [form, setForm] = useState({ amount: '', seller_payment_method_id: '', note: '' });
    const [error, setError] = useState('');
    const load = () => api.get('/account/seller/payouts').then((r) => setData(r.data)).catch(() => setData({ available: 0, methods: [], payouts: { data: [] } }));
    useEffect(() => { load(); }, []);
    if (!data) {
        return <PageSkeleton />;
    }
    const rows = data.payouts?.data || [];
    return (
        <div className="mv-split">
            <div>
                <h2 className="mv-page-title">{t('storefront.seller.payouts', 'Payouts')}</h2>
                <p className="mv-page-help">{t('storefront.seller.payouts_help', 'Request a transfer from available earnings. Store staff reviews each request.')}</p>
                <div className="shop-card mt-5 p-5">
                    <p className="mv-stat-label">{t('storefront.seller.available', 'Available')}</p>
                    <p className="mt-1 text-4xl font-extrabold">{formatMoney(data.available || 0)}</p>
                </div>
                {rows.length === 0 ? (
                    <div className="mv-body"><EmptyState title={t('storefront.seller.no_payouts', 'No payout requests yet.')} /></div>
                ) : (
                    <ul className="mv-list">
                        {rows.map((row) => (
                            <li key={row.id} className="shop-card mv-row p-4 text-sm">
                                <div>
                                    <p className="font-semibold capitalize">{String(row.status || '').replaceAll('_', ' ')}</p>
                                    <p className="mv-muted">{row.note}</p>
                                </div>
                                <p className="font-semibold">{formatMoney(row.amount)}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <form
                className="shop-card mv-panel-form p-5"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    try {
                        await csrf();
                        await api.post('/account/seller/payouts', form);
                        setForm({ amount: '', seller_payment_method_id: '', note: '' });
                        load();
                    } catch (err) {
                        setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                    }
                }}
            >
                <h3 className="font-semibold">{t('storefront.seller.request_payout', 'Request payout')}</h3>
                <Field label={t('storefront.seller.amount', 'Amount')}><input type="number" min="0.01" step="0.01" className="shop-input" value={form.amount} onChange={(e) => setForm((c) => ({ ...c, amount: e.target.value }))} required /></Field>
                <Field label={t('storefront.seller.payout_method', 'Payout method')}>
                    <select className="shop-input" value={form.seller_payment_method_id} onChange={(e) => setForm((c) => ({ ...c, seller_payment_method_id: e.target.value }))}>
                        <option value="">{t('storefront.seller.no_method', 'No method')}</option>
                        {(data.methods || []).map((method) => (
                            <option key={method.id} value={method.id}>{method.name}</option>
                        ))}
                    </select>
                </Field>
                <Field label={t('storefront.seller.note', 'Note')}><textarea className="shop-input" rows={3} value={form.note} onChange={(e) => setForm((c) => ({ ...c, note: e.target.value }))} /></Field>
                <FormNotice tone="error">{error}</FormNotice>
                <button className="shop-btn shop-btn-primary">{t('storefront.seller.request', 'Request')}</button>
            </form>
        </div>
    );
}
