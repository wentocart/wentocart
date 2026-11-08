import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    Avatar,
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
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
    useListSelection,
    ModuleColumnHeaders,
    ModuleColumns,
    KpiCards,
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.orders.all_orders', 'All Orders') },
        { key: 'pending', label: t('admin.orders.pending', 'Pending') },
        { key: 'processing', label: t('admin.orders.processing', 'Processing') },
        { key: 'shipped', label: t('admin.orders.shipped', 'Shipped') },
        { key: 'delivered', label: t('admin.orders.delivered', 'Delivered') },
        { key: 'cancelled', label: t('admin.orders.cancelled', 'Cancelled') },
        { key: 'refunded', label: t('admin.orders.refunded', 'Refunded') },
        { key: 'preorders', label: t('admin.orders.preorders', 'Pre-orders'), href: '/admin/orders/preorders' },
    ];
}

function customerName(order) {
    if (order.customer?.name) {
        return order.customer.name;
    }
    const billing = order.billing_address || {};
    const name = [billing.first_name, billing.last_name].filter(Boolean).join(' ');
    return name || order.email;
}

function statusLabel(status) {
    if (status === 'completed') {
        return t('admin.orders.delivered', 'Delivered');
    }
    return (status || 'pending').replace(/^\w/, (c) => c.toUpperCase());
}

function statusTone(status) {
    const map = {
        pending: 'warning',
        processing: 'primary',
        shipped: 'info',
        completed: 'success',
        delivered: 'success',
        cancelled: 'danger',
        refunded: 'danger',
    };
    return map[status] || 'warning';
}

function paymentStatusTone(status) {
    const map = {
        paid: 'success',
        pending: 'warning',
        partial: 'warning',
        failed: 'danger',
        refunded: 'info',
        partially_refunded: 'info',
    };
    return map[status] || 'neutral';
}

