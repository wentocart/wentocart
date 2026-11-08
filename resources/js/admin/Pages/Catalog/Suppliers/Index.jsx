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

const SUPPLIER_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.suppliers.name', 'Name'), type: 'text', placeholder: t('admin.suppliers.search_name', 'Search name') },
    { name: 'contact', label: t('admin.suppliers.contact', 'Contact'), type: 'text', placeholder: t('admin.suppliers.search_contact', 'Search email/phone') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.suppliers.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

export default function Index({ suppliers, counts = {}, filters = {}, countryOptions = [] }) {
    const { i18n } = usePage().props;
    const rows = suppliers.data || [];
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
        router.get('/admin/suppliers', cleanQuery({ ...query, ...next }), {
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
        total: t('admin.suppliers.suppliers', 'Suppliers'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.suppliers.suppliers', 'Suppliers')}
                total={suppliers.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/products', label: t('admin.suppliers.catalog', 'Catalog') }, { label: t('admin.suppliers.suppliers', 'Suppliers') }]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button icon="plus" href="/admin/suppliers/create">
                            {t('admin.suppliers.add_supplier', 'Add supplier')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/suppliers"
                            importUrl="/admin/suppliers/import"
                            importSampleUrl="/admin/suppliers/import/sample"
                            exportUrl="/admin/suppliers/export"
                            query={query}
                            exportFields={SUPPLIER_EXPORT_FIELDS}
                            showImportCsvSettings
                            importLanguages={i18n?.languages || []}
                            importDefaultLanguage={i18n?.default || i18n?.locale || 'en'}
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
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/suppliers/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/suppliers/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/suppliers/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/suppliers/bulk-delete'),
                            deleteConfirm: t('admin.suppliers.delete_selected_suppliers_products_stay_in_the_c', 'Delete selected suppliers? Products stay in the catalog; they just lose this supplier.'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={suppliers} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.suppliers.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.suppliers.supplier', 'Supplier')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="contact" label={t('admin.suppliers.contact', 'Contact')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="country" label={t('admin.addresses.country', 'Country')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="products_count" label={t('admin.suppliers.products', 'Products')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.suppliers.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.suppliers.action', 'Action')}</Th>
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.suppliers.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="contact" value={query.contact} placeholder={t('admin.suppliers.search_contact', 'Search contact')} onApply={applyColumn} />
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
                                <FilterReset query={query} href="/admin/suppliers" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.suppliers.no_suppliers_yet_add_a_vendor_then_assign_it_to_', 'No suppliers yet. Add a vendor, then assign it from the product Details tab.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`Select ${row.name}`}
                                    />
                                </Td>
                                <Td col="id">{row.id}</Td>
                                <Td>
                                    <div className={ui.row}>
                                        <img
                                            src={catalogImageUrl(row.logo_url, 'sm')}
                                            alt=""
                                            className={ui.thumbLg}
                                        />
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.name}</div>
                                            {row.email ? <div className={ui.cellSub}>{row.email}</div> : null}
                                        </div>
                                    </div>
                                </Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">{row.contact || '—'}</span>
                                </Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">{row.country_label || '—'}</span>
                                </Td>
                                <Td col="center">{row.products_count}</Td>
                                <Td col="status">
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/suppliers/${row.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/suppliers/${row.id}/edit`}
                                        canEdit
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={`Delete “${row.name}”? Products are not deleted.`}
                                        items={row.actions?.duplicate !== false ? [{
                                            key: 'duplicate',
                                            label: t('admin.common.duplicate', 'Duplicate'),
                                            icon: 'copy',
                                            onClick: () => router.post(`/admin/suppliers/${row.id}/duplicate`, {}, { preserveScroll: true }),
                                        }] : []}
                                        onDelete={() => router.delete(`/admin/suppliers/${row.id}`, { preserveScroll: true })}
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
