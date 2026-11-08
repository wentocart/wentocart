import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { mediaUrl } from '../../lib/format.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import { Icon } from '../../components/Icons.jsx';
import { SellerModalShell } from '../../components/SellerModal.jsx';
import {
    cleanQuery,
    ColumnFilter,
    ColumnRangeFilter,
    ListPagination,
    queryFromParams,
    SellerBulkActions,
    SortHeader,
    useDebouncedApply,
    useListSelection,
} from './sellerListShared.jsx';
import { SaveBtnLabel } from './sellerUi.jsx';

const DEFAULT_QUERY = {
    name: '',
    sku: '',
    supplier: '',
    id_min: '',
    id_max: '',
    sort: 'id',
    dir: 'desc',
    page: '1',
};

const BULK_ACTIONS = [
    {
        key: 'adjust',
        label: t('storefront.seller.bulk_adjust_stock', 'Adjust stock'),
        description: t('storefront.seller.bulk_adjust_stock_confirm', 'Apply a stock change to all selected products?'),
    },
];

function InventoryAdjustDialog({
    open,
    rows,
    busy,
    onCancel,
    onSave,
}) {
    const [delta, setDelta] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        setDelta('');
        setError('');
        return undefined;
    }, [open, rows]);

    if (!open) {
        return null;
    }

    const count = rows.length;
    const single = count === 1 ? rows[0] : null;
    const title = single
        ? t('storefront.seller.edit_quantity', 'Edit quantity')
        : t('storefront.seller.bulk_adjust_stock', 'Adjust stock');
    const subtitle = single
        ? single.name
        : t('storefront.seller.bulk_apply_to_selected', 'Apply to :count selected').replace(':count', String(count));

    const submit = () => {
        const parsed = Number.parseInt(delta, 10);
        if (!Number.isFinite(parsed) || parsed === 0) {
            setError(t('storefront.seller.inventory_delta_required', 'Enter a non-zero stock change.'));
            return;
        }
        setError('');
        onSave(parsed);
    };

    return (
        <SellerModalShell
            title={title}
            subtitle={subtitle}
            onClose={onCancel}
            closeDisabled={busy}
            footer={(
                <>
                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={onCancel} disabled={busy}>
                        {t('storefront.seller.cancel', 'Cancel')}
                    </button>
                    <button type="button" className="shop-btn shop-btn-primary shop-btn--sm" onClick={submit} disabled={busy} aria-busy={busy || undefined}>
                        {busy ? t('storefront.account.saving', 'Saving…') : (
                            <SaveBtnLabel>{t('storefront.seller.adjust', 'Adjust')}</SaveBtnLabel>
                        )}
                    </button>
                </>
            )}
        >
            {single ? (
                <dl className="mv-inventory-adjust-summary">
                    <div>
                        <dt>{t('storefront.seller.stock', 'Stock')}</dt>
                        <dd>{single.stock_qty}</dd>
                    </div>
                    <div>
                        <dt>{t('storefront.seller.reserved', 'Reserved')}</dt>
                        <dd>{single.reserved_qty}</dd>
                    </div>
                    <div>
                        <dt>{t('storefront.seller.available', 'Available')}</dt>
                        <dd>{single.available}</dd>
                    </div>
                </dl>
            ) : null}
            <label className="shop-field-label" htmlFor="seller-inventory-delta">
                {t('storefront.seller.delta', '± qty')}
            </label>
            <input
                id="seller-inventory-delta"
                type="number"
                className={`shop-input${error ? ' is-invalid' : ''}`}
                placeholder={t('storefront.seller.delta_placeholder', 'e.g. 5 or -2')}
                value={delta}
                onChange={(e) => {
                    setDelta(e.target.value);
                    setError('');
                }}
            />
            {error ? <em className="shop-field-error" role="alert">{error}</em> : null}
            <p className="mv-modal-sub mt-3">
                {t('storefront.seller.inventory_adjust_help', 'Use a stock delta to restock or remove units without rewriting product fields.')}
            </p>
        </SellerModalShell>
    );
}

