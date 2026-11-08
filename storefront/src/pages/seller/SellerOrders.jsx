import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { Icon } from '../../components/Icons.jsx';
import { t } from '../../i18n.js';
import { formatMoney } from '../../lib/format.js';
import { downloadCsv } from '../../lib/download.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import SellerListSettings from '../../components/SellerListSettings.jsx';
import { Field } from './SellerHome.jsx';
import { useSellerBreadcrumb } from './useSellerBreadcrumb.js';
import {
    cleanQuery,
    ColumnFilter,
    ColumnRangeFilter,
    ListPagination,
    queryFromParams,
    SortHeader,
    useDebouncedApply,
} from './sellerListShared.jsx';

const DEFAULT_QUERY = {
    number: '',
    customer: '',
    payment_status: '',
    fulfillment: '',
    total_min: '',
    total_max: '',
    id_min: '',
    id_max: '',
    from: '',
    sort: 'id',
    dir: 'desc',
    page: '1',
};

const PAYMENT_STATUS_OPTIONS = [
    { value: '', label: t('storefront.seller.filter_all', 'All') },
    { value: 'paid', label: t('storefront.seller.payment_paid', 'Paid') },
    { value: 'pending', label: t('storefront.seller.payment_pending', 'Pending') },
    { value: 'failed', label: t('storefront.seller.payment_failed', 'Failed') },
    { value: 'refunded', label: t('storefront.seller.payment_refunded', 'Refunded') },
];

const FULFILLMENT_OPTIONS = [
    { value: '', label: t('storefront.seller.filter_all', 'All') },
    { value: 'pending', label: t('storefront.seller.status_pending', 'Pending') },
    { value: 'processing', label: t('storefront.seller.status_processing', 'Processing') },
    { value: 'shipped', label: t('storefront.seller.status_shipped', 'Shipped') },
    { value: 'completed', label: t('storefront.seller.status_delivered', 'Delivered') },
    { value: 'cancelled', label: t('storefront.seller.status_cancelled', 'Cancelled') },
];

function customerName(order) {
    const ship = order.shipping_address || {};
    const name = [ship.first_name, ship.last_name].filter(Boolean).join(' ');
    return name || order.customer_email || '—';
}

function customerInitials(order) {
    const name = customerName(order);
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
        return `${parts[0].charAt(0)}${parts[1].charAt(0)}`.toUpperCase();
    }
    return String(name).trim().charAt(0).toUpperCase() || '?';
}

function fulfillmentLabel(status) {
    if (status === 'completed') {
        return t('storefront.seller.status_delivered', 'Delivered');
    }
    return (status || 'pending').replace(/_/g, ' ');
}

function fulfillmentClass(status) {
    const map = {
        pending: 'is-pending',
        processing: 'is-info',
        shipped: 'is-info',
        completed: 'is-success',
        cancelled: 'is-rejected',
        refunded: 'is-rejected',
    };
    return map[status] || 'is-inactive';
}

function paymentLabel(order) {
    const payment = order.payment_status || 'pending';
    if (payment === 'paid') {
        return t('storefront.seller.payment_paid', 'Paid');
    }
    if (payment === 'failed') {
        return t('storefront.seller.payment_failed', 'Failed');
    }
    if (payment === 'refunded') {
        return t('storefront.seller.payment_refunded', 'Refunded');
    }
    return t('storefront.seller.payment_pending', 'Pending');
}

function paymentClass(order) {
    const payment = order.payment_status || 'pending';
    if (payment === 'paid') {
        return 'is-success';
    }
    if (payment === 'failed' || payment === 'refunded') {
        return 'is-rejected';
    }
    return 'is-pending';
}

function itemQty(order) {
    return (order.items || []).reduce((sum, item) => sum + Number(item.qty || 0), 0);
}

function sellerDocUrl(orderId, kind, download = false) {
    const base = `/api/v1/account/seller/orders/${orderId}/${kind}`;
    return download ? `${base}/download` : base;
}

