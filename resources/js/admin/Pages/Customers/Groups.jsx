import { router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    ColorField,
    FeatureTile,
    hexFromTone,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    Field,
    FilterReset,
    FormErrors,
    Input,
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
    cx,
    ui,
    t,
    useListSelection,
} from '../../ui';

function groupCode(name, code) {
    const raw = String(code || name || '').toLowerCase().trim();
    return raw.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function emptyGroup() {
    return {
        name: '',
        code: '',
        color: hexFromTone('primary'),
        discount_percent: '0',
        tax_exempt: false,
        hide_prices: false,
        description: '',
        is_enabled: true,
    };
}

function sortValue(group, column) {
    if (column === 'code') {
        return String(group.code || '').toLowerCase();
    }
    if (column === 'discount') {
        return Number(group.discount_percent || 0);
    }
    if (column === 'customers') {
        return Number(group.customers_count || 0);
    }
    if (column === 'status') {
        return group.is_enabled ? 1 : 0;
    }
    return String(group.name || '').toLowerCase();
}

const FILTER_KEYS = ['name', 'code', 'discount', 'discount_min', 'discount_max', 'customers', 'customers_min', 'customers_max', 'status'];

function listQuery(filters) {
    const payload = {};
    FILTER_KEYS.forEach((key) => {
        const value = filters[key];
        if (value && value !== 'all') {
            payload[key] = value;
        }
    });
    return payload;
}

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
    ];
}

function GroupInnerForm({ panel, query }) {
    const editing = panel?.mode === 'edit';
    const [codeLocked, setCodeLocked] = useState(false);
    const form = useForm(emptyGroup());
    const seedKey = editing ? `edit-${panel.group?.id || ''}` : 'create';
    const seededFor = useRef(null);

    useEffect(() => {
        if (seededFor.current === seedKey) {
            return;
        }
        seededFor.current = seedKey;

        if (editing && panel.group) {
            form.setData({
                name: panel.group.name || '',
                code: panel.group.code || '',
                color: hexFromTone(panel.group.color || 'primary'),
                discount_percent: String(panel.group.discount_percent ?? 0),
                tax_exempt: Boolean(panel.group.tax_exempt),
                hide_prices: Boolean(panel.group.hide_prices),
                description: panel.group.description || '',
                is_enabled: panel.group.is_enabled !== false,
            });
            form.clearErrors();
            setCodeLocked(true);
            return;
        }
        if (panel?.mode === 'create') {
            form.setData(emptyGroup());
            form.clearErrors();
            setCodeLocked(false);
        }
    }, [seedKey, editing, panel]);

    useEffect(() => {
        document.getElementById('list-inner-form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [seedKey]);

    const closeForm = () => {
        router.get('/admin/customer-groups', query, { preserveScroll: true, replace: true });
    };

    const save = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            code: groupCode(data.name, data.code),
            color: hexFromTone(data.color || 'primary'),
            discount_percent: data.discount_percent || 0,
            tax_exempt: data.tax_exempt ? 1 : 0,
            hide_prices: data.hide_prices ? 1 : 0,
            description: data.description,
            is_enabled: data.is_enabled ? 1 : 0,
        }));
        const options = { preserveScroll: true, preserveState: true };
        if (editing) {
            form.put(`/admin/customer-groups/${panel.group.id}`, options);
            return;
        }
        form.post('/admin/customer-groups', options);
    };

    return (
        <div id="list-inner-form" className="p-4 sm:p-6">
            <div className={ui.sectionHead}>
                <h2 className={ui.sectionTitle}>
                    {editing ? t('admin.customer_groups.edit_group', 'Edit group') : t('admin.customer_groups.new_group', 'New group')}
                </h2>
            </div>
            <FormErrors errors={form.errors} />
            <form className={ui.fieldGrid} onSubmit={save}>
                <Field label={t('admin.customer_groups.group_name', 'Group name')} required>
                    <Input
                        wide
                        placeholder={t('admin.customer_groups.enter_group_name', 'Enter group name')}
                        value={form.data.name}
                        onChange={(e) => {
                            const name = e.target.value;
                            form.setData({
                                ...form.data,
                                name,
                                code: editing || codeLocked ? form.data.code : groupCode(name),
                            });
                        }}
                    />
                </Field>
                <Field label={t('admin.customer_groups.code', 'Code')} required={!editing}>
                    <Input
                        wide
                        placeholder={t('admin.customer_groups.retailer', 'retailer')}
                        disabled={editing}
                        value={form.data.code}
                        onChange={(e) => {
                            setCodeLocked(true);
                            form.setData('code', groupCode('', e.target.value));
                        }}
                    />
                </Field>
                <Field label={t('admin.customer_groups.discount', 'Discount %')}>
                    <Input
                        wide
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        value={form.data.discount_percent}
                        onChange={(e) => form.setData('discount_percent', e.target.value)}
                    />
                    <p className={`${ui.metaK} mt-1`}>
                        {t('admin.customer_groups.discount_hint', 'Changes catalog and cart prices for signed-in customers in this group when Group-specific pricing is on under Configure → Customers.')}
                    </p>
                </Field>
                <Field label={t('admin.customer_groups.color', 'Color')} className={ui.fieldSpan2} required>
                    <ColorField
                        value={form.data.color || hexFromTone('primary')}
                        onChange={(color) => form.setData('color', color)}
                    />
                </Field>
                <Field label={t('admin.customer_groups.description', 'Description')} className={ui.fieldSpan2}>
                    <Input
                        wide
                        placeholder={t('admin.customer_groups.optional_description', 'Optional description')}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                    />
                </Field>
                <div className={cx('grid gap-3 md:grid-cols-3', ui.fieldSpan2)}>
                    <FeatureTile
                        icon="check"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.customer_groups.status', 'Status')}
                        hint={t('admin.customer_groups.status_hint', 'An inactive group is not offered when you add a customer.')}
                        value={!!form.data.is_enabled}
                        onChange={(on) => form.setData('is_enabled', on)}
                    />
                    <FeatureTile
                        icon="shield"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.customer_groups.tax_exempt', 'Tax exempt')}
                        hint={t('admin.customer_groups.tax_exempt_hint', 'Customers in this group are not charged tax.')}
                        value={!!form.data.tax_exempt}
                        onChange={(on) => form.setData('tax_exempt', on)}
                    />
                    <FeatureTile
                        icon="eye"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.customer_groups.hide_prices', 'Hide prices')}
                        hint={t('admin.customer_groups.hide_prices_hint', 'Catalog prices stay hidden for this group.')}
                        value={!!form.data.hide_prices}
                        onChange={(on) => form.setData('hide_prices', on)}
                    />
                </div>
                <div className={cx(ui.toolbar, ui.fieldSpan2, 'justify-end')}>
                    <Button type="button" variant="secondary" onClick={closeForm}>
                        {t('admin.common.cancel', 'Cancel')}
                    </Button>
                    <Button type="submit" icon="check" busy={form.processing}>
                        {editing ? t('admin.customer_groups.save_group', 'Save group') : t('admin.customer_groups.add', 'Add')}
                    </Button>
                </div>
            </form>
        </div>
    );
}