export default function SellerInventory() {
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams, DEFAULT_QUERY), [searchParams]);
    const [paginator, setPaginator] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [adjustRows, setAdjustRows] = useState(null);

    const rows = paginator?.data || [];
    const selection = useListSelection(rows);
    const colSpan = 9;

    const apply = useCallback((patch) => {
        const next = cleanQuery({ ...query, ...patch });
        if (!patch.page) {
            next.page = '1';
        }
        setSearchParams(next, { replace: true });
    }, [query, setSearchParams]);

    const applyDebounced = useDebouncedApply(apply);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir, page: '1' });
    }, [apply, query.dir, query.sort]);

    const load = useCallback(() => {
        setLoading(true);
        setError('');
        return api.get('/account/seller/inventory', { params: cleanQuery(query) })
            .then((response) => {
                setPaginator(response.data.inventory || null);
            })
            .catch(() => {
                setPaginator({ data: [], total: 0, current_page: 1, last_page: 1 });
                setError(t('storefront.seller.load_failed', 'Could not load inventory.'));
            })
            .finally(() => setLoading(false));
    }, [query]);

    useEffect(() => { load(); }, [load]);

    const hasFilters = useMemo(() => Object.entries(query).some(([key, value]) => {
        if (key === 'sort' || key === 'dir' || key === 'page') {
            return false;
        }
        return value !== '';
    }), [query]);

    const requestBulk = (action) => {
        if (!selection.selectedCount) {
            setError(t('storefront.seller.bulk_none_selected', 'Select at least one product before choosing a bulk action.'));
            return;
        }
        setError('');
        const selectedRows = rows.filter((row) => selection.selectedIds.includes(row.product_id));
        setAdjustRows({ action, rows: selectedRows });
    };

    const openRowAdjust = (row) => {
        setError('');
        setAdjustRows({ action: { key: 'adjust' }, rows: [row] });
    };

    const closeAdjust = () => {
        if (!busy) {
            setAdjustRows(null);
        }
    };

    const saveAdjust = async (delta) => {
        const targetRows = adjustRows?.rows || [];
        const ids = targetRows.map((row) => row.product_id);
        if (!ids.length || busy) {
            return;
        }
        setBusy(true);
        setNotice('');
        setError('');
        try {
            await csrf();
            if (ids.length === 1) {
                await api.post(`/account/seller/products/${ids[0]}/inventory/adjust`, { delta });
            } else {
                await api.post('/account/seller/inventory/bulk-adjust', { ids, delta });
            }
            selection.clear();
            setAdjustRows(null);
            setNotice(t('storefront.seller.inventory_adjusted', 'Stock updated.'));
            await load();
        } catch (err) {
            setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    if (loading && !paginator) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <div className="mv-toolbar">
                <h2 className="mv-page-title">
                    {t('storefront.seller.inventory', 'Inventory')}
                    {paginator?.total != null ? (
                        <span className="mv-page-title-count">{` (${Number(paginator.total).toLocaleString()})`}</span>
                    ) : null}
                </h2>
            </div>

            <FormNotice tone="error">{error}</FormNotice>
            <FormNotice tone="ok">{notice}</FormNotice>

            <div className="mv-list-panel">
                <div className="mv-list-bulk">
                    <SellerBulkActions
                        actions={BULK_ACTIONS}
                        count={selection.selectedCount}
                        busy={busy}
                        onChoose={requestBulk}
                    />
                </div>

                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-check">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 accent-[var(--shop-accent,#4545e6)]"
                                        checked={selection.allSelected}
                                        ref={(el) => {
                                            if (el) {
                                                el.indeterminate = selection.someSelected;
                                            }
                                        }}
                                        aria-label={t('storefront.seller.select_all', 'Select all on this page')}
                                        onChange={() => selection.toggleAll()}
                                    />
                                </th>
                                <th className="mv-list-th-id">
                                    <SortHeader column="id" label={t('storefront.seller.col_id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="name" label={t('storefront.seller.col_product', 'Product')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="sku" label={t('storefront.seller.col_reference', 'Reference')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="supplier" label={t('storefront.seller.col_supplier', 'Supplier')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="stock_qty" label={t('storefront.seller.stock', 'Stock')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="reserved_qty" label={t('storefront.seller.reserved', 'Reserved')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="available" label={t('storefront.seller.available', 'Available')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">{t('storefront.seller.edit_quantity', 'Edit quantity')}</th>
                            </tr>
                            <tr>
                                <th className="mv-list-th-filter-check" />
                                <th className="mv-list-th-filter-id">
                                    <ColumnRangeFilter
                                        minName="id_min"
                                        maxName="id_max"
                                        minValue={query.id_min}
                                        maxValue={query.id_max}
                                        onApply={applyDebounced}
                                        compact
                                        center
                                    />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter name="name" value={query.name} placeholder={t('storefront.seller.search_name', 'Search name')} onApply={applyDebounced} />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter name="sku" value={query.sku} placeholder={t('storefront.seller.search_reference', 'Search reference')} onApply={applyDebounced} />
                                </th>
                                <th className="mv-list-th-filter">
                                    <ColumnFilter name="supplier" value={query.supplier} placeholder={t('storefront.seller.search_supplier', 'Search supplier')} onApply={applyDebounced} />
                                </th>
                                <th className="mv-list-th-filter-center" colSpan={3} />
                                <th className="mv-list-th-filter-center">
                                    {hasFilters ? (
                                        <button type="button" className="mv-list-filter-reset" title={t('storefront.seller.reset_filters', 'Reset filters')} aria-label={t('storefront.seller.reset_filters', 'Reset filters')} onClick={() => setSearchParams({}, { replace: true })}>
                                            ↺
                                        </button>
                                    ) : null}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={colSpan}>
                                        <div className="mv-list-empty">
                                            <p>{t('storefront.seller.no_inventory', 'No inventory yet.')}</p>
                                            <p>{t('storefront.seller.no_inventory_table_body', 'Inventory will appear here when your products are added.')}</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : rows.map((row) => (
                                <tr key={row.product_id}>
                                    <td className="mv-list-td-check">
                                        <input
                                            type="checkbox"
                                            className="h-4 w-4 accent-[var(--shop-accent,#4545e6)]"
                                            checked={selection.isSelected(row.product_id)}
                                            aria-label={`${t('storefront.seller.select', 'Select')} ${row.name}`}
                                            onChange={() => selection.toggle(row.product_id)}
                                        />
                                    </td>
                                    <td className="mv-list-td-id">{row.product_id}</td>
                                    <td>
                                        <div className="mv-list-row">
                                            {row.cover_url ? (
                                                <img src={mediaUrl(row.cover_url) || row.cover_url} alt="" className="mv-list-thumb" />
                                            ) : (
                                                <span className="mv-list-thumb" aria-hidden="true" />
                                            )}
                                            <div className="mv-list-cell-stack">
                                                <div className="mv-list-cell-strong">{row.name}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{row.sku || '—'}</td>
                                    <td>{row.supplier_name || '—'}</td>
                                    <td className="mv-list-td-center">
                                        <span className={`mv-list-badge ${Number(row.stock_qty) > 0 ? 'is-success' : 'is-danger'}`}>{row.stock_qty}</span>
                                    </td>
                                    <td className="mv-list-td-center">{row.reserved_qty}</td>
                                    <td className="mv-list-td-center">
                                        <span className={`mv-list-badge ${Number(row.available) > 0 ? 'is-success' : 'is-danger'}`}>{row.available}</span>
                                    </td>
                                    <td className="mv-list-td-center">
                                        <button
                                            type="button"
                                            className="mv-list-icon-btn"
                                            title={t('storefront.seller.edit_quantity', 'Edit quantity')}
                                            aria-label={`${t('storefront.seller.edit_quantity', 'Edit quantity')} — ${row.name}`}
                                            onClick={() => openRowAdjust(row)}
                                        >
                                            <Icon name="edit" className="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mv-list-footer">
                    <span>{rows.length} {t('storefront.seller.on_this_page', 'on this page')}</span>
                    <ListPagination paginator={paginator} onPage={(page) => apply({ page: String(page) })} />
                </div>
            </div>

            <InventoryAdjustDialog
                open={Boolean(adjustRows)}
                rows={adjustRows?.rows || []}
                busy={busy}
                onCancel={closeAdjust}
                onSave={saveAdjust}
            />
        </div>
    );
}
