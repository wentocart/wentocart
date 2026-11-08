import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    Avatar,
    BulkActions,
    ColumnFilter,
    ColumnRangeFilter,
    Alert,
    EmptyState,
    FilterReset,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    money,
    ui,
    t,
    useListSelection,
} from '../../ui';

function customerName(invoice) {
    if (invoice.customer?.name) {
        return invoice.customer.name;
    }
    const billing = invoice.billing_address || {};
    const name = [billing.first_name, billing.last_name].filter(Boolean).join(' ');

    return name || invoice.email || t('admin.orders.guest', 'Guest');
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

export default function Index({ invoices, filters = {}, invoices_enabled = true }) {
    const rows = invoices.data || [];
    const selection = useListSelection(rows);

    const query = useMemo(() => ({
        number: filters.number || '',
        customer: filters.customer || '',
        payment_method: filters.payment_method || '',
        total_min: filters.total_min || filters.total || '',
        total_max: filters.total_max || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        date: filters.date || '',
        sort: filters.sort || 'id',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/invoices', cleanQuery({ ...query, ...next, page: 1 }), {
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

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || []).join(',');
        if (ids) {
            window.location.href = adminUrl(`/admin/invoices/export?ids=${encodeURIComponent(ids)}`);
        }
    }, [selection.selectedIds]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.invoices.invoices', 'Invoices')}
                total={invoices.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.nav.invoice', 'Invoice') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <ListSettings listUrl="/admin/invoices" exportUrl="/admin/invoices/export" query={query} />
                    </div>
                )}
            />

            {!invoices_enabled ? (
                <Alert type="warning" className="mb-4">
                    {t('admin.invoices.turned_off', 'Invoices are turned off. Turn them on under Configure → Orders → Invoices. Invoice links no longer open a 404.')}
                    {' '}
                    <Link href="/admin/order-settings?tab=invoices" className="font-semibold underline">
                        {t('admin.invoices.open_settings', 'Open invoice settings')}
                    </Link>
                </Alert>
            ) : null}

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        items={[
                            {
                                key: 'export',
                                label: t('admin.common.export_selected', 'Export selected'),
                                icon: 'download',
                                onClick: exportSelected,
                            },
                        ]}
                    />
                )}
                footer={<ListFooter pagination={invoices} query={query} />}
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
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="number" label={t('admin.invoices.invoice', 'Invoice')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.orders.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="payment_method" label={t('admin.orders.payment', 'Payment')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="total" label={t('admin.orders.total', 'Total')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.orders.date', 'Date')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="number" value={query.number} placeholder="#" onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="customer" value={query.customer} placeholder={t('admin.customers.name', 'Name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="payment_method" value={query.payment_method} placeholder={t('admin.orders.method', 'Method')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="total_min"
                                    maxName="total_max"
                                    minValue={query.total_min}
                                    maxValue={query.total_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="date" type="date" value={query.date} placeholder={t('admin.orders.date', 'Date')} onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/invoices" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>
                                {invoices_enabled
                                    ? t('admin.invoices.no_paid_invoices', 'No paid invoices yet.')
                                    : t('admin.invoices.turned_off_empty', 'Invoices are turned off, so this list stays empty.')}
                            </EmptyState>
                        ) : rows.map((invoice) => {
                            const name = customerName(invoice);
                            const created = invoice.created_at ? new Date(invoice.created_at) : null;

                            return (
                                <Tr key={invoice.id}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(invoice.id)}
                                            onChange={() => selection.toggle(invoice.id)}
                                            ariaLabel={t('admin.common.select_row', 'Select row')}
                                        />
                                    </Td>
                                    <Td className="text-center tabular-nums text-neutral-500">{invoice.id}</Td>
                                    <Td>
                                        <Link href={`/admin/invoices/${invoice.id}`} className={ui.btnLink}>
                                            #{invoice.number}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={name} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{name}</div>
                                                <div className={ui.cellSub}>{invoice.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <Link href={`/admin/orders/${invoice.id}`} className={ui.btnLink}>
                                            {invoice.payment_method?.name || t('admin.orders.offline', 'Offline')}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div className={ui.cellStrong}>{money(invoice.grand_total, invoice.currency_code)}</div>
                                    </Td>
                                    <Td>
                                        {created
                                            ? created.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                                            : '—'}
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            canEdit={false}
                                            canDelete={false}
                                            items={[
                                                {
                                                    key: 'view',
                                                    label: t('admin.invoices.view_invoice', 'View Invoice'),
                                                    icon: 'eye',
                                                    href: `/admin/invoices/${invoice.id}`,
                                                },
                                                {
                                                    key: 'download',
                                                    label: t('admin.invoices.download', 'Download'),
                                                    icon: 'download',
                                                    href: `/admin/invoices/${invoice.id}/download`,
                                                    native: true,
                                                },
                                            ]}
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