function paymentStatusLabel(status) {
    const map = {
        paid: t('admin.orders.paid', 'Paid'),
        pending: t('admin.orders.pending', 'Pending'),
        partial: t('admin.orders.partially_paid', 'Partially Paid'),
        failed: t('admin.orders.failed', 'Failed'),
        refunded: t('admin.orders.refunded', 'Refunded'),
        partially_refunded: t('admin.orders.partially_refunded', 'Partially Refunded'),
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

function fulfillmentOptions() {
    return [
        { value: 'pending', label: t('admin.orders.pending', 'Pending') },
        { value: 'processing', label: t('admin.orders.processing', 'Processing') },
        { value: 'shipped', label: t('admin.orders.shipped', 'Shipped') },
        { value: 'completed', label: t('admin.orders.delivered', 'Delivered') },
        { value: 'cancelled', label: t('admin.orders.cancelled', 'Cancelled') },
        { value: 'refunded', label: t('admin.orders.refunded', 'Refunded') },
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

export default function Index({ orders, counts = {}, stats, filters = {}, moduleList = { columns: [] }, invoices_enabled = true }) {
    const rows = orders.data || [];
    const selection = useListSelection(rows);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        number: filters.number || '',
        customer: filters.customer || '',
        payment_status: filters.payment_status || '',
        payment_method: filters.payment_method || '',
        fulfillment: filters.fulfillment || '',
        total_min: filters.total_min || filters.total || '',
        total_max: filters.total_max || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        date: filters.date || '',
        from: filters.from || '',
        to: filters.to || '',
        sort: filters.sort || 'id',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/orders', cleanQuery({ ...query, ...next, page: 1 }), {
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

    const kpis = [
        { key: 'total', label: t('admin.orders.total_orders', 'Total Orders'), value: stats ? stats.total.toLocaleString() : '…', trend: stats?.total_trend ?? 0 },
        { key: 'new', label: t('admin.orders.new_orders', 'New Orders'), value: stats ? stats.new.toLocaleString() : '…', trend: stats?.new_trend ?? 0 },
        { key: 'pending', label: t('admin.orders.pending_orders', 'Pending Orders'), value: stats ? stats.pending.toLocaleString() : '…', trend: stats?.pending_trend ?? 0 },
        { key: 'paid', label: t('admin.orders.paid_orders', 'Paid Orders'), value: stats ? `${stats.paid_rate}%` : '…', trend: stats?.paid_trend ?? 0 },
    ];

    const moduleCols = moduleList.columns || [];

    const exportFields = useMemo(() => [
        { name: 'number', label: t('admin.orders.order', 'Order') },
        { name: 'customer', label: t('admin.orders.customer', 'Customer') },
        {
            name: 'status',
            label: t('admin.orders.status', 'Status'),
            type: 'select',
            options: pageTabs().filter((tab) => tab.key !== 'all' && tab.key !== 'preorders').map((tab) => ({ value: tab.key, label: tab.label })),
        },
        {
            name: 'payment_status',
            label: t('admin.orders.payment_status', 'Payment Status'),
            type: 'select',
            options: paymentStatusOptions(),
        },
        { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
        { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
        { name: 'date', label: t('admin.orders.date', 'Date'), type: 'date' },
        { name: 'from', label: t('admin.orders.from', 'From'), type: 'date' },
        { name: 'to', label: t('admin.orders.to', 'To'), type: 'date' },
    ], []);

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || []).join(',');
        if (ids) {
            window.location.href = adminUrl(`/admin/orders/export?ids=${encodeURIComponent(ids)}`);
        }
    }, [selection.selectedIds]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.orders.orders', 'Orders')}
                total={orders.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.orders.order', 'Order') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button icon="plus" href="/admin/orders/create">{t('admin.orders.create_order', 'Create Order')}</Button>
                        <ListSettings
                            listUrl="/admin/orders"
                            importUrl="/admin/orders/import"
                            importSampleUrl="/admin/orders/import/sample"
                            exportUrl="/admin/orders/export"
                            query={query}
                            exportFields={exportFields}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} loading={!stats} />

            <ListPanel
                tabs={(
                    <StatusTabs
                        tabs={tabItems}
                        value={query.status}
                        onChange={(status) => {
                            const tab = tabItems.find((item) => item.key === status);
                            if (tab?.href) {
                                router.get(tab.href, {}, { preserveState: false });
                                return;
                            }
                            apply({ status });
                        }}
                    />
                )}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        items={[
                            {
                                key: 'export',
                                label: t('admin.common.export_selected', 'Export selected'),
                                icon: 'download',
                                onClick: exportSelected,
                            },
                        ]}
                    />
                )}
                footer={(
                    <ListFooter
                        left={selection.selectedCount > 0 ? (
                            <span>{selection.selectedCount} {t('admin.orders.selected', 'selected')}</span>
                        ) : undefined}
                        pagination={orders}
                        query={query}
                    />
                )}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={() => selection.toggleAll()}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="number" label={t('admin.orders.order', 'Order')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.orders.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="payment_status" label={t('admin.orders.payment_status', 'Payment Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.orders.payment', 'Payment')}</Th>
                            <SortHeader column="status" label={t('admin.orders.fulfillment', 'Fulfillment')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="total" label={t('admin.orders.total', 'Total')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.orders.date', 'Date')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <ModuleColumnHeaders columns={moduleCols} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter
                                    minName="id_min"
                                    maxName="id_max"
                                    minValue={query.id_min}
                                    maxValue={query.id_max}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="number"
                                    value={query.number}
                                    placeholder="#"
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="customer"
                                    value={query.customer}
                                    placeholder={t('admin.customers.name', 'Name')}
                                    onApply={applyColumn}
                                />
                            </Th>
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
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="payment_method"
                                    value={query.payment_method}
                                    placeholder={t('admin.orders.method', 'Method')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="fulfillment"
                                    type="select"
                                    value={query.fulfillment}
                                    placeholder={t('admin.common.all', 'All')}
                                    options={fulfillmentOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="total_min"
                                    maxName="total_max"
                                    minValue={query.total_min}
                                    maxValue={query.total_max}
                                    onApply={applyColumn}
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
                            {moduleCols.map((col) => (
                                <Th key={col.key} className={ui.thFilter} />
                            ))}
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/orders" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={10 + moduleCols.length}>
                                {t('admin.orders.no_orders_match_filters', 'No orders match these filters.')}
                            </EmptyState>
                        ) : rows.map((order) => {
                            const name = customerName(order);
                            const created = new Date(order.created_at);
                            const payment = order.payment_status || 'pending';
                            const qty = (order.items || []).reduce((sum, item) => sum + Number(item.qty || 0), 0);
                            const rowItems = [
                                {
                                    key: 'view',
                                    label: t('admin.orders.view_order', 'View'),
                                    icon: 'eye',
                                    href: `/admin/orders/${order.id}`,
                                },
                            ];

                            if (payment === 'paid' && invoices_enabled) {
                                rowItems.push(
                                    {
                                        key: 'view-invoice',
                                        label: t('admin.orders.view_invoice', 'View Invoice'),
                                        icon: 'file',
                                        href: `/admin/orders/${order.id}/invoice`,
                                    },
                                    {
                                        key: 'download-invoice',
                                        label: t('admin.orders.download_invoice', 'Download Invoice'),
                                        icon: 'download',
                                        href: `/admin/orders/${order.id}/invoice/download`,
                                        native: true,
                                    },
                                );
                            }

                            if (payment === 'refunded') {
                                rowItems.push(
                                    {
                                        key: 'view-credit-slip',
                                        label: t('admin.nav.credit_slip', 'View Credit Slip'),
                                        icon: 'file',
                                        href: `/admin/orders/${order.id}/credit-slip`,
                                    },
                                    {
                                        key: 'download-credit-slip',
                                        label: t('admin.nav.credit_slip', 'Download Credit Slip'),
                                        icon: 'download',
                                        href: `/admin/orders/${order.id}/credit-slip/download`,
                                        native: true,
                                    },
                                );
                            }

                            if (['shipped', 'completed'].includes(order.status)) {
                                rowItems.push(
                                    {
                                        key: 'view-delivery-slip',
                                        label: t('admin.nav.delivery_slip', 'View Delivery Slip'),
                                        icon: 'file',
                                        href: `/admin/orders/${order.id}/delivery-slip`,
                                    },
                                    {
                                        key: 'download-delivery-slip',
                                        label: t('admin.nav.delivery_slip', 'Download Delivery Slip'),
                                        icon: 'download',
                                        href: `/admin/orders/${order.id}/delivery-slip/download`,
                                        native: true,
                                    },
                                );
                            }

                            return (
                                <Tr key={order.id}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(order.id)}
                                            onChange={() => selection.toggle(order.id)}
                                            ariaLabel={t('admin.common.select_row', 'Select row')}
                                        />
                                    </Td>
                                    <Td className="text-center tabular-nums text-neutral-500">{order.id}</Td>
                                    <Td>
                                        <Link href={`/admin/orders/${order.id}`} className={ui.btnLink}>
                                            #{order.number}
                                        </Link>
                                        {order.checkout_reference && order.checkout_reference !== order.number ? (
                                            <div className={ui.cellSub}>{order.checkout_reference}</div>
                                        ) : null}
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={name} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{name}</div>
                                                <div className={ui.cellSub}>{order.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <Badge tone={paymentStatusTone(order.payment_status)}>
                                            {paymentStatusLabel(order.payment_status)}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <Link href={`/admin/orders/${order.id}`} className={ui.btnLink}>
                                            {order.payment_method?.name || t('admin.orders.offline', 'Offline')}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <Badge tone={statusTone(order.status)}>{statusLabel(order.status)}</Badge>
                                    </Td>
                                    <Td>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{money(order.grand_total, order.currency_code)}</div>
                                            <div className={ui.cellSub}>{qty} {qty === 1 ? 'item' : 'items'}</div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>
                                                {created.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                            </div>
                                            <div className={ui.cellSub}>
                                                {created.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                                            </div>
                                        </div>
                                    </Td>
                                    <ModuleColumns columns={moduleCols} values={order.module_values} />
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/orders/${order.id}/edit`}
                                            editLabel={t('admin.common.edit', 'Edit')}
                                            canDelete={false}
                                            items={rowItems}
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
