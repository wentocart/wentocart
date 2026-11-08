import { router, useForm } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    Card,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FeatureTile,
    FilterReset,
    FormErrors,
    KpiCards,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SettingsCount,
    SettingsSectionHead,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    cx,
    ui,
    t,
    useListSelection,
} from '../../ui';

function tabs() {
    return [
        { key: 'all', label: t('admin.support.all', 'All') },
        { key: 'open', label: t('admin.support.open', 'Open') },
        { key: 'replied', label: t('admin.support.replied', 'Replied') },
        { key: 'closed', label: t('admin.support.closed', 'Closed') },
    ];
}

const tone = { open: 'warning', replied: 'success', closed: 'neutral' };

function statusLabel(status) {
    if (status === 'replied') {
        return t('admin.support.replied', 'Replied');
    }
    if (status === 'closed') {
        return t('admin.support.closed', 'Closed');
    }
    return t('admin.support.open', 'Open');
}

export default function Support({ tickets, filters = {}, counts = {}, settings = {} }) {
    const rows = tickets.data || [];
    const query = useMemo(() => ({
        name: filters.name || '',
        email: filters.email || '',
        message: filters.message || '',
        status: filters.status || 'all',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);
    const form = useForm({
        notify_email: settings.notify_email !== false,
        reply_email: settings.reply_email !== false,
        notify_addresses: settings.notify_addresses || '',
    });

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/support', payload, { preserveState: true, preserveScroll: true, replace: true });
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

    const runBulk = (action) => {
        const ids = selection.selectedIds;
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post('/admin/support/bulk', { action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    const tabItems = tabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));
    const kpis = [
        { key: 'total', label: t('admin.common.total', 'Total'), value: Number(counts.all || 0).toLocaleString(), trend: 0 },
        { key: 'open', label: t('admin.support.open', 'Open'), value: Number(counts.open || 0).toLocaleString(), trend: 0 },
        { key: 'replied', label: t('admin.support.replied', 'Replied'), value: Number(counts.replied || 0).toLocaleString(), trend: 0 },
        { key: 'closed', label: t('admin.support.closed', 'Closed'), value: Number(counts.closed || 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.support.title', 'Support')}
                total={tickets.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.support.title', 'Support') },
                ]}
                meta={t('admin.support.subtitle', 'Messages shoppers send from the Contact page.')}
                actions={(
                    <ListSettings listUrl="/admin/support" query={query} />
                )}
            />
            <KpiCards items={kpis} />
            <Card className="mb-4">
                <SettingsSectionHead
                    icon="bell"
                    title={t('admin.support.email_alerts', 'Email alerts')}
                    hint={t('admin.support.email_alerts_hint', 'When a customer writes from the storefront, notify staff. Leave addresses blank to use the store email.')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 3 })}</SettingsCount>}
                />
                <FormErrors errors={form.errors} />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="bell"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.support.notify_email', 'Email staff on new messages')}
                        hint={t('admin.support.notify_email_hint', 'Staff get an email when a shopper submits the contact form.')}
                        value={!!form.data.notify_email}
                        onChange={(on) => form.setData('notify_email', on)}
                    />
                    <FeatureTile
                        icon="send"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.support.reply_email', 'Email the customer when staff reply')}
                        hint={t('admin.support.reply_email_hint', 'The customer gets an email when a staff member replies.')}
                        value={!!form.data.reply_email}
                        onChange={(on) => form.setData('reply_email', on)}
                    />
                    <FeatureTile
                        fill
                        className="md:col-span-2"
                        icon="mail"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.support.notify_addresses', 'Staff emails')}
                        hint={form.errors.notify_addresses || t('admin.support.notify_addresses_hint', 'One or more addresses, separated by comma or new line.')}
                    >
                        <Textarea
                            rows={3}
                            value={form.data.notify_addresses}
                            onChange={(e) => form.setData('notify_addresses', e.target.value)}
                            placeholder="support@example.com"
                        />
                    </FeatureTile>
                </div>
                <div className={cx(ui.toolbar, 'mt-4 justify-end')}>
                    <Button
                        type="button"
                        icon="check"
                        busy={form.processing}
                        onClick={() => form.post('/admin/support/settings', { preserveScroll: true })}
                    >
                        {t('admin.common.save', 'Save')}
                    </Button>
                </div>
            </Card>
            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'close',
                                label: t('admin.support.close', 'Close'),
                                icon: 'check',
                                onClick: () => runBulk('close'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.support.delete_selected', 'Delete selected messages?'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                )}
                footer={(
                    <ListFooter pagination={tickets} query={query} />
                )}
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
                            <SortHeader column="name" label={t('admin.support.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="email" label={t('admin.support.email', 'Email')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.support.message', 'Message')}</Th>
                            <SortHeader col="status" column="status" label={t('admin.support.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.support.customer', 'Customer')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="email" value={query.email} placeholder={t('admin.support.email', 'Email')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="message" value={query.message} placeholder={t('admin.support.message', 'Message')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} defaults={{ status: 'all', sort: 'id', dir: 'desc' }} onReset={() => apply({ name: '', email: '', message: '', status: 'all', id_min: '', id_max: '', sort: 'id', dir: 'desc' })} />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <Td colSpan={7}>
                                    <EmptyState title={t('admin.support.empty', 'No support messages yet')} />
                                </Td>
                            </tr>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                    />
                                </Td>
                                <Td>{row.id}</Td>
                                <Td>
                                    <div className="font-medium">{row.name}</div>
                                    {row.order_number ? <div className={ui.muted}>{row.order_number}</div> : null}
                                </Td>
                                <Td>{row.email}</Td>
                                <Td className="max-w-sm truncate">{row.snippet}</Td>
                                <Td>
                                    <Badge tone={tone[row.status] || 'neutral'}>{statusLabel(row.status)}</Badge>
                                </Td>
                                <Td actions>
                                    <RowActions
                                        viewHref={`/admin/support/${row.id}`}
                                        canEdit={false}
                                        canDelete={false}
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
