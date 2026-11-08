import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, router } from '@inertiajs/react';
import Button from './Button';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

/** Shared list selection for checkboxes + bulk actions. */
export function useListSelection(rows = [], { idKey = 'id', canSelect } = {}) {
    const [selected, setSelected] = useState(() => new Set());

    const selectableIds = useMemo(() => {
        return rows
            .filter((row) => (canSelect ? canSelect(row) : true))
            .map((row) => normalizeListId(row?.[idKey]))
            .filter((id) => id != null);
    }, [rows, idKey, canSelect]);

    useEffect(() => {
        setSelected((prev) => {
            const allowed = new Set(selectableIds);
            const next = new Set();
            prev.forEach((id) => {
                const normalized = normalizeListId(id);
                if (normalized != null && allowed.has(normalized)) {
                    next.add(normalized);
                }
            });
            if (next.size === prev.size) {
                let same = true;
                next.forEach((id) => {
                    if (!prev.has(id)) {
                        same = false;
                    }
                });
                if (same) {
                    return prev;
                }
            }
            return next;
        });
    }, [selectableIds]);

    const selectedIds = useMemo(() => Array.from(selected), [selected]);
    const selectedCount = selectedIds.length;
    const allSelected = selectableIds.length > 0 && selectableIds.every((id) => selected.has(id));
    const someSelected = selectedCount > 0 && !allSelected;

    const toggle = useCallback((id) => {
        const normalized = normalizeListId(id);
        if (normalized == null) {
            return;
        }
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(normalized)) {
                next.delete(normalized);
            } else {
                next.add(normalized);
            }
            return next;
        });
    }, []);

    const toggleAll = useCallback(() => {
        setSelected((prev) => {
            if (selectableIds.length && selectableIds.every((id) => prev.has(id))) {
                return new Set();
            }
            return new Set(selectableIds);
        });
    }, [selectableIds]);

    const clear = useCallback(() => setSelected(new Set()), []);

    const isSelected = useCallback((id) => {
        const normalized = normalizeListId(id);
        return normalized != null && selected.has(normalized);
    }, [selected]);

    return {
        selectedIds,
        selectedCount,
        allSelected,
        someSelected,
        toggle,
        toggleAll,
        clear,
        isSelected,
        selectableIds,
    };
}

function normalizeListId(id) {
    if (id == null || id === '') {
        return null;
    }
    if (typeof id === 'number' && Number.isFinite(id)) {
        return id;
    }
    const asNumber = Number(id);
    if (Number.isFinite(asNumber) && String(asNumber) === String(id).trim()) {
        return asNumber;
    }
    return String(id);
}

export function SelectionCheckbox({ checked, indeterminate, onChange, ariaLabel = t('admin.common.select', 'Select') }) {
    const ref = useRef(null);
    useEffect(() => {
        if (ref.current) {
            ref.current.indeterminate = !!indeterminate && !checked;
        }
    }, [indeterminate, checked]);

    return (
        <input
            ref={ref}
            type="checkbox"
            className={ui.check}
            checked={!!checked}
            onChange={(e) => {
                e.stopPropagation();
                onChange?.(e);
            }}
            onClick={(e) => e.stopPropagation()}
            aria-label={ariaLabel}
        />
    );
}

export function BulkBar({ count, onClear, children, className }) {
    if (!count) {
        return null;
    }

    return (
        <div className={cx(ui.bulkBar, className)} role="status">
            <span className={ui.bulkBarCount}>{count} {t('admin.common.selected', 'selected')}</span>
            {onClear ? (
                <Button type="button" variant="ghost" onClick={onClear}>
                    {t('admin.common.clear', 'Clear')}
                </Button>
            ) : null}
            <div className={cx(ui.row, 'ml-auto')}>{children}</div>
        </div>
    );
}

/**
 * Bulk delete control. Pass selectedIds + destroyUrl (POST with _method DELETE + ids[]).
 * Or pass onDelete(ids) for custom handling.
 */
export function BulkDelete({
    ids = [],
    destroyUrl,
    onDelete,
    confirmMessage = t('admin.common.delete_selected', 'Delete selected items?'),
    label = t('admin.common.bulk_delete', 'Bulk delete'),
}) {
    if (!ids.length) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="danger"
            icon="trash"
            onClick={() => {
                if (!window.confirm(confirmMessage)) {
                    return;
                }
                if (onDelete) {
                    onDelete(ids);
                    return;
                }
                if (destroyUrl) {
                    router.delete(destroyUrl, {
                        data: { ids },
                        preserveScroll: true,
                    });
                }
            }}
        >
            {label}
        </Button>
    );
}

/**
 * Per-row actions menu (⋯). Built-in View / Edit / Delete + optional `items`.
 * Hide actions with canView / canEdit / canDelete false. View renders before Edit.
 */