function orderDocumentActions(order) {
    const payment = order.payment_status || 'pending';
    const items = [
        {
            key: 'view',
            label: t('storefront.seller.view', 'View'),
            icon: 'eye',
            href: `/account/seller/orders/${order.id}`,
            internal: true,
        },
    ];

    if (payment === 'paid') {
        items.push(
            {
                key: 'view-invoice',
                label: t('storefront.seller.view_invoice', 'View Invoice'),
                icon: 'file',
                href: sellerDocUrl(order.id, 'invoice'),
            },
            {
                key: 'download-invoice',
                label: t('storefront.seller.download_invoice', 'Download Invoice'),
                icon: 'download',
                href: sellerDocUrl(order.id, 'invoice', true),
            },
        );
    }

    if (['shipped', 'completed'].includes(order.status)) {
        items.push(
            {
                key: 'view-delivery-slip',
                label: t('storefront.seller.view_delivery_slip', 'View Delivery Slip'),
                icon: 'file',
                href: sellerDocUrl(order.id, 'delivery-slip'),
            },
            {
                key: 'download-delivery-slip',
                label: t('storefront.seller.download_delivery_slip', 'Download Delivery Slip'),
                icon: 'download',
                href: sellerDocUrl(order.id, 'delivery-slip', true),
            },
        );
    }

    return items;
}

