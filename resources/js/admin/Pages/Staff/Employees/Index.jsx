import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    BulkBar,
    BulkDelete,
    Button,
    ColumnFilter,
    EmptyState,
    FilterBar,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
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
} from '../../../ui';

function pageTabs() {
    return [
    { key: 'all', label: t('admin.common.all', 'All') },
    { key: 'active', label: t('admin.common.active', 'Active') },
    { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
    { key: 'super', label: t('admin.employees.super_admins', 'Super admins') },
];
}

export default function Index({ employees, counts = {}, filters = {}, teams = [], profiles = [] }) {
    const rows = employees.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        email: filters.email || '',
        team_id: filters.team_id || '',
        profile_id: filters.profile_id || '',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/staff/employees', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = [
        { key: 'total', label: t('admin.employees.employees', 'Employees'), value: (counts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'active', label: t('admin.common.active', 'Active'), value: (counts.active ?? 0).toLocaleString(), trend: 0 },
        { key: 'inactive', label: t('admin.employees.disabled', 'Disabled'), value: (counts.inactive ?? 0).toLocaleString(), trend: 0 },
        { key: 'super', label: t('admin.employees.super_admins', 'Super admins'), value: (counts.super ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.employees.employees', 'Employees')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.employees.employees', 'Employees') }]}
                actions={(
                    <Button icon="plus" href="/admin/staff/employees/create">
                        {t('admin.employees.add_employee', 'Add employee')}
                    </Button>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.employees.search_by_name_email_or_job_title', 'Search by name, email, or job title…')}
                        onSearchSubmit={(q) => apply({ q })}
                    >
                        <Select value={query.team_id || ''} onChange={(e) => apply({ team_id: e.target.value })} aria-label={t('admin.employees.team', 'Team')}>
                            <option value="">{t('admin.employees.team_all', 'Team: All')}</option>
                            {teams.map((team) => (
                                <option key={team.id} value={team.id}>{team.name}</option>
                            ))}
                        </Select>
                        <Select value={query.profile_id || ''} onChange={(e) => apply({ profile_id: e.target.value })} aria-label={t('admin.employees.profile', 'Profile')}>
                            <option value="">{t('admin.employees.profile_all', 'Profile: All')}</option>
                            {profiles.map((profile) => (
                                <option key={profile.id} value={profile.id}>{profile.name}</option>
                            ))}
                        </Select>
                    </FilterBar>
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.employees.delete_selected_employees_they_will_no_longer_be', 'Delete selected employees? They will no longer be able to sign in.')}
                            onDelete={(ids) => {
                                router.post('/admin/staff/employees/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={employees} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.employees.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <Th>{t('admin.employees.employee', 'Employee')}</Th>
                            <Th>{t('admin.employees.team', 'Team')}</Th>
                            <Th>{t('admin.employees.profile', 'Profile')}</Th>
                            <Th>{t('admin.employees.access', 'Access')}</Th>
                            <Th>{t('admin.employees.status', 'Status')}</Th>
                            <Th actions>{t('admin.employees.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.employees.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th />
                            <Th />
                            <Th />
                            <Th>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.employees.select_status', 'Select status')}
                                    options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.employees.disabled', 'Disabled') },
                                        { value: 'super', label: t('admin.employees.super_admin', 'Super admin') },
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th actions>
                                <FilterReset query={query} href="/admin/staff/employees" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.employees.no_employees_yet_add_one_and_assign_a_profile_so', 'No employees yet. Add one and assign a profile so they only see the pages you choose.')}</EmptyState>
                        ) : rows.map((row) => {
                            const canDelete = row.actions?.delete !== false;

                            return (
                                <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(row.id)}
                                                onChange={() => selection.toggle(row.id)}
                                                ariaLabel={`Select ${row.name}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <span className={ui.cellIcon}>
                                                <Icon name="users" />
                                            </span>
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{row.name}</div>
                                                <div className={ui.cellSub}>{row.email}{row.job_title ? ` · ${row.job_title}` : ''}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td>
                                        {row.team ? (
                                            <Badge tone={row.team.color || 'neutral'}>{row.team.name}</Badge>
                                        ) : (
                                            <span className={ui.muted}>{t('admin.employees.no_team', 'No team')}</span>
                                        )}
                                    </Td>
                                    <Td>{row.profile?.name || <span className={ui.muted}>{t('admin.employees.none', 'None')}</span>}</Td>
                                    <Td>
                                        {row.is_super ? (
                                            <Badge tone="warning">{t('admin.employees.super_admin', 'Super admin')}</Badge>
                                        ) : (
                                            <span className="text-sm text-neutral-700">{t('admin.employees.from_profile', 'From profile')}</span>
                                        )}
                                    </Td>
                                    <Td>
                                        <StatusToggle
                                            active={!!row.is_enabled}
                                            onToggle={() => {
                                                router.post(`/admin/staff/employees/${row.id}/toggle`, {}, { preserveScroll: true });
                                            }}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={`/admin/staff/employees/${row.id}/edit`}
                                            canEdit
                                            canDelete={canDelete}
                                            confirmMessage={`Delete “${row.name}”? They will no longer be able to sign in.`}
                                            onDelete={() => {
                                                router.delete(`/admin/staff/employees/${row.id}`, { preserveScroll: true });
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
