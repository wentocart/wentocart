import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Badge,
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
    SortHeader,
    KpiCards,
    statusCountKpis,
    StatusTabs,
    StatusToggle,
    listStatusFilterOptions,
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

export default function Index({ countries, counts = {}, filters = {}, default_code }) {
    const rows = countries.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        code: filters.code || '',
        currency: filters.currency || '',
        calling: filters.calling || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        regions_min: filters.regions_min || '',
        regions_max: filters.regions_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir || 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/localization/countries', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.countries.all_countries', 'All countries'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.countries.countries', 'Countries')}
                total={countries.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.countries.localization', 'Localization') },
                    { label: t('admin.countries.countries', 'Countries') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization">{t('admin.countries.overview', 'Overview')}</Button>
                        <Button icon="plus" href="/admin/localization/countries/create">{t('admin.countries.add_country', 'Add country')}</Button>
                        <ListSettings
                            listUrl="/admin/localization/countries"
                            importUrl="/admin/localization/countries/import"
                            importSampleUrl="/admin/localization/countries/import/sample"
                            exportUrl="/admin/localization/countries/export"
                            query={query}
                        />
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t(
                    'admin.countries.default_hint',
                    'Default country (:code) is pre-selected in checkout and addresses. Countries are deactivated, not deleted, so old orders stay valid.',
                ).replace(':code', default_code || '—')}
            </p>

            <KpiCards items={kpis} />

            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                footer={<ListFooter pagination={countries} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.countries.country', 'Country')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.countries.iso', 'ISO')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="currency" label={t('admin.countries.currency', 'Currency')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="calling" label={t('admin.countries.calling', 'Calling')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="regions" label={t('admin.countries.regions', 'Regions')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.countries.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.countries.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact />
                            </Th>
                            <Th className={ui.thFilter}><ColumnFilter name="name" value={query.name} placeholder={t('admin.countries.search_name', 'Search name')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="code" value={query.code} placeholder={t('admin.countries.search_iso', 'Search ISO')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="currency" value={query.currency} placeholder={t('admin.countries.search_currency', 'Search currency')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="calling" value={query.calling} placeholder={t('admin.countries.search_calling', 'Search calling')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnRangeFilter minName="regions_min" maxName="regions_max" minValue={query.regions_min} maxValue={query.regions_max} onApply={applyColumn} compact /></Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="status" value={query.status} type="select" options={listStatusFilterOptions()} onApply={applyColumn} />
                            </Th>
                            <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/localization/countries" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.countries.no_countries_yet', 'No countries yet.')}</EmptyState>
                        ) : rows.map((country) => (
                            <Tr key={country.id} className={country.is_default ? ui.trDefault : undefined}>
                                <Td className={ui.tdId}>{country.id}</Td>
                                <Td>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={ui.cellStrong}>{country.name}</span>
                                        {country.is_default ? <Badge tone="success">{t('admin.countries.default', 'Default')}</Badge> : null}
                                    </div>
                                    {country.timezone ? <div className={`${ui.cellSub} mt-0.5`}>{country.timezone}</div> : null}
                                </Td>
                                <Td>
                                    <span className={ui.cellSub}>{country.code}{country.iso3 ? ` / ${country.iso3}` : ''}</span>
                                </Td>
                                <Td>{country.default_currency_code || '—'}</Td>
                                <Td>{country.calling_code || '—'}</Td>
                                <Td>
                                    {country.regions_count > 0
                                        ? <Badge tone="info">{country.regions_count} {t('admin.countries.regions_label', 'regions')}</Badge>
                                        : country.has_regions
                                            ? <span className={ui.cellSub}>{t('admin.countries.install_pack_for_states', 'Install pack')}</span>
                                            : '—'}
                                </Td>
                                <Td>
                                    <StatusToggle
                                        active={!!country.is_enabled}
                                        onToggle={() => router.post(`/admin/localization/countries/${country.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/localization/countries/${country.id}/edit`}
                                        canDelete={false}
                                        items={country.is_default ? [] : [{
                                            key: 'default',
                                            label: t('admin.countries.set_default', 'Set default'),
                                            icon: 'check-circle',
                                            onClick: () => router.post(`/admin/localization/countries/${country.id}/default`, {}, { preserveScroll: true }),
                                        }]}
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
