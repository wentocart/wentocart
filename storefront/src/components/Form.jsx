import { Children, cloneElement, isValidElement, useId, useState } from 'react';
import { t } from '../i18n.js';
import { passwordChecks, passwordScore, passwordTone } from '../lib/passwordStrength.js';
import { Icon } from './Icons.jsx';

function decorateControl(child, { invalid, errorId }) {
    if (!isValidElement(child)) {
        return child;
    }

    const className = [child.props.className, invalid ? 'is-invalid' : null].filter(Boolean).join(' ');
    const describedBy = [child.props['aria-describedby'], invalid ? errorId : null].filter(Boolean).join(' ') || undefined;

    return cloneElement(child, {
        className,
        'aria-invalid': invalid ? true : child.props['aria-invalid'],
        'aria-describedby': describedBy,
    });
}

export function FormField({ label, hint, error, children, className = '', span = false, required: requiredLabel = false }) {
    const reactId = useId();
    const errorId = `${reactId}-error`;
    const invalid = !!error;
    const required = requiredLabel || Children.toArray(children).some(
        (child) => isValidElement(child) && Boolean(child.props?.required),
    );

    return (
        <label className={`shop-field${span ? ' shop-form-span' : ''}${className ? ` ${className}` : ''}`}>
            {label ? (
                <span className="shop-field-label">
                    {label}
                    {required ? <span className="text-red-600" aria-hidden="true"> *</span> : null}
                </span>
            ) : null}
            {Children.map(children, (child) => decorateControl(child, { invalid, errorId }))}
            {error ? <em className="shop-field-error" id={errorId} role="alert">{error}</em> : hint ? <em className="shop-field-hint">{hint}</em> : null}
        </label>
    );
}

/**
 * Translatable field: label + compact language select (admin LocalizedField pattern).
 * Reuses shop-field / shop-input / shop-input--sm — no page-specific CSS.
 */
