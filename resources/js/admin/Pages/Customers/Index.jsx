import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Avatar,
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
    money,
    ui,
    t,
    ModuleColumnHeaders,
    ModuleColumns,
    useListSelection,
    KpiCards,
} from '../../ui';

const groupMeta = {
    retailer: { label: 'Retailer', tone: 'primary' },
    wholesale: { label: 'Wholesale', tone: 'info' },
    vip: { label: 'VIP', tone: 'warning' },
};

function groupDisplay(code, groups) {
    const row = (groups || []).find((g) => (g.code || g) === code);
    return {
        label: row?.name || groupMeta[code]?.label || code || '—',
        tone: row?.color || groupMeta[code]?.tone || 'primary',
    };
}

function formatDate(iso) {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
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

export default function Index({ customers, stats, filters, groups: groupOptions, moduleList = { columns: [] } }) {
    const { shop } = usePage().props;
    const currency = shop?.currency || 'USD';
    const [bulkBusy, setBulkBusy] = useState(false);
    const rows = customers.data || [];
    const query = useMemo(() => ({
        name: filters?.name || '',
        email: filters?.email || '',
        phone: filters?.phone || '',
        status: filters?.status || 'all',
        group: filters?.group || '',
        id_min: filters?.id_min || '',
        id_max: filters?.id_max || '',
        spent_min: filters?.spent_min || filters?.spent || '',
        spent_max: filters?.spent_max || '',
        location: filters?.location || '',
        created: filters?.created || '',
        sort: filters?.sort || 'created_at',
        dir: filters?.dir === 'asc' ? 'asc' : 'desc',
    }), [filters]);

    const queryRef = useRef(query);
    queryRef.current = query;

    const apply = useCallback((next) => {
        router.get('/admin/customers', cleanQuery({ ...queryRef.current, ...next, page: 1 }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, []);

    const sortBy = (column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    };

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const selection = useListSelection(rows);
    const colSpan = 11 + (moduleList.columns?.length || 0);

    const runBulk = useCallback((action) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);

        if (!ids.length || bulkBusy) {
            return;
        }

        setBulkBusy(true);
        router.post('/admin/customers/bulk', { action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, selection.clear, selection.selectedIds]);

    const kpis = [
        { key: 'total', label: t('admin.customers.total_customers', 'Total Customers'), value: stats ? stats.total.toLocaleString() : '…', trend: stats?.total_trend ?? 0 },
        { key: 'new', label: t('admin.customers.new_customers', 'New Customers'), value: stats ? stats.new.toLocaleString() : '…', trend: stats?.new_trend ?? 0 },
        { key: 'active', label: t('admin.customers.active_customers', 'Active Customers'), value: stats ? stats.active.toLocaleString() : '…', trend: stats?.active_trend ?? 0 },
        { key: 'repeat', label: t('admin.customers.repeat_customers', 'Repeat Customers'), value: stats ? `${stats.repeat_rate}%` : '…', trend: stats?.repeat_trend ?? 0 },
    ];

    const tabItems = [
        { key: 'all', label: t('admin.customers.all_customers', 'All Customers') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
        { key: 'new', label: t('admin.customers.new_this_month', 'New This Month') },
        { key: 'repeat', label: t('admin.customers.repeat_customers', 'Repeat Customers') },
        { key: 'vip', label: t('admin.customers.vip_customers', 'VIP Customers') },
    ];

    const exportFields = useMemo(() => [
        { name: 'name', label: t('admin.customers.name', 'Name'), type: 'text' },
        { name: 'email', label: t('admin.customers.email', 'Email'), type: 'text' },
        { name: 'phone', label: t('admin.customers.phone', 'Phone'), type: 'text' },
        { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
        { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
        { name: 'location', label: t('admin.customers.location', 'Location'), type: 'text' },
        {
            name: 'status',
            label: t('admin.common.status', 'Status'),
            type: 'select',
            options: [
                { value: 'active', label: t('admin.common.active', 'Active') },
                { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
            ],
        },
        {
            name: 'group',
            label: t('admin.customers.group', 'Group'),
            type: 'select',
            options: (groupOptions || []).map((g) => ({ value: g.code || g, label: g.name || g })),
        },
    ], [groupOptions]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.customers.customers', 'Customers')}
                total={customers.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.customers.customers', 'Customers') }]}
                actions={(
                    <>
                        <Button icon="plus" href="/admin/customers/create">{t('admin.customers.add_customer', 'Add Customer')}</Button>
                        <ListSettings
                            listUrl="/admin/customers"
                            importUrl="/admin/customers/import"
                            importSampleUrl="/admin/customers/import/sample"
                            exportUrl="/admin/customers/export"
                            query={query}
                            exportFields={exportFields}
                            tableName="customers"
                        />
                    </>
                )}
            />

            <KpiCards items={kpis} loading={!stats} />

            <ListPanel
                tabs={(
                    <StatusTabs
                        tabs={tabItems}
                        value={query.status || 'all'}
                        onChange={(status) => apply({ status: status === 'all' ? '' : status })}
                    />
                )}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'enable',
                                label: t('admin.products.active_selection', 'Active selection'),
                                onClick: () => runBulk('enable'),
                            },
                            {
                                key: 'disable',
                                label: t('admin.products.deactivate_selection', 'Deactivate selection'),
                                onClick: () => runBulk('disable'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.customers.delete_selected_customers', 'Delete selected customers?'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                )}
                footer={(
                    <ListFooter
                        pagination={customers}
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
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.customers.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="email" label={t('admin.customers.email', 'Email')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="phone" label={t('admin.customers.phone', 'Phone')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="group" label={t('admin.customers.group', 'Group')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="spent" label={t('admin.customers.total_spent', 'Total Spent')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="location" label={t('admin.customers.location', 'Location')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="created_at" label={t('admin.customers.created_at', 'Created At')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <ModuleColumnHeaders columns={moduleList.columns} />
                            <SortHeader col="status" column="status" label={t('admin.customers.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.customers.name', 'Name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="email" value={query.email} placeholder={t('admin.customers.email', 'Email')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="phone" value={query.phone} placeholder={t('admin.customers.phone', 'Phone')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    type="select"
                                    name="group"
                                    value={query.group}
                                    placeholder={t('admin.customers.all_groups', 'All groups')}
                                    options={(groupOptions || []).map((g) => ({
                                        value: g.code || g,
                                        label: g.name || groupMeta[g]?.label || g,
                                    }))}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="spent_min"
                                    maxName="spent_max"
                                    minValue={query.spent_min}
                                    maxValue={query.spent_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="location" value={query.location} placeholder={t('admin.customers.city_country', 'City / country')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="created" type="date" value={query.created} placeholder={t('admin.customers.created_at', 'Created At')} onApply={applyColumn} />
                            </Th>
                            {(moduleList.columns || []).map((col) => (
                                <Th key={col.key} className={ui.thFilter} />
                            ))}
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'active' || query.status === 'inactive' ? query.status : ''}
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
                                <FilterReset query={query} href="/admin/customers" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={colSpan}>{t('admin.customers.no_customers_match_these_filters', 'No customers match these filters.')}</EmptyState>
                        ) : rows.map((c) => {
                            const group = groupDisplay(c.customer_group, groupOptions);
                            return (
                                <Tr key={c.id} selected={selection.isSelected(c.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(c.id)}
                                            onChange={() => selection.toggle(c.id)}
                                            ariaLabel={`${t('admin.common.select', 'Select')} ${c.name || c.email}`}
                                        />
                                    </Td>
                                    <Td col="id">{c.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <Avatar name={c.name || c.email} src={c.avatar_url} />
                                            <Button variant="link" href={`/admin/customers/${c.id}`}>{c.name}</Button>
                                        </div>
                                    </Td>
                                    <Td>{c.email}</Td>
                                    <Td>{c.phone || '—'}</Td>
                                    <Td><Badge tone={group.tone}>{group.label}</Badge></Td>
                                    <Td>{money(c.total_spent, currency)}</Td>
                                    <Td>{c.location || '—'}</Td>
                                    <Td>{formatDate(c.created_at)}</Td>
                                    <ModuleColumns columns={moduleList.columns} values={c.module_values} />
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!c.is_enabled}
                                            onToggle={() => router.post(`/admin/customers/${c.id}/toggle`, {}, { preserveScroll: true })}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/customers/${c.id}`}
                                            confirmMessage={t('admin.customers.delete_this_customer', 'Delete this customer?')}
                                            onDelete={() => router.delete(`/admin/customers/${c.id}`)}
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
