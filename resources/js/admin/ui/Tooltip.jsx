import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ui, cx } from './classes';

export default function Tooltip({
    content,
    children,
    className,
    placement = 'top',
    portal = false,
}) {
    if (!content) {
        return children;
    }

    const below = placement === 'bottom';
    const right = placement === 'right';
    const [visible, setVisible] = useState(false);
    const [coords, setCoords] = useState(null);
    const triggerRef = useRef(null);
    const tipRef = useRef(null);

    const updatePosition = useCallback(() => {
        const trigger = triggerRef.current;
        if (!trigger) {
            return;
        }

        const rect = trigger.getBoundingClientRect();
        const tip = tipRef.current;
        const tipHeight = tip?.offsetHeight || 28;
        const tipWidth = tip?.offsetWidth || 80;
        const gap = 6;
        const pad = 8;

        let top;
        let left;
        if (right) {
            top = rect.top + (rect.height / 2) - (tipHeight / 2);
            left = rect.right + gap;
        } else {
            top = below ? rect.bottom + gap : rect.top - gap - tipHeight;
            left = rect.left + (rect.width / 2) - (tipWidth / 2);
        }

        left = Math.max(pad, Math.min(left, window.innerWidth - tipWidth - pad));
        top = Math.max(pad, Math.min(top, window.innerHeight - tipHeight - pad));

        setCoords({ top, left });
    }, [below, right]);

    const show = () => {
        setVisible(true);
    };

    const hide = () => {
        setVisible(false);
        setCoords(null);
    };

    useLayoutEffect(() => {
        if (!visible || !portal) {
            return undefined;
        }

        updatePosition();
        const frame = window.requestAnimationFrame(updatePosition);

        const onReposition = () => updatePosition();
        window.addEventListener('scroll', onReposition, true);
        window.addEventListener('resize', onReposition);

        return () => {
            window.cancelAnimationFrame(frame);
            window.removeEventListener('scroll', onReposition, true);
            window.removeEventListener('resize', onReposition);
        };
    }, [visible, portal, updatePosition]);

    const trigger = (
        <span
            ref={triggerRef}
            className={cx(portal ? 'inline-flex' : ui.tooltipWrap, className)}
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            {children}
            {!portal ? (
                <span role="tooltip" className={below ? ui.tooltipBottom : ui.tooltip}>
                    {content}
                    <span className={below ? ui.tooltipArrowBottom : ui.tooltipArrow} aria-hidden="true" />
                </span>
            ) : null}
        </span>
    );

    const portaledTip = portal && visible && typeof document !== 'undefined'
        ? createPortal(
            <div
                ref={tipRef}
                role="tooltip"
                className={ui.tooltipPortal}
                style={{
                    top: coords?.top ?? -9999,
                    left: coords?.left ?? -9999,
                    visibility: coords ? 'visible' : 'hidden',
                }}
            >
                {content}
            </div>,
            document.body,
        )
        : null;

    return (
        <>
            {trigger}
            {portaledTip}
        </>
    );
}
