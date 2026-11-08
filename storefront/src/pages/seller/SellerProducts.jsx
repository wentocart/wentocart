import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { formatMoney, mediaUrl } from '../../lib/format.js';
import { downloadCsv } from '../../lib/download.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import { Icon } from '../../components/Icons.jsx';
import SellerListSettings from '../../components/SellerListSettings.jsx';
import { AddBtnLabel, SellerDeleteButton, SellerEditButton, SaveBtnLabel } from './sellerUi.jsx';
import { SellerConfirmDialog } from '../../components/SellerModal.jsx';

export { default as SellerProductForm } from './SellerProductForm.jsx';

const FALLBACK_TYPES = [
    { value: 'simple', label: 'Standard' },
    { value: 'combination', label: 'Combinations' },
    { value: 'group', label: 'Grouped' },
    { value: 'virtual', label: 'Virtual' },
    { value: 'bundle', label: 'Bundle' },
    { value: 'gift_card', label: 'Gift Card' },
    { value: 'service', label: 'Service' },
    { value: 'rental', label: 'Rental' },
];

const DEFAULT_QUERY = {
    status: 'all',
    q: '',
    name: '',
    sku: '',
    category: '',
    id_min: '',
    id_max: '',
    price_min: '',
    price_max: '',
    qty_min: '',
    qty_max: '',
    sort: 'id',
    dir: 'desc',
    page: '1',
};

