import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    BulkActions,
    catalogBulkActionItems,
    Badge,
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

export default function Index({ methods, counts = {}, filters = {} }) {
    const rows = methods.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        driver: filters.driver || '',
        description: filters.description || '',
        rules: filters.rules || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'name',
        dir: filters.dir === 'desc' ? 'desc' : 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/payments', cleanQuery({ ...query, ...next }), {
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

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (ids.length) {
            window.location.href = adminUrl(`/admin/payments/export?ids=${encodeURIComponent(ids.join(','))}`);
        }
    }, [selection.selectedIds]);

    const tabItems = pageTabs();

    const kpis = statusCountKpis(counts, {
        total: t('admin.payments.all_methods', 'All methods'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.payments.payment_methods', 'Payment methods')}
                total={methods.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.payments.payment_methods', 'Payment methods') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button icon="plus" href="/admin/payments/create">
                            {t('admin.common.add_new', 'Add new')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/payments"
                            importUrl="/admin/payments/import"
                            importSampleUrl="/admin/payments/import/sample"
                            exportUrl="/admin/payments/export"
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
                        items={[
                            ...catalogBulkActionItems({
                                onActive: () => runBulk('/admin/payments/bulk-status', { enabled: 1 }),
                                onDeactivate: () => runBulk('/admin/payments/bulk-status', { enabled: 0 }),
                                onDuplicate: () => runBulk('/admin/payments/bulk-duplicate'),
                                onDelete: () => runBulk('/admin/payments/bulk-delete'),
                                deleteConfirm: t('admin.payments.delete_selected_payment_methods', 'Delete selected payment methods?'),
                            }),
                            {
                                key: 'export',
                                label: t('admin.common.export_selected', 'Export selected'),
                                icon: 'download',
                                onClick: exportSelected,
                            },
                        ]}
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
                                    ariaLabel={t('admin.payments.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.payments.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="driver" label={t('admin.payments.driver', 'Gateway / Driver')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="description" label={t('admin.payments.description', 'Description')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.payments.rules', 'Rules')}</Th>
                            <SortHeader col="status" column="is_enabled" label={t('admin.payments.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.payments.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="driver" value={query.driver} placeholder={t('admin.payments.enter_driver', 'Filter gateway…')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="description" value={query.description} placeholder={t('admin.payments.enter_description', 'Enter description')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="rules" value={query.rules} placeholder={t('admin.payments.enter_rules', 'Enter rules')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.payments.select_status', 'Select status')}
                                    options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/payments" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.payments.no_payment_methods_yet', 'No payment methods yet.')}</EmptyState>
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
                                                <Icon name="orders" />
                                            </span>
                                            <div>
                                                <div className={ui.cellStrong}>{method.name}</div>
                                                {method.extension && !method.extension.complete ? (
                                                    <Badge tone="warning" className="mt-1">
                                                        {t('admin.payments.keys_missing', 'Needs API keys')}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <Badge tone={method.driver === 'offline' ? 'neutral' : 'info'} className="capitalize">
                                            {method.driver || 'offline'}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <div className="max-w-xs truncate text-sm text-neutral-600">
                                            {method.description || '—'}
                                        </div>
                                    </Td>
                                    <Td>
                                        <span className="text-sm text-neutral-600">{method.rule_summary}</span>
                                    </Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!method.is_enabled}
                                            onToggle={() => {
                                                router.post(`/admin/payments/${method.id}/toggle`, {}, { preserveScroll: true });
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/payments/${method.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={`Delete “${method.name}”?`}
                                            onDelete={() => {
                                                router.delete(`/admin/payments/${method.id}`, { preserveScroll: true });
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
