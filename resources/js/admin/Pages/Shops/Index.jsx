import { router } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';
import Layout from '../Layout';
import GroupsPanel from './GroupsPanel';
import {
    Button,
    EmptyState,
    FilterBar,
    Icon,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    Select,
    Field,
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
} from '../../ui';

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'active', label: t('admin.common.active', 'Active') },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive') },
    ];
}

function PanelIntro({ icon, title, hint, action }) {
    return (
        <div className={ui.panelIntro}>
            <div className={ui.panelIntroMain}>
                <span className={ui.panelIntroIcon}>
                    <Icon name={icon} />
                </span>
                <div>
                    <h2 className={ui.panelIntroTitle}>{title}</h2>
                    <p className={ui.panelIntroHint}>{hint}</p>
                </div>
            </div>
            {action}
        </div>
    );
}

export default function Index({ stores, counts = {}, filters = {}, groups = [], default_shop_id, default_shops = [] }) {
    const rows = stores.data || [];
    const [defaultShopId, setDefaultShopId] = useState(String(default_shop_id || ''));

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        group: filters.group || 'all',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/shops', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = statusCountKpis(counts, {
        total: t('admin.shops.all_shops', 'All shops'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.nav.shops', 'Multishop')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.nav.shops', 'Multishop') }]}
                actions={
                    <Button type="button" variant="secondary" onClick={() => router.get('/admin/shops')}>
                        {t('admin.common.reset_filters', 'Reset filters')}
                    </Button>
                }
            />

            <KpiCards items={kpis} />

            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t('admin.shops.manage_hint', 'Manage shops and shop groups. A group is a wall between catalogs, like page categories for CMS pages.')}
            </p>

            <section className={`${ui.listPanel} mb-5`}>
                <div className={ui.panelIntro}>
                    <div className={ui.panelIntroMain}>
                        <span className={ui.panelIntroIcon}>
                            <Icon name="settings" />
                        </span>
                        <div>
                            <h2 className={ui.panelIntroTitle}>{t('admin.shops.multishop_options', 'Multishop options')}</h2>
                            <p className={ui.panelIntroHint}>
                                {t('admin.shops.default_shop_hint', 'A second shop on this copy needs its own virtual path, such as web-shop-2. An address that does not match a shop shows page not found.')}
                            </p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        icon="check"
                        onClick={() => router.post('/admin/shops/default', { store_id: defaultShopId }, { preserveScroll: true })}
                    >
                        {t('admin.common.save', 'Save')}
                    </Button>
                </div>
                <div className="px-5 pb-5">
                    <Field label={t('admin.shops.default_shop', 'Default shop')}>
                        <Select
                            wide
                            value={defaultShopId}
                            onChange={(e) => setDefaultShopId(e.target.value)}
                        >
                            {default_shops.map((shop) => (
                                <option key={shop.id} value={shop.id}>{shop.name}</option>
                            ))}
                        </Select>
                    </Field>
                </div>
            </section>

            <ListPanel
                className="mb-5"
                tabs={(
                    <>
                        <PanelIntro
                            icon="store"
                            title={t('admin.shops.shops', 'Shops')}
                            hint={t('admin.shops.shops_hint', 'Each shop has its own URL, catalog, and checkout.')}
                            action={(
                                <Button icon="plus" href="/admin/shops/create">
                                    {t('admin.shops.add_shop', 'Add shop')}
                                </Button>
                            )}
                        />
                        <StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />
                    </>
                )}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.shops.search_shops', 'Search by name or URL…')}
                        onSearchSubmit={(q) => apply({ q })}
                    >
                        <Select
                            value={query.status}
                            onChange={(e) => apply({ status: e.target.value })}
                            aria-label={t('admin.shops.status', 'Status')}
                        >
                            <option value="all">{t('admin.shops.status_all', 'Status: All')}</option>
                            <option value="active">{t('admin.shops.status_active', 'Status: Active')}</option>
                            <option value="inactive">{t('admin.shops.status_inactive', 'Status: Inactive')}</option>
                        </Select>
                        <Select
                            value={query.group}
                            onChange={(e) => apply({ group: e.target.value })}
                            aria-label={t('admin.shops.group', 'Group')}
                        >
                            <option value="all">{t('admin.shops.group_all', 'Group: All')}</option>
                            {groups.map((group) => (
                                <option key={group.id} value={String(group.id)}>{group.name}</option>
                            ))}
                        </Select>
                    </FilterBar>
                )}
                footer={<ListFooter pagination={stores} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.shops.name', 'Name')}</Th>
                            <Th>{t('admin.shops.group', 'Group')}</Th>
                            <Th>{t('admin.shops.url', 'URL')}</Th>
                            <Th>{t('admin.shops.theme', 'Theme')}</Th>
                            <Th>{t('admin.shops.status', 'Status')}</Th>
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={6}>{t('admin.shops.no_shops', 'No shops yet.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td>
                                    <div className="font-medium text-neutral-900">{row.name}</div>
                                    <div className={ui.muted}>{row.code}{row.is_default ? ` · ${t('admin.shops.default', 'Default')}` : ''}</div>
                                </Td>
                                <Td>{row.group || '—'}</Td>
                                <Td>
                                    {row.url ? (
                                        <a className="text-sky-700 underline break-all" href={row.url} target="_blank" rel="noreferrer">{row.url}</a>
                                    ) : '—'}
                                </Td>
                                <Td>{row.theme || 'default'}</Td>
                                <Td>
                                    <StatusToggle
                                        active={row.is_enabled}
                                        onToggle={() => {
                                            if (row.is_default && row.is_enabled) {
                                                return;
                                            }
                                            router.post(`/admin/shops/${row.id}/toggle`, {}, { preserveScroll: true });
                                        }}
                                    />
                                </Td>
                                <Td actions>
                                    <RowActions
                                        editHref={`/admin/shops/${row.id}/edit`}
                                        canEdit={row.actions?.edit !== false}
                                        canDelete={row.actions?.delete !== false}
                                        confirmMessage={t('admin.shops.delete_this_shop', 'Delete this shop?')}
                                        onDelete={() => {
                                            router.delete(`/admin/shops/${row.id}`);
                                        }}
                                        items={!row.is_default && row.is_enabled ? [{
                                            key: 'default',
                                            icon: 'star',
                                            label: t('admin.shops.set_default', 'Set as default'),
                                            onClick: () => router.post(`/admin/shops/${row.id}/default`, {}, { preserveScroll: true }),
                                        }] : []}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>

            <GroupsPanel groups={groups} />
        </Layout>
    );
}
