import { useState } from 'react';
import { t } from '../i18n.js';
import { shopName } from '../lib/format.js';
import { Icon } from './Icons.jsx';

const VARIANTS = {
    login: {
        kicker: () => t('storefront.auth.welcome_kicker', 'Welcome back to'),
        title: () => t('storefront.auth.login_aside_title', 'Shop smarter. Live better.'),
        body: () => t(
            'storefront.auth.login_aside_body',
            'Sign in to access your wishlist, saved addresses, order history, and exclusive member benefits.',
        ),
        points: () => [
            { icon: 'truck', title: t('storefront.auth.point_ship_title', 'Free shipping'), text: t('storefront.auth.point_ship_text', 'On orders over $75') },
            { icon: 'return', title: t('storefront.auth.point_returns_title', 'Easy returns'), text: t('storefront.auth.point_returns_text', '30-day return policy') },
            { icon: 'tag', title: t('storefront.auth.point_offers_title', 'Exclusive offers'), text: t('storefront.auth.point_offers_text', 'Use code SAVE10 at checkout') },
        ],
    },
    forgot: {
        kicker: () => shopName(),
        title: () => t('storefront.auth.aside_title', 'Shop, save, and track orders in one account'),
        body: () => t(
            'storefront.auth.forgot_aside_body',
            'Reset your password and get back to a seamless shopping experience.',
        ),
        points: () => [
            { icon: 'truck', title: t('storefront.auth.point_ship_title', 'Free shipping'), text: t('storefront.auth.point_ship_text', 'On orders over $75') },
            { icon: 'return', title: t('storefront.auth.point_returns_title', '30-day returns'), text: t('storefront.auth.point_returns_text', 'Shop with confidence') },
            { icon: 'shield', title: t('storefront.auth.point_secure_title', 'Secure checkout'), text: t('storefront.auth.point_offers_text', 'Use SAVE10 at checkout') },
        ],
    },
    register: {
        kicker: () => shopName(),
        title: () => t('storefront.auth.aside_title', 'Shop, save, and track orders in one account'),
        body: () => t(
            'storefront.auth.register_aside_body',
            'Create your account to save addresses, track orders, and unlock member perks.',
        ),
        points: () => [
            { icon: 'truck', title: t('storefront.auth.point_ship_title', 'Free shipping'), text: t('storefront.auth.point_ship_text', 'On orders over $75') },
            { icon: 'return', title: t('storefront.auth.point_returns_title', '30-day returns'), text: t('storefront.auth.point_returns_text', 'Shop with confidence') },
            { icon: 'shield', title: t('storefront.auth.point_secure_title', 'Secure checkout'), text: t('storefront.auth.point_offers_text', 'Use SAVE10 at checkout') },
        ],
    },
    reset: {
        kicker: () => shopName(),
        title: () => t('storefront.auth.aside_title', 'Shop, save, and track orders in one account'),
        body: () => t(
            'storefront.auth.reset_aside_body',
            'Choose a new password and continue shopping with your saved details.',
        ),
        points: () => [
            { icon: 'shield', title: t('storefront.auth.point_secure_title', 'Secure checkout'), text: t('storefront.auth.point_secure_text', 'Protected payments') },
            { icon: 'truck', title: t('storefront.auth.point_ship_title', 'Free shipping'), text: t('storefront.auth.point_ship_text', 'On orders over $75') },
            { icon: 'return', title: t('storefront.auth.point_returns_title', '30-day returns'), text: t('storefront.auth.point_returns_text', 'Shop with confidence') },
        ],
    },
};

export function AuthField({
    label,
    icon,
    type = 'text',
    name,
    value,
    onChange,
    placeholder,
    autoComplete,
    required = false,
    optional = false,
    rightSlot = null,
    children = null,
    error = '',
}) {
    const invalid = Boolean(error);
    const rawLabel = String(label || '');
    const labeledOptional = /\(\s*optional\s*\)/i.test(rawLabel);
    const labelText = rawLabel.replace(/\s*\(\s*optional\s*\)/gi, '').replace(/\s+/g, ' ').trim();
    const showOptional = !required && (optional || labeledOptional);

    return (
        <label className={`shop-auth-field${invalid ? ' is-invalid' : ''}`}>
            {labelText ? (
                <span className="shop-auth-field-label">
                    {labelText}
                    {required ? <span className="shop-req-mark" aria-hidden="true"> *</span> : null}
                    {showOptional ? <span className="shop-auth-optional"> {t('storefront.common.optional', '(optional)')}</span> : null}
                </span>
            ) : null}
            <span className={`shop-auth-control${icon ? ' has-icon' : ''}${rightSlot ? ' has-right' : ''}`}>
                {icon ? (
                    <span className="shop-auth-control-icon" aria-hidden="true">
                        <Icon name={icon} className="h-4 w-4" />
                    </span>
                ) : null}
                {children || (
                    <input
                        className="shop-auth-input"
                        type={type}
                        name={name}
                        value={value}
                        onChange={onChange}
                        placeholder={placeholder}
                        autoComplete={autoComplete}
                        required={required}
                        aria-invalid={invalid || undefined}
                    />
                )}
                {rightSlot}
            </span>
            {invalid ? <span className="shop-auth-field-error" role="alert">{error}</span> : null}
        </label>
    );
}

export function AuthPasswordField({
    label,
    name,
    value,
    onChange,
    placeholder,
    autoComplete = 'current-password',
    required = false,
    error = '',
}) {
    const [show, setShow] = useState(false);
    return (
        <AuthField
            label={label}
            icon="lock"
            type={show ? 'text' : 'password'}
            name={name}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            autoComplete={autoComplete}
            required={required}
            error={error}
            rightSlot={(
                <button
                    type="button"
                    className="shop-auth-eye"
                    aria-label={show ? t('storefront.auth.hide_password', 'Hide password') : t('storefront.auth.show_password', 'Show password')}
                    onClick={() => setShow((v) => !v)}
                >
                    <Icon name={show ? 'eyeOff' : 'eye'} className="h-4 w-4" />
                </button>
            )}
        />
    );
}

export default function AuthShell({ variant = 'login', children }) {
    const copy = VARIANTS[variant] || VARIANTS.login;
    const points = copy.points();

    return (
        <section className="shop-auth-stage">
            <div className="shop-auth-stage-bg" aria-hidden="true">
                <span className="shop-auth-blob shop-auth-blob--a" />
                <span className="shop-auth-blob shop-auth-blob--b" />
                <span className="shop-auth-wave" />
            </div>
            <div className="shop-container shop-auth-shell">
                <aside className="shop-auth-copy">
                    <p className="shop-auth-kicker">{copy.kicker()}</p>
                    <h2>{copy.title()}</h2>
                    <p className="shop-auth-lede">{copy.body()}</p>
                    <ul className="shop-auth-points">
                        {points.map((point) => (
                            <li key={point.title}>
                                <span className="shop-auth-point-icon">
                                    <Icon name={point.icon} className="h-4 w-4" />
                                </span>
                                <span>
                                    <strong>{point.title}</strong>
                                    <em>{point.text}</em>
                                </span>
                            </li>
                        ))}
                    </ul>
                </aside>
                <div className="shop-auth-panel">
                    {children}
                </div>
            </div>
        </section>
    );
}
