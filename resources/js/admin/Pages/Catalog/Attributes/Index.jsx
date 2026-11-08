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
    ui,
    t,
    useListSelection,
    useDragReorder,
    OrderCell,
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

const ATTRIBUTE_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.attributes.name', 'Name'), type: 'text', placeholder: t('admin.attributes.search_name', 'Search name') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    { name: 'position_min', label: t('admin.attributes.position_min', 'Position min'), type: 'number' },
    { name: 'position_max', label: t('admin.attributes.position_max', 'Position max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.attributes.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

function HeaderActions({ query, importLanguages, importDefaultLanguage }) {
    return (
        <div className={ui.toolbar}>
            <Button variant="secondary" href="/admin/attributes/values/create">
                {t('admin.attributes.add_attribute_value', 'Add Attribute Value')}
            </Button>
            <Button icon="plus" href="/admin/attributes/create">
                {t('admin.attributes.add_attribute', 'Add Attribute')}
            </Button>
            <ListSettings
                listUrl="/admin/attributes"
                importUrl="/admin/attributes/import"
                importSampleUrl="/admin/attributes/import/sample"
                exportUrl="/admin/attributes/export"
                query={query}
                exportFields={ATTRIBUTE_EXPORT_FIELDS}
                showImportCsvSettings
                importLanguages={importLanguages}
                importDefaultLanguage={importDefaultLanguage}
            />
        </div>
    );
}

export default function Index({ attributes, counts = {}, filters = {}, typeOptions = [] }) {
    const { i18n } = usePage().props;
    const rows = attributes.data || [];
    const { orderedRows, canReorder, rowProps, dragHandleProps } = useDragReorder(rows, {
        reorderUrl: '/admin/attributes/reorder',
    });
    const selection = useListSelection(orderedRows, {
        canSelect: (row) => row.products_count === 0,
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
        router.get('/admin/attributes', cleanQuery({ ...query, ...next }), {
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
        total: t('admin.attributes.all_attributes', 'All attributes'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.attributes.attributes', 'Attributes')}
                total={attributes.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.attributes.attributes', 'Attributes') }]}
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
                            onActive: () => runBulk('/admin/attributes/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/attributes/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/attributes/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/attributes/bulk-delete'),
                            deleteConfirm: t('admin.attributes.delete_selected', 'Delete selected attributes? Attributes assigned to products will be skipped.'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={attributes} query={query} />}
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
                            <SortHeader column="name" label={t('admin.attributes.attribute', 'Attribute')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="type" label={t('admin.attributes.type', 'Type')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="values_count" label={t('admin.attributes.values', 'Values')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.attributes.search_name', 'Search name')} onApply={applyColumn} />
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
                                <FilterReset query={query} href="/admin/attributes" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {orderedRows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.attributes.no_attributes_yet', 'No attributes yet. Use Add Attribute to create one.')}</EmptyState>
                        ) : orderedRows.map((attribute, index) => (
                            <Tr key={attribute.id} selected={selection.isSelected(attribute.id)} {...rowProps(index)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(attribute.id)}
                                        disabled={attribute.products_count > 0}
                                        onChange={() => selection.toggle(attribute.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${attribute.name}`}
                                    />
                                </Td>
                                <Td col="id">{attribute.id}</Td>
                                <Td>
                                    <div className={ui.row}>
                                        <span className={ui.cellIcon}>
                                            <Icon name="tag" />
                                        </span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{attribute.name}</div>
                                        </div>
                                    </div>
                                </Td>
                                <Td>{attribute.type_label}</Td>
                                <Td col="center">
                                    <Button variant="link" href={`/admin/attributes/values?attribute_id=${attribute.id}`}>
                                        {attribute.values_count}
                                    </Button>
                                </Td>
                                <Td col="status">
                                    <StatusToggle
                                        active={!!attribute.is_enabled}
                                        onToggle={() => router.post(`/admin/attributes/${attribute.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td>
                                    <OrderCell
                                        position={attribute.position}
                                        canReorder={canReorder}
                                        dragHandleProps={dragHandleProps(index, attribute.name)}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        viewHref={`/admin/attributes/values?attribute_id=${attribute.id}`}
                                        viewLabel={t('admin.attributes.view_values', 'View values')}
                                        editHref={`/admin/attributes/${attribute.id}/edit`}
                                        confirmMessage={attribute.products_count > 0
                                            ? (attribute.products_count === 1
                                                ? t('admin.attributes.delete_blocked_one', 'This attribute is currently assigned to 1 product. Please remove it from those products before deleting.')
                                                : t('admin.attributes.delete_blocked_many', 'This attribute is currently assigned to :count products. Please remove it from those products before deleting.').replace(':count', String(attribute.products_count)))
                                            : `${t('admin.common.delete', 'Delete')} “${attribute.name}”?`}
                                        items={[
                                            {
                                                key: 'duplicate',
                                                label: t('admin.common.duplicate', 'Duplicate'),
                                                icon: 'copy',
                                                onClick: () => router.post('/admin/attributes/bulk-duplicate', { ids: [attribute.id] }, { preserveScroll: true }),
                                            },
                                            {
                                                key: 'add-value',
                                                label: t('admin.attributes.add_value', 'Add value'),
                                                icon: 'plus',
                                                href: `/admin/attributes/values/create?attribute_id=${attribute.id}`,
                                            },
                                        ]}
                                        onDelete={() => {
                                            if (attribute.products_count > 0) {
                                                return;
                                            }
                                            router.delete(`/admin/attributes/${attribute.id}`, { preserveScroll: true });
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
