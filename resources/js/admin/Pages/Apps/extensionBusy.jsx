import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/** Partial reload must include sidebar shares or menus stay stale after enable/uninstall. */
export const EXTENSION_RELOAD_PROPS = ['apps', 'catalog', 'appNav', 'moduleNav', 'shop', 'flash', 'errors'];

const ExtensionBusyContext = createContext(null);

export function ExtensionBusyProvider({ children }) {
    const [busyIds, setBusyIds] = useState(() => new Set());
    const inFlight = useRef(new Set());

    const setBusy = useCallback((id, busy) => {
        if (busy) {
            inFlight.current.add(id);
        } else {
            inFlight.current.delete(id);
        }
        setBusyIds((prev) => {
            const next = new Set(prev);
            if (busy) {
                next.add(id);
            } else {
                next.delete(id);
            }
            return next;
        });
    }, []);

    const isBusy = useCallback((id) => busyIds.has(id), [busyIds]);

    const visit = useCallback((id, method, url, data = {}) => {
        if (!id || inFlight.current.has(id)) {
            return;
        }

        setBusy(id, true);

        const options = {
            preserveScroll: true,
            preserveState: true,
            async: true,
            showProgress: false,
            only: EXTENSION_RELOAD_PROPS,
            onFinish: () => setBusy(id, false),
        };

        if (method === 'delete') {
            router.delete(url, options);
            return;
        }

        router.post(url, data, options);
    }, [setBusy]);

    const value = useMemo(() => ({ busyIds, isBusy, visit, setBusy }), [busyIds, isBusy, visit, setBusy]);

    return (
        <ExtensionBusyContext.Provider value={value}>
            {children}
        </ExtensionBusyContext.Provider>
    );
}

export function useExtensionBusy() {
    const ctx = useContext(ExtensionBusyContext);
    if (!ctx) {
        throw new Error('useExtensionBusy must be used inside ExtensionBusyProvider');
    }

    return ctx;
}
