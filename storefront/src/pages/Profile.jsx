import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { webPath } from '../webPath.js';
import { useStore } from '../state/StoreState.jsx';
import { FormField } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import PhoneField from '../components/PhoneField.jsx';

export default function Profile() {
    const store = useStore();
    const customer = store.customer || {};
    const settings = store.shop?.customers || {};
    const allowEdit = settings.allow_profile_editing !== false;
    const phoneMode = settings.phone_mode || 'optional';
    const dobMode = settings.dob_mode || 'hidden';
    const newsletterOptIn = settings.newsletter_opt_in !== false;
    const allowDelete = !!settings.allow_account_deletion && !store.shop?.commerce?.gdpr?.enabled;
    const gdprOn = !!store.shop?.commerce?.gdpr?.enabled;
    const [form, setForm] = useState({
        name: customer.name || '',
        first_name: customer.first_name || '',
        last_name: customer.last_name || '',
        email: customer.email || '',
        phone: customer.phone || '',
        date_of_birth: customer.date_of_birth || '',
        gender: customer.gender || '',
        newsletter: !!customer.newsletter,
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const [deletePassword, setDeletePassword] = useState('');
    const [deleteError, setDeleteError] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [exportError, setExportError] = useState('');
    const [exporting, setExporting] = useState(false);
    const [show, setShow] = useState({ current: false, next: false, confirm: false });
    const [phoneToken, setPhoneToken] = useState('');
    const [phoneVerifiedFor, setPhoneVerifiedFor] = useState('');
    const [otpCode, setOtpCode] = useState('');
    const [otpSent, setOtpSent] = useState(false);
    const [otpBusy, setOtpBusy] = useState(false);
    const [otpCooldown, setOtpCooldown] = useState(0);
    const [otpHint, setOtpHint] = useState('');

    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const mobileLoginOn = (settings.login_methods || []).some((m) => m !== 'email_password');
    const otpResendSeconds = Number(settings.otp?.resend_seconds || 60);
    const phoneChanged = (form.phone || '') !== (customer.phone || '');
    const phoneVerifiedOk = !!phoneToken && phoneVerifiedFor === form.phone;

    useEffect(() => {
        if (otpCooldown <= 0) return;
        const timer = setTimeout(() => setOtpCooldown((c) => c - 1), 1000);
        return () => clearTimeout(timer);
    }, [otpCooldown]);

    const sendProfileCode = async () => {
        setError('');
        setOtpBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/auth/otp/send', { phone: form.phone, purpose: 'profile' });
            setOtpSent(true);
            setOtpCooldown(otpResendSeconds);
            setOtpHint(data.sandbox_code ? `Sandbox code: ${data.sandbox_code}` : '');
        } catch (err) {
            setError(err.response?.data?.errors?.phone?.[0] || err.response?.data?.message || t('storefront.login.sms_failed', 'Could not send an SMS code.'));
        } finally {
            setOtpBusy(false);
        }
    };

    const verifyProfileCode = async () => {
        setError('');
        setOtpBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/auth/otp/verify', { phone: form.phone, code: otpCode, purpose: 'profile' });
            setPhoneToken(data.phone_token || '');
            setPhoneVerifiedFor(form.phone);
            setOtpHint('');
        } catch (err) {
            setError(err.response?.data?.errors?.code?.[0] || err.response?.data?.message || t('storefront.login.invalid_code', 'Invalid or expired code.'));
        } finally {
            setOtpBusy(false);
        }
    };

    useEffect(() => {
        if (window.location.hash !== '#delete-account') return;
        document.getElementById('delete-account')?.scrollIntoView({ block: 'start' });
    }, [allowDelete]);

    const save = async (e) => {
        e.preventDefault();
        if (!allowEdit) return;
        setError('');
        setSaved(false);
        setBusy(true);
        try {
            await csrf();
            const payload = {
                ...form,
                name: [form.first_name, form.last_name].filter(Boolean).join(' ') || customer.name || customer.email,
            };
            if (!payload.password) {
                delete payload.password;
                delete payload.password_confirmation;
                delete payload.current_password;
            }
            if (phoneMode === 'hidden' && !mobileLoginOn) delete payload.phone;
            if (dobMode === 'hidden') delete payload.date_of_birth;
            if (mobileLoginOn && phoneChanged && !phoneVerifiedOk) {
                setError(t('storefront.account.verify_phone_first', 'Verify your new mobile number with the SMS code first.'));
                setBusy(false);
                return;
            }
            if (phoneToken) payload.phone_token = phoneToken;
            if (!newsletterOptIn) delete payload.newsletter;
            const { data } = await api.patch('/account', payload);
            store.setCustomer({ ...store.customer, ...data.customer });
            setPhoneToken('');
            setPhoneVerifiedFor('');
            setOtpSent(false);
            setOtpCode('');
            setSaved(true);
            setForm((current) => ({ ...current, current_password: '', password: '', password_confirmation: '' }));
            store.ping?.(t('storefront.account.saved', 'Saved.'));
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.account.save_failed', 'Could not save your profile.'));
        } finally {
            setBusy(false);
        }
    };

    const exportData = async () => {
        setExportError('');
        setExporting(true);
        try {
            const { data } = await api.get('/account/export');
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'my-store-data.json';
            link.click();
            URL.revokeObjectURL(url);
            store.ping?.(t('storefront.account.export_ready', 'Download started.'));
        } catch (err) {
            setExportError(err.response?.data?.message || t('storefront.account.export_failed', 'Could not export your data.'));
        } finally {
            setExporting(false);
        }
    };

    const deleteAccount = async (e) => {
        e?.preventDefault?.();
        if (!allowDelete || deleting) return;
        if (!deletePassword) {
            setDeleteError(t('storefront.account.delete_password_required', 'Enter your password.'));
            return;
        }
        if (!window.confirm(t('storefront.account.delete_confirm', 'Permanently delete your account? This cannot be undone.'))) {
            return;
        }
        setDeleteError('');
        setDeleting(true);
        try {
            await csrf();
            await api.delete('/account', { data: { password: deletePassword } });
            store.setCustomer(null);
            window.location.assign(webPath('/'));
        } catch (err) {
            const data = err.response?.data;
            setDeleteError(
                data?.errors?.password?.[0]
                || data?.message
                || t('storefront.account.delete_failed', 'Could not delete your account.'),
            );
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="shop-profile">
        <form onSubmit={save} style={{ display: 'contents' }}>
            <header className="shop-profile-head">
                <h1>{t('storefront.account.profile', 'Profile')}</h1>
                <p>{t('storefront.account.profile_body', 'Manage your personal details and account settings.')}</p>
            </header>

            {!allowEdit ? (
                <p className="shop-profile-error">{t('storefront.account.profile_locked', 'Profile editing is disabled for this store.')}</p>
            ) : null}

            <section className="shop-profile-card">
                <h2 className="shop-profile-card-title">
                    <Icon name="user" className="h-4 w-4" />
                    {t('storefront.account.section_profile', 'Profile information')}
                </h2>

                <div className="shop-profile-fields">
                    <FormField label={t('storefront.account.gender', 'Gender')}>
                        <div className="shop-profile-gender-options">
                            {[
                                ['', t('storefront.account.prefer_not', 'Prefer not to say')],
                                ['female', t('storefront.account.female', 'Female')],
                                ['male', t('storefront.account.male', 'Male')],
                                ['other', t('storefront.account.other', 'Other')],
                            ].map(([value, label]) => (
                                <label key={value || 'none'} className="shop-profile-gender-option">
                                    <input type="radio" name="profile-gender" value={value} checked={form.gender === value} onChange={(e) => set('gender', e.target.value)} disabled={!allowEdit} />
                                    <span>{label}</span>
                                </label>
                            ))}
                        </div>
                    </FormField>
                    <div className="shop-profile-row-2">
                        <FormField label={t('storefront.account.first_name', 'First name')} required>
                            <input className="shop-input" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} required disabled={!allowEdit} />
                        </FormField>
                        <FormField label={t('storefront.account.last_name', 'Last name')} required>
                            <input className="shop-input" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} required disabled={!allowEdit} />
                        </FormField>
                    </div>

                    <FormField label={t('storefront.account.email_address', 'Email address')} required>
                        <input className="shop-input" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} required disabled={!allowEdit} />
                    </FormField>

                    <div className="shop-profile-row-2">
                        {phoneMode !== 'hidden' || mobileLoginOn ? (
                            <FormField label={t('storefront.account.phone', 'Phone number')}>
                                <PhoneField
                                    countries={store.shop?.countries || []}
                                    value={form.phone}
                                    onChange={(value) => {
                                        if (value !== phoneVerifiedFor) {
                                            setPhoneToken('');
                                            setPhoneVerifiedFor('');
                                            setOtpSent(false);
                                            setOtpCode('');
                                        }
                                        set('phone', value);
                                    }}
                                    required={phoneMode === 'required'}
                                    disabled={!allowEdit}
                                />
                                {mobileLoginOn && phoneChanged && allowEdit ? (
                                    <div className="shop-auth-otp">
                                        {phoneVerifiedOk ? (
                                            <p className="shop-auth-hint">{t('storefront.account.phone_verified', 'New number verified. Save to keep it.')}</p>
                                        ) : otpSent ? (
                                            <>
                                                <div className="shop-auth-otp-row">
                                                    <input
                                                        className="shop-input"
                                                        inputMode="numeric"
                                                        autoComplete="one-time-code"
                                                        placeholder="123456"
                                                        value={otpCode}
                                                        onChange={(e) => setOtpCode(e.target.value)}
                                                    />
                                                    <button type="button" className="shop-auth-btn" disabled={otpBusy || !otpCode} onClick={verifyProfileCode}>
                                                        {t('storefront.register.verify', 'Verify')}
                                                    </button>
                                                </div>
                                                {otpHint ? <p className="shop-auth-hint">{otpHint}</p> : null}
                                                <button type="button" className="shop-auth-link" disabled={otpBusy || otpCooldown > 0} onClick={sendProfileCode}>
                                                    {otpCooldown > 0
                                                        ? t('storefront.login.resend_in', 'Resend code in {s}s').replace('{s}', String(otpCooldown))
                                                        : t('storefront.login.resend', 'Resend code')}
                                                </button>
                                            </>
                                        ) : (
                                            <button type="button" className="shop-auth-btn" disabled={otpBusy || !form.phone} onClick={sendProfileCode}>
                                                {t('storefront.register.send_code', 'Text me a code')}
                                            </button>
                                        )}
                                    </div>
                                ) : null}
                            </FormField>
                        ) : null}
                        {dobMode !== 'hidden' ? (
                            <FormField label={t('storefront.account.dob', 'Date of birth')}>
                                <input
                                    type="date"
                                    className="shop-input"
                                    value={form.date_of_birth || ''}
                                    onChange={(e) => set('date_of_birth', e.target.value)}
                                    required={dobMode === 'required'}
                                    disabled={!allowEdit}
                                />
                            </FormField>
                        ) : null}
                    </div>

                    {newsletterOptIn ? (
                        <label className="shop-profile-check">
                            <input
                                type="checkbox"
                                checked={form.newsletter}
                                onChange={(e) => set('newsletter', e.target.checked)}
                                disabled={!allowEdit}
                            />
                            <span>{t('storefront.account.newsletter', 'Email me about orders and offers')}</span>
                        </label>
                    ) : null}
                </div>
            </section>

            {allowEdit ? (
                <section className="shop-profile-card">
                    <h2 className="shop-profile-card-title">
                        <Icon name="lock" className="h-4 w-4" />
                        {t('storefront.account.password', 'Change password')}
                    </h2>
                    <p className="shop-profile-card-lead">
                        {t('storefront.account.password_help', "Leave these fields blank if you don't want to change your password.")}
                    </p>

                    <div className="shop-profile-fields">
                        <FormField label={t('storefront.account.current_password', 'Current password')}>
                            <PasswordInput
                                value={form.current_password}
                                onChange={(v) => set('current_password', v)}
                                visible={show.current}
                                onToggle={() => setShow((s) => ({ ...s, current: !s.current }))}
                                autoComplete="current-password"
                            />
                        </FormField>

                        <div className="shop-profile-row-2">
                            <FormField label={t('storefront.account.new_password', 'New password')}>
                                <PasswordInput
                                    value={form.password}
                                    onChange={(v) => set('password', v)}
                                    visible={show.next}
                                    onToggle={() => setShow((s) => ({ ...s, next: !s.next }))}
                                    autoComplete="new-password"
                                />
                            </FormField>
                            <FormField label={t('storefront.account.confirm_new_password', 'Confirm new password')}>
                                <PasswordInput
                                    value={form.password_confirmation}
                                    onChange={(v) => set('password_confirmation', v)}
                                    visible={show.confirm}
                                    onToggle={() => setShow((s) => ({ ...s, confirm: !s.confirm }))}
                                    autoComplete="new-password"
                                />
                            </FormField>
                        </div>
                    </div>
                </section>
            ) : null}

            {error ? <p className="shop-profile-error">{error}</p> : null}
            {saved ? <p className="shop-profile-ok">{t('storefront.account.saved', 'Saved.')}</p> : null}

            {allowEdit ? (
                <button type="submit" className="shop-profile-save" disabled={busy}>
                    {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.account.save', 'Save changes')}
                </button>
            ) : null}
        </form>

            {gdprOn ? (
                <section className="shop-profile-card">
                    <h2 className="shop-profile-card-title">{t('storefront.gdpr.requests', 'GDPR Requests')}</h2>
                    <p className="shop-profile-card-lead">
                        {t('storefront.gdpr.profile_help', 'Ask for your data to be updated or deleted. HTML and PDF on each request download a copy.')}
                    </p>
                    <Link className="shop-profile-save" to="/account/gdpr">{t('storefront.gdpr.open', 'Open GDPR Requests')}</Link>
                </section>
            ) : (
            <section className="shop-profile-card">
                <h2 className="shop-profile-card-title">
                    {t('storefront.account.export_title', 'Download my data')}
                </h2>
                <p className="shop-profile-card-lead">
                    {t('storefront.account.export_help', 'Download a copy of your profile, addresses, and orders.')}
                </p>
                {exportError ? <p className="shop-profile-error">{exportError}</p> : null}
                <button type="button" className="shop-profile-save" disabled={exporting} onClick={exportData}>
                    {exporting ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.account.export_submit', 'Download JSON')}
                </button>
            </section>
            )}

            {allowDelete ? (
                <form onSubmit={deleteAccount} style={{ display: 'contents' }}>
                <section id="delete-account" className="shop-profile-card">
                    <h2 className="shop-profile-card-title">
                        {t('storefront.account.delete_title', 'Delete account')}
                    </h2>
                    <p className="shop-profile-card-lead">
                        {t('storefront.account.delete_help', 'Enter your password to permanently delete your account.')}
                    </p>
                    <FormField label={t('storefront.account.password', 'Password')}>
                        <input
                            className="shop-input"
                            type="password"
                            value={deletePassword}
                            onChange={(e) => setDeletePassword(e.target.value)}
                            autoComplete="current-password"
                            required
                        />
                    </FormField>
                    {deleteError ? <p className="shop-profile-error">{deleteError}</p> : null}
                    <button type="submit" className="shop-profile-save" disabled={deleting}>
                        {deleting ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.account.delete_submit', 'Delete my account')}
                    </button>
                </section>
                </form>
            ) : null}
        </div>
    );
}

function PasswordInput({ value, onChange, visible, onToggle, autoComplete }) {
    return (
        <div className="shop-profile-password">
            <input
                className="shop-input"
                type={visible ? 'text' : 'password'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                autoComplete={autoComplete}
            />
            <button
                type="button"
                className="shop-profile-password-toggle"
                onClick={onToggle}
                aria-label={visible
                    ? t('storefront.login.hide_password', 'Hide password')
                    : t('storefront.login.show_password', 'Show password')}
            >
                <Icon name={visible ? 'eyeOff' : 'eye'} className="h-4 w-4" />
            </button>
        </div>
    );
}
