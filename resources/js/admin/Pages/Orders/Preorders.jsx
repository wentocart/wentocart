import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import {
    Avatar,
    Badge,
    Button,
    ColumnFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    money,
    ui,
    t,
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.orders.all_orders', 'All Orders'), href: '/admin/orders' },
        { key: 'pending', label: t('admin.orders.pending', 'Pending'), href: '/admin/orders?status=pending' },
        { key: 'processing', label: t('admin.orders.processing', 'Processing'), href: '/admin/orders?status=processing' },
        { key: 'shipped', label: t('admin.orders.shipped', 'Shipped'), href: '/admin/orders?status=shipped' },
        { key: 'delivered', label: t('admin.orders.delivered', 'Delivered'), href: '/admin/orders?status=delivered' },
        { key: 'cancelled', label: t('admin.orders.cancelled', 'Cancelled'), href: '/admin/orders?status=cancelled' },
        { key: 'refunded', label: t('admin.orders.refunded', 'Refunded'), href: '/admin/orders?status=refunded' },
        { key: 'preorders', label: t('admin.orders.preorders', 'Pre-orders'), href: '/admin/orders/preorders' },
    ];
}

function paymentStatusTone(status) {
    const map = {
        paid: 'success',
        fully_paid: 'success',
        pending: 'warning',
        partial: 'warning',
        partially_paid: 'warning',
        failed: 'danger',
        refunded: 'info',
        cancelled: 'danger',
    };
    return map[status] || 'neutral';
}

function paymentStatusLabel(status) {
    const map = {
        paid: t('admin.orders.paid', 'Paid'),
        fully_paid: t('admin.orders.fully_paid', 'Fully Paid'),
        pending: t('admin.orders.pending', 'Pending'),
        partial: t('admin.orders.partially_paid', 'Partially Paid'),
        partially_paid: t('admin.orders.partially_paid', 'Partially Paid'),
        failed: t('admin.orders.failed', 'Failed'),
        refunded: t('admin.orders.refunded', 'Refunded'),
        cancelled: t('admin.orders.cancelled', 'Cancelled'),
    };
    return map[status] || (status ? status.replace(/^\w/, (c) => c.toUpperCase()) : t('admin.orders.pending', 'Pending'));
}

function paymentStatusOptions() {
    return [
        { value: 'paid', label: t('admin.orders.paid', 'Paid') },
        { value: 'partial', label: t('admin.orders.partially_paid', 'Partially Paid') },
        { value: 'pending', label: t('admin.orders.pending', 'Pending') },
        { value: 'failed', label: t('admin.orders.failed', 'Failed') },
        { value: 'refunded', label: t('admin.orders.refunded', 'Refunded') },
    ];
}

function preorderStatusTone(status) {
    const map = {
        awaiting_release: 'warning',
        ready: 'info',
        fulfilled: 'success',
        cancelled: 'danger',
    };
    return map[status] || 'neutral';
}

function preorderStatusLabel(status) {
    const map = {
        awaiting_release: t('admin.orders.preorder_awaiting_release', 'Awaiting release'),
        ready: t('admin.orders.preorder_ready', 'Ready'),
        fulfilled: t('admin.orders.preorder_fulfilled', 'Fulfilled'),
        cancelled: t('admin.orders.preorder_cancelled', 'Cancelled'),
    };
    return map[status] || (status ? status.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : '—');
}

function preorderStatusOptions() {
    return [
        { value: 'awaiting_release', label: t('admin.orders.preorder_awaiting_release', 'Awaiting release') },
        { value: 'ready', label: t('admin.orders.preorder_ready', 'Ready') },
        { value: 'fulfilled', label: t('admin.orders.preorder_fulfilled', 'Fulfilled') },
        { value: 'cancelled', label: t('admin.orders.preorder_cancelled', 'Cancelled') },
    ];
}

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

