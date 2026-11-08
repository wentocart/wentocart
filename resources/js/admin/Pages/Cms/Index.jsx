import { router } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../Layout';
import CategoriesPanel from './CategoriesPanel';
import {
    Badge,
    BulkBar,
    BulkDelete,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterBar,
    FilterReset,
    Icon,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    useListSelection,
    ui,
    t,
    KpiCards,
} from '../../ui';

function pageTabs() {
    return [
    { key: 'all', label: t('admin.common.all', 'All') },
    { key: 'published', label: t('admin.cms.published', 'Published') },
    { key: 'draft', label: t('admin.cms.draft', 'Draft') },
    { key: 'archived', label: t('admin.cms.archived', 'Archived') },
];
}

const statusTone = {
    published: 'success',
    draft: 'warning',
    archived: 'danger',
};

const categoryTone = {
    info: 'info',
    legal: 'primary',
    general: 'neutral',
};

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

export default function Index({ pages, counts = {}, filters = {}, categories = [], categoryCatalog = [] }) {
    const rows = pages.data || [];

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        title: filters.title || '',
        slug: filters.slug || '',
        category: filters.category || 'all',
        visible: filters.visible || 'all',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'updated_at',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/cms', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.sort, query.dir]);

    const selection = useListSelection(rows, {
        canSelect: (row) => row.actions?.delete !== false,
    });

    const tabItems = pageTabs().map((tab) => ({ ...tab, count: counts[tab.key] ?? 0 }));

    const kpis = [
        { key: 'total', label: t('admin.common.all', 'All'), value: (counts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'published', label: t('admin.cms.published', 'Published'), value: (counts.published ?? 0).toLocaleString(), trend: 0 },
        { key: 'draft', label: t('admin.cms.draft', 'Draft'), value: (counts.draft ?? 0).toLocaleString(), trend: 0 },
        { key: 'archived', label: t('admin.cms.archived', 'Archived'), value: (counts.archived ?? 0).toLocaleString(), trend: 0 },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.cms.pages', 'Pages')}
                total={pages.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.cms.pages', 'Pages') }]}
                actions={(
                    <ListSettings
                        listUrl="/admin/cms"
                        importUrl="/admin/cms/import"
                        importSampleUrl="/admin/cms/import/sample"
                        exportUrl="/admin/cms/export"
                        query={query}
                    />
                )}
            />

            <KpiCards items={kpis} />

            <p className={`${ui.muted} -mt-3 mb-5`}>{t('admin.cms.manage_all_cms_pages_and_page_categories_for_you', 'Manage all CMS pages and page categories for your store.')}</p>

            <ListPanel
                className="mb-5"
                tabs={(
                    <>
                        <PanelIntro
                            icon="file"
                            title={t('admin.cms.cms_pages', 'CMS Pages')}
                            hint={t('admin.cms.create_and_manage_content_pages_displayed_on_you', 'Create and manage content pages displayed on your store.')}
                            action={(
                                <Button icon="plus" href="/admin/cms/create">
                                    {t('admin.cms.add_cms_page', 'Add CMS Page')}
                                </Button>
                            )}
                        />
                        <StatusTabs tabs={tabItems} value={query.status} onChange={(status) => apply({ status })} />
                    </>
                )}
                filters={(
                    <FilterBar
                        search={query.q}
                        searchPlaceholder={t('admin.cms.search_pages', 'Search pages…')}
                        onSearchSubmit={(q) => apply({ q })}
                    >
                        <Select value={query.status} onChange={(e) => apply({ status: e.target.value })} aria-label={t('admin.cms.status', 'Status')}>
                            <option value="all">{t('admin.cms.status_all', 'Status: All')}</option>
                            <option value="published">{t('admin.cms.status_published', 'Status: Published')}</option>
                            <option value="draft">{t('admin.cms.status_draft', 'Status: Draft')}</option>
                            <option value="archived">{t('admin.cms.status_archived', 'Status: Archived')}</option>
                        </Select>
                        <Select value={query.category} onChange={(e) => apply({ category: e.target.value })} aria-label={t('admin.cms.category', 'Category')}>
                            <option value="all">{t('admin.cms.category_all', 'Category: All')}</option>
                            <option value="none">{t('admin.cms.category_none', 'Category: None')}</option>
                            {categories.map((cat) => (
                                <option key={cat.id} value={String(cat.id)}>{cat.name}</option>
                            ))}
                        </Select>
                    </FilterBar>
                )}
                bulk={(
                    <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                        <BulkDelete
                            ids={selection.selectedIds}
                            confirmMessage={t('admin.cms.delete_selected_pages', 'Delete selected pages?')}
                            onDelete={(ids) => {
                                router.post('/admin/cms/bulk-delete', { ids }, {
                                    preserveScroll: true,
                                    onSuccess: () => selection.clear(),
                                });
                            }}
                        />
                    </BulkBar>
                )}
                footer={<ListFooter pagination={pages} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.cms.select_all_on_this_page', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="title" label={t('admin.cms.page_title', 'Page Title')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="slug" label={t('admin.cms.url_slug', 'URL (Slug)')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="category" label={t('admin.cms.category', 'Category')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="status" label={t('admin.cms.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="visible" label={t('admin.cms.visible', 'Visible')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="updated_at" label={t('admin.cms.last_updated', 'Last Updated')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.cms.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact />
                            </Th>
                            <Th>
                                <ColumnFilter name="title" value={query.title} placeholder={t('admin.cms.enter_title', 'Enter title')} onApply={applyColumn} />
                            </Th>
                            <Th>
                                <ColumnFilter name="slug" value={query.slug} placeholder={t('admin.cms.enter_slug', 'Enter slug')} onApply={applyColumn} />
                            </Th>
                            <Th>
                                <ColumnFilter
                                    name="category"
                                    value={query.category === 'all' ? '' : query.category}
                                    type="select"
                                    placeholder={t('admin.cms.select_category', 'Select category')}
                                    options={[
                                        { value: 'none', label: t('admin.cms.none', 'None') },
                                        ...categories.map((cat) => ({ value: String(cat.id), label: cat.name })),
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.cms.select_status', 'Select status')}
                                    options={[
                                        { value: 'published', label: t('admin.cms.published', 'Published') },
                                        { value: 'draft', label: t('admin.cms.draft', 'Draft') },
                                        { value: 'archived', label: t('admin.cms.archived', 'Archived') },
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th>
                                <ColumnFilter name="visible" value={query.visible === 'all' ? '' : query.visible} type="select" placeholder={t('admin.common.all', 'All')} options={[
                                    { value: 'visible', label: t('admin.cms.visible', 'Visible') },
                                    { value: 'hidden', label: t('admin.cms.hidden', 'Hidden') },
                                ]} onApply={applyColumn} compact />
                            </Th>
                            <Th />
                            <Th actions><FilterReset query={query} href="/admin/cms" /></Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={9}>{t('admin.cms.no_cms_pages_yet', 'No CMS pages yet.')}</EmptyState>
                        ) : rows.map((page) => {
                            const canEdit = page.actions?.edit !== false;
                            const canDelete = page.actions?.delete !== false;

                            return (
                                <Tr key={page.id} selected={selection.isSelected(page.id)}>
                                    <Td check>
                                        {canDelete ? (
                                            <SelectionCheckbox
                                                checked={selection.isSelected(page.id)}
                                                onChange={() => selection.toggle(page.id)}
                                                ariaLabel={`Select ${page.title}`}
                                            />
                                        ) : null}
                                    </Td>
                                    <Td col="id">{page.id}</Td>
                                    <Td>
                                        <div className={ui.row}>
                                            <span className={ui.cellIcon}><Icon name="file" /></span>
                                            <span className={ui.cellStrong}>{page.title}</span>
                                        </div>
                                    </Td>
                                    <Td>
                                        <span className={ui.cellSub}>/{page.slug}</span>
                                    </Td>
                                    <Td>
                                        {page.category ? (
                                            <Badge tone={categoryTone[page.category_slug] || 'neutral'}>
                                                {page.category}
                                            </Badge>
                                        ) : '—'}
                                    </Td>
                                    <Td>
                                        <Badge tone={statusTone[page.status] || 'neutral'} dot>
                                            {page.status_label}
                                        </Badge>
                                    </Td>
                                    <Td>
                                        <button
                                            type="button"
                                            className={ui.menuBtn}
                                            title={page.is_visible ? t('admin.cms.visible_click_to_hide', 'Visible — click to hide') : t('admin.cms.hidden_click_to_show', 'Hidden — click to show')}
                                            onClick={() => router.post(`/admin/cms/${page.id}/toggle-visibility`, {}, { preserveScroll: true })}
                                        >
                                            <Icon name={page.is_visible ? 'eye' : 'eye-off'} />
                                        </button>
                                    </Td>
                                    <Td>
                                        <span className={ui.cellSub}>{page.updated_at}</span>
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            editHref={canEdit ? `/admin/cms/${page.id}/edit` : undefined}
                                            canEdit={canEdit}
                                            canDelete={canDelete}
                                            onDelete={canDelete ? () => {
                                                if (window.confirm(`Delete “${page.title}”?`)) {
                                                    router.delete(`/admin/cms/${page.id}`);
                                                }
                                            } : undefined}
                                        />
                                    </Td>
                                </Tr>
                            );
                        })}
                    </tbody>
                </Table>
            </ListPanel>

            <CategoriesPanel categories={categoryCatalog} />
        </Layout>
    );
}
