import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { FormNotice } from '../components/Form.jsx';
import { parseApiErrors } from '../lib/formErrors.js';
import { Icon } from '../components/Icons.jsx';
import PhoneField from '../components/PhoneField.jsx';
import AuthShell, { AuthField, AuthPasswordField } from '../components/AuthShell.jsx';
import CmsModal from '../components/CmsModal.jsx';
import { AuthPageSkeleton } from '../components/Feedback.jsx';

/** Accept a DOM/React change event or a raw value (PhoneField / autofill helpers). */
function readFieldValue(input) {
    if (input == null) {
        return '';
    }
    if (typeof input === 'string' || typeof input === 'number' || typeof input === 'boolean') {
        return input;
    }
    if (typeof input === 'object' && input.target != null) {
        const el = input.target;
        if (el.type === 'checkbox') {
            return !!el.checked;
        }
        return el.value ?? '';
    }
    return '';
}

function messageList(errors, key) {
    const value = errors?.[key];
    const list = Array.isArray(value) ? value : (value ? [value] : []);
    return list.map((item) => String(item || '').trim()).filter(Boolean);
}

function placeRegisterErrors(errors) {
    const next = {};
    const put = (key, messages) => {
        const text = messages.filter(Boolean).join(' ');
        if (text) {
            next[key] = text;
        }
    };
    ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'gender', 'gdpr_agreed'].forEach((key) => {
        put(key, messageList(errors, key));
    });
    const passwordMessages = messageList(errors, 'password');
    put('password', passwordMessages.filter((message) => !/does not match/i.test(message)));
    put('password_confirmation', [
        ...messageList(errors, 'password_confirmation'),
        ...passwordMessages.filter((message) => /does not match/i.test(message)),
    ]);
    Object.keys(errors || {}).forEach((key) => {
        if (key.startsWith('extra.')) {
            put(key.slice(6), messageList(errors, key));
        }
    });
    return next;
}

function readNamedValue(formEl, name, fallback = '') {
    const el = formEl?.elements?.namedItem?.(name);
    if (!el || typeof el !== 'object' || !('value' in el)) {
        return fallback;
    }
    return el.value ?? fallback;
}

