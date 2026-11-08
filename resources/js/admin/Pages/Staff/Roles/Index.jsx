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
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
} from '../../../ui';

export default function Index({ roles, counts = {}, filters = {} }) {
    const rows = roles.data || [];

    const query = useMemo(() => ({
        q: filters.q || '',
        name: filters.name || '',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key]) {
                delete payload[key];
            }
        });
        router.get('/admin/staff/roles', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const total = Number(counts.all ?? 0);
    const kpis = statusCountKpis({ all: total, active: total, inactive: 0 }, {
        total: t('admin.roles.roles', 'Permission'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.roles.roles', 'Permission')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/staff/employees', label: t('admin.roles.staff', 'Staff') }, { label: t('admin.roles.roles', 'Permission') }]}
                actions={(
                    <Button icon="plus" href="/admin/staff/roles/create">
                        {t('admin.roles.add_role', 'Add permission')}
                    </Button>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.roles.search_roles', 'Search permissions…')}
                        onSearchSubmit={(q) => apply({ q })}
                    />
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.roles.delete_selected_roles_built_in_and_in_use_roles_', 'Delete selected permissions? Built-in and in-use permissions are skipped.')}
                            onDelete={(ids) => {
                                router.post('/admin/staff/roles/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={roles} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.roles.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <Th>{t('admin.roles.role', 'Permission')}</Th>
                            <Th>{t('admin.roles.pages', 'Pages')}</Th>
                            <Th>{t('admin.roles.used_by', 'Used by')}</Th>
                            <Th actions>{t('admin.roles.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.roles.enter_name', 'Enter name')} onApply={applyColumn} />
                            </Th>
                            <Th />
                            <Th />
                            <Th actions>
                                <FilterReset query={query} href="/admin/staff/roles" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={5}>{t('admin.roles.no_roles_yet_create_a_role_tick_the_pages_it_may', 'No permissions yet. Create a permission, tick the pages it may open, then attach it to a profile.')}</EmptyState>
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
                                        <span className={ui.cellIcon}><Icon name="settings" /></span>
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.name}</div>
                                            <div className={ui.cellSub}>
                                                {row.is_system ? <Badge tone="info">{t('admin.roles.built_in', 'Built-in')}</Badge> : row.slug}
                                            </div>
                                        </div>
                                    </div>
                                </Td>
                                <Td>{row.pages_count} page{row.pages_count === 1 ? '' : 's'}</Td>
                                <Td>
                                    <span className="text-sm text-neutral-700">
                                        {row.profiles_count} profile{row.profiles_count === 1 ? '' : 's'}
                                        {' · '}
                                        {row.admins_count} employee{row.admins_count === 1 ? '' : 's'}
                                    </span>
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/staff/roles/${row.id}/edit`}
                                        canEdit
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={`Delete “${row.name}”?`}
                                        onDelete={() => router.delete(`/admin/staff/roles/${row.id}`, { preserveScroll: true })}
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
