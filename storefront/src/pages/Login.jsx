import { useEffect, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { shopName } from '../lib/format.js';
import { safeRedirectUrl } from '../lib/safeRedirect.js';
import { webPath } from '../webPath.js';
import { useStore } from '../state/StoreState.jsx';
import { FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import AuthShell, { AuthField, AuthPasswordField } from '../components/AuthShell.jsx';

function b64uToBuf(value) {
    const pad = '='.repeat((4 - (String(value).length % 4)) % 4);
    const bin = atob(String(value).replace(/-/g, '+').replace(/_/g, '/') + pad);
    return Uint8Array.from(bin, (c) => c.charCodeAt(0));
}

function bufToB64u(buf) {
    const bytes = new Uint8Array(buf);
    let s = '';
    bytes.forEach((b) => { s += String.fromCharCode(b); });
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export default function Login() {
    const nav = useNavigate();
    const location = useLocation();
    const store = useStore();
    const methods = store.shop?.customers?.login_methods || ['email_password'];
    const hasEmail = methods.includes('email_password');
    const hasMobile = methods.includes('mobile_password');
    const hasCode = methods.includes('mobile_otp');
    const otpPolicy = store.shop?.customers?.otp || {};
    const [tab, setTab] = useState(hasEmail ? 'email' : (hasMobile ? 'mobile' : 'code'));
    const [email, setEmail] = useState('');
    const [mobile, setMobile] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [mfa, setMfa] = useState(false);
    const [passkey, setPasskey] = useState(false);
    const [sms, setSms] = useState(false);
    const [code, setCode] = useState('');
    const [codeSent, setCodeSent] = useState(false);
    const [cooldown, setCooldown] = useState(0);
    const [sandboxHint, setSandboxHint] = useState('');
    const social = store.shop?.customers?.social || {};
    const socialProviders = [
        social.google ? { key: 'google', label: 'Google', icon: 'google' } : null,
        social.facebook ? { key: 'facebook', label: 'Facebook', icon: 'facebook' } : null,
        social.linkedin ? { key: 'linkedin', label: 'LinkedIn', icon: 'linkedin' } : null,
    ].filter(Boolean);
    const afterLogin = () => {
        const from = safeRedirectUrl(location.state?.from);
        nav(from && from.startsWith('/') ? from : '/account');
    };
    const tabs = [
        hasEmail ? { key: 'email', label: t('storefront.login.tab_email', 'Email') } : null,
        hasMobile ? { key: 'mobile', label: t('storefront.login.tab_mobile', 'Mobile') } : null,
        hasCode ? { key: 'code', label: t('storefront.login.tab_code', 'SMS code') } : null,
    ].filter(Boolean);

    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = setTimeout(() => setCooldown((c) => c - 1), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);

    useEffect(() => {
        const reason = new URLSearchParams(window.location.search).get('social');
        if (reason === 'pending') {
            nav('/register/pending', { replace: true });
            return;
        }
        if (reason === 'mfa') {
            setMfa(true);
            setPasskey(new URLSearchParams(window.location.search).get('passkey') === '1');
            return;
        }
        const messages = {
            denied: t('storefront.auth.social_denied', 'Sign-in was cancelled or the provider rejected it. Try again.'),
            email: t('storefront.auth.social_email', 'That account did not share an email address. Allow email access, or sign in with email.'),
            off: t('storefront.auth.social_off', 'That sign-in method is turned off.'),
            pending: t('storefront.auth.social_pending', 'Your account was created and is waiting for approval.'),
            disabled: t('storefront.auth.social_disabled', 'This account is disabled.'),
        };
        if (messages[reason]) {
            setError(messages[reason]);
        }
    }, []);

    const fail = (err, fallback) => {
        const status = err?.response?.status;
        if (status === 429) {
            setError(t('storefront.login.throttled', 'Too many sign-in tries. Wait a minute and try again.'));
        } else if (status === 403 && err?.response?.data?.message) {
            setError(err.response.data.message);
        } else if (status === 422 && err?.response?.data?.message) {
            setError(err.response.data.message);
        } else {
            setError(fallback);
        }
    };

    const sendLoginCode = async () => {
        setError('');
        setBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/auth/otp/send', { phone: mobile, purpose: 'login' });
            setCodeSent(true);
            setCooldown(Number(otpPolicy.resend_seconds || 60));
            setSandboxHint(data.sandbox_code ? t('storefront.login.sms_sandbox', 'Sandbox code: {code}').replace('{code}', data.sandbox_code) : '');
        } catch (err) {
            fail(err, t('storefront.login.sms_failed', 'Could not send an SMS code.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthShell variant="login">
            <form
                className="shop-auth-card"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    setBusy(true);
                    try {
                        await csrf();
                        if (mfa) {
                            const { data } = await api.post('/auth/mfa', { code });
                            store.setCustomer(data.customer);
                            afterLogin();
                            return;
                        }
                        if (sms) {
                            const { data } = await api.post('/auth/sms', { code });
                            store.setCustomer(data.customer);
                            afterLogin();
                            return;
                        }
                        if (tab === 'code') {
                            if (!codeSent) {
                                await sendLoginCode();
                                return;
                            }
                            const { data: proof } = await api.post('/auth/otp/verify', { phone: mobile, code, purpose: 'login' });
                            const { data } = await api.post('/auth/login', { phone: mobile, phone_token: proof.phone_token, remember });
                            store.setCustomer(data.customer);
                            afterLogin();
                            return;
                        }
                        const { data } = await api.post('/auth/login', {
                            email: tab === 'mobile' ? mobile : email,
                            password,
                            remember,
                        });
                        if (data.mfa_required) {
                            setMfa(true);
                            setPasskey(!!data.passkey_available);
                            return;
                        }
                        if (data.sms_required) {
                            setSms(true);
                            setSandboxHint(data.sandbox_code ? t('storefront.login.sms_sandbox', 'Sandbox code: {code}').replace('{code}', data.sandbox_code) : '');
                            return;
                        }
                        store.setCustomer(data.customer);
                        afterLogin();
                    } catch (err) {
                        fail(err, t('storefront.login.invalid', 'Invalid credentials'));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <h1>{t('storefront.login.title', 'Sign in')}</h1>
                {tabs.length > 1 && !mfa && !sms ? (
                    <div className="shop-auth-tabs" role="tablist">
                        {tabs.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="tab"
                                aria-selected={tab === item.key}
                                className={`shop-auth-tab${tab === item.key ? ' is-active' : ''}`}
                                onClick={() => { setTab(item.key); setError(''); setCodeSent(false); setCode(''); }}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
                <div className="shop-auth-stack">
                    {tab === 'email' ? (
                        <AuthField
                            label={t('storefront.login.email', 'Email')}
                            icon="mail"
                            type="email"
                            autoComplete="email"
                            placeholder={t('storefront.login.email_placeholder', 'Email address')}
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            required={!mfa && !sms}
                        />
                    ) : null}
                    {tab !== 'email' && !mfa && !sms ? (
                        <AuthField
                            label={t('storefront.login.mobile', 'Mobile number')}
                            icon="phone"
                            type="tel"
                            autoComplete="tel"
                            placeholder={t('storefront.login.mobile_placeholder', '+15551234567')}
                            value={mobile}
                            onChange={(e) => setMobile(e.target.value)}
                            required
                        />
                    ) : null}
                    {tab !== 'code' ? (
                        <AuthPasswordField
                            label={t('storefront.login.password', 'Password')}
                            autoComplete="current-password"
                            placeholder="••••••••"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required={!mfa && !sms}
                        />
                    ) : null}
                    {mfa ? (
                        <AuthField
                            label={t('storefront.login.mfa', 'Authenticator code')}
                            icon="lock"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            placeholder="123456"
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            required
                        />
                    ) : null}
                    {sms ? (
                        <AuthField
                            label={t('storefront.login.sms', 'SMS code')}
                            icon="lock"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            placeholder="123456"
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            required
                        />
                    ) : null}
                    {tab === 'code' && codeSent && !mfa && !sms ? (
                        <>
                            <AuthField
                                label={t('storefront.login.code', 'SMS code')}
                                icon="lock"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                placeholder="123456"
                                value={code}
                                onChange={(e) => setCode(e.target.value)}
                                required
                            />
                            {sandboxHint ? <p className="shop-auth-hint">{sandboxHint}</p> : null}
                            <button
                                type="button"
                                className="shop-auth-link"
                                disabled={busy || cooldown > 0}
                                onClick={sendLoginCode}
                            >
                                {cooldown > 0
                                    ? t('storefront.login.resend_in', 'Resend code in {s}s').replace('{s}', String(cooldown))
                                    : t('storefront.login.resend', 'Resend code')}
                            </button>
                        </>
                    ) : null}
                    {mfa && passkey ? (
                        <button
                            type="button"
                            className="shop-auth-link"
                            onClick={async () => {
                                setError('');
                                setBusy(true);
                                try {
                                    await csrf();
                                    const { data: options } = await api.post('/auth/passkey/options');
                                    const cred = await navigator.credentials.get({
                                        publicKey: {
                                            challenge: b64uToBuf(options.challenge),
                                            rpId: options.rpId,
                                            timeout: options.timeout,
                                            userVerification: options.userVerification,
                                            allowCredentials: (options.allowCredentials || []).map((row) => ({
                                                type: 'public-key',
                                                id: b64uToBuf(row.id),
                                            })),
                                        },
                                    });
                                    const { data } = await api.post('/auth/passkey/assert', {
                                        credential_id: bufToB64u(cred.rawId),
                                        client_data_json: bufToB64u(cred.response.clientDataJSON),
                                        authenticator_data: bufToB64u(cred.response.authenticatorData),
                                        signature: bufToB64u(cred.response.signature),
                                    });
                                    store.setCustomer(data.customer);
                                    nav('/account');
                                } catch {
                                    setError(t('storefront.login.passkey_failed', 'Passkey was rejected.'));
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            {t('storefront.login.use_passkey', 'Use a passkey instead')}
                        </button>
                    ) : null}
                    {!mfa && !sms && tab === 'email' && store.shop?.features?.sms_otp ? (
                        <button
                            type="button"
                            className="shop-auth-link"
                            onClick={async () => {
                                setError('');
                                setBusy(true);
                                try {
                                    await csrf();
                                    const { data } = await api.post('/auth/sms/send', { email, remember });
                                    setSms(true);
                                    setSandboxHint(data.sandbox_code ? t('storefront.login.sms_sandbox', 'Sandbox code: {code}').replace('{code}', data.sandbox_code) : '');
                                } catch {
                                    setError(t('storefront.login.sms_failed', 'Could not send an SMS code.'));
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            {t('storefront.login.send_sms', 'Email me an SMS code instead')}
                        </button>
                    ) : null}
                    <div className="shop-auth-row">
                        <label className="shop-auth-check">
                            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                            {t('storefront.login.remember', 'Remember me')}
                        </label>
                        <Link to="/forgot-password" className="shop-auth-link">
                            {t('storefront.login.forgot', 'Forgot password?')}
                        </Link>
                    </div>
                    <FormNotice tone="error">{error}</FormNotice>
                    <button type="submit" className="shop-auth-submit" disabled={busy}>
                        {busy
                            ? t('storefront.common.please_wait', 'Please wait…')
                            : (tab === 'code' && !codeSent
                                ? t('storefront.login.send_code', 'Send code')
                                : t('storefront.login.submit', 'Sign in'))}
                    </button>
                    {socialProviders.length ? (
                        <>
                            <div className="shop-auth-divider">{t('storefront.auth.or_continue', 'or continue with')}</div>
                            <div className={`shop-auth-social${socialProviders.length === 1 ? ' is-single' : ''}`}>
                                {socialProviders.map((provider) => (
                                    <button
                                        key={provider.key}
                                        type="button"
                                        className="shop-auth-social-btn"
                                        onClick={() => {
                                            window.location.href = webPath(`/auth/${provider.key}/redirect`);
                                        }}
                                    >
                                        <Icon name={provider.icon} className="h-4 w-4" />
                                        {provider.label}
                                    </button>
                                ))}
                            </div>
                        </>
                    ) : null}
                    {store.shop?.customers?.registration_enabled !== false ? (
                        <p className="shop-auth-foot">
                            {t('storefront.login.new_to', 'New to {shop}?').replace('{shop}', shopName())}
                            {' '}
                            <Link to="/register">{t('storefront.login.create_account', 'Create an account')}</Link>
                        </p>
                    ) : null}
                </div>
            </form>
        </AuthShell>
    );
}
