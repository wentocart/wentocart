import { useEffect, useMemo, useState } from 'react';
import { t } from '../../i18n.js';

function categoryLabel(category) {
    return category?.name || `Category #${category?.id || ''}`;
}

function buildCategoryTree(categories) {
    const nodes = (categories || []).map((category) => ({ ...category, children: [] }));
    const byId = new Map(nodes.map((node) => [Number(node.id), node]));
    const roots = [];

    nodes.forEach((node) => {
        const parentId = node.parent_id ? Number(node.parent_id) : null;
        if (parentId && byId.has(parentId)) {
            byId.get(parentId).children.push(node);
        } else {
            roots.push(node);
        }
    });

    const sortNodes = (list) => {
        list.sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || Number(a.id) - Number(b.id));
        list.forEach((node) => sortNodes(node.children));
    };
    sortNodes(roots);

    return roots;
}

function filterCategoryTree(nodes, needle, selectedSet) {
    return (nodes || []).reduce((acc, node) => {
        const children = filterCategoryTree(node.children || [], needle, selectedSet);
        const name = categoryLabel(node).toLowerCase();
        const allowed = node.is_enabled !== false || selectedSet.has(Number(node.id));
        const matches = !needle || name.includes(needle);
        if (allowed && (matches || children.length > 0)) {
            acc.push({ ...node, children });
        }
        return acc;
    }, []);
}

function collectExpandableCategoryIds(nodes, acc = []) {
    (nodes || []).forEach((node) => {
        if ((node.children || []).length > 0) {
            acc.push(Number(node.id));
            collectExpandableCategoryIds(node.children, acc);
        }
    });
    return acc;
}

function collectOpenIdsForCategorySearch(nodes, needle, ancestors = [], acc = new Set()) {
    if (!needle) {
        return acc;
    }
    (nodes || []).forEach((node) => {
        const childHits = new Set();
        collectOpenIdsForCategorySearch(node.children || [], needle, [...ancestors, Number(node.id)], childHits);
        childHits.forEach((id) => acc.add(id));
        const name = categoryLabel(node).toLowerCase();
        const selfMatch = name.includes(needle);
        if (selfMatch || childHits.size > 0) {
            ancestors.forEach((id) => acc.add(id));
            if ((node.children || []).length > 0) {
                acc.add(Number(node.id));
            }
        }
    });
    return acc;
}

function CategoryTreeNode({
    node,
    depth,
    openIds,
    onToggle,
    selectedSet,
    defaultCategoryId,
    onToggleId,
    onSetDefault,
}) {
    const id = Number(node.id);
    const hasChildren = (node.children || []).length > 0;
    const open = openIds.has(id);
    const checked = selectedSet.has(id);
    const isDefault = checked && Number(defaultCategoryId) === id;

    return (
        <>
            <div className="flex items-center gap-1.5 py-1.5 pr-2 hover:bg-neutral-50" style={{ paddingLeft: `${8 + depth * 16}px` }}>
                {hasChildren ? (
                    <button
                        type="button"
                        className="grid h-6 w-6 shrink-0 place-items-center rounded text-neutral-500 hover:bg-neutral-100"
                        aria-expanded={open}
                        aria-label={open ? t('storefront.seller.collapse', 'Collapse') : t('storefront.seller.expand', 'Expand')}
                        onClick={() => onToggle(id)}
                    >
                        <span aria-hidden="true">{open ? '▾' : '▸'}</span>
                    </button>
                ) : (
                    <span className="h-6 w-6 shrink-0" aria-hidden="true" />
                )}
                <label className="flex min-w-0 flex-1 items-center gap-2 text-sm text-neutral-800">
                    <input
                        type="checkbox"
                        checked={checked}
                        onChange={(e) => onToggleId(id, e.target.checked)}
                    />
                    <span className="min-w-0 flex-1 truncate" title={categoryLabel(node)}>
                        {categoryLabel(node)}
                    </span>
                </label>
                {isDefault ? (
                    <span className="shrink-0 text-[11px] font-semibold text-[var(--shop-accent,#1c523b)]">
                        {t('storefront.seller.default', 'Default')}
                    </span>
                ) : checked ? (
                    <button
                        type="button"
                        className="shrink-0 text-[11px] text-neutral-500 hover:text-neutral-800"
                        onClick={() => onSetDefault(id)}
                    >
                        {t('storefront.seller.set_default', 'Set default')}
                    </button>
                ) : hasChildren ? (
                    <span className="shrink-0 text-[11px] text-neutral-400">{node.children.length}</span>
                ) : null}
            </div>
            {hasChildren && open
                ? node.children.map((child) => (
                    <CategoryTreeNode
                        key={child.id}
                        node={child}
                        depth={depth + 1}
                        openIds={openIds}
                        onToggle={onToggle}
                        selectedSet={selectedSet}
                        defaultCategoryId={defaultCategoryId}
                        onToggleId={onToggleId}
                        onSetDefault={onSetDefault}
                    />
                ))
                : null}
        </>
    );
}

