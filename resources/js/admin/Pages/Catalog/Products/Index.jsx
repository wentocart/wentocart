import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from '../../Layout';
import { catalogImageUrl, catalogThumbError } from '../../../lib/catalogMedia';
import {
    Badge,
    Tooltip,
    BulkActions,
    catalogBulkActionItems,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FormErrors,
    FilterReset,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
    SortHeader,
    StatusToggle,
    listStatusFilterOptions,
    Table,
    THead,
    Th,
    Tr,
    Td,
    money,
    pickTranslation,
    ui,
    t,
    ModuleColumnHeaders,
    ModuleColumns,
    useListSelection,
    KpiCards,
} from '../../../ui';
import { productTypeOptions } from './productTypes';

function stockTone(qty) {
    return qty > 0 ? 'success' : 'danger';
}

function stockLabel(qty) {
    return qty > 0
        ? t('admin.products.in_stock', 'In Stock')
        : t('admin.products.out_of_stock', 'Out of Stock');
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

const PRODUCT_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.products.name', 'Name'), type: 'text', placeholder: t('admin.products.search_name', 'Search name') },
    { name: 'sku', label: t('admin.products.reference', 'Reference'), type: 'text', placeholder: t('admin.products.search_reference', 'Search reference') },
    { name: 'category', label: t('admin.products.category', 'Category'), type: 'text', placeholder: t('admin.products.search_category', 'Search category') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    { name: 'price_min', label: t('admin.products.price_min', 'Price min'), type: 'number' },
    { name: 'price_max', label: t('admin.products.price_max', 'Price max'), type: 'number' },
    { name: 'qty_min', label: t('admin.products.qty_min', 'Quantity min'), type: 'number' },
    { name: 'qty_max', label: t('admin.products.qty_max', 'Quantity max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.products.status', 'Status'),
        type: 'select',
        options: [
            ...listStatusFilterOptions(),
            { value: 'out_of_stock', label: t('admin.products.out_of_stock', 'Out of Stock') },
        ],
    },
];

export default function Index({ products, stats, filters = {}, moduleList = { columns: [] }, rowHighlight = {}, productDefaults = {} }) {
    const { shop, i18n, errors = {} } = usePage().props;
    const currency = shop?.currency || 'USD';
    const rows = products.data || [];
    const [typeModalOpen, setTypeModalOpen] = useState(false);
    const [productType, setProductType] = useState(productDefaults.default_type || '');
    const [typeError, setTypeError] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);
    const highlightOn = rowHighlight.enabled !== false;
    const highlightColor = /^#[0-9A-Fa-f]{6}$/.test(String(rowHighlight.color || ''))
        ? String(rowHighlight.color).toLowerCase()
        : '#ffece5';
    const highlightMs = Math.min(5000, Math.max(100, Number(rowHighlight.duration_ms) || 1100));
    const highlightStyle = highlightOn ? {
        '--admin-list-row-color': highlightColor,
        '--admin-list-row-duration': `${highlightMs}ms`,
    } : undefined;

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        sku: filters.sku || '',
        category: filters.category || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        price_min: filters.price_min || '',
        price_max: filters.price_max || '',
        qty_min: filters.qty_min || '',
        qty_max: filters.qty_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/products', cleanQuery({ ...query, ...next }), {
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

    const selection = useListSelection(rows);
    const colSpan = 9 + (moduleList.columns?.length || 0);

    const kpis = [
        { key: 'total', label: t('admin.products.total_products', 'Total Products'), value: stats ? stats.total.toLocaleString() : '…', trend: stats?.total_trend ?? 0 },
        { key: 'new', label: t('admin.products.new_products', 'New Products'), value: stats ? stats.new.toLocaleString() : '…', trend: stats?.new_trend ?? 0 },
        { key: 'active', label: t('admin.products.active_products', 'Active Products'), value: stats ? stats.active.toLocaleString() : '…', trend: stats?.active_trend ?? 0 },
        { key: 'oos', label: t('admin.products.out_of_stock', 'Out of Stock'), value: stats ? stats.out_of_stock.toLocaleString() : '…', trend: stats?.out_of_stock_trend ?? 0 },
    ];

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

    useEffect(() => {
        if (!typeModalOpen) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                setTypeModalOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [typeModalOpen]);

    const openTypeModal = () => {
        setProductType(productDefaults.default_type || 'simple');
        setTypeError(false);
        setTypeModalOpen(true);
    };

    const goToCreate = (e) => {
        e.preventDefault();
        if (!productType) {
            setTypeError(true);
            return;
        }
        setTypeModalOpen(false);
        router.get('/admin/products/create', { type: productType });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.products.products', 'Products')}
                total={products.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.products.products', 'Products') }]}
                actions={
                    <>
                        <Button icon="plus" type="button" onClick={openTypeModal}>
                            {t('admin.products.add_product', 'Add Product')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/products"
                            importUrl="/admin/products/import"
                            importSampleUrl="/admin/products/import/sample"
                            exportUrl="/admin/products/export"
                            query={query}
                            exportFields={PRODUCT_EXPORT_FIELDS}
                            showImportCsvSettings
                            importLanguages={i18n?.languages || []}
                            importDefaultLanguage={i18n?.default || i18n?.locale || 'en'}
                        />
                    </>
                }
            />

            <FormErrors errors={errors} variant="growl" />

            <KpiCards items={kpis} loading={!stats} />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/products/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/products/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/products/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/products/bulk-delete'),
                            deleteConfirm: t('admin.products.delete_selected', 'Delete selected products?'),
                        })}
                    />
                )}
                footer={<ListFooter pagination={products} query={query} />}
            >
                <Table className={highlightOn ? undefined : 'admin-list-highlight-off'} style={highlightStyle}>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={() => selection.toggleAll()}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.products.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="sku" label={t('admin.products.reference', 'Reference')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="category" label={t('admin.products.category', 'Category')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="price" label={t('admin.products.price_tax_excl', 'Price (tax excl.)')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="stock_qty" label={t('admin.products.quantity', 'Quantity')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.products.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <ModuleColumnHeaders columns={moduleList.columns} />
                            <Th actions>{t('admin.common.action', 'Actions')}</Th>
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.products.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="sku" value={query.sku} placeholder={t('admin.products.search_reference', 'Search reference')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="category" value={query.category} placeholder={t('admin.products.search_category', 'Search category')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="price_min"
                                    maxName="price_max"
                                    minValue={query.price_min}
                                    maxValue={query.price_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="qty_min"
                                    maxName="qty_max"
                                    minValue={query.qty_min}
                                    maxValue={query.qty_max}
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
                            {(moduleList.columns || []).map((col) => (
                                <Th key={col.key || col.id} className={ui.thFilter} />
                            ))}
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/products" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={colSpan}>{t('admin.products.no_products_yet', 'No products yet.')}</EmptyState>
                        ) : rows.map((product) => {
                            const name = pickTranslation(product.translations, i18n).name || t('admin.products.untitled', 'Untitled');
                            const qty = Number(product.stock_qty || 0);

                            return (
                                <Tr
                                    key={product.id}
                                    selected={selection.isSelected(product.id)}
                                >
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(product.id)}
                                            onChange={() => selection.toggle(product.id)}
                                            ariaLabel={`${t('admin.common.select', 'Select')} ${name}`}
                                        />
                                    </Td>
                                    <Td col="id">{product.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <img src={catalogImageUrl(product.cover_path, 'sm')} alt="" className={ui.thumbLg} onError={(event) => catalogThumbError(event, 'sm')} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{name}</div>
                                                <div className={ui.cellSub}>{product.type}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{product.sku || '—'}</Td>
                                    <Td>{product.category_name || '—'}</Td>
                                    <Td col="center">{money(product.price, currency)}</Td>
                                    <Td col="center">
                                        <Tooltip content={stockLabel(qty)}>
                                            <Badge
                                                tone={stockTone(qty)}
                                                dot={false}
                                                className="min-w-8 justify-center tabular-nums"
                                            >
                                                {qty}
                                            </Badge>
                                        </Tooltip>
                                    </Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!product.is_enabled}
                                            onToggle={() => router.post(`/admin/products/${product.id}/toggle`, {}, { preserveScroll: true })}
                                        />
                                    </Td>
                                    <ModuleColumns columns={moduleList.columns} values={product.module_values} />
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/products/${product.id}/edit`}
                                            confirmMessage={`${t('admin.common.delete', 'Delete')} “${name}”?`}
                                            items={[
                                                {
                                                    key: 'duplicate',
                                                    label: t('admin.products.duplicate', 'Duplicate'),
                                                    icon: 'copy',
                                                    onClick: () => router.post(`/admin/products/${product.id}/duplicate`, {}, { preserveScroll: true }),
                                                },
                                                product.preview_url
                                                    ? {
                                                        key: 'preview',
                                                        label: t('admin.products.preview', 'Preview'),
                                                        icon: 'eye',
                                                        href: product.preview_url,
                                                        native: true,
                                                        target: '_blank',
                                                    }
                                                    : null,
                                            ]}
                                            onDelete={() => router.delete(`/admin/products/${product.id}`, { preserveScroll: true })}
                                        />
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            </ListPanel>

            {typeModalOpen ? (
                <div className={ui.modalBackdrop} role="presentation" onClick={() => setTypeModalOpen(false)}>
                    <form
                        className={`${ui.modal} max-w-md`}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="product-type-title"
                        onClick={(e) => e.stopPropagation()}
                        onSubmit={goToCreate}
                    >
                        <div className={ui.modalHead}>
                            <h2 id="product-type-title" className={ui.modalTitle}>{t('admin.products.create_new_product', 'Create New Product')}</h2>
                            <Button type="button" variant="ghost" icon="x" aria-label={t('admin.products.close', 'Close')} onClick={() => setTypeModalOpen(false)} />
                        </div>
                        <div className="px-5 py-4 space-y-2">
                            <label className={ui.label} htmlFor="product-type">
                                {t('admin.products.type', 'Type')} <span className={ui.req}>*</span>
                            </label>
                            <Select
                                id="product-type"
                                wide
                                value={productType}
                                required
                                onChange={(e) => {
                                    setProductType(e.target.value);
                                    setTypeError(false);
                                }}
                            >
                                <option value="">{t('admin.products.select_type', 'Select type')}</option>
                                {productTypeOptions().map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </Select>
                            {typeError ? <span className={ui.fieldError}>{t('admin.products.select_a_product_type_to_continue', 'Select a product type to continue.')}</span> : null}
                        </div>
                        <div className="px-5 py-4 border-t border-neutral-100 flex justify-end">
                            <Button type="submit" icon="plus" disabled={!productType}>
                                {t('admin.products.new_product', 'New Product')}
                            </Button>
                        </div>
                    </form>
                </div>
            ) : null}
        </Layout>
    );
}
