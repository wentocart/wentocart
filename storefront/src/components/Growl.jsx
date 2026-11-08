import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from './Icons.jsx';

const GrowlContext = createContext(null);

const TONE_MAP = {
    success: { className: 'shop-growl--success', icon: 'checkCircle' },
    ok: { className: 'shop-growl--success', icon: 'checkCircle' },
    error: { className: 'shop-growl--error', icon: 'alertCircle' },
    fail: { className: 'shop-growl--error', icon: 'alertCircle' },
    warning: { className: 'shop-growl--warning', icon: 'alertTriangle' },
    info: { className: 'shop-growl--info', icon: 'info' },
};

let growlSeq = 0;

function GrowlToast({ type, message, onDismiss }) {
    const tone = TONE_MAP[type] || TONE_MAP.success;

    return (
        <div
            className={`shop-growl ${tone.className}`}
            role={type === 'error' || type === 'fail' ? 'alert' : 'status'}
            aria-live="polite"
        >
            <span className="shop-growl__icon" aria-hidden="true">
                <Icon name={tone.icon} className="shop-growl__icon-svg" />
            </span>
            <div className="shop-growl__body">{message}</div>
            <button
                type="button"
                className="shop-growl__close"
                onClick={onDismiss}
                aria-label="Dismiss notification"
            >
                <Icon name="close" className="shop-growl__close-svg" />
            </button>
        </div>
    );
}

export function GrowlProvider({ children }) {
    const [items, setItems] = useState([]);

    const dismiss = useCallback((id) => {
        setItems((prev) => prev.filter((item) => item.id !== id));
    }, []);

    const push = useCallback(
        ({ type = 'success', message }) => {
            const text = message == null ? '' : String(message).trim();
            if (!text) {
                return null;
            }

            const id = ++growlSeq;
            setItems((prev) => {
                if (prev.some((item) => item.type === type && item.message === text)) {
                    return prev;
                }
                return [...prev, { id, type, message: text }];
            });
            window.setTimeout(() => dismiss(id), 5000);
            return id;
        },
        [dismiss],
    );

    const value = useMemo(() => ({ push, dismiss }), [push, dismiss]);

    return (
        <GrowlContext.Provider value={value}>
            {children}
            <div className="shop-growl-stack" aria-live="polite" aria-atomic="true">
                {items.map((item) => (
                    <GrowlToast
                        key={item.id}
                        type={item.type}
                        message={item.message}
                        onDismiss={() => dismiss(item.id)}
                    />
                ))}
            </div>
        </GrowlContext.Provider>
    );
}

export function useGrowl() {
    return useContext(GrowlContext);
}
