import { router, usePage } from '@inertiajs/react';
import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import Layout from '../../Layout';
import { catalogImageUrl, catalogThumbError } from '../../../lib/catalogMedia';
import {
    BulkActions,
    catalogBulkActionItems,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    FilterReset,
    Icon,
    ListPanel,
    ListSettings,
    OrderCell,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    KpiCards,
    statusCountKpis,
    StatusToggle,
    listStatusLabels,
    listStatusFilterOptions,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    useDragReorder,
    useListSelection,
} from '../../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

const CATEGORY_EXPORT_FIELDS = [
    { name: 'name', label: t('admin.categories.name', 'Name'), type: 'text', placeholder: t('admin.categories.search_name', 'Search name') },
    { name: 'slug', label: t('admin.categories.slug', 'Slug'), type: 'text', placeholder: t('admin.categories.search_slug', 'Search slug') },
    { name: 'id_min', label: t('admin.common.id_min', 'ID min'), type: 'number' },
    { name: 'id_max', label: t('admin.common.id_max', 'ID max'), type: 'number' },
    { name: 'position_min', label: t('admin.categories.position_min', 'Position min'), type: 'number' },
    { name: 'position_max', label: t('admin.categories.position_max', 'Position max'), type: 'number' },
    {
        name: 'status',
        label: t('admin.categories.status', 'Status'),
        type: 'select',
        options: listStatusFilterOptions(),
    },
];

function inRange(value, min, max) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return false;
    }
    if (min !== '' && min != null && n < Number(min)) {
        return false;
    }
    if (max !== '' && max != null && n > Number(max)) {
        return false;
    }
    return true;
}

function nodeMatches(node, filters) {
    const nameQ = String(filters.name || '').trim().toLowerCase();
    const slugQ = String(filters.slug || '').trim().toLowerCase();
    if (nameQ && !String(node.name || '').toLowerCase().includes(nameQ)) {
        return false;
    }
    if (slugQ && !String(node.slug || '').toLowerCase().includes(slugQ)) {
        return false;
    }
    if (!inRange(node.id, filters.id_min, filters.id_max)) {
        return false;
    }
    if (!inRange(node.products_count ?? 0, filters.products_min, filters.products_max)) {
        return false;
    }
    if (!inRange(node.position ?? 0, filters.position_min, filters.position_max)) {
        return false;
    }
    if (filters.status === 'active' && !node.is_enabled) {
        return false;
    }
    if (filters.status === 'inactive' && node.is_enabled) {
        return false;
    }
    return true;
}

function filterTree(nodes, filters) {
    return (nodes || []).reduce((acc, node) => {
        const children = filterTree(node.children || [], filters);
        const matches = nodeMatches(node, filters);
        if (matches || children.length > 0) {
            acc.push({ ...node, children });
        }
        return acc;
    }, []);
}

function findSubtree(nodes, parentId) {
    if (!parentId || parentId === 'all') {
        return nodes;
    }
    if (parentId === 'root') {
        return (nodes || []).map((node) => ({ ...node, children: [] }));
    }
    const target = Number(parentId);
    const walk = (list) => {
        for (const node of list || []) {
            if (Number(node.id) === target) {
                return node.children || [];
            }
            const nested = walk(node.children || []);
            if (nested) {
                return nested;
            }
        }
        return null;
    };
    return walk(nodes) || [];
}

function collectExpandableIds(nodes, acc = []) {
    (nodes || []).forEach((node) => {
        if ((node.children || []).length > 0) {
            acc.push(node.id);
            collectExpandableIds(node.children, acc);
        }
    });
    return acc;
}

function collectOpenIdsForSearch(nodes, filters, ancestors = [], acc = new Set()) {
    const hasText = String(filters.name || '').trim() || String(filters.slug || '').trim();
    if (!hasText) {
        return acc;
    }
    (nodes || []).forEach((node) => {
        const childHits = new Set();
        collectOpenIdsForSearch(node.children || [], filters, [...ancestors, node.id], childHits);
        childHits.forEach((id) => acc.add(id));
        const selfMatch = nodeMatches(node, { ...filters, status: 'all', id_min: '', id_max: '', products_min: '', products_max: '', position_min: '', position_max: '' });
        if (selfMatch || childHits.size > 0) {
            ancestors.forEach((id) => acc.add(id));
            if ((node.children || []).length > 0) {
                acc.add(node.id);
            }
        }
    });
    return acc;
}

