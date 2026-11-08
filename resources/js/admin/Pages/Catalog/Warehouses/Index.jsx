import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    BulkActions,
    catalogBulkActionItems,
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
    KpiCards,
    statusCountKpis,
    StatusToggle,
    listStatusLabels,
    listStatusFilterOptions,
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
} from '../../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

const WAREHOUSE_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.warehouses.name', 'Name'), type: 'text', placeholder: t('admin.warehouses.search_name', 'Search name') },
    { name: 'code', label: t('admin.warehouses.code', 'Code'), type: 'text', placeholder: t('admin.warehouses.search_code', 'Search code') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.products.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

export default function Index({ warehouses, counts = {}, filters = {}, can = {} }) {
    const rows = warehouses?.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        code: filters.code || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        stocks_min: filters.stocks_min || '',
        stocks_max: filters.stocks_max || '',
        is_default: filters.is_default || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/warehouses', cleanQuery({ ...query, ...next }), {
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

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const runBulk = useCallback((url, payload = {}) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post(url, { ...payload, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, selection.clear, selection.selectedIds]);

    const statusLabels = listStatusLabels();
    const kpis = statusCountKpis(counts, {
        total: t('admin.warehouses.all', 'All warehouses'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    const bulkItems = catalogBulkActionItems({
        onActive: () => runBulk('/admin/warehouses/bulk-status', { enabled: 1 }),
        onDeactivate: () => runBulk('/admin/warehouses/bulk-status', { enabled: 0 }),
        onDuplicate: () => {},
        onDelete: () => runBulk('/admin/warehouses/bulk-delete'),
        deleteConfirm: t('admin.warehouses.delete_selected', 'Delete selected warehouses? Defaults and stocked locations will be skipped.'),
    }).filter((item) => item.key !== 'duplicate');

    return (
        <Layout>
            <PageHeader
                title={t('admin.warehouses.title', 'Warehouses')}
                total={warehouses?.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.nav.catalog', 'Catalog') },
                    { label: t('admin.nav.warehouses', 'Warehouses') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        {can.create !== false ? (
                            <Button icon="plus" href="/admin/warehouses/create">
                                {t('admin.warehouses.add', 'Add warehouse')}
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/warehouses"
                            importUrl="/admin/warehouses/import"
                            importSampleUrl="/admin/warehouses/import/sample"
                            exportUrl="/admin/warehouses/export"
                            query={query}
                            exportFields={WAREHOUSE_EXPORT_FIELDS}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={bulkItems}
                    />
                )}
                footer={<ListFooter pagination={warehouses} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.warehouses.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.warehouses.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="stocks_count" label={t('admin.warehouses.stocks', 'Stocks')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="is_default" label={t('admin.warehouses.default', 'Default')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.products.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.warehouses.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="code" value={query.code} placeholder={t('admin.warehouses.search_code', 'Search code')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="stocks_min"
                                    maxName="stocks_max"
                                    minValue={query.stocks_min}
                                    maxValue={query.stocks_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="is_default"
                                    value={query.is_default}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={[
                                        { value: '1', label: t('admin.common.yes', 'Yes') },
                                        { value: '0', label: t('admin.common.no', 'No') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={listStatusFilterOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/warehouses" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.warehouses.empty', 'No warehouses yet.')}</EmptyState>
                        ) : rows.map((warehouse) => {
                            const canEdit = warehouse.actions?.edit !== false;
                            const canDelete = warehouse.actions?.delete !== false;

                            return (
                                <Tr key={warehouse.id}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(warehouse.id)}
                                                onChange={() => selection.toggle(warehouse.id)}
                                                ariaLabel={`${t('admin.common.select', 'Select')} ${warehouse.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{warehouse.id}</Td>
                                    <Td>
                                        <div className={ui.cellStrong}>{warehouse.name}</div>
                                    </Td>
                                    <Td>
                                        <span className="font-mono text-sm text-neutral-700">{warehouse.code || '—'}</span>
                                    </Td>
                                    <Td col="center">{Number(warehouse.stocks_count) || 0}</Td>
                                    <Td>
                                        {warehouse.is_default ? (
                                            <Badge tone="primary">{t('admin.warehouses.default', 'Default')}</Badge>
                                        ) : (
                                            <span className={ui.muted}>—</span>
                                        )}
                                    </Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!warehouse.is_enabled}
                                            onToggle={() => {
                                                if (canEdit) {
                                                    router.post(`/admin/warehouses/${warehouse.id}/toggle`, {}, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/warehouses/${warehouse.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={warehouse.is_default
                                                ? t('admin.warehouses.cannot_delete_default', 'Set another warehouse as default before deleting this one.')
                                                : `${t('admin.common.delete', 'Delete')} “${warehouse.name}”?`}
                                            onDelete={() => {
                                                if (!canDelete) {
                                                    return;
                                                }
                                                router.delete(`/admin/warehouses/${warehouse.id}`, { preserveScroll: true });
                                            }}
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
