import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from '../../components/Icons.jsx';
import { t } from '../../i18n.js';

export function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

export function queryFromParams(params, defaults) {
    const next = { ...defaults };
    params.forEach((value, key) => {
        if (key in next) {
            next[key] = value;
        }
    });
    return next;
}

export function useDebouncedApply(apply, delay = 350) {
    const timers = useRef({});
    return useCallback((patch) => {
        Object.entries(patch).forEach(([key, value]) => {
            if (timers.current[key]) {
                clearTimeout(timers.current[key]);
            }
            timers.current[key] = setTimeout(() => {
                apply({ [key]: value, page: '1' });
            }, delay);
        });
    }, [apply, delay]);
}

export function useListSelection(rows, idKey = 'product_id') {
    const [selected, setSelected] = useState([]);
    const ids = useMemo(() => rows.map((row) => row[idKey]), [rows, idKey]);
    const selectedIds = useMemo(() => selected.filter((id) => ids.includes(id)), [ids, selected]);

    useEffect(() => {
        setSelected((current) => current.filter((id) => ids.includes(id)));
    }, [ids]);

    return {
        selectedIds,
        selectedCount: selectedIds.length,
        allSelected: ids.length > 0 && selectedIds.length === ids.length,
        someSelected: selectedIds.length > 0 && selectedIds.length < ids.length,
        isSelected: (id) => selectedIds.includes(id),
        toggle: (id) => setSelected((current) => (current.includes(id) ? current.filter((row) => row !== id) : [...current, id])),
        toggleAll: () => setSelected((current) => (current.length === ids.length ? [] : ids)),
        clear: () => setSelected([]),
    };
}

function SortGlyph({ active, dir }) {
    if (active) {
        return (
            <Icon
                name={dir === 'asc' ? 'chevron-up' : 'chevron-down'}
                className="mv-list-sort-chevron is-active"
            />
        );
    }

    return (
        <span className="mv-list-sort-pair" aria-hidden="true">
            <Icon name="chevron-up" className="mv-list-sort-chevron" />
            <Icon name="chevron-down" className="mv-list-sort-chevron" />
        </span>
    );
}

export function ListColHeader({ label, className = '' }) {
    return (
        <span className={`mv-list-col-label${className ? ` ${className}` : ''}`}>
            {label}
        </span>
    );
}

export function SortHeader({ column, label, sort, dir, onSort, className = '' }) {
    const active = sort === column;
    return (
        <button type="button" className={`mv-list-sort${active ? ' is-active' : ''}${className ? ` ${className}` : ''}`} onClick={() => onSort(column)}>
            {label}
            <span className="mv-list-sort-icon">
                <SortGlyph active={active} dir={dir} />
            </span>
        </button>
    );
}

