import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    PageHeader,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../../ui';

const ACTION_OPTIONS = [
    { value: 'sale', label: t('admin.inventory.action_sale', 'Sale') },
    { value: 'reservation', label: t('admin.inventory.action_reservation', 'Reservation') },
    { value: 'release', label: t('admin.inventory.action_release', 'Release') },
    { value: 'restock', label: t('admin.inventory.action_restock', 'Restock') },
    { value: 'return', label: t('admin.inventory.action_return', 'Return') },
    { value: 'adjustment', label: t('admin.inventory.action_adjustment', 'Adjustment') },
    { value: 'purchase', label: t('admin.inventory.action_purchase', 'Purchase') },
];

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null) {
            delete next[key];
        }
    });
    return next;
}

export default function Journal({ movements, filters = {} }) {
    const rows = movements.data || [];
    const query = useMemo(() => ({
        sku: filters.sku || '',
        name: filters.name || '',
        action: filters.action || '',
        qty_min: filters.qty_min || '',
        qty_max: filters.qty_max || '',
        reference: filters.reference || '',
        warehouse: filters.warehouse || '',
        created: filters.created || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/inventory/journal', cleanQuery({ ...query, ...next }), {
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

    return (
        <Layout>
            <PageHeader
                title={t('admin.inventory.journal', 'Stock journal')}
                total={movements.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/inventory', label: t('admin.nav.inventory', 'Inventory') },
                    { label: t('admin.inventory.journal', 'Stock journal') },
                ]}
            />
            <ListPanel footer={<ListFooter pagination={movements} query={query} />}>
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="sku" label={t('admin.products.reference', 'Reference')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.products.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="action" label={t('admin.inventory.action', 'Action')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="qty" label={t('admin.inventory.qty', 'Qty')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="reference" label={t('admin.inventory.document', 'Document')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="warehouse" label={t('admin.warehouses.title', 'Warehouse')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.common.date', 'Date')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilterId} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="sku" value={query.sku} placeholder={t('admin.products.search_reference', 'Search reference')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.products.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="action"
                                    value={query.action}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={ACTION_OPTIONS}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="qty_min"
                                    maxName="qty_max"
                                    minValue={query.qty_min}
                                    maxValue={query.qty_max}
                                    signed
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="reference" value={query.reference} placeholder={t('admin.inventory.search_document', 'Search document')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="warehouse" value={query.warehouse} placeholder={t('admin.inventory.search_warehouse', 'Search warehouse')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="created" value={query.created} type="date" onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/inventory/journal" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={9}>{t('admin.inventory.journal_empty', 'No stock movements yet.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td col="id">{row.id}</Td>
                                <Td>{row.sku || '—'}</Td>
                                <Td>{row.name || '—'}</Td>
                                <Td>{row.action}</Td>
                                <Td col="center">{row.qty}</Td>
                                <Td>{row.reference || '—'}</Td>
                                <Td>{row.warehouse_code || row.warehouse_name || '—'}</Td>
                                <Td>{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</Td>
                                <Td actions />
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