export function RowActions({
    viewHref,
    viewLabel = t('admin.common.view', 'View'),
    onView,
    canView = true,
    editHref,
    editLabel = t('admin.common.edit', 'Edit'),
    onEdit,
    canEdit = true,
    deleteLabel = t('admin.common.delete', 'Delete'),
    onDelete,
    canDelete = true,
    confirmMessage = t('admin.common.delete_item', 'Delete this item?'),
    items = [],
    className,
}) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState(null);
    const rootRef = useRef(null);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);

    const showView = canView && (viewHref || onView);
    const showEdit = canEdit && (editHref || onEdit);
    const showDelete = canDelete && onDelete;
    const extras = (items || []).filter(Boolean);
    const hasAny = showView || showEdit || showDelete || extras.length > 0;

    const updatePosition = useCallback(() => {
        const button = buttonRef.current;
        if (!button) {
            return;
        }

        const rect = button.getBoundingClientRect();
        const menu = menuRef.current;
        const menuWidth = menu?.offsetWidth || 160;
        const menuHeight = menu?.offsetHeight || 0;
        const gap = 4;
        const pad = 8;
        const spaceBelow = window.innerHeight - rect.bottom - gap;
        const spaceAbove = rect.top - gap;
        const estimatedHeight = menuHeight || 44 + ((showView ? 1 : 0) + (showEdit ? 1 : 0) + (showDelete ? 1 : 0) + extras.length) * 36;
        const openUp = spaceBelow < estimatedHeight && spaceAbove > spaceBelow;
        const height = menuHeight || estimatedHeight;

        let top = openUp ? rect.top - gap - height : rect.bottom + gap;
        top = Math.max(pad, Math.min(top, window.innerHeight - height - pad));

        let left = rect.right - menuWidth;
        left = Math.max(pad, Math.min(left, window.innerWidth - menuWidth - pad));

        setCoords({ top, left });
    }, [showView, showEdit, showDelete, extras.length]);

    useLayoutEffect(() => {
        if (!open) {
            setCoords(null);
            return undefined;
        }

        updatePosition();
        const frame = window.requestAnimationFrame(updatePosition);
        return () => window.cancelAnimationFrame(frame);
    }, [open, updatePosition]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onDoc = (e) => {
            if (rootRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) {
                return;
            }
            setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        };
        const onReposition = () => updatePosition();

        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        document.addEventListener('scroll', onReposition, true);
        window.addEventListener('resize', onReposition);

        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('scroll', onReposition, true);
            window.removeEventListener('resize', onReposition);
        };
    }, [open, updatePosition]);

    if (!hasAny) {
        return <span className="text-neutral-400">—</span>;
    }


    const close = () => setOpen(false);

    const menu = open
        ? createPortal(
            <div
                ref={menuRef}
                className={ui.rowActionsMenu}
                role="menu"
                style={{
                    top: coords?.top ?? 0,
                    left: coords?.left ?? 0,
                    visibility: coords ? 'visible' : 'hidden',
                }}
            >
                {showView ? (
                    viewHref ? (
                        <Link href={viewHref} role="menuitem" className={ui.rowActionsItem} onClick={close}>
                            <Icon name="eye" />
                            {viewLabel}
                        </Link>
                    ) : (
                        <button
                            type="button"
                            role="menuitem"
                            className={ui.rowActionsItem}
                            onClick={() => {
                                close();
                                onView?.();
                            }}
                        >
                            <Icon name="eye" />
                            {viewLabel}
                        </button>
                    )
                ) : null}
                {showEdit ? (
                    editHref ? (
                        <Link href={editHref} role="menuitem" className={ui.rowActionsItem} onClick={close}>
                            <Icon name="pencil" />
                            {editLabel}
                        </Link>
                    ) : (
                        <button
                            type="button"
                            role="menuitem"
                            className={ui.rowActionsItem}
                            onClick={() => {
                                close();
                                onEdit?.();
                            }}
                        >
                            <Icon name="pencil" />
                            {editLabel}
                        </button>
                    )
                ) : null}
                {extras.map((item) => {
                    const itemClassName = item.danger ? ui.rowActionsItemDanger : ui.rowActionsItem;
                    const label = (
                        <>
                            {item.icon ? <Icon name={item.icon} /> : null}
                            {item.label}
                        </>
                    );

                    if (item.href && item.native) {
                        return (
                            <a
                                key={item.key || item.label}
                                href={item.href}
                                role="menuitem"
                                className={itemClassName}
                                target={item.target || undefined}
                                rel={item.target === '_blank' ? 'noreferrer' : undefined}
                                onClick={close}
                            >
                                {label}
                            </a>
                        );
                    }

                    if (item.href) {
                        return (
                            <Link
                                key={item.key || item.label}
                                href={item.href}
                                role="menuitem"
                                className={itemClassName}
                                onClick={close}
                            >
                                {label}
                            </Link>
                        );
                    }

                    return (
                        <button
                            key={item.key || item.label}
                            type="button"
                            role="menuitem"
                            className={itemClassName}
                            disabled={item.disabled}
                            onClick={() => {
                                if (item.disabled) {
                                    return;
                                }
                                close();
                                item.onClick?.();
                            }}
                        >
                            {label}
                        </button>
                    );
                })}
                {showDelete ? (
                    <button
                        type="button"
                        role="menuitem"
                        className={ui.rowActionsItemDanger}
                        onClick={() => {
                            close();
                            if (window.confirm(confirmMessage)) {
                                onDelete();
                            }
                        }}
                    >
                        <Icon name="trash" />
                        {deleteLabel}
                    </button>
                ) : null}
            </div>,
            document.body,
        )
        : null;

    return (
        <div className={cx(ui.rowActions, open && 'z-50', className)} ref={rootRef}>
            <button
                ref={buttonRef}
                type="button"
                className={ui.rowActionsBtn}
                aria-label={t('admin.common.actions', 'Actions')}
                aria-expanded={open}
                aria-haspopup="menu"
                onClick={() => setOpen((v) => !v)}
            >
                <Icon name="more" className={ui.rowActionsIcon} />
            </button>
            {menu}
        </div>
    );
}

