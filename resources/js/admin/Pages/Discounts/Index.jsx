import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import { adminUrl } from '../../webPath';
import {
    BulkActions,
    catalogBulkActionItems,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    KpiCards,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
        { key: 'coupon', label: t('admin.discounts.coupons', 'Coupons') },
        { key: 'calendar', label: t('admin.discounts.calendar', 'Calendar') },
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

export default function Index({ rules, counts = {}, filters = {}, applyAs = {}, actions = {}, conditionTypes = {} }) {
    const rows = rules.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        apply_as: filters.apply_as || 'all',
        action: filters.action || 'all',
        condition: filters.condition || 'all',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'priority',
        dir: filters.dir === 'desc' ? 'desc' : 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/discounts', cleanQuery({ ...query, ...next }), {
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

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

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

    const exportSelected = useCallback(() => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (ids.length) {
            window.location.href = adminUrl(`/admin/discounts/export?ids=${encodeURIComponent(ids.join(','))}`);
        }
    }, [selection.selectedIds]);

    const tabItems = pageTabs();
    const applyAsOptions = Object.entries(applyAs).map(([value, label]) => ({ value, label }));
    const actionOptions = Object.entries(actions).map(([value, label]) => ({ value, label }));
    const conditionOptions = Object.entries(conditionTypes).map(([value, label]) => ({ value, label }));

    const kpis = [
        { key: 'total', label: t('admin.discounts.all_discounts', 'All discounts'), value: (counts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'active', label: t('admin.common.active', 'Active'), value: (counts.active ?? 0).toLocaleString(), trend: 0 },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), value: (counts.inactive ?? 0).toLocaleString(), trend: 0 },
        { key: 'coupon', label: t('admin.discounts.coupons', 'Coupons'), value: (counts.coupon ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.discounts.discounts', 'Discounts')}
                total={rules.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.discounts.discounts', 'Discounts') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button icon="plus" href="/admin/discounts/create">
                            {t('admin.common.add_new', 'Add new')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/discounts"
                            importUrl="/admin/discounts/import"
                            importSampleUrl="/admin/discounts/import/sample"
                            exportUrl="/admin/discounts/export"
                            query={query}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            ...catalogBulkActionItems({
                                onActive: () => runBulk('/admin/discounts/bulk-status', { enabled: 1 }),
                                onDeactivate: () => runBulk('/admin/discounts/bulk-status', { enabled: 0 }),
                                onDuplicate: () => runBulk('/admin/discounts/bulk-duplicate'),
                                onDelete: () => runBulk('/admin/discounts/bulk-delete'),
                                deleteConfirm: t('admin.discounts.delete_selected_discounts', 'Delete selected discounts?'),
                            }),
                            {
                                key: 'export',
                                label: t('admin.common.export_selected', 'Export selected'),
                                icon: 'download',
                                onClick: exportSelected,
                            },
                        ]}
                    />
                )}
                footer={<ListFooter pagination={rules} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.discounts.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.discounts.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="apply_as" label={t('admin.discounts.apply_as', 'Apply as')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="action" label={t('admin.discounts.action', 'Action')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.discounts.conditions', 'Conditions')}</Th>
                            <SortHeader col="status" column="is_enabled" label={t('admin.discounts.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
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
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.discounts.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="apply_as"
                                    value={query.apply_as === 'all' ? '' : query.apply_as}
                                    type="select"
                                    placeholder={t('admin.discounts.select_type', 'Select type')}
                                    options={applyAsOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="action"
                                    value={query.action === 'all' ? '' : query.action}
                                    type="select"
                                    placeholder={t('admin.discounts.select_action', 'Select action')}
                                    options={actionOptions}
                                    onApply={applyColumn}
                                />
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="condition"
                                    value={query.condition === 'all' ? '' : query.condition}
                                    type="select"
                                    placeholder={t('admin.discounts.select_condition', 'Select condition')}
                                    options={conditionOptions}
                                    onApply={applyColumn}
                                />
                            </Th>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' || query.status === 'coupon' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.discounts.select_status', 'Select status')}
                                    options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/discounts" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.discounts.no_discounts_yet_add_a_catalog_cart_or_coupon_ru', 'No discounts yet. Add a catalog, cart, or coupon rule.')}</EmptyState>
                        ) : rows.map((rule) => {
                            const canEdit = rule.actions?.edit !== false;
                            const canDelete = rule.actions?.delete !== false;

                            return (
                                <Tr key={rule.id} selected={selection.isSelected(rule.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(rule.id)}
                                                onChange={() => selection.toggle(rule.id)}
                                                ariaLabel={`Select ${rule.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{rule.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <span className={ui.cellIcon}>
                                                <Icon name="tag" />
                                            </span>
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{rule.name}</div>
                                                {rule.code ? (
                                                    <div className={ui.cellSub}>{rule.code}</div>
                                                ) : (
                                                    <div className={ui.cellSub}>Priority {rule.priority}</div>
                                                )}
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>{rule.apply_as_label}</Td>
                                    <Td>
                                        <span className="text-sm text-neutral-700">{rule.action_summary}</span>
                                    </Td>
                                    <Td>
                                        <span className="text-sm text-neutral-600">{rule.condition_summary}</span>
                                        {rule.starts_at || rule.expires_at ? (
                                            <div className={ui.cellSub}>
                                                {(rule.starts_at || '—').slice(0, 10)} → {(rule.expires_at || '—').slice(0, 10)}
                                            </div>
                                        ) : null}
                                    </Td>
                                    <Td col="status">
                                        <StatusToggle
                                            active={!!rule.is_enabled}
                                            onToggle={() => {
                                                router.post(`/admin/discounts/${rule.id}/toggle`, {}, { preserveScroll: true });
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/discounts/${rule.id}/edit`}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            confirmMessage={`Delete “${rule.name}”?`}
                                            onDelete={() => {
                                                router.delete(`/admin/discounts/${rule.id}`, { preserveScroll: true });
                                            }}
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
