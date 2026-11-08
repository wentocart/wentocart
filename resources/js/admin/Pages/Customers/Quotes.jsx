import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    useListSelection,
} from '../../ui';

const FILTER_KEYS = ['id_min', 'id_max', 'number', 'customer', 'status', 'total_min', 'total_max', 'sort', 'dir'];
const STATUS_TONE = { requested: 'warning', accepted: 'success', declined: 'danger', converted: 'primary' };

function listQuery(filters) {
    const query = {};
    FILTER_KEYS.forEach((key) => {
        const value = filters[key];
        if (value !== '' && value != null && value !== 'all') query[key] = value;
    });
    return query;
}
function tabs(counts) {
    return [
        { key: 'all', label: t('admin.common.all', 'All'), count: counts.all },
        { key: 'requested', label: t('admin.quotes.requested', 'Requested'), count: counts.requested },
        { key: 'accepted', label: t('admin.quotes.accepted', 'Accepted'), count: counts.accepted },
        { key: 'declined', label: t('admin.quotes.declined', 'Declined'), count: counts.declined },
        { key: 'converted', label: t('admin.quotes.converted', 'Converted'), count: counts.converted },
    ];
}

function statusLabel(status) {
    return t(`admin.quotes.${status}`, status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown');
}

export default function Quotes({ quotes, filters = {}, counts = {} }) {
    const rows = quotes?.data || [];
    const query = useMemo(() => listQuery(filters), [filters]);
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (payload[key] === '' || payload[key] == null || payload[key] === 'all') delete payload[key];
        });
        router.get('/admin/quotes', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = (filters.sort || 'id') === column && (filters.dir || 'desc') === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, filters.sort, filters.dir]);

    const bulk = (action) => {
        if (!selection.selectedIds.length || bulkBusy) return;
        setBulkBusy(true);
        router.post('/admin/quotes/bulk', { action, ids: selection.selectedIds }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.quotes.title', 'Quotes')}
                total={quotes?.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.nav.quotes', 'Quotes') },
                ]}
            />
            <StatusTabs tabs={tabs(counts)} value={filters.status || 'all'} onChange={(status) => apply({ status })} />
            <ListPanel
                footer={<ListFooter pagination={quotes} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            { key: 'accept', label: t('admin.quotes.accept', 'Accept'), icon: 'check', onClick: () => bulk('accept') },
                            { key: 'decline', label: t('admin.quotes.decline', 'Decline'), icon: 'ban', onClick: () => bulk('decline') },
                            { key: 'delete', label: t('admin.common.delete', 'Delete'), icon: 'trash', tone: 'danger', confirm: t('admin.quotes.delete_selected', 'Delete selected quotes?'), onClick: () => bulk('delete') },
                        ]}
                    />
                )}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check><SelectionCheckbox checked={selection.allSelected} indeterminate={selection.someSelected} onChange={selection.toggleAll} ariaLabel={t('admin.common.select_all', 'Select all on this page')} /></Th>
                            <SortHeader column="id" label="#" sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <SortHeader column="number" label={t('admin.common.number', 'Number')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.common.customer', 'Customer')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.common.status', 'Status')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <SortHeader column="total" label={t('admin.common.total', 'Total')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.common.created', 'Created')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilter}><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={filters.id_min || ''} maxValue={filters.id_max || ''} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="number" value={filters.number || ''} placeholder={t('admin.common.number', 'Number')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="customer" value={filters.customer || ''} placeholder={t('admin.common.customer', 'Customer')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter type="select" name="status" value={filters.status === 'all' ? '' : (filters.status || '')} placeholder={t('admin.common.all', 'All')} options={[
                                    { value: 'requested', label: t('admin.quotes.requested', 'Requested') },
                                    { value: 'accepted', label: t('admin.quotes.accepted', 'Accepted') },
                                    { value: 'declined', label: t('admin.quotes.declined', 'Declined') },
                                    { value: 'converted', label: t('admin.quotes.converted', 'Converted') },
                                ]} onApply={applyColumn} compact />
                            </Th>
                            <Th className={ui.thFilter}><ColumnRangeFilter minName="total_min" maxName="total_max" minValue={filters.total_min || ''} maxValue={filters.total_max || ''} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter} />
                            <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/quotes" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.quotes.empty', 'No quotes yet. Customers can request one from checkout.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check><SelectionCheckbox checked={selection.isSelected(row.id)} onChange={() => selection.toggle(row.id)} ariaLabel={`${t('admin.common.select', 'Select')} ${row.number}`} /></Td>
                                <Td><span className={ui.metaK}>#{row.id}</span></Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <span className={ui.cellStrong}>{row.number}</span>
                                        <span className={ui.cellSub}>{row.items?.length || 0} {t('admin.common.items', 'items')}</span>
                                    </div>
                                </Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <span>{row.customer?.name || row.customer?.email || '—'}</span>
                                        {row.customer?.name && row.customer?.email ? <span className={ui.cellSub}>{row.customer.email}</span> : null}
                                    </div>
                                </Td>
                                <Td col="status"><Badge tone={STATUS_TONE[row.status] || 'neutral'}>{statusLabel(row.status)}</Badge></Td>
                                <Td>{row.currency_code ? `${row.currency_code} ` : ''}{Number(row.total || 0).toFixed(2)}</Td>
                                <Td>{row.created_at ? new Date(row.created_at).toLocaleDateString() : '—'}</Td>
                                <Td actions>
                                    <RowActions
                                        items={[
                                            row.status === 'requested' ? { key: 'accept', label: t('admin.quotes.accept', 'Accept'), icon: 'check', onClick: () => router.post(`/admin/quotes/${row.id}/accept`, {}, { preserveScroll: true }) } : null,
                                            ['requested', 'accepted'].includes(row.status) ? { key: 'decline', label: t('admin.quotes.decline', 'Decline'), icon: 'ban', onClick: () => router.post(`/admin/quotes/${row.id}/decline`, {}, { preserveScroll: true }) } : null,
                                            row.status === 'accepted' ? { key: 'convert', label: t('admin.quotes.convert', 'Load into cart'), icon: 'cart', onClick: () => router.post(`/admin/quotes/${row.id}/convert`, {}, { preserveScroll: true }) } : null,
                                        ].filter(Boolean)}
                                        confirmMessage={t('admin.quotes.delete_quote', 'Delete this quote?')}
                                        onDelete={() => router.delete(`/admin/quotes/${row.id}`, { preserveScroll: true })}
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
