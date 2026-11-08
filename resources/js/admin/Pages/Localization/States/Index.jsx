import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    EmptyState,
    FilterBar,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    KpiCards,
    statusCountKpis,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
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

export default function Index({ states, counts = {}, filters = {}, countries = [] }) {
    const rows = states.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        country: filters.country || '',
        q: filters.q || '',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/localization/states', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.states.all_states', 'All states'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.states.states', 'States')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.nav.localization', 'Localization') },
                    { label: t('admin.states.states', 'States') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/countries">{t('admin.states.countries', 'Countries')}</Button>
                        <Button type="button" variant="secondary" href="/admin/localization/packs">{t('admin.states.install_pack', 'Install pack')}</Button>
                        <ListSettings
                            listUrl="/admin/localization/states"
                            importUrl="/admin/localization/states/import"
                            importSampleUrl="/admin/localization/states/import/sample"
                            exportUrl="/admin/localization/states/export"
                            query={query}
                        />
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t('admin.states.states_come_from_localization_packs_install_a_cou', 'States come from localization packs. Install a country pack (or edit a country) to add more.')}
            </p>

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.states.search_by_name_code_or_country', 'Search by name, code, or country…')}
                        onSearchSubmit={(q) => apply({ q })}
                    >
                        <Select value={query.status} onChange={(e) => apply({ status: e.target.value })} aria-label={t('admin.states.status', 'Status')}>
                            <option value="all">{t('admin.states.status_all', 'Status: All')}</option>
                            <option value="active">{t('admin.states.status_active', 'Status: Active')}</option>
                            <option value="inactive">{t('admin.states.status_inactive', 'Status: Inactive')}</option>
                        </Select>
                        <Select value={query.country} onChange={(e) => apply({ country: e.target.value })} aria-label={t('admin.states.country', 'Country')}>
                            <option value="">{t('admin.states.all_countries', 'All countries')}</option>
                            {countries.map((country) => (
                                <option key={country.code} value={country.code}>{country.name} ({country.code})</option>
                            ))}
                        </Select>
                    </FilterBar>
                )}
                footer={<ListFooter pagination={states} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.states.state', 'State')}</Th>
                            <Th>{t('admin.states.code', 'Code')}</Th>
                            <Th>{t('admin.states.country', 'Country')}</Th>
                            <Th>{t('admin.states.status', 'Status')}</Th>
                            <Th actions>{t('admin.states.action', 'Action')}</Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={5}>
                                {t('admin.states.no_states_yet_install_a_localization_pack', 'No states yet. Install a localization pack for India, US, Canada, Australia, or UAE.')}
                            </EmptyState>
                        ) : rows.map((state) => (
                            <Tr key={state.id}>
                                <Td>
                                    <span className={ui.cellStrong}>{state.name}</span>
                                </Td>
                                <Td>
                                    <span className={ui.cellSub}>{state.code}</span>
                                </Td>
                                <Td>
                                    {state.country ? (
                                        <div className={ui.cellStack}>
                                            <span className={ui.cellStrong}>{state.country.name}</span>
                                            <span className={ui.cellSub}>{state.country.code}</span>
                                        </div>
                                    ) : '—'}
                                </Td>
                                <Td>
                                    <StatusToggle
                                        active={!!state.is_enabled}
                                        onToggle={() => router.post(`/admin/localization/states/${state.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    {state.country ? (
                                        <RowActions
                                            editHref={`/admin/localization/countries/${state.country.id}/edit`}
                                            editLabel={t('admin.states.edit_country', 'Edit country')}
                                            canDelete={false}
                                        />
                                    ) : (
                                        <Badge tone="neutral">—</Badge>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
