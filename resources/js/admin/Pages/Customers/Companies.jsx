import { router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    Field,
    FilterReset,
    FormErrors,
    Input,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    cx,
    ui,
    t,
} from '../../ui';

const FILTER_KEYS = ['name', 'email', 'users_min', 'users_max', 'credit_min', 'credit_max', 'status', 'sort', 'dir'];

function listQuery(filters) {
    const query = {};
    FILTER_KEYS.forEach((key) => {
        const value = filters[key];
        if (value !== '' && value != null && value !== 'all') {
            query[key] = value;
        }
    });
    return query;
}
function companyTabs(counts) {
    return [
        { key: 'all', label: t('admin.common.all', 'All'), count: counts.all },
        { key: 'active', label: t('admin.common.active', 'Active'), count: counts.active },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: counts.inactive },
    ];
}

function emptyCompany() {
    return {
        name: '',
        email: '',
        phone: '',
        credit_limit: '0',
        net_days: '30',
        is_enabled: true,
    };
}

function CompanyForm({ panel, query }) {
    const editing = panel?.mode === 'edit';
    const form = useForm(emptyCompany());
    const seededFor = useRef(null);
    const seedKey = editing ? `edit-${panel.company?.id || ''}` : 'create';

    useEffect(() => {
        if (seededFor.current === seedKey) return;
        seededFor.current = seedKey;
        const company = panel?.company || {};
        form.setData(editing ? {
            name: company.name || '',
            email: company.email || '',
            phone: company.phone || '',
            credit_limit: String(company.credit_limit ?? 0),
            net_days: String(company.net_days ?? 30),
            is_enabled: company.is_enabled !== false,
        } : emptyCompany());
        form.clearErrors();
    }, [seedKey, editing, panel]);

    useEffect(() => {
        document.getElementById('list-inner-form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [seedKey]);

    const close = () => router.get('/admin/companies', query, { preserveScroll: true, replace: true });
    const save = (event) => {
        event.preventDefault();
        const payload = {
            ...form.data,
            credit_limit: form.data.credit_limit || 0,
            net_days: form.data.net_days || 0,
            is_enabled: form.data.is_enabled ? 1 : 0,
        };
        const options = { preserveScroll: true, preserveState: true, onSuccess: close };
        if (editing) {
            router.put(`/admin/companies/${panel.company.id}`, payload, options);
        } else {
            router.post('/admin/companies', payload, options);
        }
    };

    return (
        <div id="list-inner-form" className="p-4 sm:p-6">
            <div className={ui.sectionHead}>
                <h2 className={ui.sectionTitle}>
                    {editing ? t('admin.companies.edit_company', 'Edit company') : t('admin.companies.new_company', 'New company')}
                </h2>
                <Button type="button" variant="secondary" onClick={close}>{t('admin.common.cancel', 'Cancel')}</Button>
            </div>
            <FormErrors errors={form.errors} />
            <form className={ui.fieldGrid} onSubmit={save}>
                <Field label={t('admin.companies.name', 'Company name')} error={form.errors.name} required>
                    <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label={t('admin.common.email', 'Email')} error={form.errors.email}>
                    <Input wide type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label={t('admin.common.phone', 'Phone')} error={form.errors.phone}>
                    <Input wide value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder="+1 555 123 4567" />
                </Field>
                <Field label={t('admin.companies.credit_limit', 'Credit limit')} error={form.errors.credit_limit}>
                    <Input wide type="number" min="0" step="0.01" value={form.data.credit_limit} onChange={(e) => form.setData('credit_limit', e.target.value)} />
                </Field>
                <Field label={t('admin.companies.net_days', 'Net days')} error={form.errors.net_days}>
                    <Input wide type="number" min="0" max="365" value={form.data.net_days} onChange={(e) => form.setData('net_days', e.target.value)} />
                </Field>
                <Field label={t('admin.common.status', 'Status')}>
                    <Select wide value={form.data.is_enabled ? '1' : '0'} onChange={(e) => form.setData('is_enabled', e.target.value === '1')}>
                        <option value="1">{t('admin.common.active', 'Active')}</option>
                        <option value="0">{t('admin.common.inactive', 'Inactive')}</option>
                    </Select>
                </Field>
                <div className={cx(ui.toolbar, ui.fieldSpan2)}>
                    <Button type="submit" icon="check" busy={form.processing}>
                        {editing ? t('admin.common.save', 'Save') : t('admin.companies.add', 'Add company')}
                    </Button>
                </div>
            </form>
        </div>
    );
}

export default function Companies({ companies, filters = {}, counts = {}, panel = null }) {
    const rows = companies?.data || [];
    const query = useMemo(() => listQuery(filters), [filters]);
    const formOpen = panel?.mode === 'create' || panel?.mode === 'edit';

    const panelParams = useMemo(() => {
        if (panel?.mode === 'edit' && panel.company?.id) return { edit: panel.company.id };
        if (panel?.mode === 'create') return { create: 1 };
        return {};
    }, [panel]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...panelParams, ...next };
        Object.keys(payload).forEach((key) => {
            if (payload[key] === '' || payload[key] == null || payload[key] === 'all') delete payload[key];
        });
        router.get('/admin/companies', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query, panelParams]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = (filters.sort || 'name') === column && (filters.dir || 'asc') === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, filters.sort, filters.dir]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.companies.title', 'Companies')}
                total={companies?.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.nav.companies', 'Companies') },
                ]}
                actions={!formOpen ? (
                    <>
                        <Button type="button" icon="plus" onClick={() => router.get('/admin/companies', { ...query, create: 1 }, { preserveScroll: true })}>
                            {t('admin.companies.add', 'Add company')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/companies"
                            importUrl="/admin/companies/import"
                            importSampleUrl="/admin/companies/import/sample"
                            exportUrl="/admin/companies/export"
                            query={query}
                            tableName="companies"
                        />
                    </>
                ) : null}
            />
            <StatusTabs tabs={companyTabs(counts)} value={filters.status || 'all'} onChange={(status) => apply({ status })} />
            <ListPanel footer={!formOpen ? <ListFooter pagination={companies} /> : null}>
                {formOpen ? <CompanyForm panel={panel} query={query} /> : (
                    <Table>
                        <THead>
                            <tr>
                                <SortHeader column="id" label="#" sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader column="name" label={t('admin.common.name', 'Name')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader column="email" label={t('admin.common.contact', 'Contact')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader column="users" label={t('admin.companies.members', 'Members')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader column="credit_limit" label={t('admin.companies.credit_limit', 'Credit limit')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader column="net_days" label={t('admin.companies.net_days', 'Net days')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <SortHeader col="status" column="status" label={t('admin.common.status', 'Status')} sort={filters.sort} dir={filters.dir} onSort={sortBy} />
                                <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                            </tr>
                            <tr>
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter}><ColumnFilter name="name" value={filters.name || ''} placeholder={t('admin.common.name', 'Name')} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilter}><ColumnFilter name="email" value={filters.email || ''} placeholder={t('admin.common.email', 'Email')} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilter}><ColumnRangeFilter minName="users_min" maxName="users_max" minValue={filters.users_min || ''} maxValue={filters.users_max || ''} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilter}><ColumnRangeFilter minName="credit_min" maxName="credit_max" minValue={filters.credit_min || ''} maxValue={filters.credit_max || ''} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilterStatus}>
                                    <ColumnFilter type="select" name="status" value={filters.status === 'all' ? '' : (filters.status || '')} placeholder={t('admin.common.all', 'All')} options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]} onApply={applyColumn} compact />
                                </Th>
                                <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/companies" /></Th>
                            </tr>
                        </THead>
                        <tbody>
                            {rows.length === 0 ? (
                                <EmptyState colSpan={8}>{t('admin.companies.empty', 'No companies yet.')}</EmptyState>
                            ) : rows.map((row) => (
                                <Tr key={row.id}>
                                    <Td><span className={ui.metaK}>#{row.id}</span></Td>
                                    <Td>
                                        <div className={ui.cellStack}>
                                            <span className={ui.cellStrong}>{row.name}</span>
                                            {row.phone ? <span className={ui.cellSub}>{row.phone}</span> : null}
                                        </div>
                                    </Td>
                                    <Td>{row.email || '—'}</Td>
                                    <Td>{row.users_count ?? row.users?.length ?? 0}</Td>
                                    <Td>{Number(row.credit_limit || 0).toFixed(2)}</Td>
                                    <Td>{row.net_days}</Td>
                                    <Td col="status"><Badge tone={row.is_enabled ? 'success' : 'neutral'}>{row.is_enabled ? t('admin.common.active', 'Active') : t('admin.common.inactive', 'Inactive')}</Badge></Td>
                                    <Td actions>
                                        <RowActions
                                            onEdit={() => router.get('/admin/companies', { ...query, edit: row.id }, { preserveScroll: true })}
                                            items={[{
                                                key: 'toggle',
                                                label: row.is_enabled ? t('admin.common.disable', 'Disable') : t('admin.common.enable', 'Enable'),
                                                icon: row.is_enabled ? 'ban' : 'check',
                                                onClick: () => router.put(`/admin/companies/${row.id}`, { ...row, is_enabled: !row.is_enabled }, { preserveScroll: true }),
                                            }]}
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
