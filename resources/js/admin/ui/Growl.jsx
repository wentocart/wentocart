import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

const GrowlContext = createContext(null);

const toneMap = {
    success: {
        className: ui.growlSuccess,
        icon: 'check-circle',
        iconClass: ui.growlIconSuccess,
        wrapClass: ui.growlIconWrapSuccess,
    },
    ok: {
        className: ui.growlSuccess,
        icon: 'check-circle',
        iconClass: ui.growlIconSuccess,
        wrapClass: ui.growlIconWrapSuccess,
    },
    error: {
        className: ui.growlError,
        icon: 'alert-circle',
        iconClass: ui.growlIconError,
        wrapClass: ui.growlIconWrapError,
    },
    fail: {
        className: ui.growlError,
        icon: 'alert-circle',
        iconClass: ui.growlIconError,
        wrapClass: ui.growlIconWrapError,
    },
    warning: {
        className: ui.growlWarning,
        icon: 'alert-triangle',
        iconClass: ui.growlIconWarning,
        wrapClass: ui.growlIconWrapWarning,
    },
    info: {
        className: ui.growlInfo,
        icon: 'info',
        iconClass: ui.growlIconInfo,
        wrapClass: ui.growlIconWrapInfo,
    },
};

let growlSeq = 0;

function GrowlToast({ type, message, onDismiss }) {
    const tone = toneMap[type] || toneMap.success;

    return (
        <div className={cx(ui.growl, tone.className)} role={type === 'error' || type === 'fail' ? 'alert' : 'status'}>
            <span className={cx(ui.growlIconWrap, tone.wrapClass)} aria-hidden>
                <Icon name={tone.icon} className={cx(ui.growlIcon, tone.iconClass)} />
            </span>
            <div className={ui.growlBody}>{message}</div>
            <button type="button" className={ui.growlClose} onClick={onDismiss} aria-label={t('admin.common.close', 'Close')}>
                <Icon name="x" className="!h-4 !w-4" />
            </button>
        </div>
    );
}

export function GrowlProvider({ children }) {
    const [items, setItems] = useState([]);

    const dismiss = useCallback((id) => {
        setItems((prev) => prev.filter((item) => item.id !== id));
    }, []);

    const push = useCallback(({ type = 'success', message }) => {
        const text = message == null ? '' : String(message).trim();
        if (!text) {
            return null;
        }

        const id = ++growlSeq;
        setItems((prev) => [
            ...prev.filter((item) => !(item.type === type && item.message === text)),
            { id, type, message: text },
        ]);
        window.setTimeout(() => dismiss(id), 5000);
        return id;
    }, [dismiss]);

    const value = useMemo(() => ({ push, dismiss }), [push, dismiss]);

    return (
        <GrowlContext.Provider value={value}>
            {children}
            <div className={ui.growlStack} aria-live="polite" aria-atomic="true">
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

/** Session flash → growl only when list actions set flash.toast. */
export function FlashGrowl({ flash }) {
    const growl = useGrowl();
    const { url } = usePage();
    const seenRef = useRef(new Set());

    useEffect(() => {
        const forget = router.on('start', () => {
            seenRef.current.clear();
        });

        return forget;
    }, []);

    useEffect(() => {
        seenRef.current.clear();
    }, [url]);

    useEffect(() => {
        if (!growl || !flash?.toast) {
            return;
        }

        const entries = [
            flash.success ? ['success', flash.success] : null,
            flash.error ? ['error', flash.error] : null,
            flash.warning ? ['warning', flash.warning] : null,
            flash.info ? ['info', flash.info] : null,
        ].filter(Boolean);

        entries.forEach(([type, message]) => {
            const sig = `${url}:${type}:${message}`;
            if (seenRef.current.has(sig)) {
                return;
            }
            seenRef.current.add(sig);
            growl.push({ type, message });
        });
    }, [flash, growl, url]);

    return null;
}

/** Validation errors → growl toasts (for list pages). */
export function FormErrorsGrowl({ errors, replayKey }) {
    const growl = useGrowl();
    const { url } = usePage();
    const seenRef = useRef('');

    useEffect(() => {
        const forget = router.on('start', () => {
            seenRef.current = '';
        });

        return forget;
    }, []);

    const messages = useMemo(
        () => Object.values(errors || {}).flat().filter(Boolean).map(String),
        [errors],
    );

    useEffect(() => {
        if (!growl || !messages.length) {
            return;
        }
        const sig = `${url}:errors:${messages.join('\u0001')}:${replayKey ?? ''}`;
        if (seenRef.current === sig) {
            return;
        }
        seenRef.current = sig;
        messages.forEach((message) => growl.push({ type: 'error', message }));
    }, [growl, messages, url, replayKey]);

    return null;
}
