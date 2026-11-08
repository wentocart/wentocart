import { Link, router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import { catalogImageUrl } from '../../lib/catalogMedia';
import {
    Alert,
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    EmptyState,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    PageHeader,
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

function statusTone(status) {
    return status === 'notified' ? 'success' : 'warning';
}

function formatStamp(value) {
    return value ? String(value).replace('T', ' ').slice(0, 16) : '—';
}

function customerName(row) {
    return row.customer?.name
        || [row.first_name, row.last_name].filter(Boolean).join(' ')
        || row.email
        || '—';
}

function customerEmail(row) {
    return row.customer?.email || row.email || '—';
}

export default function StockAlerts({ alerts, filters = {}, enabled = false }) {
    const rows = alerts.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);
    const query = useMemo(() => ({
        product: filters.product || '',
        customer: filters.customer || '',
        status: filters.status || 'all',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/stock-alerts', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const runBulk = useCallback((action) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post('/admin/stock-alerts/bulk', { action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, selection]);

    const resendOne = useCallback((id) => {
        router.post(`/admin/stock-alerts/${id}/resend`, {}, { preserveScroll: true });
    }, []);

    return (
        <Layout>
            <PageHeader
                title={t('admin.stock_alerts.title', 'Stock Alerts')}
                total={alerts.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.nav.catalog', 'Catalog') },
                    { label: t('admin.nav.stock_alerts', 'Stock Alerts') },
                ]}
            />

            {!enabled ? (
                <Alert type="warning" className="mb-4">
                    {t(
                        'admin.stock_alerts.disabled',
                        'Out of stock notification is disabled. Enable it under Configure → Product settings → Product Features.',
                    )}
                    {' '}
                    <Link className="underline font-medium" href="/admin/product-settings">
                        {t('admin.stock_alerts.open_settings', 'Open product settings')}
                    </Link>
                </Alert>
            ) : null}

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'resend',
                                label: t('admin.stock_alerts.bulk_resend', 'Resend email'),
                                icon: 'mail',
                                onClick: () => runBulk('resend'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.stock_alerts.bulk_delete', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.stock_alerts.confirm_bulk_delete', 'Delete selected stock alerts?'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                )}
                footer={<ListFooter pagination={alerts} query={query} />}
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
                            <SortHeader column="product" label={t('admin.stock_alerts.col_product', 'Product')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.stock_alerts.col_customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.stock_alerts.col_subscribed', 'Subscribed')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.stock_alerts.col_status', 'Status')}</Th>
                            <SortHeader column="notified_at" label={t('admin.stock_alerts.col_notified', 'Notified')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="product" value={query.product} placeholder={t('admin.stock_alerts.col_product', 'Product')} onApply={(name, value) => apply({ [name]: value })} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="customer" value={query.customer} placeholder={t('admin.stock_alerts.col_customer', 'Customer')} onApply={(name, value) => apply({ [name]: value })} />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    type="select"
                                    name="status"
                                    value={query.status === 'all' ? '' : (query.status || '')}
                                    placeholder={t('admin.stock_alerts.col_status', 'Status')}
                                    options={[
                                        { value: 'pending', label: t('admin.stock_alerts.pending', 'Pending') },
                                        { value: 'notified', label: t('admin.stock_alerts.notified', 'Notified') },
                                    ]}
                                    onApply={(name, value) => apply({ [name]: value || 'all' })}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/stock-alerts" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>
                                {t('admin.stock_alerts.empty', 'No stock alerts yet')}
                            </EmptyState>
                        ) : rows.map((row) => {
                            const name = customerName(row);
                            const email = customerEmail(row);
                            const productName = row.product?.name || `#${row.product_id}`;
                            const productUrl = row.product_id ? `/admin/products/${row.product_id}/edit` : null;
                            const customerUrl = row.customer?.admin_url || (row.customer_id ? `/admin/customers/${row.customer_id}` : null);
                            const variant = row.variant?.label || row.variant?.sku || (row.product_variant_id ? `#${row.product_variant_id}` : '');
                            const canResend = !!row.available;

                            return (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={`${t('admin.common.select', 'Select')} ${productName}`}
                                        />
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <img
                                                src={catalogImageUrl(row.product?.cover_path || row.product?.image, 'sm')}
                                                alt=""
                                                className={ui.thumbLg}
                                            />
                                            <div className={ui.cellStack}>
                                                {productUrl ? (
                                                    <Button
                                                        variant="link"
                                                        href={productUrl}
                                                        className="font-medium"
                                                    >
                                                        {productName}
                                                    </Button>
                                                ) : (
                                                    <div className={ui.cellStrong}>{productName}</div>
                                                )}
                                                {variant ? (
                                                    <div className={ui.cellSub}>{variant}</div>
                                                ) : null}
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        {customerUrl ? (
                                            <Button variant="link" href={customerUrl} className="font-medium">
                                                {name}
                                            </Button>
                                        ) : (
                                            <div className="font-medium text-neutral-900">{name}</div>
                                        )}
                                        <div className="text-xs text-neutral-500">{email}</div>
                                    </Td>
                                    <Td>{formatStamp(row.subscribed_at)}</Td>
                                    <Td><Badge tone={statusTone(row.status)}>{row.status}</Badge></Td>
                                    <Td>{formatStamp(row.notified_at)}</Td>
                                    <Td className={ui.actionsCol}>
                                        <div className={`${ui.rowActions} gap-0.5`}>
                                            {canResend ? (
                                                <button
                                                    type="button"
                                                    className={ui.rowActionsBtn}
                                                    aria-label={t('admin.stock_alerts.resend', 'Resend email')}
                                                    title={t('admin.stock_alerts.resend', 'Resend email')}
                                                    onClick={() => resendOne(row.id)}
                                                >
                                                    <Icon name="mail" className={ui.rowActionsIcon} />
                                                </button>
                                            ) : null}
                                            <button
                                                type="button"
                                                className={ui.rowActionsBtn}
                                                aria-label={t('admin.common.delete', 'Delete')}
                                                title={t('admin.common.delete', 'Delete')}
                                                onClick={() => {
                                                    if (window.confirm(t('admin.stock_alerts.confirm_delete', 'Remove this stock alert?'))) {
                                                        router.delete(`/admin/stock-alerts/${row.id}`, { preserveScroll: true });
                                                    }
                                                }}
                                            >
                                                <Icon name="trash" className={ui.rowActionsIcon} />
                                            </button>
                                        </div>
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
