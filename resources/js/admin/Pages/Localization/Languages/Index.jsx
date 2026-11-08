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

export default function Index({ languages, counts = {}, filters = {} }) {
    const rows = languages.data || [];
    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        name: filters.name || '',
        code: filters.code || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir || 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') delete payload[key];
        });
        router.get('/admin/localization/languages', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const tabs = [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
    ];

    const tabItems = tabs.map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));
    const kpis = statusCountKpis(counts, {
        total: t('admin.languages.title', 'Languages'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.languages.title', 'Languages')}
                total={languages.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.nav.localization', 'Localization') },
                    { label: t('admin.languages.title', 'Languages') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/translations">
                            {t('admin.nav.translations', 'Translations')}
                        </Button>
                        <Button icon="plus" href="/admin/localization/languages/create">
                            {t('admin.languages.add', 'Add language')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/localization/languages"
                            importUrl="/admin/localization/languages/import"
                            importSampleUrl="/admin/localization/languages/import/sample"
                            exportUrl="/admin/localization/languages/export"
                            query={query}
                        />
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t('admin.languages.hint', 'Enable languages the shop can use. English source copy stays in the code; you translate it on the Translations page.')}
            </p>
            <KpiCards items={kpis} />
            <ListPanel
                tabs={<StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />}
                footer={<ListFooter pagination={languages} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.languages.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="code" label={t('admin.languages.code', 'Code')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="status" label={t('admin.common.active', 'Active')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.edit', 'Edit')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilterId}><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="name" value={query.name} placeholder={t('admin.languages.search_name', 'Search name')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="code" value={query.code} placeholder={t('admin.languages.search_code', 'Search code')} onApply={applyColumn} /></Th>
                            <Th className={ui.thFilter}><ColumnFilter name="status" value={query.status} type="select" options={listStatusFilterOptions()} onApply={applyColumn} /></Th>
                            <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/localization/languages" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={5}>{t('admin.languages.no_languages_yet', 'No languages yet.')}</EmptyState>
                        ) : rows.map((language) => (
                            <Tr key={language.id} className={language.is_default ? ui.trDefault : undefined}>
                                <Td className={ui.tdId}>{language.id}</Td>
                                <Td>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={ui.cellStrong}>{language.name}</span>
                                        {language.is_default ? <Badge tone="success">{t('admin.languages.default', 'Default')}</Badge> : null}
                                    </div>
                                </Td>
                                <Td>{language.code}</Td>
                                <Td>
                                    <StatusToggle
                                        active={!!language.is_enabled}
                                        onToggle={() => router.post(`/admin/localization/languages/${language.id}/toggle`, {}, { preserveScroll: true })}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/localization/languages/${language.id}/edit`}
                                        canDelete={false}
                                        items={language.is_default ? [] : [{
                                            key: 'default',
                                            label: t('admin.languages.set_default', 'Set default'),
                                            icon: 'check-circle',
                                            onClick: () => router.post(`/admin/localization/languages/${language.id}/default`, {}, { preserveScroll: true }),
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
