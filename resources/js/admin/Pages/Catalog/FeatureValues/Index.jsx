import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
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

function HeaderActions({ feature, query }) {
    const createHref = feature?.id
        ? `/admin/features/values/create?feature_id=${feature.id}`
        : '/admin/features/values/create';

    return (
        <div className={ui.toolbar}>
            <Button variant="secondary" href="/admin/features">
                {t('admin.features.back_to_features', 'Back to features')}
            </Button>
            <Button icon="plus" href={createHref}>
                {t('admin.features.add_feature_value', 'Add Feature Value')}
            </Button>
            <ListSettings
                listUrl={feature?.id ? `/admin/features/${feature.id}/values` : '/admin/features/values'}
                importUrl="/admin/features/values/import"
                importSampleUrl="/admin/features/values/import/sample"
                exportUrl="/admin/features/values/export"
                query={query}
            />
        </div>
    );
}

export default function Index({ values, counts = {}, filters = {}, feature = null }) {
    const rows = values.data || [];

    const reorderEnabled = useMemo(() => {
        if (rows.length < 2) {
            return false;
        }
        const parentIds = new Set(rows.map((row) => Number(row.feature_id)));

        return parentIds.size === 1;
    }, [rows]);

    const { orderedRows, canReorder, rowProps, dragHandleProps } = useDragReorder(rows, {
        enabled: reorderEnabled,
        reorderUrl: '/admin/features/values/reorder',
    });

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        value: filters.value || '',
        feature: filters.feature || '',
        feature_id: filters.feature_id || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        position_min: filters.position_min || '',
        position_max: filters.position_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/features/values', cleanQuery({ ...query, ...next }), {
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

    const statusLabels = listStatusLabels();
    const kpis = statusCountKpis(counts, {
        total: t('admin.features.all_values', 'All values'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    const title = feature
        ? `${t('admin.features.values', 'Values')}: ${feature.name}`
        : t('admin.features.feature_values', 'Feature values');
    const crumbs = [
        { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
        { href: '/admin/features', label: t('admin.features.features', 'Features') },
        ...(feature
            ? [
                { href: `/admin/features/${feature.id}/edit`, label: feature.name },
                { label: t('admin.features.values', 'Values') },
            ]
            : [{ label: t('admin.features.feature_values', 'Feature values') }]),
    ];

    return (
        <Layout>
            <PageHeader
                title={title}
                total={values.total}
                crumbs={crumbs}
                actions={<HeaderActions feature={feature} query={query} />}
            />

            <KpiCards items={kpis} />

            <ListPanel footer={<ListFooter pagination={values} query={query} />}>
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="value" label={t('admin.features.value', 'Value')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            {!feature ? (
                                <SortHeader column="feature" label={t('admin.features.feature', 'Feature')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            ) : null}
                            <SortHeader col="status" column="is_enabled" label={t('admin.products.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="position" label={t('admin.common.position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
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
                                <ColumnFilter name="value" value={query.value} placeholder={t('admin.features.search_value', 'Search value')} onApply={applyColumn} />
                            </Th>
                            {!feature ? (
                                <Th className={ui.thFilter}>
                                    <ColumnFilter name="feature" value={query.feature} placeholder={t('admin.features.search_feature', 'Search feature')} onApply={applyColumn} />
                                </Th>
                            ) : null}
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
                                <FilterReset
                                    query={query}
                                    href={feature?.id ? `/admin/features/${feature.id}/values` : '/admin/features/values'}
                                    ignore={feature ? ['sort', 'dir', 'page', 'per_page', 'feature_id'] : undefined}
                                />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {orderedRows.length === 0 ? (
                            <EmptyState colSpan={feature ? 5 : 6}>
                                {feature
                                    ? t('admin.features.no_values_for', 'No values for “:name” yet. Use Add Feature Value to create one.').replace(':name', feature.name)
                                    : t('admin.features.no_feature_values_yet', 'No feature values yet. Use Add Feature Value to create one.')}
                            </EmptyState>
                        ) : orderedRows.map((row, index) => (
                            <Tr key={row.id} {...rowProps(index)}>
                                <Td col="id">{row.id}</Td>
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
                                {!feature ? <Td>{row.feature_name}</Td> : null}
                                <Td col="status">
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/features/values/${row.id}/toggle`, {}, { preserveScroll: true })}
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
                                        editHref={`/admin/features/values/${row.id}/edit`}
                                        confirmMessage={row.products_count > 0
                                            ? `This feature value is currently assigned to ${row.products_count} product${row.products_count === 1 ? '' : 's'}. Please remove it from those products before deleting.`
                                            : `${t('admin.common.delete', 'Delete')} “${row.value}”?`}
                                        onDelete={() => {
                                            if (row.products_count > 0) {
                                                return;
                                            }
                                            router.delete(`/admin/features/values/${row.id}`, { preserveScroll: true });
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