export function LocalizedFormField({
    label,
    languages = [],
    currentLanguage,
    onLanguageChange,
    error,
    hint,
    required = false,
    span = false,
    children,
    className = '',
}) {
    const reactId = useId();
    const errorId = `${reactId}-error`;
    const showLocale = languages.length > 1;
    const invalid = !!error;

    return (
        <div className={`shop-field${span ? ' shop-form-span' : ''}${className ? ` ${className}` : ''}`}>
            <div className={`flex flex-col gap-2${showLocale ? ' sm:flex-row sm:items-center sm:justify-between' : ''}`}>
                <span className="shop-field-label">
                    {label}
                    {required ? <span className="text-red-600" aria-hidden="true"> *</span> : null}
                </span>
                {showLocale ? (
                    <label className="flex items-center gap-2 sm:shrink-0">
                        <span className="sr-only">{label} language</span>
                        <select
                            className="shop-input shop-input--sm"
                            value={currentLanguage}
                            onChange={(e) => onLanguageChange?.(e.target.value)}
                            aria-label={`${label} language`}
                        >
                            {languages.map((row) => (
                                <option key={row.code} value={row.code}>
                                    {String(row.code || '').toUpperCase()}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : null}
            </div>
            {Children.map(children, (child) => decorateControl(child, { invalid, errorId }))}
            {error ? <em className="shop-field-error" id={errorId} role="alert">{error}</em> : hint ? <em className="shop-field-hint">{hint}</em> : null}
        </div>
    );
}

export function FormSwitch({ checked, onChange, label, hint, disabled = false }) {
    return (
        <label className="shop-switch">
            <input type="checkbox" checked={!!checked} disabled={disabled} onChange={(e) => onChange?.(e.target.checked)} />
            <span className="shop-switch-ui" />
            <span className="shop-switch-copy">
                <strong>{label}</strong>
                {hint ? <em>{hint}</em> : null}
            </span>
        </label>
    );
}

export function FormNotice({ children, tone = 'info' }) {
    if (!children) {
        return null;
    }

    return <p className={`shop-notice shop-notice--${tone}`}>{children}</p>;
}

/**
 * Reusable form-level validation banner. Pair with parseApiErrors() and keep field state on the caller.
 */
export function FormErrorBanner({ messages, placement = 'top' }) {
    const list = (Array.isArray(messages) ? messages : [messages]).map((item) => String(item || '').trim()).filter(Boolean);
    if (!list.length) {
        return null;
    }

    return (
        <div className={`shop-form-errors shop-form-errors--${placement === 'bottom' ? 'bottom' : 'top'}`} role="alert">
            {list.map((message, index) => (
                <FormNotice key={`${index}-${message}`} tone="error">{message}</FormNotice>
            ))}
        </div>
    );
}

export function FormSection({ title, description, children, layout = 'grid' }) {
    return (
        <section className="shop-form-section">
            {title || description ? (
                <header className="shop-form-section-head">
                    {title ? <h2>{title}</h2> : null}
                    {description ? <p>{description}</p> : null}
                </header>
            ) : null}
            <div className={layout === 'stack' ? 'grid gap-4' : 'shop-form-grid'}>{children}</div>
        </section>
    );
}

export function FormShell({ children, className = '', onSubmit, size = 'lg', noValidate = false }) {
    const sizeClass = size ? ` shop-form--${size}` : '';
    return (
        <form
            className={`shop-form${sizeClass}${className ? ` ${className}` : ''}`}
            onSubmit={onSubmit}
            noValidate={noValidate}
        >
            {children}
        </form>
    );
}

export function FormActions({ children }) {
    return <div className="shop-form-actions">{children}</div>;
}

export function PasswordInput({ className = '', required, ...props }) {
    const [show, setShow] = useState(false);
    const invalid = String(className).includes('is-invalid') || !!props['aria-invalid'];
    const describedBy = props['aria-describedby'];

    return (
        <span className={['shop-profile-password', invalid ? 'is-invalid' : null].filter(Boolean).join(' ')}>
            <input
                {...props}
                required={required}
                aria-invalid={invalid ? true : props['aria-invalid']}
                aria-describedby={describedBy}
                type={show ? 'text' : 'password'}
                className={`shop-input${invalid ? ' is-invalid' : ''}`}
            />
            <button
                type="button"
                className="shop-profile-password-toggle"
                aria-pressed={show}
                aria-label={show ? t('storefront.auth.hide_password', 'Hide password') : t('storefront.auth.show_password', 'Show password')}
                onMouseDown={(e) => e.preventDefault()}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    setShow((v) => !v);
                }}
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="shop-password-toggle-icon" aria-hidden="true">
                    {show ? (
                        <>
                            <path d="M3 3l18 18" />
                            <path d="M10.6 10.6a2 2 0 1 0 2.8 2.8" />
                            <path d="M9.9 5.1A10 10 0 0 1 12 5c5 0 9.3 3.1 10.7 7.4-.5 1.5-1.4 2.9-2.6 4" />
                            <path d="M6.7 6.7C4.4 8.2 2.8 10.4 2.3 12.4 3.7 16.7 8 20 13 20c1.3 0 2.6-.2 3.8-.7" />
                        </>
                    ) : (
                        <>
                            <path d="M2.3 12.4C3.7 8.1 8 5 13 5s9.3 3.1 10.7 7.4C22.3 16.7 18 20 13 20s-9.3-3.1-10.7-7.6Z" />
                            <circle cx="13" cy="12.4" r="3.1" />
                        </>
                    )}
                </svg>
            </button>
        </span>
    );
}

export function PasswordHint({ value = '', confirm }) {
    const checks = passwordChecks(value);
    const score = passwordScore(value);
    const tone = passwordTone(score);
    const started = value.length > 0;
    const confirmStarted = typeof confirm === 'string' && confirm.length > 0;
    const matched = confirmStarted && value === confirm;
    const rules = [
        { key: 'length', ok: checks.length, label: t('storefront.auth.password_rule_length', '8–72 characters') },
        { key: 'capital', ok: checks.capital, label: t('storefront.auth.password_rule_capital', 'One capital letter') },
        { key: 'number', ok: checks.number, label: t('storefront.auth.password_rule_number', 'One number') },
        { key: 'spaces', ok: checks.spaces, label: t('storefront.auth.password_rule_spaces', 'No spaces') },
    ];
    const toneLabel = {
        weak: t('storefront.auth.password_weak', 'Weak'),
        fair: t('storefront.auth.password_fair', 'Fair'),
        medium: t('storefront.auth.password_medium', 'Medium'),
        strong: t('storefront.auth.password_strong', 'Strong'),
    }[tone];

    return (
        <div className="shop-password-hint">
            <div className="shop-password-meter" aria-live="polite">
                <span className={`shop-password-meter-label is-${started ? tone : 'idle'}`}>
                    {started ? toneLabel : t('storefront.auth.password_hint', 'Use a strong password')}
                </span>
                <span className="shop-password-meter-bar" aria-hidden="true">
                    {[1, 2, 3, 4].map((n) => (
                        <i key={n} className={started && score >= n ? `is-on is-${tone}` : ''} />
                    ))}
                </span>
            </div>
            <ul className="shop-password-rules">
                {rules.map((rule) => (
                    <li key={rule.key} className={rule.ok ? 'is-ok' : started ? 'is-miss' : ''}>
                        {rule.ok
                            ? <Icon name="check" className="shop-password-rule-icon" width="14" height="14" />
                            : <span className="shop-password-rule-dot" aria-hidden="true" />}
                        {rule.label}
                    </li>
                ))}
            </ul>
            {confirmStarted ? (
                <p className={`shop-password-match${matched ? ' is-ok' : ' is-miss'}`}>
                    {matched
                        ? t('storefront.auth.password_match', 'Passwords match')
                        : t('storefront.checkout.password_mismatch', 'Password and re-password do not match.')}
                </p>
            ) : null}
        </div>
    );
}