const STATUS_OPTIONS = [
    { value: '', label: t('storefront.seller.filter_all', 'All') },
    { value: 'active', label: t('storefront.seller.status_active', 'Active') },
    { value: 'inactive', label: t('storefront.seller.status_inactive', 'Inactive') },
    { value: 'pending', label: t('storefront.seller.moderation_pending', 'Pending moderation') },
    { value: 'approved', label: t('storefront.seller.moderation_approved', 'Approved') },
    { value: 'rejected', label: t('storefront.seller.moderation_rejected', 'Rejected') },
    { value: 'out_of_stock', label: t('storefront.seller.out_of_stock', 'Out of stock') },
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

function statusLabel(row) {
    const key = row.status_label || row.approval_status;
    if (key === 'pending') return t('storefront.seller.moderation_pending', 'Pending');
    if (key === 'rejected') return t('storefront.seller.moderation_rejected', 'Rejected');
    if (key === 'active') return t('storefront.seller.status_active', 'Active');
    return t('storefront.seller.status_inactive', 'Inactive');
}

function statusClass(row) {
    const key = row.status_label || (row.is_enabled ? 'active' : 'inactive');
    if (key === 'pending') return 'is-pending';
    if (key === 'rejected') return 'is-rejected';
    if (key === 'active') return 'is-active';
    return 'is-inactive';
}

function useDebouncedApply(apply, delay = 350) {
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

function useListSelection(rows) {
    const [selected, setSelected] = useState([]);
    const ids = useMemo(() => rows.map((row) => row.product_id), [rows]);
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

function SortHeader({ column, label, sort, dir, onSort }) {
    const active = sort === column;
    return (
        <button type="button" className={`mv-list-sort${active ? ' is-active' : ''}`} onClick={() => onSort(column)}>
            {label}
            <span className="mv-list-sort-icon">
                <SortGlyph active={active} dir={dir} />
            </span>
        </button>
    );
}

const BULK_ACTIONS = [
    {
        key: 'activate',
        label: t('storefront.seller.bulk_activate', 'Activate selection'),
        description: t('storefront.seller.bulk_activate_confirm', 'Are you sure you want to activate the selected products?'),
    },
    {
        key: 'deactivate',
        label: t('storefront.seller.bulk_deactivate', 'Deactivate selection'),
        description: t('storefront.seller.bulk_deactivate_confirm', 'Are you sure you want to deactivate the selected products?'),
    },
    {
        key: 'delete',
        label: t('storefront.seller.bulk_delete', 'Delete selection'),
        icon: 'trash',
        tone: 'danger',
        description: t('storefront.seller.bulk_delete_confirm', 'Are you sure you want to delete the selected products?'),
        detail: t('storefront.seller.bulk_delete_detail', 'This action cannot be undone.'),
    },
];

function SellerBulkActions({ count, busy, onChoose }) {
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
                        {BULK_ACTIONS.map((item) => (
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

function ColumnFilter({ name, value, placeholder, type = 'text', options = [], onApply, compact = false, statusFilter = false }) {
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

function ColumnRangeFilter({ minName, maxName, minValue, maxValue, onApply, compact = false, center = false }) {
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

function RowActionsMenu({ row, onDelete }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const close = (event) => {
            if (ref.current && !ref.current.contains(event.target)) {
                setOpen(false);
            }
        };
        window.addEventListener('click', close);
        return () => window.removeEventListener('click', close);
    }, [open]);

    return (
        <div className="mv-row-menu" ref={ref}>
            <button
                type="button"
                className="mv-row-menu-btn"
                aria-expanded={open}
                aria-label={t('storefront.seller.actions', 'Actions')}
                onClick={() => setOpen((value) => !value)}
            >
                ⋯
            </button>
            {open ? (
                <div className="mv-row-menu-panel" role="menu">
                    <Link className="mv-row-menu-item" to={`/account/seller/products/${row.product_id}`} role="menuitem" onClick={() => setOpen(false)}>
                        <Icon name="pencil" className="h-4 w-4 shrink-0" />
                        {t('storefront.seller.edit', 'Edit')}
                    </Link>
                    {row.slug ? (
                        <Link className="mv-row-menu-item" to={`/${row.slug}`} role="menuitem" onClick={() => setOpen(false)}>
                            {t('storefront.seller.view', 'View')}
                        </Link>
                    ) : null}
                    <button type="button" className="mv-row-menu-item is-danger" role="menuitem" onClick={() => { setOpen(false); onDelete(row); }}>
                        <Icon name="trash" className="h-4 w-4 shrink-0" />
                        {t('storefront.seller.delete', 'Delete')}
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function ListPagination({ paginator, onPage }) {
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

function ProductTypeModal({ open, types, onClose, onContinue }) {
    const [productType, setProductType] = useState('');
    const [typeError, setTypeError] = useState(false);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        setProductType('');
        setTypeError(false);
        const onKey = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" role="presentation" onClick={onClose}>
            <form
                className="shop-card w-full max-w-md overflow-hidden"
                role="dialog"
                aria-modal="true"
                aria-labelledby="seller-product-type-title"
                onClick={(e) => e.stopPropagation()}
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!productType) {
                        setTypeError(true);
                        return;
                    }
                    onContinue(productType);
                }}
            >
                <div className="flex items-center justify-between border-b border-neutral-100 px-5 py-4">
                    <h2 id="seller-product-type-title" className="text-lg font-semibold">
                        {t('storefront.seller.create_new_product', 'Create New Product')}
                    </h2>
                    <button type="button" className="shop-btn shop-btn-ghost" aria-label={t('storefront.seller.close', 'Close')} onClick={onClose}>×</button>
                </div>
                <div className="space-y-2 px-5 py-4">
                    <label className="shop-field-label" htmlFor="seller-product-type">
                        {t('storefront.seller.type', 'Type')}
                        <span className="text-red-600" aria-hidden="true"> *</span>
                    </label>
                    <select
                        id="seller-product-type"
                        className={`shop-input${typeError ? ' is-invalid' : ''}`}
                        value={productType}
                        required
                        onChange={(e) => {
                            setProductType(e.target.value);
                            setTypeError(false);
                        }}
                    >
                        <option value="">{t('storefront.seller.select_type', 'Select type')}</option>
                        {(types || FALLBACK_TYPES).map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </select>
                    {typeError ? (
                        <em className="shop-field-error" role="alert">
                            {t('storefront.seller.select_type_continue', 'Select a product type to continue.')}
                        </em>
                    ) : null}
                </div>
                <div className="flex justify-end border-t border-neutral-100 px-5 py-4">
                    <button type="submit" className="shop-btn shop-btn-primary" disabled={!productType}>
                        <AddBtnLabel>{t('storefront.seller.new_product', 'New Product')}</AddBtnLabel>
                    </button>
                </div>
            </form>
        </div>
    );
}

export default function SellerProducts() {
    const nav = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const query = useMemo(() => queryFromParams(searchParams), [searchParams]);
    const [paginator, setPaginator] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [typeModalOpen, setTypeModalOpen] = useState(false);
    const [productTypes, setProductTypes] = useState(FALLBACK_TYPES);
    const [bulkBusy, setBulkBusy] = useState(false);
    const [pendingBulk, setPendingBulk] = useState(null);

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
        return api.get('/account/seller/products', { params: cleanQuery(query) })
            .then((response) => {
                setPaginator(response.data.products || null);
            })
            .catch(() => {
                setPaginator({ data: [], total: 0, current_page: 1, last_page: 1 });
                setError(t('storefront.seller.load_failed', 'Could not load products.'));
            })
            .finally(() => setLoading(false));
    }, [query]);

    useEffect(() => { load(); }, [load]);

    useEffect(() => {
        api.get('/account/seller/options')
            .then((response) => {
                if (response.data.product_types?.length) {
                    setProductTypes(response.data.product_types);
                }
            })
            .catch(() => {});
    }, []);

    const deleteRow = async (row) => {
        if (!window.confirm(t('storefront.seller.delete_confirm', 'Remove this product from your catalog?'))) {
            return;
        }
        await csrf();
        await api.delete(`/account/seller/products/${row.product_id}`);
        selection.clear();
        load();
    };

    const requestBulk = (action) => {
        if (!selection.selectedCount) {
            setError(t('storefront.seller.bulk_none_selected', 'Select at least one product before choosing a bulk action.'));
            return;
        }
        setError('');
        setPendingBulk(action);
    };

    const cancelBulk = () => {
        if (bulkBusy) {
            return;
        }
        setPendingBulk(null);
    };

    const runBulk = async () => {
        const ids = selection.selectedIds;
        const action = pendingBulk?.key;
        if (!ids.length || !action || bulkBusy) {
            if (!ids.length) {
                setError(t('storefront.seller.bulk_none_selected', 'Select at least one product before choosing a bulk action.'));
                setPendingBulk(null);
            }
            return;
        }
        setBulkBusy(true);
        setNotice('');
        setError('');
        try {
            await csrf();
            if (action === 'delete') {
                await api.post('/account/seller/products/bulk-delete', { ids });
            } else {
                await api.post('/account/seller/products/bulk-status', {
                    ids,
                    enabled: action === 'activate',
                });
            }
            selection.clear();
            setPendingBulk(null);
            await load();
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.bulk_failed', 'Bulk action failed.'));
        } finally {
            setBulkBusy(false);
        }
    };

    const hasFilters = useMemo(() => Object.entries(query).some(([key, value]) => {
        if (key === 'sort' || key === 'dir' || key === 'page') {
            return false;
        }
        return value !== '' && value !== 'all';
    }), [query]);

    if (loading && !paginator) {
        return <PageSkeleton />;
    }

    const openTypeModal = () => setTypeModalOpen(true);

    return (
        <div>
            <div className="mv-toolbar">
                <h2 className="mv-page-title">
                    {t('storefront.seller.products', 'Seller Products')}
                    {paginator?.total != null ? (
                        <span className="mv-page-title-count">{` (${Number(paginator.total).toLocaleString()})`}</span>
                    ) : null}
                </h2>
                <div className="mv-toolbar-actions">
                    <button type="button" className="shop-btn shop-btn-primary shop-btn-list-head" onClick={openTypeModal}>
                        <AddBtnLabel>{t('storefront.seller.add_product', 'Add product')}</AddBtnLabel>
                    </button>
                    <SellerListSettings
                        onExport={() => downloadCsv(api, '/account/seller/exports/products', 'seller-products.csv', cleanQuery(query))}
                        onRefresh={load}
                        onImported={(data) => {
                            setNotice(t('storefront.seller.import_success', 'Imported :count products.').replace(':count', String(data?.count ?? 0)));
                            load();
                        }}
                    />
                </div>
            </div>

            <FormNotice tone="error">{error}</FormNotice>
            <FormNotice tone="ok">{notice}</FormNotice>

            <div className="mv-list-panel">
                <div className="mv-list-bulk">
                    <SellerBulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
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
                                    <SortHeader column="name" label={t('storefront.seller.col_name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="sku" label={t('storefront.seller.col_reference', 'Reference')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>{t('storefront.seller.col_category', 'Category')}</th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="price" label={t('storefront.seller.col_price', 'Price')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-center">
                                    <SortHeader column="stock_qty" label={t('storefront.seller.col_quantity', 'Quantity')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th>
                                    <SortHeader column="is_enabled" label={t('storefront.seller.col_status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                </th>
                                <th className="mv-list-th-actions">{t('storefront.seller.col_actions', 'Actions')}</th>
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
                                    <ColumnFilter name="category" value={query.category} placeholder={t('storefront.seller.search_category', 'Search category')} onApply={applyDebounced} />
                                </th>
                                <th className="mv-list-th-filter-center">
                                    <ColumnRangeFilter
                                        minName="price_min"
                                        maxName="price_max"
                                        minValue={query.price_min}
                                        maxValue={query.price_max}
                                        onApply={applyDebounced}
                                        center
                                    />
                                </th>
                                <th className="mv-list-th-filter-center">
                                    <ColumnRangeFilter
                                        minName="qty_min"
                                        maxName="qty_max"
                                        minValue={query.qty_min}
                                        maxValue={query.qty_max}
                                        onApply={applyDebounced}
                                        center
                                    />
                                </th>
                                <th className="mv-list-th-filter-status">
                                    <ColumnFilter
                                        name="status"
                                        value={query.status === 'all' ? '' : query.status}
                                        type="select"
                                        placeholder={t('storefront.seller.filter_all', 'All')}
                                        options={STATUS_OPTIONS}
                                        onApply={apply}
                                        statusFilter
                                    />
                                </th>
                                <th className="mv-list-th-filter-actions">
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
                                            <p>{t('storefront.seller.no_products', 'No products yet.')}</p>
                                            <p>{t('storefront.seller.no_products_table_body', 'Add your first product to start selling.')}</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : rows.map((row) => {
                                const qty = Number(row.stock_qty || 0);
                                return (
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
                                                    {row.type ? <div className="mv-list-cell-sub">{row.type}</div> : null}
                                                </div>
                                            </div>
                                        </td>
                                        <td>{row.sku || '—'}</td>
                                        <td>{row.category_name || '—'}</td>
                                        <td className="mv-list-td-center">{formatMoney(row.price)}</td>
                                        <td className="mv-list-td-center">
                                            <span className={`mv-list-badge ${qty > 0 ? 'is-success' : 'is-danger'}`}>{qty}</span>
                                        </td>
                                        <td className="mv-list-td-status">
                                            <span className={`mv-list-status ${statusClass(row)}`}>{statusLabel(row)}</span>
                                        </td>
                                        <td className="mv-list-td-actions">
                                            <RowActionsMenu row={row} onDelete={deleteRow} />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="mv-list-footer">
                    <span>{rows.length} {t('storefront.seller.on_this_page', 'on this page')}</span>
                    <ListPagination paginator={paginator} onPage={(page) => apply({ page: String(page) })} />
                </div>
            </div>

            <ProductTypeModal
                open={typeModalOpen}
                types={productTypes}
                onClose={() => setTypeModalOpen(false)}
                onContinue={(type) => {
                    setTypeModalOpen(false);
                    nav(`/account/seller/products/new?type=${encodeURIComponent(type)}`);
                }}
            />

            <SellerConfirmDialog
                open={Boolean(pendingBulk)}
                title={t('storefront.seller.confirm_bulk_action', 'Confirm bulk action')}
                description={pendingBulk?.description}
                detail={pendingBulk?.detail}
                busy={bulkBusy}
                onCancel={cancelBulk}
                onConfirm={runBulk}
            />
        </div>
    );
}