/**
 * Backoffice-style category tree picker (search, expand/collapse, multi-select + default).
 * Uses shared shop/Tailwind classes — no seller-specific CSS.
 */
export default function CategoryTreePicker({
    categories = [],
    selectedIds = [],
    defaultCategoryId = '',
    onChange,
    onDefaultChange,
    error = null,
}) {
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const selected = selectedIds.map(Number);
    const selectedKey = selected.join(',');
    const selectedSet = useMemo(() => new Set(selected), [selectedKey]);
    const tree = useMemo(() => buildCategoryTree(categories), [categories]);
    const visible = useMemo(
        () => filterCategoryTree(tree, needle, selectedSet),
        [tree, needle, selectedSet],
    );
    const [openIds, setOpenIds] = useState(() => new Set());
    const [userToggled, setUserToggled] = useState(false);

    useEffect(() => {
        if (needle) {
            setOpenIds(collectOpenIdsForCategorySearch(visible, needle));
            setUserToggled(false);
            return;
        }
        if (!userToggled) {
            setOpenIds(new Set());
        }
    }, [needle, visible, userToggled]);

    const selectedCategories = selected
        .map((id) => categories.find((category) => Number(category.id) === id))
        .filter(Boolean);

    const toggleOpen = (id) => {
        setUserToggled(true);
        setOpenIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const toggleId = (id, checked) => {
        const next = checked ? [...selected, id] : selected.filter((value) => value !== id);
        onChange?.(next);
        if (!checked && Number(defaultCategoryId) === Number(id)) {
            onDefaultChange?.(next[0] || '');
        } else if (checked && !defaultCategoryId) {
            onDefaultChange?.(id);
        }
    };

    const setDefault = (id) => {
        if (!selectedSet.has(Number(id))) return;
        onDefaultChange?.(id);
    };

    return (
        <div className="space-y-3">
            <input
                className="shop-input"
                type="search"
                value={query}
                placeholder={t('storefront.seller.search_categories', 'Search categories')}
                onChange={(e) => setQuery(e.target.value)}
                aria-label={t('storefront.seller.search_categories', 'Search categories')}
            />

            {selectedCategories.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                    {selectedCategories.map((category) => {
                        const id = Number(category.id);
                        const isDefault = Number(defaultCategoryId) === id;
                        return (
                            <span key={id} className="inline-flex items-center gap-1 rounded-full border border-neutral-200 bg-neutral-50 px-2.5 py-1 text-xs">
                                {categoryLabel(category)}
                                {isDefault ? ` · ${t('storefront.seller.default', 'Default')}` : ''}
                                <button
                                    type="button"
                                    className="text-neutral-500 hover:text-neutral-800"
                                    aria-label={`${t('storefront.seller.remove', 'Remove')} ${categoryLabel(category)}`}
                                    onClick={() => toggleId(id, false)}
                                >
                                    ×
                                </button>
                            </span>
                        );
                    })}
                </div>
            ) : (
                <p className="mv-muted-sm">{t('storefront.seller.category_tree_help', 'Select categories. Mark one as default for the product URL.')}</p>
            )}

            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    className="shop-btn shop-btn-ghost"
                    onClick={() => {
                        setUserToggled(true);
                        setOpenIds(new Set(collectExpandableCategoryIds(visible)));
                    }}
                >
                    {t('storefront.seller.expand_all', 'Expand all')}
                </button>
                <button
                    type="button"
                    className="shop-btn shop-btn-ghost"
                    onClick={() => {
                        setUserToggled(true);
                        setOpenIds(new Set());
                    }}
                >
                    {t('storefront.seller.collapse_all', 'Collapse all')}
                </button>
            </div>

            <div className={`max-h-72 overflow-auto rounded-lg border bg-white ${error ? 'border-red-400' : 'border-neutral-200'}`}>
                {visible.map((node) => (
                    <CategoryTreeNode
                        key={node.id}
                        node={node}
                        depth={0}
                        openIds={openIds}
                        onToggle={toggleOpen}
                        selectedSet={selectedSet}
                        defaultCategoryId={defaultCategoryId}
                        onToggleId={toggleId}
                        onSetDefault={setDefault}
                    />
                ))}
                {categories.length === 0 ? (
                    <div className="px-3 py-4 text-sm text-neutral-500">{t('storefront.seller.no_categories', 'No categories yet.')}</div>
                ) : null}
                {categories.length > 0 && visible.length === 0 ? (
                    <div className="px-3 py-4 text-sm text-neutral-500">{t('storefront.seller.no_matching_categories', 'No matching categories.')}</div>
                ) : null}
            </div>
            {error ? <em className="shop-field-error" role="alert">{error}</em> : null}
        </div>
    );
}
