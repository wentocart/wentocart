import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from '@inertiajs/react';
import { Icon, ui, cx, t } from '../../ui';

function extensionActionVariant(row) {
    return !row.installed && row.installable ? 'secondary' : 'primary';
}

function ExtensionMenuPanel({ open, menuItems, onClose, coords, menuRef }) {
    if (!open) {
        return null;
    }

    return createPortal(
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
            {menuItems.map((item) => {
                const itemClassName = item.danger ? ui.rowActionsItemDanger : ui.rowActionsItem;
                const label = (
                    <>
                        {item.icon ? <Icon name={item.icon} className={ui.icon} /> : null}
                        {item.label}
                    </>
                );

                if (item.href) {
                    return (
                        <Link
                            key={item.key || item.label}
                            href={item.href}
                            role="menuitem"
                            className={itemClassName}
                            onClick={onClose}
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
                            onClose();
                            item.onClick?.();
                        }}
                    >
                        {label}
                    </button>
                );
            })}
        </div>,
        document.body,
    );
}

export default function ExtensionSplitButton({ row, primary, menuItems, className, busy = false }) {
    const rootRef = useRef(null);
    const menuRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState(null);
    const variant = extensionActionVariant(row);
    const isPrimary = variant === 'primary';
    const extras = (menuItems || []).filter(Boolean);
    const hasMenu = extras.length > 0;
    const primaryClick = primary?.onClick;
    const menuItemCount = extras.length;

    const updatePosition = useCallback(() => {
        const anchor = rootRef.current;
        if (!anchor) {
            return;
        }

        const rect = anchor.getBoundingClientRect();
        const menu = menuRef.current;
        const menuWidth = menu?.offsetWidth || 176;
        const menuHeight = menu?.offsetHeight || 0;
        const gap = 4;
        const pad = 8;
        const estimatedHeight = menuHeight || menuItemCount * 36 + 8;
        const spaceBelow = window.innerHeight - rect.bottom - gap;
        const spaceAbove = rect.top - gap;
        const openUp = spaceBelow < estimatedHeight && spaceAbove > spaceBelow;
        const height = menuHeight || estimatedHeight;

        let top = openUp ? rect.top - gap - height : rect.bottom + gap;
        top = Math.max(pad, Math.min(top, window.innerHeight - height - pad));

        let left = rect.right - menuWidth;
        left = Math.max(pad, Math.min(left, window.innerWidth - menuWidth - pad));

        setCoords({ top, left });
    }, [menuItemCount]);

    useLayoutEffect(() => {
        if (!open) {
            setCoords(null);
            return undefined;
        }

        updatePosition();
        const frame = window.requestAnimationFrame(updatePosition);
        return () => window.cancelAnimationFrame(frame);
    }, [open, updatePosition, menuItemCount]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onDoc = (event) => {
            if (rootRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) {
                return;
            }
            setOpen(false);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
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

    const close = () => setOpen(false);

    const shellClass = cx(
        ui.splitBtn,
        isPrimary ? ui.splitBtnPrimary : ui.splitBtnSecondary,
    );
    const mainClass = cx(
        ui.splitBtnMain,
        isPrimary ? ui.splitBtnMainPrimary : ui.splitBtnMainSecondary,
    );
    const toggleClass = cx(
        ui.splitBtnToggle,
        isPrimary ? ui.splitBtnTogglePrimary : ui.splitBtnToggleSecondary,
    );

    const renderMain = () => {
        if (primary?.href) {
            return (
                <Link href={primary.href} className={mainClass} tabIndex={busy ? -1 : undefined} aria-disabled={busy || undefined}>
                    {busy ? <Icon name="spinner" className="h-4 w-4 animate-spin" /> : null}
                    {primary.label}
                </Link>
            );
        }
        if (primaryClick) {
            return (
                <button type="button" className={mainClass} onClick={primaryClick} disabled={busy}>
                    {busy ? <Icon name="spinner" className="h-4 w-4 animate-spin" /> : null}
                    {primary.label}
                </button>
            );
        }

        return (
            <span className={mainClass}>—</span>
        );
    };

    if (!primary && !hasMenu) {
        return (
            <div className={cx(ui.splitBtnWrap, className)}>
                <span className="flex h-9 w-full items-center justify-center rounded-lg border border-neutral-200 bg-white px-3 text-sm text-neutral-400">
                    —
                </span>
            </div>
        );
    }

    return (
        <div ref={rootRef} className={cx(ui.splitBtnWrap, className)}>
            <div className={shellClass}>
                {renderMain()}
                {hasMenu ? (
                    <button
                        type="button"
                        className={toggleClass}
                        aria-label={t('admin.common.more_actions', 'More actions')}
                        aria-haspopup="menu"
                        aria-expanded={open}
                        disabled={busy}
                        onClick={() => setOpen((value) => !value)}
                    >
                        <Icon name="chevron-down" className="h-4 w-4" />
                    </button>
                ) : null}
            </div>
            {hasMenu ? (
                <ExtensionMenuPanel
                    open={open}
                    menuItems={extras}
                    onClose={close}
                    coords={coords}
                    menuRef={menuRef}
                />
            ) : null}
        </div>
    );
}
