import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { Icon } from '../../components/Icons.jsx';
import { SellerConfirmDialog } from '../../components/SellerModal.jsx';
import { filterRows, sortRows } from './featureListUtils.js';
import { AddBtnLabel, SellerDeleteButton, SellerEditLink } from './sellerUi.jsx';

export { default as SellerFeatureForm } from './SellerFeatureForm.jsx';

const DEFAULT_QUERY = {
    status: 'all',
    name: '',
    type: '',
    id_min: '',
    id_max: '',
    values_min: '',
    values_max: '',
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

const FEATURE_BULK_ACTIONS = [
    { key: 'activate', label: t('storefront.seller.bulk_activate', 'Activate selection') },
    { key: 'deactivate', label: t('storefront.seller.bulk_deactivate', 'Deactivate selection') },
    { key: 'delete', label: t('storefront.seller.bulk_delete', 'Delete selection'), tone: 'danger' },
];

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
                        {FEATURE_BULK_ACTIONS.map((item) => (
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

export default function SellerFeatures() {
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams), [searchParams]);
    const [rows, setRows] = useState(null);
    const [counts, setCounts] = useState({ all: 0, active: 0, inactive: 0 });
    const [typeOptions, setTypeOptions] = useState([]);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);

    const typeFilterOptions = useMemo(
        () => [{ value: '', label: t('storefront.seller.filter_all', 'All') }, ...typeOptions],
        [typeOptions],
    );

    const load = useCallback(() => api.get('/account/seller/features').then((r) => {
        setRows(r.data.rows || []);
        setCounts(r.data.counts || { all: 0, active: 0, inactive: 0 });
        setTypeOptions(r.data.type_options || []);
    }).catch(() => {
        setRows([]);
        setCounts({ all: 0, active: 0, inactive: 0 });
        setTypeOptions([]);
    }), []);

    useEffect(() => { load(); }, [load]);

    const apply = useCallback((patch) => {
        setSearchParams(cleanQuery({ ...query, ...patch }), { replace: true });
    }, [query, setSearchParams]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.dir, query.sort]);

    const displayRows = useMemo(
        () => sortRows(filterRows(rows || [], query), query.sort, query.dir),
        [rows, query],
    );
    const selection = useListSelection(displayRows);
    const hasFilters = Object.entries(query).some(([key, value]) => !['sort', 'dir'].includes(key) && value && value !== 'all');

    const runBulk = async (action) => {
        const ids = selection.selectedIds.map(Number).filter((id) => id > 0);
        if (!ids.length) return;
        setBulkBusy(true);
        try {
            await csrf();
            if (action.key === 'delete') {
                await api.post('/account/seller/features/bulk-delete', { ids });
            } else {
                await api.post('/account/seller/features/bulk-status', { ids, enabled: action.key === 'activate' });
            }
            selection.clear();
            load();
        } finally {
            setBulkBusy(false);
            setConfirm(null);
        }
    };

    const toggleStatus = async (row) => {
        await csrf();
        await api.post(`/account/seller/features/${row.id}/toggle`);
        load();
    };

    const deleteRow = async (row) => {
        if ((row.products_count ?? 0) > 0) {
            window.alert(t(
                'storefront.seller.feature_delete_blocked',
                `This feature is assigned to ${row.products_count} product${row.products_count === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_feature_confirm', 'Delete this feature?'))) {
            return;
        }
        await csrf();
        await api.delete(`/account/seller/features/${row.id}`);
        load();
    };

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="mv-features-page">
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">{t('storefront.seller.features', 'Features')} <span className="mv-page-title-count">({counts.all ?? 0})</span></h2>
                    <p className="mv-page-help">{t('storefront.seller.taxonomy_help', 'Saved to your seller catalog first, then copied to the store catalog.')}</p>
                </div>
                <div className="mv-toolbar-actions">
                    <Link to="/account/seller/features/new" className="shop-btn shop-btn-primary shop-btn-list-head">
                        <AddBtnLabel>{t('storefront.seller.add_feature', 'Add feature')}</AddBtnLabel>
                    </Link>
                    <Link to="/account/seller/feature-values/new" className="shop-btn shop-btn-ghost shop-btn-list-head">
                        <AddBtnLabel>{t('storefront.seller.add_feature_value', 'Add feature value')}</AddBtnLabel>
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
                                <th><SortHeader column="name" label={t('storefront.seller.col_feature', 'Feature')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="type" label={t('storefront.seller.col_type', 'Type')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-center"><SortHeader column="values_count" label={t('storefront.seller.col_values', 'Values')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="is_enabled" label={t('storefront.seller.col_status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-id"><SortHeader column="position" label={t('storefront.seller.col_position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-actions">{t('storefront.seller.col_actions', 'Actions')}</th>
                            </tr>
                            <tr>
                                <th className="mv-list-th-filter-check" />
                                <th className="mv-list-th-filter-id"><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter"><ColumnFilter name="name" value={query.name} placeholder={t('storefront.seller.search_name', 'Search name')} onApply={apply} /></th>
                                <th className="mv-list-th-filter"><ColumnFilter name="type" value={query.type} type="select" placeholder={t('storefront.seller.filter_all', 'All')} options={typeFilterOptions} onApply={apply} /></th>
                                <th className="mv-list-th-filter-center"><ColumnRangeFilter minName="values_min" maxName="values_max" minValue={query.values_min} maxValue={query.values_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter-status"><ColumnFilter name="status" value={query.status === 'all' ? '' : query.status} type="select" placeholder={t('storefront.seller.filter_all', 'All')} options={STATUS_OPTIONS} onApply={apply} compact /></th>
                                <th className="mv-list-th-filter-id"><ColumnRangeFilter minName="position_min" maxName="position_max" minValue={query.position_min} maxValue={query.position_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter-actions">
                                    {hasFilters ? (
                                        <button type="button" className="mv-list-filter-reset" title={t('storefront.seller.reset_filters', 'Reset filters')} aria-label={t('storefront.seller.reset_filters', 'Reset filters')} onClick={() => setSearchParams({}, { replace: true })}>↺</button>
                                    ) : null}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {displayRows.length > 0 ? displayRows.map((row) => (
                                <tr key={row.id}>
                                    <td className="mv-list-td-check">
                                        <input type="checkbox" className="h-4 w-4 accent-[var(--shop-accent,#4545e6)]" checked={selection.isSelected(row.id)} aria-label={`${t('storefront.seller.select', 'Select')} ${row.name}`} onChange={() => selection.toggle(row.id)} />
                                    </td>
                                    <td className="mv-list-td-id">{row.id}</td>
                                    <td>
                                        <div className="mv-list-row">
                                            <span className="mv-list-thumb mv-list-thumb--icon" aria-hidden="true"><Icon name="tag" className="h-4 w-4" /></span>
                                            <div className="mv-list-cell-stack">
                                                <div className="mv-list-cell-strong">{row.name}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{row.type_label || row.type || '—'}</td>
                                    <td className="mv-list-td-center">
                                        {(row.values_count ?? 0) > 0 ? (
                                            <Link className="mv-link" to={`/account/seller/features/${row.id}/values`}>{row.values_count}</Link>
                                        ) : (
                                            <Link className="mv-link mv-muted-sm" to={`/account/seller/features/${row.id}/values/new`}>0</Link>
                                        )}
                                    </td>
                                    <td>
                                        <button type="button" className={`mv-status-toggle${row.is_enabled ? ' is-on' : ''}`} aria-pressed={!!row.is_enabled} onClick={() => toggleStatus(row)}>
                                            <span className="mv-status-toggle-knob" />
                                        </button>
                                    </td>
                                    <td className="mv-list-td-id">{row.position ?? 0}</td>
                                    <td className="mv-list-td-actions">
                                        <div className="mv-row-actions">
                                            <Link className="mv-link" to={`/account/seller/features/${row.id}/values`}>{t('storefront.seller.view_values', 'View values')}</Link>
                                            <Link className="mv-link" to={`/account/seller/features/${row.id}/values/new`}>{t('storefront.seller.add_value', 'Add value')}</Link>
                                            <SellerEditLink to={`/account/seller/features/${row.id}`} />
                                            <SellerDeleteButton onClick={() => deleteRow(row)} />
                                        </div>
                                    </td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan={8}>
                                        <div className="mv-list-empty-state">
                                            <p>
                                                {hasFilters
                                                    ? t('storefront.seller.no_features_filtered', 'No features match your filters.')
                                                    : t('storefront.seller.no_features', 'No features yet. Use Add feature to create one.')}
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
                        ? t('storefront.seller.bulk_delete_features_confirm', 'Delete selected features? Features assigned to products will be skipped.')
                        : t('storefront.seller.bulk_status_confirm', 'Apply this status change to selected features?')}
                    confirmLabel={confirm.action.label}
                    busy={bulkBusy}
                    onConfirm={() => runBulk(confirm.action)}
                    onCancel={() => setConfirm(null)}
                />
            ) : null}
        </div>
    );
}