export default function Groups({ groups = [], filters = {}, counts = {}, panel = null }) {
    const [sort, setSort] = useState({ column: 'name', dir: 'asc' });
    const [bulkBusy, setBulkBusy] = useState(false);
    const query = useMemo(() => listQuery(filters), [filters]);
    const editingId = panel?.mode === 'edit' ? panel.group?.id : null;
    const formOpen = panel?.mode === 'create' || panel?.mode === 'edit';

    const panelParams = useMemo(() => {
        if (panel?.mode === 'edit' && panel.group?.id) {
            return { edit: panel.group.id };
        }
        if (panel?.mode === 'create') {
            return { create: 1 };
        }
        return {};
    }, [panel]);

    const rows = useMemo(() => {
        const list = [...groups];
        const mul = sort.dir === 'asc' ? 1 : -1;
        list.sort((a, b) => {
            const av = sortValue(a, sort.column);
            const bv = sortValue(b, sort.column);
            if (av < bv) {
                return -1 * mul;
            }
            if (av > bv) {
                return 1 * mul;
            }
            return 0;
        });
        return list;
    }, [groups, sort]);

    const selection = useListSelection(rows);

    const apply = useCallback((next) => {
        const payload = { ...query, ...panelParams, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/customer-groups', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query, panelParams]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const openCreate = () => {
        router.get('/admin/customer-groups', { ...query, create: 1 }, { preserveScroll: true });
    };

    const openEdit = (group) => {
        router.get('/admin/customer-groups', { ...query, edit: group.id }, { preserveScroll: true });
    };

    const runBulkStatus = (enabled) => {
        const ids = selection.selectedIds;
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post('/admin/customer-groups/bulk-status', { ids, enabled }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.customer_groups.customer_group', 'Customer Group')}
                total={counts.all ?? groups.length}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/customers', label: t('admin.customer_groups.customers', 'Customers') }, { label: t('admin.customer_groups.customer_group', 'Customer Group') }]}
                actions={!formOpen ? (
                    <>
                        <Button type="button" icon="plus" onClick={openCreate}>
                            {t('admin.customer_groups.add', 'Add')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/customer-groups"
                            importUrl="/admin/customer-groups/import"
                            importSampleUrl="/admin/customer-groups/import/sample"
                            exportUrl="/admin/customer-groups/export"
                            query={query}
                            exportFields={[
                                { name: 'name', label: t('admin.customer_groups.group_name', 'Group name'), type: 'text' },
                                { name: 'code', label: t('admin.customer_groups.code', 'Code'), type: 'text' },
                                {
                                    name: 'status',
                                    label: t('admin.common.status', 'Status'),
                                    type: 'select',
                                    options: [
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ],
                                },
                            ]}
                            tableName="customer-groups"
                        />
                    </>
                ) : null}
            />
            <ListPanel
                tabs={formOpen ? null : <StatusTabs tabs={pageTabs()} value={filters.status || 'all'} onChange={(status) => apply({ status })} />}
                bulk={formOpen ? null : (
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'enable',
                                label: t('admin.products.active_selection', 'Active selection'),
                                onClick: () => runBulkStatus(1),
                            },
                            {
                                key: 'disable',
                                label: t('admin.products.deactivate_selection', 'Deactivate selection'),
                                onClick: () => runBulkStatus(0),
                            },
                            {
                                key: 'duplicate',
                                label: t('admin.products.duplicate_selection', 'Duplicate selection'),
                                icon: 'copy',
                                onClick: () => {
                                    const ids = selection.selectedIds.filter((id) => {
                                        const group = (groups || []).find((row) => String(row.id) === String(id));

                                        return group && !group.is_system;
                                    });
                                    if (!ids.length || bulkBusy) {
                                        return;
                                    }
                                    setBulkBusy(true);
                                    router.post('/admin/customer-groups/bulk-duplicate', { ids }, {
                                        preserveScroll: true,
                                        onSuccess: () => selection.clear(),
                                        onFinish: () => setBulkBusy(false),
                                    });
                                },
                            },
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.customer_groups.delete_selected', 'Delete selected groups? Groups assigned to customers will be skipped.'),
                                onClick: () => {
                                    const ids = selection.selectedIds;
                                    if (!ids.length || bulkBusy) {
                                        return;
                                    }
                                    setBulkBusy(true);
                                    router.post('/admin/customer-groups/bulk-delete', { ids }, {
                                        preserveScroll: true,
                                        onSuccess: () => selection.clear(),
                                        onFinish: () => setBulkBusy(false),
                                    });
                                },
                            },
                        ]}
                    />
                )}
            >
                {formOpen ? <GroupInnerForm panel={panel} query={query} /> : (
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
                            <SortHeader column="name" label={t('admin.customer_groups.name', 'Name')} sort={sort.column} dir={sort.dir} onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))} />
                            <SortHeader column="code" label={t('admin.customer_groups.code', 'Code')} sort={sort.column} dir={sort.dir} onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))} />
                            <SortHeader column="discount" label={t('admin.customer_groups.discount_2', 'Discount')} sort={sort.column} dir={sort.dir} onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))} />
                            <SortHeader column="customers" label={t('admin.customer_groups.customers', 'Customers')} sort={sort.column} dir={sort.dir} onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))} />
                            <SortHeader col="status" column="status" label={t('admin.customer_groups.status', 'Status')} sort={sort.column} dir={sort.dir} onSort={(column) => setSort((cur) => ({ column, dir: cur.column === column && cur.dir === 'asc' ? 'desc' : 'asc' }))} />
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={filters.name || ''} placeholder={t('admin.customer_groups.name', 'Name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="code" value={filters.code || ''} placeholder={t('admin.customer_groups.code', 'Code')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="discount_min"
                                    maxName="discount_max"
                                    minValue={filters.discount_min || filters.discount || ''}
                                    maxValue={filters.discount_max || ''}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="customers_min"
                                    maxName="customers_max"
                                    minValue={filters.customers_min || filters.customers || ''}
                                    maxValue={filters.customers_max || ''}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    type="select"
                                    name="status"
                                    value={filters.status === 'all' ? '' : (filters.status || '')}
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
                                <FilterReset query={query} href="/admin/customer-groups" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.customer_groups.no_customer_groups_yet', 'No customer groups yet.')}</EmptyState>
                        ) : rows.map((group) => (
                            <Tr key={group.id} selected={selection.isSelected(group.id)} className={group.id === editingId ? 'bg-sky-50/70' : undefined}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(group.id)}
                                        onChange={() => selection.toggle(group.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${group.name}`}
                                    />
                                </Td>
                                <Td>
                                    <div className={ui.cellStack}>
                                        <div className={ui.row}>
                                            <div className={ui.cellStrong}>{group.name}</div>
                                            <Badge tone={group.color || 'primary'}>{group.name}</Badge>
                                        </div>
                                        {group.description ? <div className={ui.cellSub}>{group.description}</div> : null}
                                    </div>
                                </Td>
                                <Td>{group.code}</Td>
                                <Td>
                                    {Number(group.discount_percent || 0)}%{group.tax_exempt ? ` · ${t('admin.customer_groups.exempt', 'Exempt')}` : ''}
                                </Td>
                                <Td>{group.customers_count ?? 0}</Td>
                                <Td col="status">
                                    <StatusToggle
                                        active={!!group.is_enabled}
                                        disabled={!!group.is_system}
                                        onToggle={() => router.post(`/admin/customer-groups/${group.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        canDelete={!group.is_system}
                                        onEdit={() => openEdit(group)}
                                        items={group.is_system ? [] : [{
                                            key: 'duplicate',
                                            label: t('admin.common.duplicate', 'Duplicate'),
                                            icon: 'copy',
                                            onClick: () => router.post(`/admin/customer-groups/${group.id}/duplicate`),
                                        }]}
                                        confirmMessage={t('admin.customer_groups.delete_this_group', 'Delete this group?')}
                                        onDelete={() => router.delete(`/admin/customer-groups/${group.id}`, { preserveScroll: true })}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
                )}
            </ListPanel>
        </Layout>
    );
}