export function SellerBulkActions({ actions, count, busy, onChoose }) {
    const rootRef = useRef(null);
    const [open, setOpen] = useState(false);
    const disabled = count < 1;

    useEffect(() => {
        if (disabled) {
            setOpen(false);
        }
    }, [disabled]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div className="mv-bulk-bar">
            <div ref={rootRef} className="mv-bulk-wrap">
                <button
                    type="button"
                    className="mv-bulk-btn"
                    aria-haspopup="menu"
                    aria-expanded={open}
                    disabled={disabled || busy}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span>{t('storefront.seller.bulk_actions', 'Bulk actions')}</span>
                    <Icon name="chevron-down" className="mv-bulk-btn-icon" />
                </button>
                {open && !disabled ? (
                    <div className="mv-bulk-menu" role="menu">
                        <div className="mv-bulk-hint">
                            {t('storefront.seller.bulk_apply_to_selected', 'Apply to :count selected').replace(':count', String(count))}
                        </div>
                        {actions.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                className={item.tone === 'danger' ? 'mv-bulk-item is-danger' : 'mv-bulk-item'}
                                disabled={busy}
                                onClick={() => {
                                    setOpen(false);
                                    onChoose(item);
                                }}
                            >
                                {item.icon ? <Icon name={item.icon} className="h-4 w-4 shrink-0" /> : null}
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            {count > 0 ? (
                <span className="mv-muted-sm">{count} {t('storefront.seller.selected', 'selected')}</span>
            ) : null}
        </div>
    );
}

export function ColumnFilter({ name, value, placeholder, type = 'text', options = [], onApply, compact = false, statusFilter = false }) {
    const [draft, setDraft] = useState(value ?? '');
    useEffect(() => { setDraft(value ?? ''); }, [value]);

    const inputClass = [
        'mv-list-filter',
        compact ? 'mv-list-filter--compact' : null,
        statusFilter ? 'mv-list-filter--status' : null,
    ].filter(Boolean).join(' ');

    if (type === 'select') {
        return (
            <select
                className={inputClass}
                value={draft}
                aria-label={placeholder}
                onChange={(e) => {
                    setDraft(e.target.value);
                    onApply({ [name]: e.target.value });
                }}
            >
                {options.map((opt) => (
                    <option key={opt.value || 'all'} value={opt.value}>{opt.label}</option>
                ))}
            </select>
        );
    }

    return (
        <input
            className={inputClass}
            type={type}
            value={draft}
            placeholder={placeholder}
            aria-label={placeholder}
            onChange={(e) => {
                setDraft(e.target.value);
                onApply({ [name]: e.target.value });
            }}
        />
    );
}

export function ColumnRangeFilter({ minName, maxName, minValue, maxValue, onApply, compact = false, center = false }) {
    const [minDraft, setMinDraft] = useState(minValue ?? '');
    const [maxDraft, setMaxDraft] = useState(maxValue ?? '');
    useEffect(() => { setMinDraft(minValue ?? ''); setMaxDraft(maxValue ?? ''); }, [minValue, maxValue]);

    const wrapClass = [
        'mv-list-filter-range',
        compact ? 'mv-list-filter-range--compact' : null,
        center ? 'mv-list-filter-range--center' : null,
    ].filter(Boolean).join(' ');

    return (
        <div className={wrapClass}>
            <input
                className="mv-list-filter"
                type="number"
                value={minDraft}
                placeholder="Min"
                aria-label="Min"
                onChange={(e) => {
                    setMinDraft(e.target.value);
                    onApply({ [minName]: e.target.value, page: '1' });
                }}
            />
            <input
                className="mv-list-filter"
                type="number"
                value={maxDraft}
                placeholder="Max"
                aria-label="Max"
                onChange={(e) => {
                    setMaxDraft(e.target.value);
                    onApply({ [maxName]: e.target.value, page: '1' });
                }}
            />
        </div>
    );
}

export function ListPagination({ paginator, onPage }) {
    const current = paginator?.current_page || 1;
    const last = Math.max(1, paginator?.last_page || 1);
    const pages = [...new Set([1, last, current - 1, current, current + 1])]
        .filter((page) => page >= 1 && page <= last)
        .sort((a, b) => a - b);

    return (
        <div className="mv-list-pagination" role="navigation" aria-label={t('storefront.seller.pagination', 'Pagination')}>
            <button type="button" disabled={current <= 1} onClick={() => onPage(current - 1)} aria-label={t('storefront.seller.prev_page', 'Previous page')}>
                <Icon name="chevron-left" className="h-3.5 w-3.5" />
            </button>
            {pages.map((page, index) => {
                const prev = pages[index - 1];
                const showEllipsis = prev != null && page - prev > 1;
                return (
                    <span key={page} className="contents">
                        {showEllipsis ? <span className="mv-list-page-ellipsis" aria-hidden>…</span> : null}
                        <button
                            type="button"
                            className={page === current ? 'is-active' : undefined}
                            aria-current={page === current ? 'page' : undefined}
                            onClick={() => onPage(page)}
                        >
                            {page}
                        </button>
                    </span>
                );
            })}
            <button type="button" disabled={current >= last} onClick={() => onPage(current + 1)} aria-label={t('storefront.seller.next_page', 'Next page')}>
                <Icon name="chevron-right" className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

export function StatusTabs({ tabs, value, onChange }) {
    return (
        <div className="mv-status-tabs" role="tablist" aria-label={t('storefront.seller.order_status_tabs', 'Order status filters')}>
            {tabs.map((tab) => {
                const active = value === tab.key;
                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        className={active ? 'mv-status-tab is-active' : 'mv-status-tab'}
                        onClick={() => onChange?.(tab.key)}
                    >
                        {tab.label}
                        {tab.count != null ? (
                            <span className="mv-status-tab-count">{Number(tab.count).toLocaleString()}</span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}
