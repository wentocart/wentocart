import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { Icon } from '../../components/Icons.jsx';
import { filterRows, sortRows } from './featureValueListUtils.js';
import { AddBtnLabel, SellerDeleteButton, SellerEditLink } from './sellerUi.jsx';

export { default as SellerFeatureValueForm } from './SellerFeatureValueForm.jsx';

const DEFAULT_QUERY = {
    status: 'all',
    value: '',
    feature: '',
    id_min: '',
    id_max: '',
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

export default function SellerFeatureValues() {
    const { featureId } = useParams();
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams), [searchParams]);
    const [rows, setRows] = useState(null);
    const [counts, setCounts] = useState({ all: 0, active: 0, inactive: 0 });
    const [feature, setFeature] = useState(null);

    const load = useCallback(() => {
        const suffix = featureId ? `?feature_id=${featureId}` : '';
        return api.get(`/account/seller/feature-values${suffix}`).then((r) => {
            setRows(r.data.rows || []);
            setCounts(r.data.counts || { all: 0, active: 0, inactive: 0 });
            setFeature(r.data.feature || null);
        }).catch(() => {
            setRows([]);
            setCounts({ all: 0, active: 0, inactive: 0 });
            setFeature(null);
        });
    }, [featureId]);

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
    const hasFilters = Object.entries(query).some(([key, value]) => !['sort', 'dir'].includes(key) && value && value !== 'all');
    const scopedFeature = feature || (featureId ? { id: featureId, name: '' } : null);

    const createHref = featureId
        ? `/account/seller/features/${featureId}/values/new`
        : '/account/seller/feature-values/new';

    const toggleStatus = async (row) => {
        await csrf();
        await api.post(`/account/seller/feature-values/${row.id}/toggle`);
        load();
    };

    const deleteRow = async (row) => {
        if ((row.products_count ?? 0) > 0) {
            window.alert(t(
                'storefront.seller.feature_value_delete_blocked',
                `This feature value is assigned to ${row.products_count} product${row.products_count === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_feature_value_confirm', 'Delete this feature value?'))) {
            return;
        }
        await csrf();
        await api.delete(`/account/seller/feature-values/${row.id}`);
        load();
    };

    if (!rows) {
        return <PageSkeleton />;
    }

    const title = scopedFeature?.name
        ? `${t('storefront.seller.feature_values_for', 'Values')}: ${scopedFeature.name}`
        : t('storefront.seller.feature_values', 'Feature values');

    return (
        <div className="mv-feature-values-page">
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">{title} <span className="mv-page-title-count">({counts.all ?? 0})</span></h2>
                    <p className="mv-page-help">{t('storefront.seller.feature_values_help', 'Values belong to a feature and can be assigned to products.')}</p>
                </div>
                <div className="mv-toolbar-actions">
                    <Link to="/account/seller/features" className="shop-btn shop-btn-ghost shop-btn-list-head">
                        {t('storefront.seller.back_to_features', 'Back to features')}
                    </Link>
                    <Link to={createHref} className="shop-btn shop-btn-primary shop-btn-list-head">
                        <AddBtnLabel>{t('storefront.seller.add_feature_value', 'Add feature value')}</AddBtnLabel>
                    </Link>
                </div>
            </div>

            <div className="mv-list-panel">
                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-id"><SortHeader column="id" label={t('storefront.seller.col_id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th><SortHeader column="value" label={t('storefront.seller.col_value', 'Value')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                {!featureId ? (
                                    <th><SortHeader column="feature" label={t('storefront.seller.col_feature', 'Feature')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                ) : null}
                                <th><SortHeader column="is_enabled" label={t('storefront.seller.col_status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-id"><SortHeader column="position" label={t('storefront.seller.col_position', 'Position')} sort={query.sort} dir={query.dir} onSort={sortBy} /></th>
                                <th className="mv-list-th-actions">{t('storefront.seller.col_actions', 'Actions')}</th>
                            </tr>
                            <tr>
                                <th className="mv-list-th-filter-id"><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={apply} center /></th>
                                <th className="mv-list-th-filter"><ColumnFilter name="value" value={query.value} placeholder={t('storefront.seller.search_value', 'Search value')} onApply={apply} /></th>
                                {!featureId ? (
                                    <th className="mv-list-th-filter"><ColumnFilter name="feature" value={query.feature} placeholder={t('storefront.seller.search_feature', 'Search feature')} onApply={apply} /></th>
                                ) : null}
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
                                    <td className="mv-list-td-id">{row.id}</td>
                                    <td>
                                        <div className="mv-list-row">
                                            <span className="mv-list-thumb mv-list-thumb--icon" aria-hidden="true"><Icon name="tag" className="h-4 w-4" /></span>
                                            <div className="mv-list-cell-stack">
                                                <div className="mv-list-cell-strong">{row.value}</div>
                                            </div>
                                        </div>
                                    </td>
                                    {!featureId ? <td>{row.feature_name || '—'}</td> : null}
                                    <td>
                                        <button type="button" className={`mv-status-toggle${row.is_enabled ? ' is-on' : ''}`} aria-pressed={!!row.is_enabled} onClick={() => toggleStatus(row)}>
                                            <span className="mv-status-toggle-knob" />
                                        </button>
                                    </td>
                                    <td className="mv-list-td-id">{row.position ?? 0}</td>
                                    <td className="mv-list-td-actions">
                                        <div className="mv-row-actions">
                                            <SellerEditLink to={`/account/seller/feature-values/${row.id}`} />
                                            <SellerDeleteButton onClick={() => deleteRow(row)} />
                                        </div>
                                    </td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan={featureId ? 5 : 6}>
                                        <div className="mv-list-empty-state">
                                            <p>
                                                {hasFilters
                                                    ? t('storefront.seller.no_feature_values_filtered', 'No feature values match your filters.')
                                                    : (scopedFeature?.name
                                                        ? t('storefront.seller.no_feature_values_for', 'No values for this feature yet.')
                                                        : t('storefront.seller.no_feature_values', 'No feature values yet. Use Add feature value to create one.'))}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
