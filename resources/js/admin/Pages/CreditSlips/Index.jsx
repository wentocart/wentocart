import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    Avatar,
    BulkActions,
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

function customerName(creditSlip) {
    if (creditSlip.customer?.name) {
        return creditSlip.customer.name;
    }
    const billing = creditSlip.billing_address || {};
    const name = [billing.first_name, billing.last_name].filter(Boolean).join(' ');

    return name || creditSlip.email || t('admin.orders.guest', 'Guest');
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

export default function Index({ creditSlips, filters = {} }) {
    const rows = creditSlips.data || [];
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
        router.get('/admin/credit-slips', cleanQuery({ ...query, ...next, page: 1 }), {
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
            window.location.href = adminUrl(`/admin/credit-slips/export?ids=${encodeURIComponent(ids)}`);
        }
    }, [selection.selectedIds]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.nav.credit_slip', 'Credit Slip')}
                total={creditSlips.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.nav.credit_slip', 'Credit Slip') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <ListSettings listUrl="/admin/credit-slips" exportUrl="/admin/credit-slips/export" query={query} />
                    </div>
                )}
            />

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
                footer={<ListFooter pagination={creditSlips} query={query} />}
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
                            <SortHeader column="number" label={t('admin.nav.credit_slip', 'Credit Slip')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <FilterReset query={query} href="/admin/credit-slips" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.credit_slips.no_credit_slips_yet', 'No credit slips yet.')}</EmptyState>
                        ) : rows.map((creditSlip) => {
                            const name = customerName(creditSlip);
                            const created = creditSlip.created_at ? new Date(creditSlip.created_at) : null;

                            return (
                                <Tr key={creditSlip.id}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(creditSlip.id)}
                                            onChange={() => selection.toggle(creditSlip.id)}
                                            ariaLabel={t('admin.common.select_row', 'Select row')}
                                        />
                                    </Td>
                                    <Td className="text-center tabular-nums text-neutral-500">{creditSlip.id}</Td>
                                    <Td>
                                        <Link href={`/admin/credit-slips/${creditSlip.id}`} className={ui.btnLink}>
                                            #{creditSlip.number}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={name} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{name}</div>
                                                <div className={ui.cellSub}>{creditSlip.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        <Link href={`/admin/orders/${creditSlip.id}`} className={ui.btnLink}>
                                            {creditSlip.payment_method?.name || t('admin.orders.offline', 'Offline')}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div className={ui.cellStrong}>{money(creditSlip.grand_total, creditSlip.currency_code)}</div>
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
                                                    label: t('admin.nav.credit_slip', 'View Credit Slip'),
                                                    icon: 'eye',
                                                    href: `/admin/credit-slips/${creditSlip.id}`,
                                                },
                                                {
                                                    key: 'download',
                                                    label: t('admin.invoices.download', 'Download'),
                                                    icon: 'download',
                                                    href: `/admin/credit-slips/${creditSlip.id}/download`,
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
