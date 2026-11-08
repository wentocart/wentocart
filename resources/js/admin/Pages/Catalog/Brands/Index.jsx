import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../../Layout';
import { catalogImageUrl } from '../../../lib/catalogMedia';
import {
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

const BRAND_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.brands.name', 'Name'), type: 'text', placeholder: t('admin.brands.search_name', 'Search name') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.brands.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

export default function Index({ brands, counts = {}, filters = {}, can = {}, countryOptions = [] }) {
    const { i18n } = usePage().props;
    const rows = brands.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        contact: filters.contact || '',
        country: filters.country || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        products_min: filters.products_min || '',
        products_max: filters.products_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/brands', cleanQuery({ ...query, ...next }), {
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
        total: t('admin.brands.all_brands', 'All brands'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.brands.brands', 'Brands')}
                total={brands.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.brands.brands', 'Brands') }]}
                actions={
                    <div className={ui.toolbar}>
                        {can.create !== false ? (
                            <Button icon="plus" href="/admin/brands/create">
                                {t('admin.brands.add_brand', 'Add brand')}
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/brands"
                            importUrl="/admin/brands/import"
                            importSampleUrl="/admin/brands/import/sample"
                            exportUrl="/admin/brands/export"
                            query={query}
                            exportFields={BRAND_EXPORT_FIELDS}
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
                            onActive: () => runBulk('/admin/brands/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/brands/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/brands/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/brands/bulk-delete'),
                            deleteConfirm: t('admin.brands.delete_selected', 'Delete selected brands? Brands assigned to products will be skipped.'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={brands} query={query} />}
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
                            <SortHeader column="name" label={t('admin.brands.brand_name', 'Brand name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="contact" label={t('admin.suppliers.contact', 'Contact')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="country" label={t('admin.addresses.country', 'Country')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="products_count" label={t('admin.brands.products', 'Products')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.brands.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="contact" value={query.contact} placeholder={t('admin.brands.search_contact', 'Search contact')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="country"
                                    value={query.country}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={countryOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="products_min"
                                    maxName="products_max"
                                    minValue={query.products_min}
                                    maxValue={query.products_max}
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
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/brands" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.brands.no_brands_yet', 'No brands yet.')}</EmptyState>
                        ) : rows.map((brand) => {
                            const canEdit = brand.actions?.edit !== false;
                            const canDelete = brand.actions?.delete !== false;
                            const assigned = Number(brand.products_count) || 0;

                            return (
                                <Tr key={brand.id} selected={selection.isSelected(brand.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(brand.id)}
                                                onChange={() => selection.toggle(brand.id)}
                                                ariaLabel={`${t('admin.common.select', 'Select')} ${brand.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{brand.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <img src={catalogImageUrl(brand.logo_url || brand.logo_path, 'sm')} alt="" className={ui.thumbLg} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{brand.name}</div>
                                                <div className={ui.cellSub}>{brand.slug || '—'}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <span className="text-sm text-neutral-700">{brand.contact || '—'}</span>
                                    </Td>
                                    <Td>
                                        <span className="text-sm text-neutral-700">{brand.country_label || '—'}</span>
                                    </Td>
                                    <Td col="center">{assigned}</Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!brand.is_enabled}
                                            onToggle={() => {
                                                if (canEdit) {
                                                    router.post(`/admin/brands/${brand.id}/toggle`, {}, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/brands/${brand.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={assigned > 0
                                                ? `This brand is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please reassign these products before deleting the brand.`
                                                : `${t('admin.common.delete', 'Delete')} “${brand.name}”?`}
                                            items={brand.actions?.duplicate !== false ? [{
                                                key: 'duplicate',
                                                label: t('admin.common.duplicate', 'Duplicate'),
                                                icon: 'copy',
                                                onClick: () => router.post(`/admin/brands/${brand.id}/duplicate`),
                                            }] : []}
                                            onDelete={() => {
                                                if (assigned > 0) {
                                                    return;
                                                }
                                                router.delete(`/admin/brands/${brand.id}`, { preserveScroll: true });
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