export default function Preorders({ preorders, filters = {} }) {
    const rows = preorders.data || [];

    const query = useMemo(() => ({
        number: filters.number || '',
        customer: filters.customer || '',
        product: filters.product || '',
        payment_status: filters.payment_status || '',
        preorder_status: filters.preorder_status || '',
        date: filters.date || '',
        from: filters.from || '',
        to: filters.to || '',
        sort: filters.sort || 'id',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/orders/preorders', cleanQuery({ ...query, ...next, page: 1 }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const tabItems = pageTabs();

    return (
        <Layout>
            <PageHeader
                title={t('admin.orders.preorders', 'Pre-orders')}
                total={preorders.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.orders.preorders', 'Pre-orders') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" href="/admin/orders">{t('admin.orders.all_orders', 'All Orders')}</Button>
                    </div>
                )}
            />

            <ListPanel
                tabs={(
                    <StatusTabs
                        tabs={tabItems}
                        value="preorders"
                        onChange={(status) => {
                            const tab = tabItems.find((item) => item.key === status);
                            if (tab?.href) {
                                router.get(tab.href, {}, { preserveState: false });
                            }
                        }}
                    />
                )}
                footer={<ListFooter pagination={preorders} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader column="number" label={t('admin.orders.order', 'Order')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.orders.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="product" label={t('admin.orders.product', 'Product')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="qty" label={t('admin.orders.quantity', 'Quantity')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="total" label={t('admin.orders.total_amount', 'Total Amount')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.orders.paid', 'Paid')}</Th>
                            <Th>{t('admin.orders.preorder_remaining', 'Remaining')}</Th>
                            <SortHeader column="payment_status" label={t('admin.orders.payment_status', 'Payment Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="preorder_status" label={t('admin.orders.preorder_status', 'Pre-order Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.orders.date', 'Date')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="number" value={query.number} placeholder="#" onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="customer"
                                    value={query.customer}
                                    placeholder={t('admin.customers.name', 'Name')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="product"
                                    value={query.product}
                                    placeholder={t('admin.orders.product', 'Product')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterCenter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="payment_status"
                                    type="select"
                                    value={query.payment_status}
                                    placeholder={t('admin.common.all', 'All')}
                                    options={paymentStatusOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="preorder_status"
                                    type="select"
                                    value={query.preorder_status}
                                    placeholder={t('admin.common.all', 'All')}
                                    options={preorderStatusOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="date"
                                    type="date"
                                    value={query.date}
                                    placeholder={t('admin.orders.date', 'Date')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/orders/preorders" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={11}>
                                {t('admin.orders.no_preorders_match_filters', 'No pre-order items match these filters.')}
                            </EmptyState>
                        ) : rows.map((row) => {
                            const created = row.created_at ? new Date(row.created_at) : null;
                            const currency = row.currency_code || 'USD';
                            const payment = row.payment_status || row.order_payment_status || 'pending';
                            const preorderStatus = row.preorder_status || row.line_status || '';
                            const productName = row.product?.name || t('admin.orders.product', 'Product');
                            const totalAmount = Number(row.line_total ?? row.price ?? 0);
                            const paidAmount = Number(row.paid ?? 0);
                            const remainingAmount = Number(row.remaining ?? Math.max(0, totalAmount - paidAmount));

                            return (
                                <Tr key={row.id}>
                                    <Td>
                                        <Link href={`/admin/orders/${row.order_id}`} className={ui.btnLink}>
                                            #{row.order_number}
                                        </Link>
                                        {row.order_reference && row.order_reference !== row.order_number ? (
                                            <div className={ui.cellSub}>{row.order_reference}</div>
                                        ) : null}
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={row.customer?.name || row.customer?.email || '?'} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{row.customer?.name || '—'}</div>
                                                <div className={ui.cellSub}>{row.customer?.email || ''}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className={ui.cellStrong}>{productName}</div>
                                    </Td>
                                    <Td col="center">{Number(row.quantity ?? 0)}</Td>
                                    <Td className="tabular-nums">
                                        <div className={ui.cellStrong}>{money(totalAmount, currency)}</div>
                                    </Td>
                                    <Td className="tabular-nums">
                                        <div className={ui.cellStrong}>{money(paidAmount, currency)}</div>
                                    </Td>
                                    <Td className="tabular-nums">
                                        <div className={ui.cellStrong}>{money(remainingAmount, currency)}</div>
                                    </Td>
                                    <Td>
                                        <Badge tone={paymentStatusTone(payment)}>{paymentStatusLabel(payment)}</Badge>
                                    </Td>
                                    <Td>
                                        <Badge tone={preorderStatusTone(preorderStatus)}>
                                            {preorderStatusLabel(preorderStatus)}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        {created ? (
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>
                                                    {created.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                                </div>
                                                <div className={ui.cellSub}>
                                                    {created.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                                                </div>
                                            </div>
                                        ) : '—'}
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            viewHref={`/admin/orders/${row.order_id}`}
                                            viewLabel={t('admin.orders.view_order', 'View Order')}
                                            canEdit={false}
                                            canDelete={false}
                                        />
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