function sortNodes(nodes, sort, dir) {
    const factor = dir === 'desc' ? -1 : 1;
    const compare = (a, b) => {
        let av;
        let bv;
        switch (sort) {
            case 'name':
                av = String(a.name || '').toLowerCase();
                bv = String(b.name || '').toLowerCase();
                break;
            case 'slug':
                av = String(a.slug || '').toLowerCase();
                bv = String(b.slug || '').toLowerCase();
                break;
            case 'products_count':
                av = Number(a.products_count) || 0;
                bv = Number(b.products_count) || 0;
                break;
            case 'is_enabled':
                av = a.is_enabled ? 1 : 0;
                bv = b.is_enabled ? 1 : 0;
                break;
            case 'position':
                av = Number(a.position) || 0;
                bv = Number(b.position) || 0;
                break;
            case 'id':
            default:
                av = Number(a.id) || 0;
                bv = Number(b.id) || 0;
                break;
        }
        if (av < bv) return -1 * factor;
        if (av > bv) return 1 * factor;
        return (Number(a.id) || 0) - (Number(b.id) || 0);
    };

    return [...(nodes || [])]
        .map((node) => ({
            ...node,
            children: sortNodes(node.children || [], sort, dir),
        }))
        .sort(compare);
}

function collectVisibleNodes(nodes, openIds, out = []) {
    (nodes || []).forEach((node) => {
        out.push(node);
        if ((node.children || []).length > 0 && openIds.has(node.id)) {
            collectVisibleNodes(node.children, openIds, out);
        }
    });
    return out;
}

