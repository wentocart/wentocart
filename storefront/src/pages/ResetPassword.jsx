import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import AuthShell, { AuthField, AuthPasswordField } from '../components/AuthShell.jsx';

export default function ResetPassword() {
    const [params] = useSearchParams();
    const [form, setForm] = useState({
        email: params.get('email') || '',
        token: params.get('token') || '',
        password: '',
        password_confirmation: '',
    });
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    return (
        <AuthShell variant="reset">
            <form
                className="shop-auth-card"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    setBusy(true);
                    try {
                        await csrf();
                        await api.post('/auth/reset', form);
                        setDone(true);
                    } catch (err) {
                        setError(err.response?.data?.message || t('storefront.login.reset_failed', 'This reset link is invalid or expired.'));
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <h1>{t('storefront.login.reset_title', 'Choose a new password')}</h1>
                <p className="shop-auth-card-lead">
                    {t('storefront.login.reset_body', 'Enter a new password for your account.')}
                </p>
                <div className="shop-auth-stack">
                    <AuthField
                        label={t('storefront.login.email', 'Email')}
                        icon="mail"
                        type="email"
                        autoComplete="email"
                        value={form.email}
                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                        required
                    />
                    <AuthPasswordField
                        label={t('storefront.register.password', 'Password')}
                        autoComplete="new-password"
                        value={form.password}
                        onChange={(e) => setForm({ ...form, password: e.target.value })}
                        required
                    />
                    <AuthPasswordField
                        label={t('storefront.register.confirm', 'Confirm password')}
                        autoComplete="new-password"
                        value={form.password_confirmation}
                        onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                        required
                    />
                    <FormNotice tone="error">{error}</FormNotice>
                    {done ? (
                        <FormNotice tone="ok">{t('storefront.login.reset_ok', 'Password updated. You can sign in now.')}</FormNotice>
                    ) : (
                        <button type="submit" className="shop-auth-submit" disabled={busy}>
                            {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.login.reset_submit', 'Update password')}
                        </button>
                    )}
                    <Link to="/login" className="shop-auth-ghost">
                        <Icon name="arrowLeft" className="h-4 w-4" />
                        {t('storefront.login.back', 'Back to sign in')}
                    </Link>
                </div>
            </form>
        </AuthShell>
    );
}
