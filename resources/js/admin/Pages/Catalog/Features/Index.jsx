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
    useDragReorder,
    OrderCell,
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

const FEATURE_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.features.name', 'Name'), type: 'text', placeholder: t('admin.features.search_name', 'Search name') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    { name: 'position_min', label: t('admin.features.position_min', 'Position min'), type: 'number' },
    { name: 'position_max', label: t('admin.features.position_max', 'Position max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.features.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

function HeaderActions({ query, importLanguages, importDefaultLanguage }) {
    return (
        <div className={ui.toolbar}>
            <Button variant="secondary" href="/admin/features/values/create">
                {t('admin.features.add_feature_value', 'Add Feature Value')}
            </Button>
            <Button icon="plus" href="/admin/features/create">
                {t('admin.features.add_feature', 'Add Feature')}
            </Button>
            <ListSettings
                listUrl="/admin/features"
                importUrl="/admin/features/import"
                importSampleUrl="/admin/features/import/sample"
                exportUrl="/admin/features/export"
                query={query}
                exportFields={FEATURE_EXPORT_FIELDS}
                showImportCsvSettings
                importLanguages={importLanguages}
                importDefaultLanguage={importDefaultLanguage}
            />
        </div>
    );
}

export default function Index({ features, counts = {}, filters = {}, typeOptions = [] }) {
    const { i18n } = usePage().props;
    const rows = features.data || [];
    const { orderedRows, canReorder, rowProps, dragHandleProps } = useDragReorder(rows, {
        reorderUrl: '/admin/features/reorder',
    });
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        type: filters.type || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        values_min: filters.values_min || '',
        values_max: filters.values_max || '',
        position_min: filters.position_min || '',
        position_max: filters.position_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/features', cleanQuery({ ...query, ...next }), {
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

    const selection = useListSelection(orderedRows);

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
        total: t('admin.features.all_features', 'All features'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.features.features', 'Features')}
                total={features.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.features.features', 'Features') }]}
                actions={(
                    <HeaderActions
                        query={query}
                        importLanguages={i18n?.languages || []}
                        importDefaultLanguage={i18n?.default || i18n?.locale || 'en'}
                    />
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/features/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/features/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/features/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/features/bulk-delete'),
                            deleteConfirm: t('admin.features.delete_selected', 'Delete selected features? Features assigned to products will be skipped.'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={features} query={query} />}
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
                            <SortHeader column="name" label={t('admin.features.feature', 'Feature')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="type" label={t('admin.features.type', 'Type')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="values_count" label={t('admin.features.values', 'Values')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.products.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="position" label={t('admin.common.position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.features.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="type"
                                    value={query.type}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={typeOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="values_min"
                                    maxName="values_max"
                                    minValue={query.values_min}
                                    maxValue={query.values_max}
                                    onApply={applyColumn}
                                    center
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
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="position_min"
                                    maxName="position_max"
                                    minValue={query.position_min}
                                    maxValue={query.position_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/features" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {orderedRows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.features.no_features_yet', 'No features yet. Use Add Feature to create one.')}</EmptyState>
                        ) : orderedRows.map((feature, index) => (
                            <Tr key={feature.id} selected={selection.isSelected(feature.id)} {...rowProps(index)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(feature.id)}
                                        onChange={() => selection.toggle(feature.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${feature.name}`}
                                    />
                                </Td>
                                <Td col="id">{feature.id}</Td>
                                <Td>
                                    <div className={ui.row}>
                                        <span className={ui.cellIcon}>
                                            <Icon name="tag" />
                                        </span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{feature.name}</div>
                                        </div>
                                    </div>
                                </Td>
                                <Td>{feature.type_label}</Td>
                                <Td col="center">
                                    <Button variant="link" href={`/admin/features/values?feature_id=${feature.id}`}>
                                        {feature.values_count}
                                    </Button>
                                </Td>
                                <Td col="status">
                                    <StatusToggle
                                        active={!!feature.is_enabled}
                                        onToggle={() => router.post(`/admin/features/${feature.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td>
                                    <OrderCell
                                        position={feature.position}
                                        canReorder={canReorder}
                                        dragHandleProps={dragHandleProps(index, feature.name)}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        viewHref={`/admin/features/values?feature_id=${feature.id}`}
                                        viewLabel={t('admin.features.view_values', 'View values')}
                                        editHref={`/admin/features/${feature.id}/edit`}
                                        canDelete={feature.actions?.delete !== false}
                                        confirmMessage={feature.products_count > 0
                                            ? `This feature is currently assigned to ${feature.products_count} product${feature.products_count === 1 ? '' : 's'}. Please remove it from those products before deleting.`
                                            : `${t('admin.common.delete', 'Delete')} “${feature.name}”?`}
                                        items={[
                                            {
                                                key: 'duplicate',
                                                label: t('admin.common.duplicate', 'Duplicate'),
                                                icon: 'copy',
                                                onClick: () => router.post('/admin/features/bulk-duplicate', { ids: [feature.id] }, { preserveScroll: true }),
                                            },
                                            {
                                                key: 'add-value',
                                                label: t('admin.features.add_value', 'Add value'),
                                                icon: 'plus',
                                                href: `/admin/features/values/create?feature_id=${feature.id}`,
                                            },
                                        ]}
                                        onDelete={() => {
                                            if (feature.products_count > 0) {
                                                return;
                                            }
                                            router.delete(`/admin/features/${feature.id}`, { preserveScroll: true });
                                        }}
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
