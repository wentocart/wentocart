import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    FormErrors,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    listStatusFilterOptions,
    ui,
    t,
    money,
    useListSelection,
    KpiCards,
    statusCountKpis,
} from '../../ui';

const tone = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    blocked: 'danger',
    enabled: 'success',
    disabled: 'danger',
    paid: 'success',
    available: 'info',
    processing: 'info',
    shipped: 'info',
    completed: 'success',
    cancelled: 'danger',
    open: 'warning',
    replied: 'success',
};

const BULK_CONFIG = {
    products: { status: true, delete: true, approve: true },
    categories: { status: true, delete: true },
    features: { status: true, delete: true },
    brands: { status: true, delete: true },
    suppliers: { status: true, delete: true },
    discounts: { status: true, delete: true },
    shipping: { status: true, delete: true },
    payments: { status: true, delete: true },
    'commission-rules': { status: true, delete: true },
    commissions: { status: true, delete: true },
    shops: { status: true },
    profiles: { approve: true },
    reviews: { delete: true, approve: true },
    requests: { approve: true },
    sellers: { approve: true },
};

const BADGE_KEYS = new Set(['status', 'enabled_label']);
const SORTABLE_KEYS = new Set([
    'id', 'name', 'shop', 'shop_name', 'sku', 'created_at', 'price', 'stock_qty', 'status', 'code', 'profile_name',
    'seller', 'customer', 'email', 'shop_slug', 'city', 'country_code', 'number', 'customer_email', 'grand_total',
    'earning_total', 'order_number', 'gross_amount', 'commission_source', 'commission_type', 'commission_rate',
    'commission_amount', 'net_amount', 'entry_type', 'direction', 'amount', 'reference', 'rate', 'live_rate',
    'action', 'value', 'value_label', 'type', 'scope', 'slug', 'account_name', 'enabled_label', 'default_label',
]);
const RANGE_FILTER_KEYS = new Set([
    'price', 'stock_qty', 'amount', 'rating', 'value', 'quantity', 'refund_total', 'grand_total', 'earning_total',
    'gross_amount', 'commission_amount', 'net_amount', 'commission_rate', 'rate', 'live_rate',
]);
const MONEY_KEYS = new Set([
    'price', 'amount', 'grand_total', 'earning_total', 'refund_total', 'gross_amount', 'commission_amount', 'net_amount',
]);
const DATE_FILTER_KEYS = new Set(['created_at']);

