import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { Icon } from '../../components/Icons.jsx';
import { SellerConfirmDialog } from '../../components/SellerModal.jsx';
import {
    collectExpandableIds,
    collectOpenIdsForSearch,
    collectVisibleNodes,
    filterTree,
    sortNodes,
} from './categoryTree.js';
import { AddBtnLabel, SellerDeleteButton, SellerEditLink } from './sellerUi.jsx';

export { default as SellerCategoryForm } from './SellerCategoryForm.jsx';

const DEFAULT_QUERY = {
    status: 'all',
    name: '',
    slug: '',
    id_min: '',
    id_max: '',
    products_min: '',
    products_max: '',
    position_min: '',
    position_max: '',
    sort: 'position',
    dir: 'asc',
};

const STATUS_OPTIONS = [
    { value: '', label: t('storefront.seller.filter_all', 'All') },
    { value: 'active', label: t('storefront.seller.status_active', 'Active') },
    { value: 'inactive', label: t('storefront.seller.status_inactive', 'Inactive') },
];

const CATEGORY_BULK_ACTIONS = [
    { key: 'activate', label: t('storefront.seller.bulk_activate', 'Activate selection') },
    { key: 'deactivate', label: t('storefront.seller.bulk_deactivate', 'Deactivate selection') },
    { key: 'delete', label: t('storefront.seller.bulk_delete', 'Delete selection'), tone: 'danger' },
];