function OrderRowActionsMenu({ order }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const items = orderDocumentActions(order);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const close = (event) => {
            if (ref.current && !ref.current.contains(event.target)) {
                setOpen(false);
            }
        };
        window.addEventListener('click', close);
        return () => window.removeEventListener('click', close);
    }, [open]);

    return (
        <div className="mv-row-menu" ref={ref}>
            <button
                type="button"
                className="mv-row-menu-btn"
                aria-expanded={open}
                aria-label={t('storefront.seller.actions', 'Actions')}
                onClick={() => setOpen((value) => !value)}
            >
                ⋯
            </button>
            {open ? (
                <div className="mv-row-menu-panel" role="menu">
                    <Link
                        className="mv-row-menu-item"
                        to={`/account/seller/orders/${order.id}`}
                        role="menuitem"
                        onClick={() => setOpen(false)}
                    >
                        <Icon name="pencil" className="h-4 w-4 shrink-0" />
                        {t('storefront.seller.edit', 'Edit')}
                    </Link>
                    {items.map((item) => (
                        item.internal ? (
                            <Link
                                key={item.key}
                                className="mv-row-menu-item"
                                to={item.href}
                                role="menuitem"
                                onClick={() => setOpen(false)}
                            >
                                <Icon name={item.icon} className="h-4 w-4 shrink-0" />
                                {item.label}
                            </Link>
                        ) : (
                            <a
                                key={item.key}
                                className="mv-row-menu-item"
                                href={item.href}
                                role="menuitem"
                                target="_blank"
                                rel="noreferrer"
                                onClick={() => setOpen(false)}
                            >
                                <Icon name={item.icon} className="h-4 w-4 shrink-0" />
                                {item.label}
                            </a>
                        )
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default function SellerOrders() {
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams, DEFAULT_QUERY), [searchParams]);
    const [paginator, setPaginator] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const rows = paginator?.data || [];
    const colSpan = 9;

    const apply = useCallback((patch) => {
        const next = cleanQuery({ ...query, ...patch });
        if (!patch.page) {
            next.page = '1';
        }
        setSearchParams(next, { replace: true });
    }, [query, setSearchParams]);

    const applyDebounced = useDebouncedApply(apply);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir, page: '1' });
    }, [apply, query.dir, query.sort]);

    const load = useCallback(() => {
        setLoading(true);
        setError('');
        return api.get('/account/seller/orders', { params: cleanQuery(query) })
            .then((response) => {
                setPaginator(response.data.orders || null);
            })
            .catch(() => {
                setPaginator({ data: [], total: 0, current_page: 1, last_page: 1 });
                setError(t('storefront.seller.load_failed', 'Could not load orders.'));
            })
            .finally(() => setLoading(false));
    }, [query]);

    useEffect(() => { load(); }, [load]);

    const hasFilters = useMemo(() => Object.entries(query).some(([key, value]) => {
        if (key === 'sort' || key === 'dir' || key === 'page') {
            return false;
        }
        return value !== '' && value != null;
    }), [query]);

    if (loading && !paginator) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <div className="mv-toolbar">
                <h2 className="mv-page-title">
                    {t('storefront.seller.orders', 'Seller Orders')}
                    {paginator?.total != null ? (
                        <span className="mv-page-title-count">{` (${Number(paginator.total).toLocaleString()})`}</span>
                    ) : null}
                </h2>
                <div className="mv-toolbar-actions">
                    <SellerListSettings
                        onExport={() => downloadCsv(api, '/account/seller/exports/orders', 'seller-orders.csv', cleanQuery(query))}
                        onRefresh={load}
                    />
                </div>
            </div>

            <FormNotice tone="error">{error}</FormNotice>

            <div className="mv-list-panel">
                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-id">
                                    <SortHeader column="id" label={t('storefront.seller.col_id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="number" label={t('storefront.seller.col_order', 'Order')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="customer" label={t('storefront.seller.col_customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="payment_status" label={t('storefront.seller.col_status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>{t('storefront.seller.col_payment', 'Payment')}</th>
                                <th>
                                    <SortHeader column="status" label={t('storefront.seller.col_fulfillment', 'Fulfillment')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="grand_total" label={t('storefront.seller.col_total', 'Total')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="created_at" label={t('storefront.seller.col_date', 'Date')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-actions">{t('storefront.seller.col_actions', 'Actions')}</th>
                            </tr>
                            <tr>
                                <th className="mv-list-th-filter-id">
                                    <ColumnRangeFilter
                                        minName="id_min"
                                        maxName="id_max"
                                        minValue={query.id_min}
                                        maxValue={query.id_max}
                                        onApply={applyDebounced}
                                        compact
                                        center
                                    />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter
                                        name="number"
                                        value={query.number}
                                        placeholder="#"
                                        onApply={applyDebounced}
                                    />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter
                                        name="customer"
                                        value={query.customer}
                                        placeholder={t('storefront.seller.search_customer', 'Search customer')}
                                        onApply={applyDebounced}
                                    />
                                </th>
                                <th className="mv-list-th-filter-status">
                                    <ColumnFilter
                                        name="payment_status"
                                        type="select"
                                        value={query.payment_status}
                                        placeholder={t('storefront.seller.filter_all', 'All')}
                                        options={PAYMENT_STATUS_OPTIONS}
                                        onApply={apply}
                                        compact
                                        statusFilter
                                    />
                                </th>
                                <th className="mv-list-th-filter">
                                    <span className="sr-only">{t('storefront.seller.col_payment', 'Payment')}</span>
                                </th>
                                <th className="mv-list-th-filter-status">
                                    <ColumnFilter
                                        name="fulfillment"
                                        type="select"
                                        value={query.fulfillment}
                                        placeholder={t('storefront.seller.filter_all', 'All')}
                                        options={FULFILLMENT_OPTIONS}
                                        onApply={apply}
                                        compact
                                        statusFilter
                                    />
                                </th>
                                <th className="mv-list-th-filter-center">
                                    <ColumnRangeFilter
                                        minName="total_min"
                                        maxName="total_max"
                                        minValue={query.total_min}
                                        maxValue={query.total_max}
                                        onApply={applyDebounced}
                                        center
                                    />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter
                                        name="from"
                                        type="date"
                                        value={query.from}
                                        placeholder={t('storefront.seller.col_date', 'Date')}
                                        onApply={apply}
                                    />
                                </th>
                                <th className="mv-list-th-filter-actions">
                                    {hasFilters ? (
                                        <button
                                            type="button"
                                            className="mv-list-filter-reset"
                                            title={t('storefront.seller.reset_filters', 'Reset filters')}
                                            aria-label={t('storefront.seller.reset_filters', 'Reset filters')}
                                            onClick={() => setSearchParams({}, { replace: true })}
                                        >
                                            ↺
                                        </button>
                                    ) : null}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={colSpan}>
                                        <div className="mv-list-empty">
                                            <p>{t('storefront.seller.no_orders', 'No seller orders yet.')}</p>
                                            <p>{t('storefront.seller.no_orders_table_body', 'Orders that include your products will show here.')}</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : rows.map((order) => {
                                const created = order.created_at ? new Date(order.created_at) : null;
                                const qty = itemQty(order);
                                const paymentMethod = order.order?.payment_method?.name || t('storefront.seller.payment_offline', 'Offline');
                                return (
                                    <tr key={order.id}>
                                        <td className="mv-list-td-id">{order.id}</td>
                                        <td>
                                            <Link to={`/account/seller/orders/${order.id}`} className="mv-list-link">
                                                #{order.number}
                                            </Link>
                                        </td>
                                        <td>
                                            <div className="mv-list-row">
                                                <span className="mv-list-avatar" aria-hidden="true">{customerInitials(order)}</span>
                                                <div className="mv-list-cell-stack">
                                                    <div className="mv-list-cell-strong">{customerName(order)}</div>
                                                    <div className="mv-list-cell-sub">{order.customer_email}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="mv-list-td-status">
                                            <span className={`mv-list-status ${paymentClass(order)}`}>{paymentLabel(order)}</span>
                                        </td>
                                        <td>{paymentMethod}</td>
                                        <td className="mv-list-td-status">
                                            <span className={`mv-list-status ${fulfillmentClass(order.status)}`}>{fulfillmentLabel(order.status)}</span>
                                        </td>
                                        <td className="mv-list-td-center">
                                            <div className="mv-list-cell-stack">
                                                <div className="mv-list-cell-strong">{formatMoney(order.grand_total, order.currency_code)}</div>
                                                <div className="mv-list-cell-sub">{qty} {qty === 1 ? t('storefront.account.item', 'item') : t('storefront.account.items', 'items')}</div>
                                            </div>
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
                                        <td className="mv-list-td-actions">
                                            <OrderRowActionsMenu order={order} />
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
                            ? `${Number(paginator.total).toLocaleString()} ${t('storefront.seller.orders_total', 'orders')}`
                            : null}
                    </span>
                    <ListPagination paginator={paginator} onPage={(page) => apply({ page: String(page) })} />
                </div>
            </div>
        </div>
    );
}

export function SellerOrderDetail() {
    const { id } = useParams();
    const [order, setOrder] = useState(null);
    const [shipments, setShipments] = useState([]);
    const [returns, setReturns] = useState([]);
    const [address, setAddress] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [tracking, setTracking] = useState({ carrier: '', tracking_number: '', tracking_url: '' });

    const load = () => api.get(`/account/seller/orders/${id}`).then((r) => {
        setOrder(r.data.order);
        setShipments(r.data.shipments || []);
        setReturns(r.data.returns || []);
        setAddress(r.data.shipping_address || r.data.order?.shipping_address || null);
        const active = (r.data.shipments || []).find((row) => row.status !== 'cancelled');
        if (active) {
            setTracking({
                carrier: active.carrier || '',
                tracking_number: active.tracking_number || '',
                tracking_url: active.tracking_url || '',
            });
        }
    }).catch(() => setError(t('storefront.seller.order_missing', 'Order not found.')));

    useEffect(() => { load(); }, [id]);

    useSellerBreadcrumb(order?.number || null);

    if (error) {
        return <EmptyState title={error} />;
    }
    if (!order) {
        return <PageSkeleton />;
    }

    const active = shipments.find((row) => row.status !== 'cancelled') || null;
    const ship = address || {};
    const manualStatuses = ['pending', 'processing', 'cancelled'];
    const canEditStatus = ['pending', 'processing'].includes(order.status);
    const canInvoice = (order.payment_status || 'pending') === 'paid';
    const canDelivery = ['shipped', 'completed'].includes(order.status);

    const run = async (fn, { reload = true } = {}) => {
        setNotice('');
        setError('');
        try {
            await csrf();
            await fn();
            if (reload) {
                await load();
            }
        } catch (err) {
            setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || t('storefront.seller.save_failed', 'Could not save.'));
        }
    };

    const updateStatus = async (nextStatus) => {
        if (!canEditStatus || nextStatus === order.status) {
            return;
        }

        setNotice('');
        setError('');
        const previousStatus = order.status;
        setOrder((current) => ({ ...current, status: nextStatus }));

        try {
            await csrf();
            const { data } = await api.patch(`/account/seller/orders/${order.id}`, { status: nextStatus });
            setOrder(data.order);
            setNotice(t('storefront.seller.status_updated', 'Order status updated.'));
            await load();
        } catch (err) {
            setOrder((current) => ({ ...current, status: previousStatus }));
            setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || t('storefront.seller.save_failed', 'Could not save.'));
        }
    };

    return (
        <div className="space-y-5">
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">{order.number}</h2>
                    <p className="capitalize mv-muted">{order.status} · {order.payment_status}</p>
                </div>
                <div className="flex flex-wrap items-end justify-end gap-2">
                    {canInvoice ? (
                        <>
                            <a href={sellerDocUrl(order.id, 'invoice')} target="_blank" rel="noreferrer" className="shop-btn shop-btn-secondary">
                                {t('storefront.seller.view_invoice', 'View Invoice')}
                            </a>
                            <a href={sellerDocUrl(order.id, 'invoice', true)} className="shop-btn shop-btn-secondary">
                                {t('storefront.seller.download_invoice', 'Download Invoice')}
                            </a>
                        </>
                    ) : null}
                    {canDelivery ? (
                        <>
                            <a href={sellerDocUrl(order.id, 'delivery-slip')} target="_blank" rel="noreferrer" className="shop-btn shop-btn-secondary">
                                {t('storefront.seller.view_delivery_slip', 'View Delivery Slip')}
                            </a>
                            <a href={sellerDocUrl(order.id, 'delivery-slip', true)} className="shop-btn shop-btn-secondary">
                                {t('storefront.seller.download_delivery_slip', 'Download Delivery Slip')}
                            </a>
                        </>
                    ) : null}
                    {canEditStatus ? (
                        <label className="grid min-w-[12rem] gap-1 text-sm">
                            <span className="font-medium text-neutral-600">{t('storefront.seller.order_status', 'Order status')}</span>
                            <select
                                className="shop-input"
                                value={manualStatuses.includes(order.status) ? order.status : 'processing'}
                                onChange={(e) => updateStatus(e.target.value)}
                            >
                                {manualStatuses.map((status) => (
                                    <option key={status} value={status}>{status.replace('_', ' ')}</option>
                                ))}
                            </select>
                        </label>
                    ) : (
                        <p className="rounded-full border border-neutral-200 px-3 py-1.5 text-sm capitalize text-neutral-600">
                            {order.status.replace('_', ' ')}
                        </p>
                    )}
                </div>
            </div>

            <FormNotice tone="error">{error}</FormNotice>
            <FormNotice tone="success">{notice}</FormNotice>

            <div className="shop-card divide-y p-0">
                {(order.items || []).map((item) => (
                    <div key={item.id} className="flex items-center justify-between p-4 text-sm">
                        <div>
                            <p className="font-medium">{item.name}</p>
                            <p className="mv-muted">{item.sku} · ×{item.qty}</p>
                        </div>
                        <p>{formatMoney(item.total, order.currency_code)}</p>
                    </div>
                ))}
            </div>

            <div className="shop-card grid gap-4 p-5 text-sm sm:grid-cols-2">
                <div>
                    <h3 className="font-semibold">{t('storefront.seller.ship_to', 'Ship to')}</h3>
                    <p className="mt-2">{ship.first_name} {ship.last_name}</p>
                    <p>{ship.line1}</p>
                    <p>{ship.city} {ship.state} {ship.postal_code}</p>
                    <p>{ship.country_code}</p>
                </div>
                <div className="grid gap-2">
                    <p>{t('storefront.seller.subtotal', 'Subtotal')}: {formatMoney(order.subtotal, order.currency_code)}</p>
                    <p>{t('storefront.seller.shipping', 'Shipping')}: {formatMoney(order.shipping_total || 0, order.currency_code)}</p>
                    <p>{t('storefront.seller.commission_total', 'Commission')}: {formatMoney(order.commission_total, order.currency_code)}</p>
                    <p>{t('storefront.seller.earning_total', 'Your earning')}: {formatMoney(order.earning_total, order.currency_code)}</p>
                    <p className="font-semibold">{t('storefront.seller.grand', 'Line total')}: {formatMoney(order.grand_total, order.currency_code)}</p>
                </div>
            </div>

            <section className="shop-card space-y-4 p-5">
                <div className="mv-toolbar">
                    <div>
                        <h3 className="font-semibold">{t('storefront.seller.fulfillment', 'Fulfillment')}</h3>
                        <p className="mv-muted-sm capitalize">
                            {active ? `${t('storefront.seller.shipment', 'Shipment')}: ${active.status}` : t('storefront.seller.no_shipment', 'No shipment yet.')}
                        </p>
                    </div>
                    {!active && order.status !== 'cancelled' ? (
                        <button
                            type="button"
                            className="shop-btn shop-btn-primary"
                            onClick={() => run(async () => {
                                await api.post(`/account/seller/orders/${order.id}/shipments`, tracking);
                                setNotice(t('storefront.seller.shipment_created', 'Shipment created.'));
                            })}
                        >
                            {t('storefront.seller.create_shipment', 'Create shipment')}
                        </button>
                    ) : null}
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label={t('storefront.seller.carrier', 'Carrier')}>
                        <input className="shop-input" value={tracking.carrier} onChange={(e) => setTracking((c) => ({ ...c, carrier: e.target.value }))} />
                    </Field>
                    <Field label={t('storefront.seller.tracking_number', 'Tracking number')}>
                        <input className="shop-input" value={tracking.tracking_number} onChange={(e) => setTracking((c) => ({ ...c, tracking_number: e.target.value }))} />
                    </Field>
                    <Field label={t('storefront.seller.tracking_url', 'Tracking URL')}>
                        <input className="shop-input" value={tracking.tracking_url} onChange={(e) => setTracking((c) => ({ ...c, tracking_url: e.target.value }))} />
                    </Field>
                </div>

                {active ? (
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="shop-btn shop-btn-ghost"
                            onClick={() => run(async () => {
                                await api.patch(`/account/seller/shipments/${active.id}`, tracking);
                                setNotice(t('storefront.seller.tracking_saved', 'Tracking saved.'));
                            })}
                        >
                            {t('storefront.seller.save_tracking', 'Save tracking')}
                        </button>
                        {active.status === 'processing' ? (
                            <button
                                type="button"
                                className="shop-btn shop-btn-ghost"
                                onClick={() => run(async () => {
                                    await api.post(`/account/seller/shipments/${active.id}/ready`);
                                })}
                            >
                                {t('storefront.seller.ready_to_ship', 'Ready to ship')}
                            </button>
                        ) : null}
                        {['processing', 'ready_to_ship'].includes(active.status) ? (
                            <button
                                type="button"
                                className="shop-btn shop-btn-primary"
                                onClick={() => run(async () => {
                                    await api.post(`/account/seller/shipments/${active.id}/ship`, tracking);
                                    setNotice(t('storefront.seller.marked_shipped', 'Marked as shipped.'));
                                })}
                            >
                                {t('storefront.seller.mark_shipped', 'Mark shipped')}
                            </button>
                        ) : null}
                        {active.status === 'shipped' ? (
                            <button
                                type="button"
                                className="shop-btn shop-btn-primary"
                                onClick={() => run(async () => {
                                    await api.post(`/account/seller/shipments/${active.id}/deliver`);
                                    setNotice(t('storefront.seller.marked_delivered', 'Marked as delivered.'));
                                })}
                            >
                                {t('storefront.seller.mark_delivered', 'Mark delivered')}
                            </button>
                        ) : null}
                    </div>
                ) : null}
            </section>

            {returns.length > 0 ? (
                <section className="shop-card space-y-3 p-5">
                    <div className="mv-toolbar">
                        <h3 className="font-semibold">{t('storefront.seller.returns', 'Returns')}</h3>
                        <Link to="/account/seller/returns" className="mv-link">{t('storefront.home.view_all', 'View All')}</Link>
                    </div>
                    <ul className="space-y-2 text-sm">
                        {returns.map((row) => (
                            <li key={row.id} className="flex justify-between gap-3 border-b border-neutral-100 pb-2">
                                <span className="capitalize">{row.status?.replaceAll('_', ' ')}</span>
                                <span className="mv-muted">{row.reason || row.number || `#${row.id}`}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
