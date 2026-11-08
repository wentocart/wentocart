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

export default function Index({ teams, counts = {}, filters = {} }) {
    const rows = teams.data || [];

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
        router.get('/admin/staff/teams', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.teams.teams', 'Teams'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.teams.teams', 'Teams')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/staff/employees', label: t('admin.teams.staff', 'Staff') }, { label: t('admin.teams.teams', 'Teams') }]}
                actions={(
                    <Button icon="plus" href="/admin/staff/teams/create">
                        {t('admin.teams.add_team', 'Add team')}
                    </Button>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.teams.search_teams', 'Search teams…')}
                        onSearchSubmit={(q) => apply({ q })}
                    />
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.teams.delete_selected_teams_teams_with_employees_are_s', 'Delete selected teams? Teams with employees are skipped.')}
                            onDelete={(ids) => {
                                router.post('/admin/staff/teams/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={teams} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.teams.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <Th>{t('admin.teams.team', 'Team')}</Th>
                            <Th>{t('admin.teams.default_profile', 'Default profile')}</Th>
                            <Th>{t('admin.teams.employees', 'Employees')}</Th>
                            <Th>{t('admin.teams.status', 'Status')}</Th>
                            <Th actions>{t('admin.teams.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.teams.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th />
                            <Th />
                            <Th />
                            <Th actions>
                                <FilterReset query={query} href="/admin/staff/teams" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={6}>{t('admin.teams.no_teams_yet_create_one_so_you_can_group_employe', 'No teams yet. Create one so you can group employees (Sales, Operations, and so on).')}</EmptyState>
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
                                        <span className={ui.cellIcon}><Icon name="users" /></span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.name}</div>
                                            <div className={ui.cellSub}>
                                                <Badge tone={row.color || 'neutral'}>{row.slug}</Badge>
                                                {row.description ? ` · ${row.description}` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </Td>
                                <Td>{row.default_profile || <span className={ui.muted}>{t('admin.teams.none', 'None')}</span>}</Td>
                                <Td>{row.admins_count}</Td>
                                <Td>
                                    <StatusToggle
                                        active={!!row.is_enabled}
                                        onToggle={() => router.post(`/admin/staff/teams/${row.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/staff/teams/${row.id}/edit`}
                                        canEdit
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={`Delete “${row.name}”?`}
                                        onDelete={() => router.delete(`/admin/staff/teams/${row.id}`, { preserveScroll: true })}
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
