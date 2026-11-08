import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    BulkActions,
    catalogBulkActionItems,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    KpiCards,
    statusCountKpis,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
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

export default function Index({ methods, counts = {}, filters = {}, types = {} }) {
    const rows = methods.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        description: filters.description || '',
        condition: filters.condition || '',
        type: filters.type || 'all',
        price_min: filters.price_min || '',
        price_max: filters.price_max || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'name',
        dir: filters.dir === 'desc' ? 'desc' : 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/shipping', cleanQuery({ ...query, ...next }), {
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

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));
    const typeOptions = Object.entries(types).map(([value, label]) => ({ value, label }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.shipping.all_methods', 'All methods'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.shipping.shipping_methods', 'Shipping methods')}
                total={methods.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.shipping.shipping_methods', 'Shipping methods') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button icon="plus" href="/admin/shipping/create">
                            {t('admin.common.add_new', 'Add new')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/shipping"
                            importUrl="/admin/shipping/import"
                            importSampleUrl="/admin/shipping/import/sample"
                            exportUrl="/admin/shipping/export"
                            query={query}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/shipping/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/shipping/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/shipping/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/shipping/bulk-delete'),
                            deleteConfirm: t('admin.shipping.delete_selected_shipping_methods', 'Delete selected shipping methods?'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={methods} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.shipping.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.shipping.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="description" label={t('admin.shipping.description', 'Description')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="type" label={t('admin.shipping.type', 'Type')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="price" label={t('admin.shipping.price_rule', 'Price / rule')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.shipping.conditions', 'Conditions')}</Th>
                            <SortHeader col="status" column="is_enabled" label={t('admin.shipping.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.shipping.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="description" value={query.description} placeholder={t('admin.shipping.description', 'Description')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="type"
                                    value={query.type === 'all' ? '' : query.type}
                                    type="select"
                                    placeholder={t('admin.shipping.select_type', 'Select type')}
                                    options={typeOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter minName="price_min" maxName="price_max" minValue={query.price_min} maxValue={query.price_max} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="condition" value={query.condition} placeholder={t('admin.shipping.conditions', 'Conditions')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.shipping.select_status', 'Select status')}
                                    options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/shipping" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={9}>{t('admin.shipping.no_shipping_methods_yet', 'No shipping methods yet.')}</EmptyState>
                        ) : rows.map((method) => {
                            const canEdit = method.actions?.edit !== false;
                            const canDelete = method.actions?.delete !== false;

                            return (
                                <Tr key={method.id} selected={selection.isSelected(method.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(method.id)}
                                                onChange={() => selection.toggle(method.id)}
                                                ariaLabel={`Select ${method.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{method.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <span className={ui.cellIcon}>
                                                <Icon name="truck" />
                                            </span>
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{method.name}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td><span className={ui.cellSub}>{method.description || '—'}</span></Td>
                                    <Td>{method.type_label}</Td>
                                    <Td>
                                        <span className="text-sm text-neutral-700">{method.price_summary}</span>
                                    </Td>
                                    <Td>
                                        <span className="text-sm text-neutral-600">{method.condition_summary}</span>
                                    </Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!method.is_enabled}
                                            onToggle={() => {
                                                router.post(`/admin/shipping/${method.id}/toggle`, {}, { preserveScroll: true });
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/shipping/${method.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={`Delete “${method.name}”?`}
                                            onDelete={() => {
                                                router.delete(`/admin/shipping/${method.id}`, { preserveScroll: true });
                                            }}
                                            items={[{
                                                key: 'duplicate',
                                                label: t('admin.common.duplicate', 'Duplicate'),
                                                icon: 'copy',
                                                onClick: () => router.post(`/admin/shipping/${method.id}/duplicate`, {}, { preserveScroll: true }),
                                            }]}
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
