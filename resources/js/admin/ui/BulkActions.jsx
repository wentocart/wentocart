import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

/**
 * Standard Catalog list bulk actions (always all four).
 *
 * @param {{
 *   onActive: () => void,
 *   onDeactivate: () => void,
 *   onDuplicate: () => void,
 *   onDelete: () => void,
 *   deleteConfirm: string,
 * }} handlers
 */
export function catalogBulkActionItems({
    onActive,
    onDeactivate,
    onDuplicate,
    onDelete,
    deleteConfirm,
}) {
    return [
        {
            key: 'enable',
            label: t('admin.products.active_selection', 'Active selection'),
            icon: 'check-circle',
            onClick: onActive,
        },
        {
            key: 'disable',
            label: t('admin.products.deactivate_selection', 'Deactivate selection'),
            icon: 'ban',
            onClick: onDeactivate,
        },
        {
            key: 'duplicate',
            label: t('admin.products.duplicate_selection', 'Duplicate selection'),
            icon: 'copy',
            onClick: onDuplicate,
        },
        {
            key: 'delete',
            label: t('admin.products.delete_selection', 'Delete selection'),
            icon: 'trash',
            tone: 'danger',
            confirm: deleteConfirm,
            onClick: onDelete,
        },
    ];
}

/**
 * Compact bulk-actions dropdown for list pages.
 * Disabled when count is 0; opens a menu directly below the trigger.
 *
 * items: [{ key, label, icon?, tone?: 'danger', confirm?, disabled?, onClick }]
 */
export default function BulkActions({
    count = 0,
    busy = false,
    label = t('admin.common.bulk_actions', 'Bulk actions'),
    items = [],
    className,
    barClassName,
    buttonClassName,
}) {
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
            if (!rootRef.current?.contains(event.target)) {
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

    const runItem = (item) => {
        if (item.disabled || busy) {
            return;
        }
        if (item.confirm && !window.confirm(item.confirm)) {
            return;
        }
        setOpen(false);
        item.onClick?.();
    };

    const visibleItems = items.filter(Boolean);

    return (
        <div className={cx(ui.bulkActionsBar, barClassName)}>
            <div ref={rootRef} className={cx(ui.bulkActionsWrap, className)}>
                <button
                    type="button"
                    className={buttonClassName || ui.bulkActionsBtn}
                    aria-haspopup="menu"
                    aria-expanded={open}
                    disabled={disabled}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span className="truncate">{label}</span>
                    <Icon name="chevron-down" className={ui.bulkActionsBtnIcon} />
                </button>
                {open && !disabled ? (
                    <div className={ui.bulkActionsMenu} role="menu">
                        <div className={ui.bulkActionsHint}>
                            {t('admin.list.bulk_apply_to_selected', 'Apply to :count selected').replace(':count', String(count))}
                        </div>
                        {visibleItems.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                className={item.tone === 'danger' ? ui.bulkActionsItemDanger : ui.bulkActionsItem}
                                disabled={!!item.disabled || busy}
                                onClick={() => runItem(item)}
                            >
                                {item.icon ? <Icon name={item.icon} className={ui.icon} /> : null}
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
        </div>
    );
}
