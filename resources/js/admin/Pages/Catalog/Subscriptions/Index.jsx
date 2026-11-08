import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../../Layout';
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

const frequencyLabel = {
    hour: 'Hour',
    day: 'Day',
    week: 'Week',
    month: 'Month',
    year: 'Year',
};

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

const SUBSCRIPTION_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.common.name', 'Name'), type: 'text', placeholder: t('admin.common.search_name', 'Search name') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    { name: 'cycle_min', label: 'Cycle min', type: 'number' },
    { name: 'cycle_max', label: 'Cycle max', type: 'number' },
    {
        name: 'frequency',
        label: 'Frequency',
        type: 'select',
        options: [
            { value: 'hour', label: 'Hour' },
            { value: 'day', label: 'Day' },
            { value: 'week', label: 'Week' },
            { value: 'month', label: 'Month' },
            { value: 'year', label: 'Year' },
        ],
    },
    {
        name: 'trial',
        label: 'Trial',
        type: 'select',
        options: [
            { value: 'yes', label: 'With trial' },
            { value: 'no', label: 'No trial' },
        ],
    },
    {
        name: 'status',
        label: t('admin.common.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

export default function Index({ plans, counts = {}, filters = {}, can = {}, frequencyOptions = [] }) {
    const { i18n } = usePage().props;
    const rows = plans.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        frequency: filters.frequency || '',
        trial: filters.trial || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        cycle_min: filters.cycle_min || '',
        cycle_max: filters.cycle_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/subscription-plans', cleanQuery({ ...query, ...next }), {
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
        total: 'All plans',
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title="Subscription plans"
                total={plans.total}
                crumbs={[{ href: '/admin', label: 'Dashboard' }, { label: 'Subscription plans' }]}
                actions={
                    <div className={ui.toolbar}>
                        {can.create !== false ? (
                            <Button icon="plus" href="/admin/subscription-plans/create">
                                Add new
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/subscription-plans"
                            importUrl="/admin/subscription-plans/import"
                            importSampleUrl="/admin/subscription-plans/import/sample"
                            exportUrl="/admin/subscription-plans/export"
                            query={query}
                            exportFields={SUBSCRIPTION_EXPORT_FIELDS}
                            showImportCsvSettings
                            importLanguages={i18n?.languages || []}
                            importDefaultLanguage={i18n?.default || i18n?.locale || 'en'}
                        />
                    </div>
                }
            />

            <KpiCards items={kpis} />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/subscription-plans/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/subscription-plans/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/subscription-plans/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/subscription-plans/bulk-delete'),
                            deleteConfirm: 'Delete selected subscription plans? Plans assigned to products will be skipped.',
                        })}
                    />
                )}
                footer={<ListFooter pagination={plans} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel="Select all on this page"
                                />
                            </Th>
                            <SortHeader col="id" column="id" label="ID" sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label="Plan" sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>Trial</Th>
                            <SortHeader column="frequency" label="Billing frequency" sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="cycle" label="Billing cycle" sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label="Status" sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>Action</Th>
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
                                <ColumnFilter name="name" value={query.name} placeholder="Search name" onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="trial"
                                    value={query.trial}
                                    type="select"
                                    placeholder="All"
                                    options={[
                                        { value: 'yes', label: 'With trial' },
                                        { value: 'no', label: 'No trial' },
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="frequency"
                                    value={query.frequency}
                                    type="select"
                                    placeholder="All"
                                    options={frequencyOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="cycle_min"
                                    maxName="cycle_max"
                                    minValue={query.cycle_min}
                                    maxValue={query.cycle_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder="All"
                                    options={listStatusFilterOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/subscription-plans" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>No subscription plans yet.</EmptyState>
                        ) : rows.map((plan) => {
                            const canEdit = plan.actions?.edit !== false;
                            const canDelete = plan.actions?.delete !== false;
                            const assigned = Number(plan.products_count) || 0;

                            return (
                                <Tr key={plan.id} selected={selection.isSelected(plan.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(plan.id)}
                                                onChange={() => selection.toggle(plan.id)}
                                                ariaLabel={`Select ${plan.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{plan.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <span className={ui.cellIcon}>
                                                <Icon name="calendar" />
                                            </span>
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{plan.name}</div>
                                                <div className={ui.cellSub}>{plan.billing_summary}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{plan.trial_summary}</Td>
                                    <Td>{frequencyLabel[plan.frequency] || plan.frequency}</Td>
                                    <Td>{plan.cycle}</Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!plan.is_enabled}
                                            onToggle={() => {
                                                if (canEdit) {
                                                    router.post(`/admin/subscription-plans/${plan.id}/toggle`, {}, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/subscription-plans/${plan.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={assigned > 0
                                                ? `This subscription plan is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please remove the plan from these products before deleting it.`
                                                : `Delete “${plan.name}”?`}
                                            items={plan.actions?.duplicate !== false ? [{
                                                key: 'duplicate',
                                                label: 'Duplicate',
                                                icon: 'copy',
                                                onClick: () => router.post(`/admin/subscription-plans/${plan.id}/duplicate`),
                                            }] : []}
                                            onDelete={() => {
                                                if (assigned > 0) {
                                                    return;
                                                }
                                                router.delete(`/admin/subscription-plans/${plan.id}`, { preserveScroll: true });
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