export default function Register() {
    const nav = useNavigate();
    const store = useStore();
    const [form, setForm] = useState({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        phone: '',
        date_of_birth: '',
        gender: '',
        newsletter: false,
        gdpr_agreed: false,
    });
    const [extra, setExtra] = useState({});
    const [fields, setFields] = useState([]);
    const [optionsCustomers, setOptionsCustomers] = useState(null);
    const [optionsGdpr, setOptionsGdpr] = useState(null);
    const [ready, setReady] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [info, setInfo] = useState('');
    const [agreementOpen, setAgreementOpen] = useState(false);
    const [phoneToken, setPhoneToken] = useState('');
    const [phoneVerified, setPhoneVerified] = useState(false);
    const [otpCode, setOtpCode] = useState('');
    const [otpSent, setOtpSent] = useState(false);
    const [otpBusy, setOtpBusy] = useState(false);
    const [otpCooldown, setOtpCooldown] = useState(0);
    const [otpHint, setOtpHint] = useState('');

    const customerSettings = optionsCustomers || store.shop?.customers || {};
    const registrationEnabled = customerSettings.registration_enabled !== false;
    const mobileLoginOn = (customerSettings.login_methods || []).some((m) => m !== 'email_password');
    const otpResendSeconds = Number(customerSettings.otp?.resend_seconds || 60);
    const phoneMode = customerSettings.phone_mode || 'optional';
    const dobMode = customerSettings.dob_mode || 'hidden';
    const newsletterOptIn = customerSettings.newsletter_opt_in !== false;
    const gdpr = optionsGdpr || store.shop?.commerce?.gdpr || {};

    useEffect(() => {
        api.get('/auth/register-options')
            .then((r) => {
                const rows = (r.data.fields || []).filter((f) => f.type === 'field' && f.name);
                setFields(rows);
                setExtra(Object.fromEntries(rows.map((f) => [f.name, ''])));
                if (r.data.customers) {
                    setOptionsCustomers(r.data.customers);
                }
                if (r.data.gdpr) {
                    setOptionsGdpr(r.data.gdpr);
                }
            })
            .catch(() => {})
            .finally(() => setReady(true));
    }, []);

    useEffect(() => {
        if (otpCooldown <= 0) return;
        const timer = setTimeout(() => setOtpCooldown((c) => c - 1), 1000);
        return () => clearTimeout(timer);
    }, [otpCooldown]);

    useEffect(() => {
        if (!Object.keys(fieldErrors).length) {
            return;
        }
        document.querySelector('.shop-auth-field.is-invalid')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }, [fieldErrors]);

    const clearFieldError = (key) => {
        setFieldErrors((prev) => {
            if (!prev[key]) {
                return prev;
            }
            const next = { ...prev };
            delete next[key];
            return next;
        });
    };

    const set = (key) => (input) => {
        clearFieldError(key);
        if (key === 'phone') {
            setPhoneToken('');
            setPhoneVerified(false);
            setOtpSent(false);
            setOtpCode('');
        }
        setForm((prev) => ({ ...prev, [key]: readFieldValue(input) }));
    };

    const sendRegisterCode = async () => {
        setError('');
        clearFieldError('phone');
        setOtpBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/auth/otp/send', { phone: form.phone, purpose: 'register' });
            setOtpSent(true);
            setOtpCooldown(otpResendSeconds);
            setOtpHint(data.sandbox_code ? t('storefront.login.sms_sandbox', 'Sandbox code: {code}').replace('{code}', data.sandbox_code) : '');
        } catch (err) {
            const placed = placeRegisterErrors(err.response?.data?.errors);
            if (placed.phone) {
                setFieldErrors((prev) => ({ ...prev, phone: placed.phone }));
            } else {
                setError(err.response?.data?.message || t('storefront.login.sms_failed', 'Could not send an SMS code.'));
            }
        } finally {
            setOtpBusy(false);
        }
    };

    const verifyRegisterCode = async () => {
        setError('');
        clearFieldError('phone');
        setOtpBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/auth/otp/verify', { phone: form.phone, code: otpCode, purpose: 'register' });
            setPhoneToken(data.phone_token || '');
            setPhoneVerified(true);
            setOtpHint('');
        } catch (err) {
            setFieldErrors((prev) => ({ ...prev, phone: err.response?.data?.errors?.code?.[0] || err.response?.data?.message || t('storefront.login.invalid_code', 'Invalid or expired code.') }));
        } finally {
            setOtpBusy(false);
        }
    };

    if (!ready) {
        return <AuthPageSkeleton variant="register" />;
    }

    if (!registrationEnabled) {
        return (
            <AuthShell variant="register">
                <div className="shop-auth-card">
                    <h1>{t('storefront.register.disabled_title', 'Registration closed')}</h1>
                    <p className="shop-auth-card-lead">
                        {t('storefront.register.disabled_body', 'This store is not accepting new customer accounts right now.')}
                    </p>
                    <p className="shop-auth-foot">
                        <Link to="/login">{t('storefront.login.title', 'Sign in')}</Link>
                    </p>
                </div>
            </AuthShell>
        );
    }

    return (
        <AuthShell variant="register">
            <form
                className="shop-auth-card"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    setFieldErrors({});
                    setInfo('');
                    setBusy(true);
                    try {
                        // Chrome Autofill may update the DOM without always syncing React state.
                        // Prefer named form controls at submit time, then fall back to state.
                        const root = e.currentTarget;
                        const synced = {
                            ...form,
                            first_name: readNamedValue(root, 'first_name', form.first_name),
                            last_name: readNamedValue(root, 'last_name', form.last_name),
                            email: readNamedValue(root, 'email', form.email),
                            password: readNamedValue(root, 'password', form.password),
                            password_confirmation: readNamedValue(root, 'password_confirmation', form.password_confirmation),
                            date_of_birth: readNamedValue(root, 'date_of_birth', form.date_of_birth),
                            gender: readNamedValue(root, 'gender', form.gender),
                            // PhoneField composes dial + national into React state; do not overwrite from tel-national alone.
                            phone: form.phone,
                        };
                        const newsletterEl = root.elements?.namedItem?.('newsletter');
                        const agreed = root.elements?.namedItem?.('gdpr_agreed');
                        if (agreed && 'checked' in agreed) {
                            synced.gdpr_agreed = !!agreed.checked;
                        }
                        if (newsletterEl && 'checked' in newsletterEl) {
                            synced.newsletter = !!newsletterEl.checked;
                        }
                        const syncedExtra = { ...extra };
                        fields.forEach((field) => {
                            syncedExtra[field.name] = readNamedValue(root, `extra_${field.name}`, extra[field.name] || '');
                        });
                        setForm(synced);
                        setExtra(syncedExtra);

                        await csrf();
                        const payload = { ...synced, extra: syncedExtra };
                        if (phoneMode === 'hidden' && !mobileLoginOn) delete payload.phone;
                        if (mobileLoginOn) {
                            if (!phoneToken) {
                                setFieldErrors({ phone: t('storefront.register.verify_phone_first', 'Verify your mobile number with the SMS code first.') });
                                setBusy(false);
                                return;
                            }
                            payload.phone_token = phoneToken;
                        }
                        if (dobMode === 'hidden') delete payload.date_of_birth;
                        if (!newsletterOptIn) delete payload.newsletter;
                        if (!gdpr.agreement_enabled) delete payload.gdpr_agreed;
                        const { data } = await api.post('/auth/register', payload);
                        if (data.pending && (data.reason === 'approval' || /admin approval/i.test(data.message || ''))) {
                            try {
                                sessionStorage.setItem('wento.register.pendingEmail', synced.email || '');
                            } catch {
                                /* private mode */
                            }
                            nav('/register/pending', { replace: true, state: { email: synced.email || '' } });
                            return;
                        }
                        if (data.pending) {
                            setInfo(data.message || t('storefront.register.pending', 'Your account is waiting for approval.'));
                            return;
                        }
                        store.setCustomer(data.customer);
                        nav('/account');
                    } catch (err) {
                        const placed = placeRegisterErrors(err.response?.data?.errors);
                        setFieldErrors(placed);
                        if (!Object.keys(placed).length) {
                            const parsed = parseApiErrors(err, t('storefront.register.failed', 'Could not register'));
                            setError(parsed.message);
                        }
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <h1>{t('storefront.register.title', 'Create an account')}</h1>
                <p className="shop-auth-card-lead">
                    {t('storefront.register.body', 'Save addresses, track orders, and write reviews.')}
                </p>
                <div className="shop-auth-stack">
                    <div className="shop-auth-grid">
                        <AuthField
                            label={t('storefront.register.first_name', 'First name')}
                            icon="user"
                            name="first_name"
                            autoComplete="given-name"
                            value={form.first_name}
                            onChange={set('first_name')}
                            error={fieldErrors.first_name || ''}
                            required
                        />
                        <AuthField
                            label={t('storefront.register.last_name', 'Last name')}
                            icon="user"
                            name="last_name"
                            autoComplete="family-name"
                            value={form.last_name}
                            onChange={set('last_name')}
                            error={fieldErrors.last_name || ''}
                            required
                        />
                    </div>
                    <AuthField
                        label={t('storefront.register.email', 'Email')}
                        icon="mail"
                        type="email"
                        name="email"
                        autoComplete="email"
                        value={form.email}
                        onChange={set('email')}
                        error={fieldErrors.email || ''}
                        required
                    />
                    <div className="shop-auth-grid">
                        <AuthPasswordField
                            label={t('storefront.register.password', 'Password')}
                            name="password"
                            autoComplete="new-password"
                            value={form.password}
                            onChange={set('password')}
                            error={fieldErrors.password || ''}
                            required
                        />
                        <AuthPasswordField
                            label={t('storefront.register.confirm', 'Confirm password')}
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={form.password_confirmation}
                            onChange={set('password_confirmation')}
                            error={fieldErrors.password_confirmation || ''}
                            required
                        />
                    </div>
                    {phoneMode !== 'hidden' || mobileLoginOn ? (
                        <AuthField
                            label={mobileLoginOn || phoneMode === 'required'
                                ? t('storefront.register.phone_required', 'Phone')
                                : t('storefront.register.phone', 'Phone (optional)')}
                            required={mobileLoginOn || phoneMode === 'required'}
                            error={fieldErrors.phone || ''}
                        >
                            <PhoneField
                                name="phone"
                                countries={store.shop?.countries || []}
                                value={form.phone}
                                onChange={(value) => {
                                    clearFieldError('phone');
                                    setPhoneToken('');
                                    setPhoneVerified(false);
                                    setOtpSent(false);
                                    setOtpCode('');
                                    setForm((prev) => ({ ...prev, phone: value || '' }));
                                }}
                                required={mobileLoginOn || phoneMode === 'required'}
                            />
                            {mobileLoginOn ? (
                                <div className="shop-auth-otp">
                                    {phoneVerified ? (
                                        <p className="shop-auth-hint">{t('storefront.register.phone_verified', 'Mobile number verified.')}{'\u2713'}</p>
                                    ) : otpSent ? (
                                        <>
                                            <div className="shop-auth-otp-row">
                                                <input
                                                    className="shop-auth-input"
                                                    inputMode="numeric"
                                                    autoComplete="one-time-code"
                                                    placeholder="123456"
                                                    value={otpCode}
                                                    onChange={(e) => setOtpCode(e.target.value)}
                                                />
                                                <button type="button" className="shop-auth-btn" disabled={otpBusy || !otpCode} onClick={verifyRegisterCode}>
                                                    {t('storefront.register.verify', 'Verify')}
                                                </button>
                                            </div>
                                            {otpHint ? <p className="shop-auth-hint">{otpHint}</p> : null}
                                            <button type="button" className="shop-auth-link" disabled={otpBusy || otpCooldown > 0} onClick={sendRegisterCode}>
                                                {otpCooldown > 0
                                                    ? t('storefront.login.resend_in', 'Resend code in {s}s').replace('{s}', String(otpCooldown))
                                                    : t('storefront.login.resend', 'Resend code')}
                                            </button>
                                        </>
                                    ) : (
                                        <button type="button" className="shop-auth-btn" disabled={otpBusy || !form.phone} onClick={sendRegisterCode}>
                                            {t('storefront.register.send_code', 'Text me a code')}
                                        </button>
                                    )}
                                </div>
                            ) : null}
                        </AuthField>
                    ) : null}
                    {dobMode !== 'hidden' ? (
                        <AuthField
                            label={dobMode === 'required'
                                ? t('storefront.register.dob_required', 'Date of birth')
                                : t('storefront.register.dob', 'Date of birth (optional)')}
                            icon="calendar"
                            type="date"
                            name="date_of_birth"
                            value={form.date_of_birth}
                            onChange={set('date_of_birth')}
                            error={fieldErrors.date_of_birth || ''}
                            required={dobMode === 'required'}
                        />
                    ) : null}
                    <AuthField label={t('storefront.register.gender', 'Gender (optional)')} icon="user" error={fieldErrors.gender || ''}>
                        <select className="shop-auth-input" name="gender" value={form.gender} onChange={set('gender')}>
                            <option value="">{t('storefront.common.select', 'Prefer not to say')}</option>
                            <option value="female">{t('storefront.register.female', 'Female')}</option>
                            <option value="male">{t('storefront.register.male', 'Male')}</option>
                            <option value="other">{t('storefront.register.other', 'Other')}</option>
                        </select>
                    </AuthField>
                    {newsletterOptIn ? (
                        <label className="shop-auth-check" style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                            <input
                                type="checkbox"
                                name="newsletter"
                                checked={!!form.newsletter}
                                onChange={(e) => setForm((prev) => ({ ...prev, newsletter: !!e?.target?.checked }))}
                            />
                            <span>{t('storefront.register.newsletter', 'Email me about orders and offers')}</span>
                        </label>
                    ) : null}
                    {fields.map((field) => (
                        <AuthField
                            key={field.name}
                            label={field.label || field.name}
                            icon="user"
                            error={fieldErrors[field.name] || ''}
                            required={!!field.required}
                            optional={!field.required}
                        >
                            {field.input === 'textarea' ? (
                                <textarea
                                    className="shop-auth-input"
                                    name={`extra_${field.name}`}
                                    rows={3}
                                    required={!!field.required}
                                    value={extra[field.name] || ''}
                                    onChange={(e) => {
                                        clearFieldError(field.name);
                                        setExtra((prev) => ({ ...prev, [field.name]: readFieldValue(e) }));
                                    }}
                                />
                            ) : field.input === 'select' ? (
                                <select
                                    className="shop-auth-input"
                                    name={`extra_${field.name}`}
                                    required={!!field.required}
                                    value={extra[field.name] || ''}
                                    onChange={(e) => {
                                        clearFieldError(field.name);
                                        setExtra((prev) => ({ ...prev, [field.name]: readFieldValue(e) }));
                                    }}
                                >
                                    <option value="">{t('storefront.common.select', 'Select')}</option>
                                    {(field.options || []).map((opt) => {
                                        const optionValue = typeof opt === 'object' && opt != null
                                            ? (opt.value ?? opt.id ?? '')
                                            : opt;
                                        const optionLabel = typeof opt === 'object' && opt != null
                                            ? (opt.label || opt.value || optionValue)
                                            : opt;
                                        return (
                                            <option key={String(optionValue)} value={optionValue}>
                                                {optionLabel}
                                            </option>
                                        );
                                    })}
                                </select>
                            ) : (
                                <input
                                    type={field.input === 'number' ? 'number' : field.input === 'password' ? 'password' : 'text'}
                                    className="shop-auth-input"
                                    name={`extra_${field.name}`}
                                    required={!!field.required}
                                    value={extra[field.name] || ''}
                                    onChange={(e) => {
                                        clearFieldError(field.name);
                                        setExtra((prev) => ({ ...prev, [field.name]: readFieldValue(e) }));
                                    }}
                                />
                            )}
                        </AuthField>
                    ))}
                    <FormNotice tone="error">{Object.keys(fieldErrors).length ? '' : error}</FormNotice>
                    <FormNotice tone="ok">{info}</FormNotice>
                    {gdpr.agreement_enabled ? (
                        <label className="shop-auth-check" style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start' }}>
                            <input
                                type="checkbox"
                                name="gdpr_agreed"
                                checked={!!form.gdpr_agreed}
                                onChange={(e) => setForm((prev) => ({ ...prev, gdpr_agreed: !!e?.target?.checked }))}
                                required
                            />
                            <span>
                                {gdpr.agreement_title || t('storefront.gdpr.agree', 'I agree with the terms and conditions')}
                                {gdpr.agreement_page?.slug ? (
                                    <>
                                        {' '}
                                        <button
                                            type="button"
                                            className="underline cursor-pointer"
                                            onClick={() => setAgreementOpen(true)}
                                        >
                                            {t('storefront.gdpr.click_here', 'Click Here')}
                                        </button>
                                    </>
                                ) : null}
                            </span>
                        </label>
                    ) : null}
                    <button type="submit" className="shop-auth-submit" disabled={busy}>
                        {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.register.submit', 'Register')}
                        {!busy ? (
                            <span className="shop-auth-submit-icon">
                                <Icon name="chevron" className="h-3.5 w-3.5" />
                            </span>
                        ) : null}
                    </button>
                    <p className="shop-auth-foot">
                        {t('storefront.register.have_account', 'Already have an account?')}
                        {' '}
                        <Link to="/login">{t('storefront.login.title', 'Sign in')}</Link>
                    </p>
                </div>
            </form>
            <CmsModal
                slug={gdpr.agreement_page?.slug || ''}
                titleFallback={gdpr.agreement_page?.title || ''}
                open={agreementOpen}
                onClose={() => setAgreementOpen(false)}
            />
        </AuthShell>
    );
}