function SellerBulkActions({ count, busy, onChoose }) {
    const rootRef = useRef(null);
    const [open, setOpen] = useState(false);
    const disabled = count < 1;

    useEffect(() => {
        if (disabled) setOpen(false);
    }, [disabled]);

    useEffect(() => {
        if (!open) return undefined;
        const onDoc = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [open]);

    return (
        <div className="mv-bulk-bar">
            <div ref={rootRef} className="mv-bulk-wrap">
                <button type="button" className="mv-bulk-btn" aria-haspopup="menu" aria-expanded={open} disabled={disabled || busy} onClick={() => setOpen((value) => !value)}>
                    <span>{t('storefront.seller.bulk_actions', 'Bulk actions')}</span>
                    <Icon name="chevron-down" className="mv-bulk-btn-icon" />
                </button>
                {open && !disabled ? (
                    <div className="mv-bulk-menu" role="menu">
                        {CATEGORY_BULK_ACTIONS.map((item) => (
                            <button key={item.key} type="button" role="menuitem" className={item.tone === 'danger' ? 'mv-bulk-item is-danger' : 'mv-bulk-item'} disabled={busy} onClick={() => { setOpen(false); onChoose(item); }}>
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            {count > 0 ? <span className="mv-muted-sm">{count} {t('storefront.seller.selected', 'selected')}</span> : null}
        </div>
    );
}

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

function queryFromParams(params) {
    const next = { ...DEFAULT_QUERY };
    params.forEach((value, key) => {
        if (key in next) {
            next[key] = value;
        }
    });
    return next;
}

function useListSelection(rows) {
    const ids = useMemo(() => rows.map((row) => row.id), [rows]);
    const [selected, setSelected] = useState(() => new Set());
    useEffect(() => { setSelected(new Set()); }, [ids.join(',')]);
    const toggle = (id) => setSelected((current) => {
        const next = new Set(current);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        return next;
    });
    const toggleAll = () => setSelected((current) => (current.size === ids.length ? new Set() : new Set(ids)));
    return {
        selectedIds: [...selected],
        selectedCount: selected.size,
        allSelected: ids.length > 0 && selected.size === ids.length,
        someSelected: selected.size > 0 && selected.size < ids.length,
        isSelected: (id) => selected.has(id),
        toggle,
        toggleAll,
        clear: () => setSelected(new Set()),
    };
}

function SortGlyph({ active, dir }) {
    if (!active) {
        return (
            <span className="mv-list-sort-pair" aria-hidden="true">
                <Icon name="chevron-up" className="mv-list-sort-chevron" />
                <Icon name="chevron-down" className="mv-list-sort-chevron" />
            </span>
        );
    }
    return <Icon name={dir === 'asc' ? 'chevron-up' : 'chevron-down'} className="mv-list-sort-chevron is-active" />;
}

function SortHeader({ column, label, sort, dir, onSort }) {
    const active = sort === column;
    return (
        <button type="button" className={`mv-list-sort${active ? ' is-active' : ''}`} onClick={() => onSort(column)}>
            {label}
            <span className="mv-list-sort-icon"><SortGlyph active={active} dir={dir} /></span>
        </button>
    );
}

function ColumnFilter({ name, value, placeholder, type = 'text', options = [], onApply, compact = false }) {
    const [draft, setDraft] = useState(value ?? '');
    useEffect(() => { setDraft(value ?? ''); }, [value]);
    const cls = ['mv-list-filter', compact ? 'mv-list-filter--compact' : null].filter(Boolean).join(' ');
    if (type === 'select') {
        return (
            <select className={cls} value={draft} aria-label={placeholder} onChange={(e) => { setDraft(e.target.value); onApply({ [name]: e.target.value }); }}>
                {options.map((opt) => <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>)}
            </select>
        );
    }
    return <input className={cls} value={draft} placeholder={placeholder} aria-label={placeholder} onChange={(e) => { setDraft(e.target.value); onApply({ [name]: e.target.value }); }} />;
}

function ColumnRangeFilter({ minName, maxName, minValue, maxValue, onApply, center = false }) {
    const [minDraft, setMinDraft] = useState(minValue ?? '');
    const [maxDraft, setMaxDraft] = useState(maxValue ?? '');
    useEffect(() => { setMinDraft(minValue ?? ''); setMaxDraft(maxValue ?? ''); }, [minValue, maxValue]);
    const wrapClass = ['mv-list-filter-range', center ? 'mv-list-filter-range--center' : null].filter(Boolean).join(' ');
    return (
        <div className={wrapClass}>
            <input className="mv-list-filter" type="number" value={minDraft} placeholder="Min" aria-label="Min" onChange={(e) => { setMinDraft(e.target.value); onApply({ [minName]: e.target.value }); }} />
            <input className="mv-list-filter" type="number" value={maxDraft} placeholder="Max" aria-label="Max" onChange={(e) => { setMaxDraft(e.target.value); onApply({ [maxName]: e.target.value }); }} />
        </div>
    );
}

function CategoryRows({ nodes, depth, openIds, onToggle, selection, onToggleStatus, onDelete }) {
    return nodes.map((node) => {
        const hasChildren = (node.children || []).length > 0;
        const open = openIds.has(node.id);
        return (
            <Fragment key={node.id}>
                <tr>
                    <td className="mv-list-td-check">
                        <input type="checkbox" className="h-4 w-4 accent-[var(--shop-accent,#4545e6)]" checked={selection.isSelected(node.id)} aria-label={`${t('storefront.seller.select', 'Select')} ${node.name}`} onChange={() => selection.toggle(node.id)} />
                    </td>
                    <td className="mv-list-td-id">{node.id}</td>
                    <td>
                        <div className="mv-list-row" style={{ paddingInlineStart: `${depth * 1.125}rem` }}>
                            {hasChildren ? (
                                <button type="button" className="mv-tree-toggle" aria-expanded={open} onClick={() => onToggle(node.id)}>
                                    <Icon name={open ? 'chevron-down' : 'chevron-right'} className="h-4 w-4" />
                                </button>
                            ) : <span className="mv-tree-spacer" aria-hidden="true" />}
                            {node.image_url ? (
                                <img src={node.image_url} alt="" className="mv-list-thumb" />
                            ) : (
                                <span className="mv-list-thumb mv-list-thumb--icon" aria-hidden="true"><Icon name="grid" className="h-4 w-4" /></span>
                            )}
                            <div className="mv-list-cell-stack">
                                <div className="mv-list-cell-strong">{node.name}</div>
                                {hasChildren ? (
                                    <div className="mv-list-cell-sub">
                                        {node.children.length} {node.children.length === 1 ? t('storefront.seller.subcategory', 'subcategory') : t('storefront.seller.subcategories', 'subcategories')}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </td>
                    <td>{node.seller_slug || node.slug || '—'}</td>
                    <td className="mv-list-td-center">{node.products_count ?? 0}</td>
                    <td className="mv-list-td-id">{node.position ?? 0}</td>
                    <td>
                        <button type="button" className={`mv-status-toggle${node.is_enabled ? ' is-on' : ''}`} aria-pressed={!!node.is_enabled} onClick={() => onToggleStatus(node)}>
                            <span className="mv-status-toggle-knob" />
                        </button>
                    </td>
                    <td className="mv-list-td-actions">
                        <div className="mv-row-actions">
                            <SellerEditLink to={`/account/seller/categories/${node.id}`} />
                            <Link className="mv-link" to={`/account/seller/categories/new?parent_id=${node.id}`}>
                                <AddBtnLabel>{t('storefront.seller.add_child', 'Add child')}</AddBtnLabel>
                            </Link>
                            <SellerDeleteButton onClick={() => onDelete(node)} />
                        </div>
                    </td>
                </tr>
                {hasChildren && open ? (
                    <CategoryRows nodes={node.children} depth={depth + 1} openIds={openIds} onToggle={onToggle} selection={selection} onToggleStatus={onToggleStatus} onDelete={onDelete} />
                ) : null}
            </Fragment>
        );
    });
}

export default function SellerCategories() {
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams), [searchParams]);
    const [tree, setTree] = useState(null);
    const [counts, setCounts] = useState({ all: 0, active: 0, inactive: 0 });
    const [bulkBusy, setBulkBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [openIds, setOpenIds] = useState(() => new Set());
    const [userToggled, setUserToggled] = useState(false);

    const load = useCallback(() => api.get('/account/seller/categories').then((r) => {
        setTree(r.data.tree || []);
        setCounts(r.data.counts || { all: 0, active: 0, inactive: 0 });
    }).catch(() => {
        setTree([]);
        setCounts({ all: 0, active: 0, inactive: 0 });
    }), []);

    useEffect(() => { load(); }, [load]);

    const apply = useCallback((patch) => {
        setSearchParams(cleanQuery({ ...query, ...patch }), { replace: true });
    }, [query, setSearchParams]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const scopedTree = useMemo(() => sortNodes(filterTree(tree || [], query), query.sort, query.dir), [tree, query]);

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

    const visibleRows = useMemo(() => collectVisibleNodes(scopedTree, openIds), [scopedTree, openIds]);
    const selection = useListSelection(visibleRows);
    const hasFilters = Object.entries(query).some(([key, value]) => !['sort', 'dir'].includes(key) && value && value !== 'all');

    const toggleNode = (id) => {
        setUserToggled(true);
        setOpenIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const runBulk = async (action) => {
        const ids = selection.selectedIds.map(Number).filter((id) => id > 0);
        if (!ids.length) return;
        setBulkBusy(true);
        try {
            await csrf();
            if (action.key === 'delete') {
                await api.post('/account/seller/categories/bulk-delete', { ids });
            } else {
                await api.post('/account/seller/categories/bulk-status', { ids, enabled: action.key === 'activate' });
            }
            selection.clear();
            load();
        } finally {
            setBulkBusy(false);
            setConfirm(null);
        }
    };

    const toggleStatus = async (node) => {
        await csrf();
        await api.post(`/account/seller/categories/${node.id}/toggle`);
        load();
    };

    const deleteRow = async (node) => {
        if (!window.confirm(t('storefront.seller.delete_category_confirm', 'Delete this category?'))) {
            return;
        }
        await csrf();
        await api.delete(`/account/seller/categories/${node.id}`);
        load();
    };

    if (!tree) {
        return <PageSkeleton />;
    }

    return (
        <div className="mv-categories-page">
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">{t('storefront.seller.categories', 'Categories')} <span className="mv-page-title-count">({counts.all ?? 0})</span></h2>
                    <p className="mv-page-help">{t('storefront.seller.taxonomy_help', 'Saved to your seller catalog first, then copied to the store catalog.')}</p>
                </div>
                <div className="mv-toolbar-actions">
                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => { setUserToggled(true); setOpenIds(new Set(collectExpandableIds(scopedTree))); }}>{t('storefront.seller.expand_all', 'Expand all')}</button>
                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => { setUserToggled(true); setOpenIds(new Set()); }}>{t('storefront.seller.collapse_all', 'Collapse all')}</button>
                    <Link to="/account/seller/categories/new" className="shop-btn shop-btn-primary shop-btn-list-head">
                        <AddBtnLabel>{t('storefront.seller.add_category', 'Add category')}</AddBtnLabel>
                    </Link>
                </div>
            </div>

            <div className="mv-list-panel">
                <div className="mv-list-bulk">
                    <SellerBulkActions count={selection.selectedCount} busy={bulkBusy} onChoose={(action) => setConfirm({ type: 'bulk', action })} />
                </div>
                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-check">
                                    <input type="checkbox" className="h-4 w-4 accent-[var(--shop-accent,#4545e6)]" checked={selection.allSelected} ref={(el) => { if (el) el.indeterminate = selection.someSelected; }} aria-label={t('storefront.seller.select_all', 'Select all on this page')} onChange={selection.toggleAll} />
                                </th>
                                <th className="mv-list-th-id"><SortHeader column="id" label={t('storefront.seller.col_id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="name" label={t('storefront.seller.col_category', 'Category')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="slug" label={t('storefront.seller.slug', 'Slug')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-center"><SortHeader column="products_count" label={t('storefront.seller.col_products', 'Products')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-id"><SortHeader column="position" label={t('storefront.seller.col_position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="is_enabled" label={t('storefront.seller.col_status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-actions">{t('storefront.seller.col_actions', 'Actions')}</th>
                            </tr>
                            <tr>
                                <th className="mv-list-th-filter-check" />
                                <th className="mv-list-th-filter-id"><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter"><ColumnFilter name="name" value={query.name} placeholder={t('storefront.seller.search_name', 'Search name')} onApply={apply} /></th>
                                <th className="mv-list-th-filter"><ColumnFilter name="slug" value={query.slug} placeholder={t('storefront.seller.search_slug', 'Search slug')} onApply={apply} /></th>
                                <th className="mv-list-th-filter-center"><ColumnRangeFilter minName="products_min" maxName="products_max" minValue={query.products_min} maxValue={query.products_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter-id"><ColumnRangeFilter minName="position_min" maxName="position_max" minValue={query.position_min} maxValue={query.position_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter-status"><ColumnFilter name="status" value={query.status === 'all' ? '' : query.status} type="select" placeholder={t('storefront.seller.filter_all', 'All')} options={STATUS_OPTIONS} onApply={apply} compact /></th>
                                <th className="mv-list-th-filter-actions">
                                    {hasFilters ? (
                                        <button type="button" className="mv-list-filter-reset" title={t('storefront.seller.reset_filters', 'Reset filters')} aria-label={t('storefront.seller.reset_filters', 'Reset filters')} onClick={() => setSearchParams({}, { replace: true })}>↺</button>
                                    ) : null}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {scopedTree.length > 0 ? (
                                <CategoryRows nodes={scopedTree} depth={0} openIds={openIds} onToggle={toggleNode} selection={selection} onToggleStatus={toggleStatus} onDelete={deleteRow} />
                            ) : (
                                <tr>
                                    <td colSpan={8}>
                                        <div className="mv-list-empty-state">
                                            <p>
                                                {hasFilters
                                                    ? t('storefront.seller.no_categories_filtered', 'No categories match your filters.')
                                                    : t('storefront.seller.no_categories', 'Nothing here yet.')}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {confirm?.type === 'bulk' ? (
                <SellerConfirmDialog
                    open
                    title={confirm.action.label}
                    description={confirm.action.key === 'delete'
                        ? t('storefront.seller.bulk_delete_categories_confirm', 'Delete selected categories? Categories with children or products will be skipped.')
                        : t('storefront.seller.bulk_status_confirm', 'Apply this status change to selected categories?')}
                    confirmLabel={confirm.action.label}
                    busy={bulkBusy}
                    onConfirm={() => runBulk(confirm.action)}
                    onCancel={() => setConfirm(null)}
                />
            ) : null}
        </div>
    );
}
