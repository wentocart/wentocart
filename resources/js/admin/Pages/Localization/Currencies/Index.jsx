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

export default function Index({ currencies, counts = {}, filters = {}, default_code }) {
    const rows = currencies.data || [];
    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        code: filters.code || '',
        symbol: filters.symbol || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        rate_min: filters.rate_min || '',
        rate_max: filters.rate_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir || 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') delete payload[key];
        });
        router.get('/admin/localization/currencies', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));
    const kpis = statusCountKpis(counts, {
        total: t('admin.currencies.currencies', 'Currencies'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.currencies.currencies', 'Currencies')}
                total={currencies.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.currencies.localization', 'Localization') },
                    { label: t('admin.currencies.currencies', 'Currencies') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization">{t('admin.currencies.overview', 'Overview')}</Button>
                        <Button icon="plus" href="/admin/localization/currencies/create">{t('admin.currencies.add_currency', 'Add currency')}</Button>
                        <ListSettings
                            listUrl="/admin/localization/currencies"
                            importUrl="/admin/localization/currencies/import"
                            importSampleUrl="/admin/localization/currencies/import/sample"
                            exportUrl="/admin/localization/currencies/export"
                            query={query}
                        />
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t(
                    'admin.currencies.default_rate_hint',
                    'Default currency (:code) has exchange rate 1. Other rates are relative to the default.',
                ).replace(':code', default_code || '—')}
            </p>
            <KpiCards items={kpis} />
            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                footer={<ListFooter pagination={currencies} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.currencies.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.currencies.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="symbol" label={t('admin.currencies.symbol', 'Symbol')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="rate" label={t('admin.currencies.rate', 'Rate')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.currencies.sample', 'Sample')}</Th>
                            <SortHeader col="status" column="status" label={t('admin.currencies.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.currencies.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilterId}><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="code" value={query.code} placeholder={t('admin.currencies.search_code', 'Search code')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="name" value={query.name} placeholder={t('admin.currencies.search_name', 'Search name')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="symbol" value={query.symbol} placeholder={t('admin.currencies.search_symbol', 'Search symbol')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnRangeFilter minName="rate_min" maxName="rate_max" minValue={query.rate_min} maxValue={query.rate_max} onApply={applyColumn} compact /></Th>
                            <Th className={ui.thFilter} />
                            <Th className={ui.thFilter}><ColumnFilter name="status" value={query.status} type="select" options={listStatusFilterOptions()} onApply={applyColumn} /></Th>
                            <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/localization/currencies" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.currencies.no_currencies_yet', 'No currencies yet.')}</EmptyState>
                        ) : rows.map((c) => (
                            <Tr key={c.id} className={c.is_default ? ui.trDefault : undefined}>
                                <Td className={ui.tdId}>{c.id}</Td>
                                <Td>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={ui.cellStrong}>{c.code}</span>
                                        {c.is_default ? <Badge tone="success">{t('admin.currencies.default', 'Default')}</Badge> : null}
                                    </div>
                                </Td>
                                <Td>{c.name}</Td>
                                <Td>{c.symbol} ({c.symbol_position})</Td>
                                <Td>{c.exchange_rate}</Td>
                                <Td>{c.sample}</Td>
                                <Td>
                                    <StatusToggle
                                        active={!!c.is_enabled}
                                        onToggle={() => router.post(`/admin/localization/currencies/${c.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/localization/currencies/${c.id}/edit`}
                                        canDelete={false}
                                        items={c.is_default ? [] : [{
                                            key: 'default',
                                            label: t('admin.currencies.set_default', 'Set default'),
                                            icon: 'check-circle',
                                            onClick: () => router.post(`/admin/localization/currencies/${c.id}/default`, {}, { preserveScroll: true }),
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