/** Shared Active / Inactive labels for Catalog (and other) list Status UI. */
export function listStatusLabels() {
    return {
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
    };
}

/** Status column filter options: Active / Inactive (values stay active|inactive). */
export function listStatusFilterOptions() {
    const labels = listStatusLabels();
    return [
        { value: 'active', label: labels.active },
        { value: 'inactive', label: labels.inactive },
    ];
}

/** Clickable Active / Inactive control for list rows. */
export function StatusToggle({ active, onToggle, activeLabel, inactiveLabel, busy = false, disabled = false }) {
    const labels = listStatusLabels();
    const onText = activeLabel ?? labels.active;
    const offText = inactiveLabel ?? labels.inactive;
    const locked = busy || disabled;

    return (
        <button
            type="button"
            className={cx(ui.statusToggle, active ? ui.statusToggleOn : ui.statusToggleOff)}
            disabled={locked}
            aria-pressed={!!active}
            onClick={locked ? undefined : onToggle}
        >
            <span className={cx(ui.statusToggleDot, active ? ui.statusToggleDotOn : ui.statusToggleDotOff)} />
            {active ? onText : offText}
        </button>
    );
}

/**
 * Inline column filter (global).
 * - text: input; placeholder = column name (e.g. "Name"); debounced as you type
 * - select: dropdown (e.g. Active / Inactive)
 */
export function ColumnFilter({
    name,
    value = '',
    onApply,
    type = 'text',
    options = [],
    placeholder,
    debounceMs = 300,
    compact = false,
    className,
}) {
    const [draft, setDraft] = useState(value ?? '');
    const skipFirst = useRef(true);
    const wrapClass = compact ? ui.columnFilterCompact : ui.columnFilter;

    useEffect(() => {
        setDraft(value ?? '');
    }, [value]);

    useEffect(() => {
        if (type !== 'text') {
            return undefined;
        }
        if (skipFirst.current) {
            skipFirst.current = false;
            return undefined;
        }
        const timer = window.setTimeout(() => {
            const next = String(draft ?? '').trim();
            const current = String(value ?? '').trim();
            if (next !== current) {
                onApply?.(name, next);
            }
        }, debounceMs);
        return () => window.clearTimeout(timer);
    }, [draft, debounceMs, name, onApply, type, value]);

    if (type === 'select') {
        return (
            <div className={cx(wrapClass, className)}>
                <select
                    className={ui.columnFilterSelect}
                    aria-label={placeholder || name}
                    value={value === '' || value == null ? 'all' : value}
                    onChange={(e) => {
                        const next = e.target.value === 'all' ? '' : e.target.value;
                        onApply?.(name, next);
                    }}
                >
                    <option value="all">{placeholder || t('admin.common.all', 'All')}</option>
                    {options.map((opt) => (
                        <option key={String(opt.value)} value={opt.value}>
                            {opt.label}
                        </option>
                    ))}
                </select>
            </div>
        );
    }

    if (type === 'date') {
        return (
            <div className={cx(ui.columnFilter, className)}>
                <input
                    type="date"
                    className={ui.columnFilterInput}
                    aria-label={placeholder || name}
                    value={value ?? ''}
                    onChange={(e) => onApply?.(name, e.target.value)}
                />
            </div>
        );
    }

    return (
        <div className={cx(wrapClass, className)}>
            <input
                className={ui.columnFilterInput}
                value={draft}
                placeholder={placeholder || name}
                aria-label={placeholder || name}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onApply?.(name, String(draft ?? '').trim());
                    }
                }}
            />
        </div>
    );
}

