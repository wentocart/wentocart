import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

/** Move an item from one index to another in a list. */
export function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}

/**
 * Drag-and-drop reorder for list rows.
 * @param {array} rows
 * @param {{ enabled?: boolean, onReorder?: (next: array) => void, reorderUrl?: string }} options
 */
export function useDragReorder(rows = [], { enabled = true, onReorder, reorderUrl } = {}) {
    const [orderedRows, setOrderedRows] = useState(rows);
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);
    // Parent pages commonly build row objects during render. Watching that array
    // directly resets drag state after `onDragStart`, before a drop can occur.
    // Synchronize only when the meaningful external row data changes instead.
    const rowsSignature = rows.map((row) => [
        row?.id ?? row?.code ?? '',
        row?.position ?? '',
        row?.enabled ?? '',
        row?.label ?? '',
        row?.hint ?? '',
    ].join('\u0001')).join('\u0002');

    useEffect(() => {
        setOrderedRows(rows);
        setFromIndex(null);
        setOverIndex(null);
    }, [rowsSignature]);

    const canReorder = enabled && orderedRows.length > 1;

    const persist = useCallback((nextRows) => {
        setOrderedRows(nextRows.map((row, index) => (
            row && typeof row === 'object' && 'position' in row
                ? { ...row, position: index }
                : row
        )));
        if (onReorder) {
            onReorder(nextRows);
            return;
        }
        if (reorderUrl) {
            router.post(reorderUrl, {
                ids: nextRows.map((row) => row.id),
            }, { preserveScroll: true });
        }
    }, [onReorder, reorderUrl]);

    const rowProps = useCallback((index) => {
        if (!canReorder) {
            return {};
        }

        return {
            className: cx(overIndex === index && fromIndex !== null && fromIndex !== index && ui.dragOver),
            onDragOver: (e) => {
                if (fromIndex === null) {
                    return;
                }
                e.preventDefault();
                setOverIndex(index);
            },
            onDrop: (e) => {
                e.preventDefault();
                if (fromIndex === null || fromIndex === index) {
                    setFromIndex(null);
                    setOverIndex(null);
                    return;
                }
                persist(moveItem(orderedRows, fromIndex, index));
                setFromIndex(null);
                setOverIndex(null);
            },
        };
    }, [canReorder, fromIndex, overIndex, orderedRows, persist]);

    const dragHandleProps = useCallback((index, label = '') => {
        if (!canReorder) {
            return null;
        }

        return {
            type: 'button',
            className: cx(ui.dragHandle, fromIndex === index && ui.dragHandleActive),
            title: t('admin.common.drag_to_reorder', 'Drag to reorder'),
            'aria-label': label
                ? `${t('admin.common.reorder', 'Reorder')} ${label}`
                : t('admin.common.drag_to_reorder', 'Drag to reorder'),
            draggable: true,
            onDragStart: (e) => {
                e.dataTransfer.setData('text/plain', String(index));
                e.dataTransfer.effectAllowed = 'move';
                setFromIndex(index);
            },
            onDragEnd: () => {
                setFromIndex(null);
                setOverIndex(null);
            },
        };
    }, [canReorder, fromIndex]);

    return {
        orderedRows,
        setOrderedRows,
        canReorder,
        fromIndex,
        overIndex,
        rowProps,
        dragHandleProps,
        persist,
    };
}

/** Position column cell with optional grip handle (visible on cell hover). */
export function OrderCell({ position, canReorder, dragHandleProps }) {
    return (
        <div className={ui.orderCell}>
            {canReorder && dragHandleProps ? (
                <button {...dragHandleProps}>
                    <Icon name="grip" className={ui.dragHandleIcon} />
                </button>
            ) : null}
            <span className={ui.orderCellValue}>{position ?? 0}</span>
        </div>
    );
}
