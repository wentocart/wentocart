import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    KpiCards,
    RowActions,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../../ui';

function statusTone(status) {
    if (status === 'received') return 'success';
    if (status === 'ordered') return 'warning';
    if (status === 'cancelled') return 'danger';
    return 'neutral';
}

function statusLabel(status) {
    if (status === 'draft') return t('admin.purchase_orders.status_draft', 'Draft');
    if (status === 'ordered') return t('admin.purchase_orders.status_ordered', 'Ordered');
    if (status === 'received') return t('admin.purchase_orders.status_received', 'Received');
    if (status === 'cancelled') return t('admin.purchase_orders.status_cancelled', 'Cancelled');
    return status;
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

export default function Index({ orders, counts = {}, filters = {} }) {
    const rows = orders?.data || (Array.isArray(orders) ? orders : []);
    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        number: filters.number || '',
        supplier: filters.supplier || '',
        warehouse: filters.warehouse || '',
        expected_on: filters.expected_on || '',
        lines_min: filters.lines_min || '',
        lines_max: filters.lines_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/purchase-orders', cleanQuery({ ...query, ...next }), {
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

    const kpis = [
        { key: 'total', label: t('admin.purchase_orders.total', 'Total'), value: (counts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'draft', label: statusLabel('draft'), value: (counts.draft ?? 0).toLocaleString(), trend: 0 },
        { key: 'ordered', label: statusLabel('ordered'), value: (counts.ordered ?? 0).toLocaleString(), trend: 0 },
        { key: 'received', label: statusLabel('received'), value: (counts.received ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.purchase_orders.title', 'Purchase orders')}
                total={orders?.total ?? rows.length}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.nav.catalog', 'Catalog') },
                    { label: t('admin.nav.purchase_orders', 'Purchase orders') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <ListSettings
                            listUrl="/admin/purchase-orders"
                            importUrl="/admin/purchase-orders/import"
                            importSampleUrl="/admin/purchase-orders/import/sample"
                            exportUrl="/admin/purchase-orders/export"
                            query={query}
                        />
                        <Button href="/admin/purchase-orders/create" icon="plus">
                            {t('admin.purchase_orders.new', 'New purchase order')}
                        </Button>
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel footer={orders?.data ? <ListFooter pagination={orders} query={query} /> : null}>
                <Table>
                    <THead>
                        <tr>
                            <SortHeader column="number" label={t('admin.purchase_orders.number', 'Number')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.common.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="supplier" label={t('admin.purchase_orders.supplier', 'Supplier')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="warehouse" label={t('admin.nav.warehouses', 'Warehouse')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="expected_at" label={t('admin.purchase_orders.expected', 'Expected')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="items_count" label={t('admin.purchase_orders.lines', 'Lines')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.actions', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="number" value={query.number} placeholder={t('admin.purchase_orders.search', 'Search number / supplier')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.select_status', 'Select status')}
                                    options={[
                                        { value: 'draft', label: statusLabel('draft') },
                                        { value: 'ordered', label: statusLabel('ordered') },
                                        { value: 'received', label: statusLabel('received') },
                                        { value: 'cancelled', label: statusLabel('cancelled') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="supplier" value={query.supplier} placeholder={t('admin.purchase_orders.search_supplier', 'Search supplier')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="warehouse" value={query.warehouse} placeholder={t('admin.purchase_orders.search_warehouse', 'Search warehouse')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="expected_on" value={query.expected_on} type="date" onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="lines_min"
                                    maxName="lines_max"
                                    minValue={query.lines_min}
                                    maxValue={query.lines_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/purchase-orders" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>
                                {t('admin.purchase_orders.empty', 'No purchase orders yet. Create one to receive supplier stock into a warehouse.')}
                            </EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td>
                                    <span className={ui.cellStrong}>{row.number}</span>
                                </Td>
                                <Td col="status"><Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge></Td>
                                <Td>{row.supplier || '—'}</Td>
                                <Td>{row.warehouse || '—'}</Td>
                                <Td>{row.expected_at || '—'}</Td>
                                <Td col="center">{row.items_count}</Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/purchase-orders/${row.id}/edit`}
                                        canDelete={row.status === 'draft' || row.status === 'cancelled'}
                                        confirmMessage={`${t('admin.common.delete', 'Delete')} “${row.number}”?`}
                                        onDelete={() => router.delete(`/admin/purchase-orders/${row.id}`, { preserveScroll: true })}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
