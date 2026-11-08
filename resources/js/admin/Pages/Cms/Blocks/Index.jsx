import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    BulkBar,
    BulkDelete,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterBar,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
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
} from '../../../ui';

function pageTabs() {
    return [
    { key: 'all', label: t('admin.common.all', 'All') },
    { key: 'active', label: t('admin.common.active', 'Active') },
    { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
];
}

export default function Index({ blocks, counts = {}, filters = {}, locations = {} }) {
    const rows = blocks.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        location: filters.location || 'all',
        q: filters.q || '',
        title: filters.title || '',
        code: filters.code || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        position_min: filters.position_min || '',
        position_max: filters.position_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir || 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/blocks', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.sort, query.dir]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));
    const locationOptions = Object.entries(locations).map(([value, label]) => ({ value, label }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.blocks.blocks', 'Blocks'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.blocks.blocks', 'Blocks')}
                total={blocks.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/cms', label: t('admin.blocks.design', 'Design') }, { label: t('admin.blocks.blocks', 'Blocks') }]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" onClick={() => router.get('/admin/blocks')}>
                            {t('admin.common.reset_filters', 'Reset filters')}
                        </Button>
                        <Button icon="plus" href="/admin/blocks/create">
                            {t('admin.blocks.add_block', 'Add block')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/blocks"
                            importUrl="/admin/blocks/import"
                            importSampleUrl="/admin/blocks/import/sample"
                            exportUrl="/admin/blocks/export"
                            query={query}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.blocks.search_by_title_code_or_content', 'Search by title, code, or content…')}
                        onSearchSubmit={(q) => apply({ q })}
                    >
                        <Select
                            value={query.location}
                            onChange={(e) => apply({ location: e.target.value })}
                        >
                            <option value="all">{t('admin.blocks.all_locations', 'All locations')}</option>
                            {locationOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </Select>
                    </FilterBar>
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.blocks.delete_selected_blocks_the_storefront_will_stop_', 'Delete selected blocks? The storefront will stop receiving them.')}
                            onDelete={(ids) => {
                                router.post('/admin/blocks/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={blocks} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.blocks.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="title" label={t('admin.blocks.title', 'Title')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.blocks.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="location" label={t('admin.blocks.location', 'Location')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="position" label={t('admin.blocks.position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.blocks.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.blocks.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th className={ui.thFilterId}><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact /></Th>
                            <Th>
                                <ColumnFilter name="title" value={query.title} placeholder={t('admin.blocks.enter_title', 'Enter title')} onApply={applyColumn} />
                            </Th>
                            <Th><ColumnFilter name="code" value={query.code} placeholder={t('admin.blocks.code', 'Code')} onApply={applyColumn} /></Th>
                            <Th><ColumnFilter name="location" value={query.location === 'all' ? '' : query.location} type="select" placeholder={t('admin.blocks.all_locations', 'All locations')} options={locationOptions} onApply={applyColumn} /></Th>
                            <Th><ColumnRangeFilter minName="position_min" maxName="position_max" minValue={query.position_min} maxValue={query.position_max} onApply={applyColumn} /></Th>
                            <Th><ColumnFilter name="status" value={query.status === 'all' ? '' : query.status} type="select" placeholder={t('admin.common.all', 'All')} options={[
                                { value: 'active', label: t('admin.common.active', 'Active') },
                                { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                            ]} onApply={applyColumn} compact /></Th>
                            <Th actions><FilterReset query={query} href="/admin/blocks" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.blocks.no_blocks_yet_add_a_home_header_or_footer_slot_f', 'No blocks yet. Add a home, header, or footer slot for the theme to render.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`Select ${row.title}`}
                                    />
                                </Td>
                                <Td col="id">{row.id}</Td>
                                <Td>
                                    <div className={ui.row}>
                                        <span className={ui.cellIcon}><Icon name="file" /></span>
                                        <div className={ui.cellStrong}>{row.title}</div>
                                    </div>
                                </Td>
                                <Td><span className={ui.cellSub}>{row.code}</span></Td>
                                <Td>{row.location_label}</Td>
                                <Td>{row.position}</Td>
                                <Td>
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/blocks/${row.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/blocks/${row.id}/edit`}
                                        canEdit
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={`Delete “${row.title}”?`}
                                        onDelete={() => router.delete(`/admin/blocks/${row.id}`, { preserveScroll: true })}
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
