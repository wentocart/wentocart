import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { ui, cx } from './classes';
import Icon from './Icon';
import { FlashGrowl, FormErrorsGrowl } from './Growl';

function resolveFlash(flashProp, page) {
    const shared = flashProp ?? page.props?.flash ?? {};
    const native = page.flash ?? {};

    return {
        success: shared.success ?? native.success ?? null,
        error: shared.error ?? native.error ?? null,
        warning: shared.warning ?? native.warning ?? null,
        info: shared.info ?? native.info ?? null,
        toast: Boolean(shared.toast ?? native.toast),
        customer_id: shared.customer_id ?? native.customer_id ?? null,
        import_batch_id: shared.import_batch_id ?? native.import_batch_id ?? null,
    };
}

const types = {
    ok: { className: ui.alertOk, icon: 'check-circle' },
    success: { className: ui.alertOk, icon: 'check-circle' },
    error: { className: ui.alertErr, icon: 'alert-circle' },
    fail: { className: ui.alertErr, icon: 'alert-circle' },
    warning: { className: ui.alertWarn, icon: 'alert-triangle' },
    info: { className: ui.alertInfo, icon: 'info' },
};

export default function Alert({ type = 'ok', children, className }) {
    const t = types[type] || types.ok;

    return (
        <div className={cx(ui.alert, t.className, className)} role={type === 'error' || type === 'fail' ? 'alert' : 'status'}>
            <Icon name={t.icon} />
            <div>{children}</div>
        </div>
    );
}

function formErrorEntries(errors, except = []) {
    const exceptSet = except instanceof Set ? except : new Set(except || []);

    return Object.entries(errors || {}).filter(([key]) => {
        if (exceptSet.has(key)) {
            return false;
        }
        for (const prefix of exceptSet) {
            if (prefix && key.startsWith(`${prefix}.`)) {
                return false;
            }
        }

        return true;
    });
}

export function FormErrors({ errors, className, variant = 'inline', replayKey, except }) {
    const entries = formErrorEntries(errors, except);
    const messages = entries.map(([, value]) => value).flat().filter(Boolean);
    const anchor = useRef(null);
    const signature = messages.join('\n');

    useEffect(() => {
        if (variant === 'growl' || !signature || !anchor.current) {
            return;
        }
        anchor.current.focus();
        anchor.current.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }, [signature, variant]);

    if (variant === 'growl') {
        const filtered = Object.fromEntries(entries);

        return <FormErrorsGrowl errors={filtered} replayKey={replayKey} />;
    }

    if (!messages.length) {
        return null;
    }

    return (
        <div ref={anchor} tabIndex={-1} className="outline-none">
        <Alert type="error" className={className}>
            {messages.length === 1 ? (
                messages[0]
            ) : (
                <ul className="list-disc pl-4 space-y-1">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}
        </Alert>
        </div>
    );
}

export function Flash({ flash: flashProp, variant, className }) {
    const page = usePage();
    const flash = resolveFlash(flashProp, page);
    const hasMessage = Boolean(flash.success || flash.error || flash.warning || flash.info);

    if (!hasMessage && !flash.toast) {
        return null;
    }

    // Growl only when a list-level action marked the flash with toast=true.
    // Form create/edit/save use plain session flash → inline banner (legacy UI).
    const useGrowl = variant === 'growl' || (variant !== 'inline' && flash.toast);

    if (useGrowl) {
        return <FlashGrowl flash={flash} />;
    }

    return (
        <div className={className}>
            {flash.success ? <Alert type="ok">{flash.success}</Alert> : null}
            {flash.error ? <Alert type="error">{flash.error}</Alert> : null}
            {flash.warning ? <Alert type="warning">{flash.warning}</Alert> : null}
            {flash.info ? <Alert type="info">{flash.info}</Alert> : null}
        </div>
    );
}

export function FieldError({ children }) {
    if (children == null || children === false || children === '') {
        return null;
    }

    if (typeof children === 'object' && !Array.isArray(children)) {
        return null;
    }

    return (
        <p className={ui.fieldError} role="alert">
            {children}
        </p>
    );
}
