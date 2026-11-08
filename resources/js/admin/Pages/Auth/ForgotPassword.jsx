import { useForm, usePage } from '@inertiajs/react';
import { Alert, Button, Icon, ui, t, setI18nMessages } from '../../ui';
import AuthShell, { AuthField, AuthLink } from './AuthShell';

export default function ForgotPassword({ status } = {}) {
    const { flash = {}, i18n } = usePage().props;
    setI18nMessages(i18n?.messages);
    const sent = Boolean(status || flash.success);
    const form = useForm({ email: '' });

    return (
        <AuthShell>
            <form
                className={ui.loginCard}
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!sent) {
                        form.post('/admin/forgot-password');
                    }
                }}
            >
                <div>
                    <h1 className={ui.loginTitle}>{t('admin.login.forgot_title', 'Forgot Password?')}</h1>
                    <p className={ui.loginLead}>
                        {t('admin.login.forgot_body', "Enter your email address and we'll send you a link to reset your password.")}
                    </p>
                </div>
                <label className="block space-y-1.5">
                    <span className={ui.label}>{t('admin.login.email', 'Email address')}</span>
                    <AuthField
                        icon="mail"
                        type="email"
                        autoComplete="username"
                        placeholder={t('admin.login.email_placeholder', 'Enter your email address')}
                        value={form.data.email}
                        invalid={!!form.errors.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        required
                    />
                </label>
                {form.errors.email ? (
                    <p className={ui.loginError} role="alert">
                        <Icon name="alert-circle" />
                        {form.errors.email}
                    </p>
                ) : null}
                {sent ? (
                    <Alert type="ok">{t('admin.login.reset_sent', 'Check your email for the reset link.')}</Alert>
                ) : null}
                <Button variant="login" busy={form.processing} disabled={sent}>
                    {t('admin.login.send_reset', 'Send Reset Link')}
                    {!form.processing ? <Icon name="arrow-right" /> : null}
                </Button>
                <div className={ui.loginOr}>
                    <span className={ui.loginOrRule} />
                    <span className={ui.loginOrText}>{t('admin.login.or', 'or')}</span>
                </div>
                <AuthLink href="/admin/login" className={ui.loginGhost}>
                    <Icon name="arrow-left" />
                    {t('admin.login.back', 'Back to Sign in')}
                </AuthLink>
            </form>
        </AuthShell>
    );
}