function statusFilterOptions(screen, columnKey) {
    if (columnKey === 'enabled_label' && screen === 'products') {
        return [
            { value: 'pending', label: t('admin.sellers.pending', 'Pending') },
            { value: 'approved', label: t('admin.sellers.approved', 'Approved') },
            { value: 'rejected', label: t('admin.sellers.rejected', 'Rejected') },
            { value: 'enabled', label: t('admin.common.active', 'Active') },
            { value: 'disabled', label: t('admin.common.inactive', 'Inactive') },
        ];
    }

    if (columnKey === 'enabled_label') {
        return [
            { value: 'enabled', label: t('admin.common.active', 'Active') },
            { value: 'disabled', label: t('admin.common.inactive', 'Inactive') },
        ];
    }

    if (columnKey === 'status') {
        if (['requests', 'sellers', 'profiles', 'shops'].includes(screen)) {
            return [
                { value: 'pending', label: t('admin.sellers.pending', 'Pending') },
                { value: 'approved', label: t('admin.sellers.approved', 'Approved') },
                { value: 'rejected', label: t('admin.sellers.rejected', 'Rejected') },
                { value: 'blocked', label: t('admin.sellers.blocked', 'Blocked') },
            ];
        }

        if (screen === 'orders') {
            return [
                { value: 'pending', label: t('admin.orders.pending', 'Pending') },
                { value: 'processing', label: t('admin.orders.processing', 'Processing') },
                { value: 'shipped', label: t('admin.orders.shipped', 'Shipped') },
                { value: 'completed', label: t('admin.orders.completed', 'Completed') },
                { value: 'cancelled', label: t('admin.orders.cancelled', 'Cancelled') },
            ];
        }

        if (screen === 'reviews') {
            return [
                { value: 'pending', label: t('admin.sellers.pending', 'Pending') },
                { value: 'approved', label: t('admin.sellers.approved', 'Approved') },
                { value: 'rejected', label: t('admin.sellers.rejected', 'Rejected') },
            ];
        }

        if (screen === 'earnings') {
            return [
                { value: 'pending', label: t('admin.sellers.pending', 'Pending') },
                { value: 'available', label: t('admin.multivendor.available', 'Available') },
                { value: 'paid', label: t('admin.multivendor.paid', 'Paid') },
                { value: 'refunded', label: t('admin.multivendor.refunded', 'Refunded') },
            ];
        }

        if (screen === 'returns') {
            return [
                { value: 'requested', label: t('admin.multivendor.requested', 'Requested') },
                { value: 'approved', label: t('admin.sellers.approved', 'Approved') },
                { value: 'received', label: t('admin.multivendor.received', 'Received') },
                { value: 'refunded', label: t('admin.multivendor.refunded', 'Refunded') },
                { value: 'rejected', label: t('admin.sellers.rejected', 'Rejected') },
            ];
        }

        if (screen === 'payouts') {
            return [
                { value: 'requested', label: t('admin.multivendor.requested', 'Requested') },
                { value: 'under_review', label: t('admin.multivendor.under_review', 'Under review') },
                { value: 'processing', label: t('admin.orders.processing', 'Processing') },
                { value: 'paid', label: t('admin.multivendor.paid', 'Paid') },
                { value: 'failed', label: t('admin.multivendor.failed', 'Failed') },
                { value: 'rejected', label: t('admin.sellers.rejected', 'Rejected') },
            ];
        }

        if (screen === 'commission-rules') {
            return [
                { value: 'product', label: t('admin.products.product', 'Product') },
                { value: 'seller', label: t('admin.multivendor.seller', 'Seller') },
                { value: 'category', label: t('admin.products.category', 'Category') },
                { value: 'global', label: t('admin.multivendor.global', 'Global') },
            ];
        }
    }

    return listStatusFilterOptions();
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

function cellValue(row, key) {
    const value = row?.[key];
    if (value == null || value === '') {
        return '—';
    }
    if (typeof value === 'object') {
        if (key === 'customer') {
            return value.name || value.email || '—';
        }
        return value.name || value.label || value.email || '—';
    }
    return value;
}

function columnCol(key) {
    if (key === 'id') {
        return 'id';
    }
    if (BADGE_KEYS.has(key)) {
        return 'status';
    }
    if (['price', 'stock_qty', 'rating', 'value', 'amount', 'quantity'].includes(key)) {
        return 'center';
    }
    return undefined;
}

function crumbs(title) {
    return [
        { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
        { href: '/admin/multivendor/dashboard', label: t('admin.nav.multivendor', 'Multivendor Management') },
        { label: title },
    ];
}

function buildBulkItems(screen, runBulk, records, selection) {
    const config = BULK_CONFIG[screen];
    if (!config) {
        return [];
    }

    const items = [];

    if (config.status) {
        const showShop = screen === 'shops';
        items.push(
            {
                key: 'enable',
                label: showShop
                    ? t('admin.multivendor.show_shop', 'Show shop')
                    : t('admin.products.active_selection', 'Active selection'),
                onClick: () => runBulk('enable'),
            },
            {
                key: 'disable',
                label: showShop
                    ? t('admin.multivendor.hide_shop', 'Hide shop')
                    : t('admin.products.deactivate_selection', 'Deactivate selection'),
                onClick: () => runBulk('disable'),
            },
        );
    }

    if (config.approve) {
        const selected = records.filter((row) => selection.isSelected(row.id));
        const canModerate = selected.length === 0 || selected.some((row) => row.can_moderate !== false);
        if (!canModerate) {
            return items.concat(config.delete ? [{
                key: 'delete',
                label: t('admin.products.delete_selection', 'Delete selection'),
                icon: 'trash',
                tone: 'danger',
                confirm: t('admin.common.delete_selected', 'Delete selected items?'),
                onClick: () => runBulk('delete'),
            }] : []);
        }
        items.push(
            {
                key: 'approve',
                label: t('admin.sellers.accept', 'Accept'),
                onClick: () => runBulk('approve'),
            },
            {
                key: 'reject',
                label: t('admin.sellers.reject', 'Reject'),
                onClick: () => {
                    const note = window.prompt(t('admin.multivendor.rejection_reason', 'Rejection reason'));
                    if (note === null) {
                        return;
                    }
                    if (!String(note).trim()) {
                        window.alert(t('admin.multivendor.rejection_required', 'A rejection reason is required.'));
                        return;
                    }
                    runBulk('reject', { note: String(note).trim() });
                },
            },
        );
    }

    if (config.delete) {
        items.push({
            key: 'delete',
            label: t('admin.products.delete_selection', 'Delete selection'),
            icon: 'trash',
            tone: 'danger',
            confirm: t('admin.common.delete_selected', 'Delete selected items?'),
            onClick: () => runBulk('delete'),
        });
    }

    return items;
}

function rowExtraItems(row) {
    const items = [];

    if (row.approveHref) {
        items.push({
            key: 'approve',
            label: t('admin.sellers.accept', 'Accept'),
            onClick: () => router.post(row.approveHref, { action: 'approve' }, { preserveScroll: true }),
        });
    }

    if (row.rejectHref) {
        items.push({
            key: 'reject',
            label: t('admin.sellers.reject', 'Reject'),
            onClick: () => {
                const note = window.prompt(t('admin.multivendor.rejection_reason', 'Rejection reason'));
                if (note === null) {
                    return;
                }
                if (!String(note).trim()) {
                    window.alert(t('admin.multivendor.rejection_required', 'A rejection reason is required.'));
                    return;
                }
                router.post(row.rejectHref, { action: 'reject', note: String(note).trim() }, { preserveScroll: true });
            },
        });
    }

    if (row.markPaidHref) {
        items.push({
            key: 'mark-paid',
            label: t('admin.multivendor.mark_paid', 'Mark paid'),
            onClick: () => router.post(row.markPaidHref, { status: 'paid' }, { preserveScroll: true }),
        });
    }

    return items;
}

export default function List({
    screen,
    title,
    path,
    rows,
    columns = [],
    filters = {},
    sellers,
    createHref,
    createLabel,
    exportHref,
    empty,
    hint,
}) {
    const { errors = {}, shop } = usePage().props;
    const currency = shop?.currency || 'USD';
    const records = rows?.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const displayColumns = useMemo(() => {
        if (columns.some((column) => column.key === 'id')) {
            return columns;
        }
        return [{ key: 'id', label: t('admin.common.id', 'ID') }, ...columns];
    }, [columns]);

    const query = useMemo(() => {
        const next = {
            status: filters.status || 'all',
            seller_id: filters.seller_id || 'all',
            sort: filters.sort || 'id',
            dir: filters.dir || 'desc',
            id_min: filters.id_min || '',
            id_max: filters.id_max || '',
            scope: filters.scope || '',
        };

        Object.entries(filters).forEach(([key, value]) => {
            if (key.endsWith('_min') || key.endsWith('_max')) {
                next[key] = value || '';
            }
        });

        displayColumns.forEach((column) => {
            if (column.key !== 'id' && filters[column.key] != null) {
                next[column.key] = filters[column.key];
            }
        });

        return next;
    }, [displayColumns, filters]);

    const sellerColumnKey = useMemo(
        () => (sellers ? displayColumns.find((column) => column.key === 'shop_name')?.key : null),
        [displayColumns, sellers],
    );

    const apply = useCallback((next) => {
        router.get(path, cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [path, query]);

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

    const selection = useListSelection(records, {
        canSelect: (row) => row?.selectable !== false,
    });

    const bulkItems = BULK_CONFIG[screen] || null;

    const runBulk = useCallback((action, extra = {}) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);

        if (!ids.length || bulkBusy || !bulkItems) {
            return;
        }

        setBulkBusy(true);
        router.post('/admin/multivendor/bulk', { screen, action, ids, ...extra }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, bulkItems, screen, selection]);

    const resolvedBulkItems = useMemo(
        () => buildBulkItems(screen, runBulk, records, selection),
        [runBulk, screen, records, selection],
    );

    const colSpan = displayColumns.length + 2;

    const renderHeaderCell = (column) => {
        if (SORTABLE_KEYS.has(column.key)) {
            return (
                <SortHeader
                    key={column.key}
                    column={column.key}
                    label={column.label}
                    sort={query.sort}
                    dir={query.dir}
                    onSort={sortBy}
                    col={columnCol(column.key)}
                />
            );
        }

        return (
            <Th key={column.key} col={columnCol(column.key)}>
                {column.label}
            </Th>
        );
    };

    const renderFilterCell = (column) => {
        if (column.key === 'id') {
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilterId}>
                    <ColumnRangeFilter
                        minName="id_min"
                        maxName="id_max"
                        minValue={query.id_min}
                        maxValue={query.id_max}
                        onApply={applyColumn}
                        compact
                    />
                </Th>
            );
        }

        if (BADGE_KEYS.has(column.key)) {
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilterStatus}>
                    <ColumnFilter
                        name="status"
                        value={query.status === 'all' ? '' : query.status}
                        type="select"
                        placeholder={t('admin.common.all', 'All')}
                        options={statusFilterOptions(screen, column.key)}
                        onApply={applyColumn}
                        compact
                    />
                </Th>
            );
        }

        if (column.key === 'scope' && screen === 'commission-rules') {
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilterStatus}>
                    <ColumnFilter
                        name="scope"
                        value={query.scope || ''}
                        type="select"
                        placeholder={t('admin.common.all', 'All')}
                        options={statusFilterOptions(screen, 'status')}
                        onApply={applyColumn}
                        compact
                    />
                </Th>
            );
        }

        if (DATE_FILTER_KEYS.has(column.key)) {
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilter}>
                    <ColumnFilter
                        name={column.key}
                        value={query[column.key] || ''}
                        type="date"
                        placeholder={column.label}
                        onApply={applyColumn}
                    />
                </Th>
            );
        }

        if (sellerColumnKey && column.key === sellerColumnKey) {
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilter}>
                    <Select
                        value={String(filters.seller_id || 'all')}
                        onChange={(e) => apply({ seller_id: e.target.value })}
                        aria-label={t('admin.multivendor.seller', 'Seller')}
                    >
                        <option value="all">{t('admin.multivendor.all_sellers', 'All sellers')}</option>
                        {sellers.map((seller) => (
                            <option key={seller.id} value={seller.id}>{seller.label}</option>
                        ))}
                    </Select>
                </Th>
            );
        }

        if (RANGE_FILTER_KEYS.has(column.key)) {
            const minName = `${column.key}_min`;
            const maxName = `${column.key}_max`;
            return (
                <Th key={`filter-${column.key}`} className={ui.thFilterCenter}>
                    <ColumnRangeFilter
                        minName={minName}
                        maxName={maxName}
                        minValue={query[minName] || ''}
                        maxValue={query[maxName] || ''}
                        onApply={applyColumn}
                        center
                    />
                </Th>
            );
        }

        return (
            <Th key={`filter-${column.key}`} className={ui.thFilter}>
                <ColumnFilter
                    name={column.key}
                    value={query[column.key] || ''}
                    placeholder={t('admin.common.search', 'Search')}
                    onApply={applyColumn}
                />
            </Th>
        );
    };

    const kpis = statusCountKpis({ all: rows?.total ?? 0 }, {
        total: title || t('admin.common.total', 'Total'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={title}
                total={rows?.total}
                crumbs={crumbs(title)}
                actions={(
                    <div className={ui.toolbar}>
                        {exportHref ? (
                            <Button variant="secondary" native href={exportHref}>{t('admin.common.export', 'Export')}</Button>
                        ) : null}
                        {createHref ? (
                            <Button icon="plus" href={createHref}>{createLabel || t('admin.common.add', 'Add')}</Button>
                        ) : null}
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            {hint ? <p className="mb-4 text-sm text-neutral-600">{hint}</p> : null}

            <FormErrors errors={errors} variant="growl" />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={resolvedBulkItems}
                    />
                )}
                footer={(
                    <ListFooter
                        pagination={rows}
                        query={query}
                    />
                )}
            >
                <Table>
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
                            {displayColumns.map((column) => renderHeaderCell(column))}
                            <Th actions>{t('admin.common.action', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilterCheck} />
                            {displayColumns.map((column) => renderFilterCell(column))}
                            <Th actions className={ui.thFilterActions}>
                                <FilterReset query={query} href={path} />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {records.length === 0 ? (
                            <EmptyState colSpan={colSpan}>{empty || t('admin.common.no_records', 'No records yet.')}</EmptyState>
                        ) : records.map((row) => {
                            const label = row.name || row.shop || row.shop_name || row.title || row.number || `#${row.id}`;

                            return (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={`${t('admin.common.select', 'Select')} ${label}`}
                                        />
                                    </Td>
                                    {displayColumns.map((column) => (
                                        <Td key={column.key} col={columnCol(column.key)}>
                                            {BADGE_KEYS.has(column.key) ? (
                                                <Badge tone={tone[String(row[column.key] || '').toLowerCase()] || 'neutral'}>
                                                    {row[column.key] || '—'}
                                                </Badge>
                                            ) : column.key === 'name' && row.href ? (
                                                <Link className={ui.cellStrong} href={row.href}>{cellValue(row, column.key)}</Link>
                                            ) : MONEY_KEYS.has(column.key) && row[column.key] != null && row[column.key] !== '' && row[column.key] !== '—' ? (
                                                money(row[column.key], row.currency_code || currency)
                                            ) : (
                                                cellValue(row, column.key)
                                            )}
                                        </Td>
                                    ))}
                                    <Td actions>
                                        <RowActions
                                            editHref={row.href || undefined}
                                            editLabel={row.action_label || t('admin.common.edit', 'Edit')}
                                            canEdit={!!row.href}
                                            canDelete={!!row.deleteHref}
                                            onDelete={row.deleteHref
                                                ? () => router.delete(row.deleteHref, { preserveScroll: true })
                                                : undefined}
                                            items={rowExtraItems(row)}
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
