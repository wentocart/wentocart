import { router } from '@inertiajs/react';
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
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    KpiCards,
    statusCountKpis,
    useListSelection,
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
    ];
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

export default function Index({ stages, counts = {}, filters = {}, can_manage = false }) {
    const rows = stages.data || [];
    const selection = useListSelection(rows);
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        name: filters.name || '',
        buyer_label: filters.buyer_label || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir === 'desc' ? 'desc' : 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/order-stages', cleanQuery({ ...query, ...next, page: 1 }), {
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

    const runBulk = useCallback((action) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);

        if (!ids.length || bulkBusy) {
            return;
        }

        setBulkBusy(true);

        if (action === 'enable' || action === 'disable') {
            router.post('/admin/order-stages/bulk-status', { ids, is_enabled: action === 'enable' }, {
                preserveScroll: true,
                onSuccess: () => selection.clear(),
                onFinish: () => setBulkBusy(false),
            });
        } else if (action === 'delete') {
            router.post('/admin/order-stages/bulk-destroy', { ids }, {
                preserveScroll: true,
                onSuccess: () => selection.clear(),
                onFinish: () => setBulkBusy(false),
            });
        }
    }, [bulkBusy, selection]);

    const tabItems = pageTabs();

    const kpis = statusCountKpis(counts, {
        total: t('admin.common.all', 'All'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.nav.order_statuses', 'Order statuses')}
                total={stages.total}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.nav.order_statuses', 'Order statuses') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        {can_manage ? (
                            <Button icon="plus" href="/admin/order-stages/create">
                                {t('admin.order_stages.add_status', 'Add order status')}
                            </Button>
                        ) : null}
                        {can_manage ? (
                            <ListSettings
                                listUrl="/admin/order-stages"
                                importUrl="/admin/order-stages/import"
                                importSampleUrl="/admin/order-stages/import/sample"
                                exportUrl="/admin/order-stages/export"
                                query={query}
                            />
                        ) : null}
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={can_manage ? (
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'enable',
                                label: t('admin.common.activate_selection', 'Activate selection'),
                                onClick: () => runBulk('enable'),
                            },
                            {
                                key: 'disable',
                                label: t('admin.common.deactivate_selection', 'Deactivate selection'),
                                onClick: () => runBulk('disable'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.common.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.order_stages.delete_selected_confirm', 'Delete selected order statuses? (System or in-use statuses will be skipped)'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                ) : null}
                footer={<ListFooter pagination={stages} query={query} />}
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
                            <SortHeader column="name" label={t('admin.order_stages.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="buyer_label" label={t('admin.order_stages.customer_label', 'Customer sees')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.order_stages.color', 'Color')}</Th>
                            <Th>{t('admin.order_stages.docs', 'Documents')}</Th>
                            <Th>{t('admin.order_stages.email', 'Email')}</Th>
                            <Th>{t('admin.order_stages.access_to', 'Access to')}</Th>
                            <SortHeader col="status" column="is_enabled" label={t('admin.payments.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.order_stages.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="buyer_label" value={query.buyer_label} placeholder={t('admin.order_stages.customer_label', 'Customer sees')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/order-stages" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={10}>{t('admin.order_stages.no_statuses_yet', 'No order statuses yet.')}</EmptyState>
                        ) : rows.map((stage) => (
                            <Tr key={stage.id}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(stage.id)}
                                        onChange={() => selection.toggle(stage.id)}
                                        ariaLabel={t('admin.common.select_row', 'Select row')}
                                    />
                                </Td>
                                <Td className="text-center tabular-nums text-neutral-500">{stage.id}</Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <div className={ui.row}>
                                            <Badge tone={stage.tone || 'neutral'}>{stage.name}</Badge>
                                            {stage.is_system ? <span className={ui.cellSub}>system</span> : null}
                                        </div>
                                        <div className={ui.cellSub}>{stage.buyer_note || stage.code}</div>
                                    </div>
                                </Td>
                                <Td>{stage.buyer_label}</Td>
                                <Td>
                                    <Badge tone={stage.tone || 'neutral'}>{stage.tone || 'neutral'}</Badge>
                                </Td>
                                <Td>
                                    <div className={ui.cellSub}>
                                        {[
                                            stage.unlock_invoice ? t('admin.order_stages.generate_pdf', 'PDF') : null,
                                            stage.unlock_credit_slip ? t('admin.nav.credit_slip', 'Credit') : null,
                                            stage.unlock_delivery_slip ? t('admin.order_stages.generate_delivery_slip', 'Delivery slip') : null,
                                        ].filter(Boolean).join(' · ') || '—'}
                                    </div>
                                </Td>
                                <Td>
                                    {stage.notify_buyer
                                        ? t('admin.common.on', 'On')
                                        : t('admin.common.off', 'Off')}
                                </Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">
                                        {(stage.profiles || []).length
                                            ? stage.profiles.map((profile) => profile.name).join(', ')
                                            : t('admin.order_stages.all_staff', 'All staff')}
                                    </span>
                                </Td>
                                <Td>
                                    {can_manage ? (
                                        <StatusToggle
                                            active={!!stage.is_enabled}
                                            onToggle={() => router.post(`/admin/order-stages/${stage.id}/toggle`, {}, { preserveScroll: true })}
                                        />
                                    ) : (
                                        <Badge tone={stage.is_enabled ? 'success' : 'neutral'}>
                                            {stage.is_enabled ? t('admin.common.active', 'Active') : t('admin.common.inactive', 'Inactive')}
                                        </Badge>
                                    )}
                                </Td>
                                <Td actions>
                                    {can_manage ? (
                                    <RowActions
                                        editHref={`/admin/order-stages/${stage.id}/edit`}
                                        editLabel={t('admin.common.edit', 'Edit')}
                                        canDelete={false}
                                        items={[
                                            {
                                                key: 'delete',
                                                label: t('admin.common.delete', 'Delete'),
                                                icon: 'trash',
                                                danger: true,
                                                onClick: () => {
                                                    if (stage.is_system) {
                                                        window.alert(t('admin.order_stages.system_cannot_delete', 'System statuses cannot be deleted. Disable them instead.'));
                                                        return;
                                                    }
                                                    if (window.confirm(t('admin.order_stages.delete_confirm', 'Delete “:name”?', { name: stage.name }).replace(':name', stage.name))) {
                                                        router.delete(`/admin/order-stages/${stage.id}`, { preserveScroll: true });
                                                    }
                                                },
                                            },
                                        ]}
                                    />
                                    ) : <span className={ui.muted}>—</span>}
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
