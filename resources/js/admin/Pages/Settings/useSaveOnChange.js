import { useEffect, useRef } from 'react';

function snapshotOf(data, deferKeys) {
    if (!deferKeys.length) {
        return JSON.stringify(data);
    }
    const copy = { ...data };
    deferKeys.forEach((key) => {
        delete copy[key];
    });

    return JSON.stringify(copy);
}

function withoutEmptySecrets(body, deferKeys, includeDeferred) {
    const next = { ...body };
    deferKeys.forEach((key) => {
        const value = next[key];
        const empty = value == null || String(value).trim() === '';
        if (!includeDeferred || empty) {
            delete next[key];
        }
    });

    return next;
}

/**
 * Saves a settings form when its data changes.
 * Toggles and selects save immediately. Text keys wait briefly.
 * deferKeys (passwords and secrets) stay out of the request until saveDeferred().
 */
export function useSaveOnChange(form, url, options = {}) {
    const formRef = useRef(form);
    formRef.current = form;
    const optionsRef = useRef(options);
    optionsRef.current = options;
    const urlRef = useRef(url);
    urlRef.current = url;
    const booted = useRef(false);
    const lastData = useRef(form.data);
    const lastSent = useRef('');
    const saveTimer = useRef(null);

    const put = (includeDeferred) => {
        const current = formRef.current;
        const opts = optionsRef.current;
        const deferKeys = opts.deferKeys || [];
        let body = { ...current.data };
        if (opts.transform) {
            body = opts.transform(body);
        }
        body = withoutEmptySecrets(body, deferKeys, includeDeferred);
        lastSent.current = snapshotOf(current.data, deferKeys);
        current.transform(() => body);
        current.put(urlRef.current, {
            preserveScroll: true,
            preserveState: true,
        });
    };
    const putRef = useRef(put);
    putRef.current = put;

    useEffect(() => () => {
        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
    }, []);

    useEffect(() => {
        const opts = optionsRef.current;
        if (opts.enabled === false) {
            return;
        }
        const deferKeys = opts.deferKeys || [];
        const textKeys = opts.textKeys || [];
        const delay = opts.delay ?? 1000;
        const shot = snapshotOf(form.data, deferKeys);
        if (!booted.current) {
            booted.current = true;
            lastData.current = form.data;
            lastSent.current = shot;

            return;
        }
        if (shot === lastSent.current) {
            return;
        }

        const previous = lastData.current || {};
        lastData.current = form.data;
        const changed = Object.keys(form.data).filter((key) => (
            !deferKeys.includes(key)
            && JSON.stringify(form.data[key]) !== JSON.stringify(previous[key])
        ));
        if (changed.length === 0) {
            return;
        }
        const textOnly = changed.every((key) => textKeys.includes(key));
        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
        saveTimer.current = setTimeout(() => putRef.current(false), textOnly ? delay : 0);
    }, [form.data]);

    return {
        saveDeferred() {
            const deferKeys = optionsRef.current.deferKeys || [];
            const current = formRef.current;
            const hasSecret = deferKeys.some((key) => String(current.data[key] ?? '').trim() !== '');
            if (!hasSecret) {
                return;
            }
            if (saveTimer.current) {
                clearTimeout(saveTimer.current);
            }
            put(true);
        },
    };
}
