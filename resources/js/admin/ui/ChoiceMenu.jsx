import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from './Icon';
import { cx, ui } from './classes';

/**
 * Button menu for short option lists.
 * A native select opens on mousedown, so the same click lands on the option
 * under the pointer and commits it. This menu opens after that click and
 * ignores the pointer until it actually moves, then commits on a later click.
 */
export default function ChoiceMenu({ value, options = [], onChange, className, ariaLabel, disabled = false }) {
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(null);
    const [coords, setCoords] = useState(null);
    const buttonRef = useRef(null);
    const menuRef = useRef(null);
    const openPoint = useRef(null);
    const pointerReady = useRef(false);
    const listId = useId();
    const selected = options.find((option) => option.value === value) || null;

    const place = () => {
        const button = buttonRef.current;
        const menu = menuRef.current;
        if (!button) {
            return;
        }
        const rect = button.getBoundingClientRect();
        const width = Math.max(rect.width, menu?.offsetWidth || 0, 176);
        const height = menu?.offsetHeight || 0;
        const gap = 4;
        const pad = 8;
        const spaceBelow = window.innerHeight - rect.bottom - gap;
        const openUp = height > 0 && spaceBelow < height && rect.top > spaceBelow;
        let top = openUp ? rect.top - gap - height : rect.bottom + gap;
        top = Math.max(pad, Math.min(top, window.innerHeight - Math.max(height, 40) - pad));
        let left = rect.left;
        left = Math.max(pad, Math.min(left, window.innerWidth - width - pad));
        setCoords({ top, left, width });
    };

    useLayoutEffect(() => {
        if (!open) {
            setCoords(null);
            setActive(null);
            pointerReady.current = false;
            return;
        }
        place();
    }, [open, options]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (buttonRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) {
                return;
            }
            setOpen(false);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                buttonRef.current?.focus();
            }
        };
        const onReposition = () => place();
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', onReposition);
        window.addEventListener('scroll', onReposition, true);
        const frame = window.requestAnimationFrame(() => menuRef.current?.focus());
        return () => {
            window.cancelAnimationFrame(frame);
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('resize', onReposition);
            window.removeEventListener('scroll', onReposition, true);
        };
    }, [open]);

    const choose = (next) => {
        onChange?.(next);
        setOpen(false);
        buttonRef.current?.focus();
    };

    const moveActive = (direction) => {
        const current = active ?? value;
        const index = options.findIndex((option) => option.value === current);
        const start = index === -1 ? 0 : index;
        const next = options[Math.min(options.length - 1, Math.max(0, start + direction))];
        pointerReady.current = true;
        setActive(next?.value ?? null);
    };

    const onButtonKey = (event) => {
        if (disabled) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                openPoint.current = null;
                pointerReady.current = true;
                const index = options.findIndex((option) => option.value === value);
                const start = index === -1 ? 0 : index;
                setActive(options[start]?.value ?? null);
                setOpen(true);
                return;
            }
            moveActive(event.key === 'ArrowDown' ? 1 : -1);
        } else if ((event.key === 'Enter' || event.key === ' ') && open && active) {
            event.preventDefault();
            choose(active);
        }
    };

    const onMenuKey = (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(event.key === 'ArrowDown' ? 1 : -1);
        } else if ((event.key === 'Enter' || event.key === ' ') && active) {
            event.preventDefault();
            choose(active);
        }
    };

    const onMenuMouseMove = (event) => {
        const origin = openPoint.current;
        if (!pointerReady.current) {
            if (!origin) {
                openPoint.current = { x: event.clientX, y: event.clientY };
                return;
            }
            const moved = Math.abs(event.clientX - origin.x) + Math.abs(event.clientY - origin.y);
            if (moved <= 4) {
                return;
            }
            pointerReady.current = true;
        }
        const next = event.target.closest?.('[data-choice]')?.getAttribute('data-choice');
        if (next) {
            setActive(next);
        }
    };

    return (
        <div className={cx('relative shrink-0', className)}>
            <button
                ref={buttonRef}
                type="button"
                disabled={disabled}
                aria-label={ariaLabel}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                className={cx(ui.select, 'flex w-full items-center justify-between gap-2 text-left', disabled && 'cursor-not-allowed')}
                onClick={(event) => {
                    if (disabled) {
                        return;
                    }
                    openPoint.current = { x: event.clientX, y: event.clientY };
                    pointerReady.current = false;
                    setActive(null);
                    setOpen((current) => !current);
                }}
                onKeyDown={onButtonKey}
            >
                <span className="min-w-0 truncate">{selected?.label || ''}</span>
                <Icon name="chevron-down" className={cx('h-4 w-4 shrink-0 text-neutral-400 transition', open && 'rotate-180')} />
            </button>
            {open ? createPortal(
                <div
                    ref={menuRef}
                    id={listId}
                    role="listbox"
                    tabIndex={-1}
                    aria-label={ariaLabel}
                    className="fixed z-[80] max-h-64 overflow-auto rounded-xl border border-neutral-200 bg-white p-1 shadow-lg outline-none"
                    style={{
                        top: coords?.top ?? -9999,
                        left: coords?.left ?? 0,
                        width: coords?.width ?? 192,
                        visibility: coords ? 'visible' : 'hidden',
                    }}
                    onKeyDown={onMenuKey}
                    onMouseMove={onMenuMouseMove}
                >
                    {options.map((option) => {
                        const isSelected = option.value === value;
                        const isActive = option.value === active;
                        return (
                            <button
                                key={option.value}
                                type="button"
                                role="option"
                                data-choice={option.value}
                                aria-selected={isSelected}
                                className={cx(
                                    'flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm text-neutral-800',
                                    isActive && 'bg-violet-50 text-violet-900',
                                )}
                                onClick={() => choose(option.value)}
                            >
                                <span className="truncate">{option.label}</span>
                                {isSelected ? <Icon name="check" className="h-4 w-4 shrink-0 text-violet-600" /> : <span className="h-4 w-4 shrink-0" />}
                            </button>
                        );
                    })}
                </div>,
                document.body,
            ) : null}
        </div>
    );
}
