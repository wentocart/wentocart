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
    ui,
    t,
    useListSelection,
} from '../../ui';

function customerName(deliverySlip) {
    if (deliverySlip.customer?.name) {
        return deliverySlip.customer.name;
    }
    const shipping = deliverySlip.shipping_address || {};
    const name = [shipping.first_name, shipping.last_name].filter(Boolean).join(' ');

    return name || deliverySlip.email || t('admin.orders.guest', 'Guest');
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

export default function Index({ deliverySlips, filters = {} }) {
    const rows = deliverySlips.data || [];
    const selection = useListSelection(rows);

    const query = useMemo(() => ({
        number: filters.number || '',
        customer: filters.customer || '',
        shipping_method: filters.shipping_method || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        date: filters.date || '',
        sort: filters.sort || 'id',
        dir: filters.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/delivery-slips', cleanQuery({ ...query, ...next, page: 1 }), {
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
            window.location.href = adminUrl(`/admin/delivery-slips/export?ids=${encodeURIComponent(ids)}`);
        }
    }, [selection.selectedIds]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.nav.delivery_slip', 'Delivery Slip')}
                total={deliverySlips.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.nav.delivery_slip', 'Delivery Slip') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <ListSettings listUrl="/admin/delivery-slips" exportUrl="/admin/delivery-slips/export" query={query} />
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
                footer={<ListFooter pagination={deliverySlips} query={query} />}
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
                            <SortHeader column="number" label={t('admin.nav.delivery_slip', 'Delivery Slip')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.orders.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="shipping_method" label={t('admin.orders.fulfillment', 'Shipping')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="shipping_method" value={query.shipping_method} placeholder={t('admin.orders.shipping_method', 'Shipping method')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="date" type="date" value={query.date} placeholder={t('admin.orders.date', 'Date')} onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/delivery-slips" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.delivery_slips.no_delivery_slips_yet', 'No delivery slips yet.')}</EmptyState>
                        ) : rows.map((deliverySlip) => {
                            const name = customerName(deliverySlip);
                            const created = deliverySlip.created_at ? new Date(deliverySlip.created_at) : null;

                            return (
                                <Tr key={deliverySlip.id}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(deliverySlip.id)}
                                            onChange={() => selection.toggle(deliverySlip.id)}
                                            ariaLabel={t('admin.common.select_row', 'Select row')}
                                        />
                                    </Td>
                                    <Td className="text-center tabular-nums text-neutral-500">{deliverySlip.id}</Td>
                                    <Td>
                                        <Link href={`/admin/delivery-slips/${deliverySlip.id}`} className={ui.btnLink}>
                                            #{deliverySlip.number}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={name} />
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{name}</div>
                                                <div className={ui.cellSub}>{deliverySlip.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        {deliverySlip.shipping_method?.name ? (
                                            <Link href={`/admin/orders/${deliverySlip.id}`} className={ui.btnLink}>
                                                {deliverySlip.shipping_method.name}
                                            </Link>
                                        ) : '—'}
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
                                                    label: t('admin.nav.delivery_slip', 'View Delivery Slip'),
                                                    icon: 'eye',
                                                    href: `/admin/delivery-slips/${deliverySlip.id}`,
                                                },
                                                {
                                                    key: 'download',
                                                    label: t('admin.invoices.download', 'Download'),
                                                    icon: 'download',
                                                    href: `/admin/delivery-slips/${deliverySlip.id}/download`,
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