/**
 * Stacked Min / Max column filter (ID, price, quantity ranges).
 * Calls onApply(patch) with both keys when either value changes (debounced) or Enter is pressed.
 * IDs / counts / money are unsigned unless signed={true}.
 */
function rangePlaceholder(key, fallback) {
    const value = t(key, fallback);
    if (/^\{[a-z_]+\}$/i.test(value)) {
        return fallback;
    }
    return value;
}

function rangeLooksDecimal(minName = '', maxName = '') {
    return /(price|total|spent|discount|amount)/i.test(`${minName} ${maxName}`);
}

function sanitizeRangeValue(value, { signed = false, decimal = false } = {}) {
    let next = String(value ?? '');
    if (next === '') {
        return '';
    }
    if (!signed) {
        next = next.replace(/[eE+\-]/g, '');
    }
    if (!decimal) {
        next = next.replace(/\./g, '');
    }
    if (next === '') {
        return '';
    }
    const n = Number(next);
    if (!Number.isFinite(n)) {
        return '';
    }
    if (!signed && n < 0) {
        return '0';
    }

    return next;
}

function blockUnsignedNumberKeys(event) {
    if (event.key === '-' || event.key === '+' || event.key === 'e' || event.key === 'E') {
        event.preventDefault();
        return true;
    }

    return false;
}

export function ColumnRangeFilter({
    minName,
    maxName,
    minValue = '',
    maxValue = '',
    onApply,
    minPlaceholder = rangePlaceholder('admin.common.min', 'Min'),
    maxPlaceholder = rangePlaceholder('admin.common.max', 'Max'),
    debounceMs = 400,
    compact = false,
    center = false,
    signed = false,
    className,
}) {
    const decimal = rangeLooksDecimal(minName, maxName);
    const [minDraft, setMinDraft] = useState(minValue ?? '');
    const [maxDraft, setMaxDraft] = useState(maxValue ?? '');
    const skipFirst = useRef(true);
    const wrapClass = compact
        ? ui.columnFilterRangeCompact
        : center
            ? ui.columnFilterRangeCenter
            : ui.columnFilterRange;

    useEffect(() => {
        setMinDraft(minValue ?? '');
        setMaxDraft(maxValue ?? '');
    }, [minValue, maxValue]);

    const emit = useCallback((min, max) => {
        const nextMin = sanitizeRangeValue(min, { signed, decimal });
        const nextMax = sanitizeRangeValue(max, { signed, decimal });
        const currentMin = String(minValue ?? '').trim();
        const currentMax = String(maxValue ?? '').trim();
        if (nextMin === currentMin && nextMax === currentMax) {
            return;
        }
        onApply?.({ [minName]: nextMin, [maxName]: nextMax });
    }, [decimal, maxName, minName, minValue, maxValue, onApply, signed]);

    useEffect(() => {
        if (skipFirst.current) {
            skipFirst.current = false;
            return undefined;
        }
        const timer = window.setTimeout(() => emit(minDraft, maxDraft), debounceMs);
        return () => window.clearTimeout(timer);
    }, [debounceMs, emit, maxDraft, minDraft]);

    const onRangeKeyDown = (event) => {
        if (!signed && blockUnsignedNumberKeys(event)) {
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            emit(minDraft, maxDraft);
        }
    };

    return (
        <div className={cx(wrapClass, className)}>
            <input
                className={ui.columnFilterRangeInput}
                type="number"
                min={signed ? undefined : '0'}
                step={decimal ? 'any' : '1'}
                inputMode={decimal ? 'decimal' : 'numeric'}
                value={minDraft}
                placeholder={minPlaceholder}
                aria-label={minPlaceholder}
                onChange={(e) => setMinDraft(sanitizeRangeValue(e.target.value, { signed, decimal }))}
                onKeyDown={onRangeKeyDown}
            />
            <input
                className={ui.columnFilterRangeInput}
                type="number"
                min={signed ? undefined : '0'}
                step={decimal ? 'any' : '1'}
                inputMode={decimal ? 'decimal' : 'numeric'}
                value={maxDraft}
                placeholder={maxPlaceholder}
                aria-label={maxPlaceholder}
                onChange={(e) => setMaxDraft(sanitizeRangeValue(e.target.value, { signed, decimal }))}
                onKeyDown={onRangeKeyDown}
            />
        </div>
    );
}
