import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ui } from './classes';

export default function PortalMenu({ open, onClose, anchorRef, children, className }) {
    const menuRef = useRef(null);
    const [coords, setCoords] = useState(null);

    const updatePosition = useCallback(() => {
        const button = anchorRef?.current;
        if (!button) {
            return;
        }
        const rect = button.getBoundingClientRect();
        const menu = menuRef.current;
        const menuWidth = menu?.offsetWidth || 208;
        const menuHeight = menu?.offsetHeight || 0;
        const gap = 4;
        const pad = 8;
        const spaceBelow = window.innerHeight - rect.bottom - gap;
        const openUp = spaceBelow < (menuHeight || 180) && rect.top > spaceBelow;
        let top = openUp ? rect.top - gap - (menuHeight || 180) : rect.bottom + gap;
        top = Math.max(pad, Math.min(top, window.innerHeight - (menuHeight || 40) - pad));
        let left = rect.right - menuWidth;
        left = Math.max(pad, Math.min(left, window.innerWidth - menuWidth - pad));
        setCoords({ top, left });
    }, [anchorRef]);

    useLayoutEffect(() => {
        if (!open) {
            setCoords(null);
            return undefined;
        }
        updatePosition();
        const frame = window.requestAnimationFrame(updatePosition);
        return () => window.cancelAnimationFrame(frame);
    }, [open, updatePosition, children]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (anchorRef?.current?.contains(event.target) || menuRef.current?.contains(event.target)) {
                return;
            }
            onClose?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
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
    }, [open, onClose, anchorRef, updatePosition]);

    if (!open) {
        return null;
    }

    return createPortal(
        <div
            ref={menuRef}
            className={className || ui.rowActionsMenu}
            role="menu"
            style={{
                top: coords?.top ?? 0,
                left: coords?.left ?? 0,
                visibility: coords ? 'visible' : 'hidden',
            }}
        >
            {children}
        </div>,
        document.body,
    );
}
