import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
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
    SelectionCheckbox,
    KpiCards,
    statusCountKpis,
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
];
}

export default function Index({ profiles, counts = {}, filters = {} }) {
    const rows = profiles.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/staff/profiles', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.profiles.profiles', 'Profiles'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.profiles.profiles', 'Profiles')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/staff/employees', label: t('admin.profiles.staff', 'Staff') }, { label: t('admin.profiles.profiles', 'Profiles') }]}
                actions={(
                    <Button icon="plus" href="/admin/staff/profiles/create">
                        {t('admin.profiles.add_profile', 'Add profile')}
                    </Button>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.profiles.search_profiles', 'Search profiles…')}
                        onSearchSubmit={(q) => apply({ q })}
                    />
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.profiles.delete_selected_profiles_profiles_still_assigned', 'Delete selected profiles? Profiles still assigned to employees are skipped.')}
                            onDelete={(ids) => {
                                router.post('/admin/staff/profiles/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={profiles} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.profiles.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <Th>{t('admin.profiles.profile', 'Profile')}</Th>
                            <Th>{t('admin.profiles.pages', 'Pages')}</Th>
                            <Th>{t('admin.profiles.teams', 'Teams')}</Th>
                            <Th>{t('admin.profiles.employees', 'Employees')}</Th>
                            <Th>{t('admin.profiles.status', 'Status')}</Th>
                            <Th actions>{t('admin.profiles.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.profiles.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th />
                            <Th />
                            <Th />
                            <Th />
                            <Th actions>
                                <FilterReset query={query} href="/admin/staff/profiles" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.profiles.no_profiles_yet_a_profile_is_a_job_template_pick', 'No profiles yet. Create a profile, tick the admin pages it may open, then assign it to a team.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    {row.actions?.delete !== false ? (
                                        <SelectionCheckbox
                                            checked={selection.isSelected(row.id)}
                                            onChange={() => selection.toggle(row.id)}
                                            ariaLabel={`Select ${row.name}`}
                                        />
                                    ) : null}
                                </Td>
                                <Td>
                                    <div className={ui.row}>
                                        <span className={ui.cellIcon}><Icon name="file" /></span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.name}</div>
                                            {row.description ? <div className={ui.cellSub}>{row.description}</div> : null}
                                        </div>
                                    </div>
                                </Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">{row.pages_count ?? 0}</span>
                                </Td>
                                <Td>{row.teams_count ?? 0}</Td>
                                <Td>{row.admins_count}</Td>
                                <Td>
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/staff/profiles/${row.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/staff/profiles/${row.id}/edit`}
                                        canEdit
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={`Delete “${row.name}”?`}
                                        onDelete={() => router.delete(`/admin/staff/profiles/${row.id}`, { preserveScroll: true })}
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
