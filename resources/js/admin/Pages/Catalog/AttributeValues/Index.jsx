import { router } from '@inertiajs/react';
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
    OrderCell,
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
    useDragReorder,
    useListSelection,
} from '../../../ui';

function HeaderActions({ attribute, query }) {
    const createHref = attribute?.id
        ? `/admin/attributes/values/create?attribute_id=${attribute.id}`
        : '/admin/attributes/values/create';

    return (
        <div className={ui.toolbar}>
            <Button variant="secondary" href="/admin/attributes">
                {t('admin.attributes.back_to_attributes', 'Back to attributes')}
            </Button>
            <Button icon="plus" href={createHref}>
                {t('admin.attributes.add_attribute_value', 'Add Attribute Value')}
            </Button>
            <ListSettings
                listUrl={attribute?.id ? `/admin/attributes/values?attribute_id=${attribute.id}` : '/admin/attributes/values'}
                importUrl="/admin/attributes/values/import"
                importSampleUrl="/admin/attributes/values/import/sample"
                exportUrl="/admin/attributes/values/export"
                query={query}
            />
        </div>
    );
}

export default function Index({ values, counts = {}, filters = {}, attribute = null }) {
    const rows = values.data || [];

    const reorderEnabled = useMemo(() => {
        if (rows.length < 2) {
            return false;
        }
        const parentIds = new Set(rows.map((row) => Number(row.attribute_id)));

        return parentIds.size === 1;
    }, [rows]);

    const { orderedRows, canReorder, rowProps, dragHandleProps } = useDragReorder(rows, {
        enabled: reorderEnabled,
        reorderUrl: '/admin/attributes/values/reorder',
    });

    const selection = useListSelection(orderedRows);
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        value: filters.value || '',
        attribute_id: filters.attribute_id || '',
        position_min: filters.position_min || '',
        position_max: filters.position_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/attributes/values', payload, { preserveState: true, preserveScroll: true, replace: true });
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

    const bulkItems = catalogBulkActionItems({
        onActive: () => runBulk('/admin/attributes/values/bulk-status', { enabled: 1 }),
        onDeactivate: () => runBulk('/admin/attributes/values/bulk-status', { enabled: 0 }),
        onDuplicate: () => {},
        onDelete: () => runBulk('/admin/attributes/values/bulk-delete'),
        deleteConfirm: t('admin.attribute_values.delete_selected', 'Delete selected attribute values? Values assigned to products will be skipped.'),
    }).filter((item) => item.key !== 'duplicate');

    const statusLabels = listStatusLabels();
    const kpis = statusCountKpis(counts, {
        total: t('admin.attribute_values.all_values', 'All values'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    const title = attribute
        ? `${t('admin.attribute_values.values', 'Values')}: ${attribute.name}`
        : t('admin.attribute_values.attribute_values', 'Attribute values');
    const crumbs = [
        { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
        { href: '/admin/attributes', label: t('admin.attributes.attributes', 'Attributes') },
        ...(attribute
            ? [
                { href: `/admin/attributes/${attribute.id}/edit`, label: attribute.name },
                { label: t('admin.attribute_values.values', 'Values') },
            ]
            : [{ label: t('admin.attribute_values.attribute_values', 'Attribute values') }]),
    ];

    const colSpan = attribute ? 5 : 6;

    return (
        <Layout>
            <PageHeader
                title={title}
                total={values.total}
                crumbs={crumbs}
                actions={<HeaderActions attribute={attribute} query={query} />}
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
                footer={<ListFooter pagination={values} query={query} />}
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
                            <SortHeader column="value" label={t('admin.attribute_values.value', 'Value')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            {!attribute ? (
                                <SortHeader column="attribute" label={t('admin.attributes.attribute', 'Attribute')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            ) : null}
                            <SortHeader col="status" column="is_enabled" label={t('admin.products.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="position" label={t('admin.common.position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th>
                                <ColumnFilter name="value" value={query.value} placeholder={t('admin.attribute_values.enter_value', 'Enter value')} onApply={applyColumn} />
                            </Th>
                            {!attribute ? <Th /> : null}
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.select_status', 'Select status')}
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
                                <FilterReset
                                    query={query}
                                    href={attribute?.id ? `/admin/attributes/values?attribute_id=${attribute.id}` : '/admin/attributes/values'}
                                    ignore={attribute ? ['sort', 'dir', 'page', 'per_page', 'attribute_id'] : undefined}
                                />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {orderedRows.length === 0 ? (
                            <EmptyState colSpan={colSpan}>
                                {attribute
                                    ? t('admin.attribute_values.no_values_for', 'No values for “:name” yet. Use Add Attribute Value to create one.').replace(':name', attribute.name)
                                    : t('admin.attribute_values.no_attribute_values_yet', 'No attribute values yet. Use Add Attribute Value to create one.')}
                            </EmptyState>
                        ) : orderedRows.map((row, index) => (
                            <Tr key={row.id} {...rowProps(index)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${row.value}`}
                                    />
                                </Td>
                                <Td>
                                    <div className={ui.row}>
                                        <span className={ui.cellIcon}>
                                            <Icon name="tag" />
                                        </span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.value}</div>
                                        </div>
                                    </div>
                                </Td>
                                {!attribute ? <Td>{row.attribute_name}</Td> : null}
                                <Td col="status">
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/attributes/values/${row.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td>
                                    <OrderCell
                                        position={row.position}
                                        canReorder={canReorder}
                                        dragHandleProps={dragHandleProps(index, row.value)}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/attributes/values/${row.id}/edit`}
                                        confirmMessage={row.products_count > 0
                                            ? (row.products_count === 1
                                                ? t('admin.attribute_values.delete_blocked_one', 'This attribute value is currently assigned to 1 product. Please remove it from those products before deleting.')
                                                : t('admin.attribute_values.delete_blocked_many', 'This attribute value is currently assigned to :count products. Please remove it from those products before deleting.').replace(':count', String(row.products_count)))
                                            : `${t('admin.common.delete', 'Delete')} “${row.value}”?`}
                                        onDelete={() => {
                                            if (row.products_count > 0) {
                                                return;
                                            }
                                            router.delete(`/admin/attributes/values/${row.id}`, { preserveScroll: true });
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