function CategorySiblingGroup({ nodes, depth, openIds, onToggle, canCreate, selection }) {
    const { orderedRows, canReorder, rowProps, dragHandleProps } = useDragReorder(nodes, {
        reorderUrl: '/admin/categories/reorder',
    });

    return orderedRows.map((node, index) => {
        const hasChildren = (node.children || []).length > 0;
        const open = openIds.has(node.id);

        return (
            <Fragment key={node.id}>
                <Tr selected={selection.isSelected(node.id)} {...rowProps(index)}>
                    <Td check>
                        <SelectionCheckbox
                            checked={selection.isSelected(node.id)}
                            onChange={() => selection.toggle(node.id)}
                            ariaLabel={`${t('admin.common.select', 'Select')} ${node.name}`}
                        />
                    </Td>
                    <Td col="id">{node.id}</Td>
                    <Td>
                        <div className={ui.row} style={{ paddingLeft: `${depth * 18}px` }}>
                            {hasChildren ? (
                                <button
                                    type="button"
                                    className={ui.treeToggle}
                                    aria-expanded={open}
                                    aria-label={open ? t('admin.categories.collapse', 'Collapse') : t('admin.categories.expand', 'Expand')}
                                    onClick={() => onToggle(node.id)}
                                >
                                    <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                                </button>
                            ) : (
                                <span className={ui.treeToggleSpacer} aria-hidden="true" />
                            )}
                            <img src={catalogImageUrl(node.image_url || node.image_path, 'sm')} alt="" className={ui.thumbLg} onError={(event) => catalogThumbError(event, 'sm')} />
                            <div className={ui.cellStack}>
                                <div className={ui.cellStrong}>{node.name}</div>
                                {hasChildren ? (
                                    <div className={ui.cellSub}>
                                        {node.children.length}
                                        {' '}
                                        {node.children.length === 1
                                            ? t('admin.categories.subcategory', 'subcategory')
                                            : t('admin.categories.subcategories', 'subcategories')}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </Td>
                    <Td>{node.slug || '—'}</Td>
                    <Td col="center">{node.products_count ?? 0}</Td>
                    <Td>
                        <OrderCell
                            position={node.position}
                            canReorder={canReorder}
                            dragHandleProps={dragHandleProps(index, node.name)}
                        />
                    </Td>
                    <Td col="status">
                        <StatusToggle
                            active={!!node.is_enabled}
                            onToggle={() => router.post(`/admin/categories/${node.id}/toggle`, {}, { preserveScroll: true })}
                        />
                    </Td>
                    <Td actions>
                        <RowActions
                            editHref={`/admin/categories/${node.id}/edit`}
                            confirmMessage={`${t('admin.common.delete', 'Delete')} “${node.name}”?`}
                            onDelete={() => router.delete(`/admin/categories/${node.id}`, { preserveScroll: true })}
                            items={canCreate !== false ? [{
                                key: 'add-child',
                                label: t('admin.categories.add_child', 'Add child'),
                                icon: 'plus',
                                onClick: () => router.get('/admin/categories/create', { parent_id: node.id }),
                            }] : []}
                        />
                    </Td>
                </Tr>
                {hasChildren && open ? (
                    <CategorySiblingGroup
                        nodes={node.children}
                        depth={depth + 1}
                        openIds={openIds}
                        onToggle={onToggle}
                        canCreate={canCreate}
                        selection={selection}
                    />
                ) : null}
            </Fragment>
        );
    });
}

export default function Index({ tree = [], counts = {}, filters = {}, can = {} }) {
    const { i18n } = usePage().props;
    const [bulkBusy, setBulkBusy] = useState(false);
    const query = useMemo(() => ({
        status: filters.status || 'all',
        name: filters.name || '',
        slug: filters.slug || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        products_min: filters.products_min || '',
        products_max: filters.products_max || '',
        position_min: filters.position_min || '',
        position_max: filters.position_max || '',
        parent_id: filters.parent_id || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/categories', cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const scopedTree = useMemo(() => {
        const rooted = findSubtree(tree, query.parent_id || 'all');
        const filtered = filterTree(rooted, query);
        return sortNodes(filtered, query.sort, query.dir);
    }, [tree, query]);

    const [openIds, setOpenIds] = useState(() => new Set());
    const [userToggled, setUserToggled] = useState(false);

    useEffect(() => {
        const hasText = String(query.name || '').trim() || String(query.slug || '').trim();
        if (hasText) {
            setOpenIds(collectOpenIdsForSearch(scopedTree, query));
            setUserToggled(false);
            return;
        }
        if (!userToggled) {
            setOpenIds(new Set());
        }
    }, [query, scopedTree, userToggled]);

    const visibleRows = useMemo(
        () => collectVisibleNodes(scopedTree, openIds),
        [scopedTree, openIds],
    );
    const selection = useListSelection(visibleRows);

    const runBulk = useCallback((url, payload = {}) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post(url, { ...payload, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    }, [bulkBusy, selection.clear, selection.selectedIds]);

    const toggle = (id) => {
        setUserToggled(true);
        setOpenIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const expandAll = () => {
        setUserToggled(true);
        setOpenIds(new Set(collectExpandableIds(scopedTree)));
    };
    const collapseAll = () => {
        setUserToggled(true);
        setOpenIds(new Set());
    };

    const statusLabels = listStatusLabels();
    const kpis = statusCountKpis(counts, {
        total: t('admin.categories.all_categories', 'All categories'),
        active: statusLabels.active,
        inactive: statusLabels.inactive,
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.categories.categories', 'Categories')}
                total={counts.all}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.categories.categories', 'Categories') }]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="ghost" onClick={expandAll}>{t('admin.categories.expand_all', 'Expand all')}</Button>
                        <Button type="button" variant="ghost" onClick={collapseAll}>{t('admin.categories.collapse_all', 'Collapse all')}</Button>
                        {can.create !== false ? (
                            <Button icon="plus" href="/admin/categories/create">
                                {t('admin.categories.add_category', 'Add category')}
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/categories"
                            importUrl="/admin/categories/import"
                            importSampleUrl="/admin/categories/import/sample"
                            exportUrl="/admin/categories/export"
                            query={query}
                            exportFields={CATEGORY_EXPORT_FIELDS}
                            showImportCsvSettings
                            importLanguages={i18n?.languages || []}
                            importDefaultLanguage={i18n?.default || i18n?.locale || 'en'}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={catalogBulkActionItems({
                            onActive: () => runBulk('/admin/categories/bulk-status', { enabled: 1 }),
                            onDeactivate: () => runBulk('/admin/categories/bulk-status', { enabled: 0 }),
                            onDuplicate: () => runBulk('/admin/categories/bulk-duplicate'),
                            onDelete: () => runBulk('/admin/categories/bulk-delete'),
                            deleteConfirm: t('admin.categories.delete_selected', 'Delete selected categories? Categories with children or product assignments will be skipped.'),
                        })}
                    />
                )}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.categories.category', 'Category')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="slug" label={t('admin.categories.slug', 'Slug')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="products_count" label={t('admin.categories.products', 'Products')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="position" label={t('admin.common.position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="status" column="is_enabled" label={t('admin.categories.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter
                                    minName="id_min"
                                    maxName="id_max"
                                    minValue={query.id_min}
                                    maxValue={query.id_max}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.categories.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="slug" value={query.slug} placeholder={t('admin.categories.search_slug', 'Search slug')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="products_min"
                                    maxName="products_max"
                                    minValue={query.products_min}
                                    maxValue={query.products_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnRangeFilter
                                    minName="position_min"
                                    maxName="position_max"
                                    minValue={query.position_min}
                                    maxValue={query.position_max}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={listStatusFilterOptions()}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/categories" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {scopedTree.length === 0 ? (
                            <EmptyState colSpan={8}>{t('admin.categories.no_categories_yet', 'No categories yet.')}</EmptyState>
                        ) : (
                            <CategorySiblingGroup
                                nodes={scopedTree}
                                depth={0}
                                openIds={openIds}
                                onToggle={toggle}
                                canCreate={can.create}
                                selection={selection}
                            />
                        )}
                    </tbody>
                </Table>
            </ListPanel>
        </Layout>
    );
}
