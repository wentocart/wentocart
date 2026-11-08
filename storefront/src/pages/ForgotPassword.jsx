import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import AuthShell, { AuthField } from '../components/AuthShell.jsx';

export default function ForgotPassword() {
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    return (
        <AuthShell variant="forgot">
            <form
                className="shop-auth-card"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    setBusy(true);
                    try {
                        await csrf();
                        await api.post('/auth/forgot', { email });
                        setSent(true);
                    } catch {
                        setSent(true);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <h1>{t('storefront.login.forgot_title', 'Forgot password?')}</h1>
                <p className="shop-auth-card-lead">
                    {t('storefront.login.forgot_body', "Enter your email address and we'll send you a link to reset your password.")}
                </p>
                <div className="shop-auth-stack">
                    <AuthField
                        label={t('storefront.login.email_address', 'Email address')}
                        icon="mail"
                        type="email"
                        autoComplete="email"
                        placeholder={t('storefront.login.email_placeholder', 'Enter your email address')}
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />
                    <FormNotice tone="error">{error}</FormNotice>
                    <FormNotice tone="ok">{sent ? t('storefront.login.reset_sent', 'Check your email for the reset link.') : null}</FormNotice>
                    <button type="submit" className="shop-auth-submit" disabled={busy || sent}>
                        {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.login.send_reset', 'Send reset link')}
                        {!busy ? (
                            <span className="shop-auth-submit-icon">
                                <Icon name="send" className="h-3.5 w-3.5" />
                            </span>
                        ) : null}
                    </button>
                    <div className="shop-auth-divider">{t('storefront.auth.or', 'or')}</div>
                    <Link to="/login" className="shop-auth-ghost">
                        <Icon name="arrowLeft" className="h-4 w-4" />
                        {t('storefront.login.back', 'Back to sign in')}
                    </Link>
                </div>
            </form>
        </AuthShell>
    );
}
