import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Button, Icon, ui, t, setI18nMessages } from '../../ui';
import AuthShell, { AuthField, AuthLink } from './AuthShell';

export default function ResetPassword({ email = '', token = '' }) {
    const { i18n } = usePage().props;
    setI18nMessages(i18n?.messages);
    const form = useForm({
        email,
        token,
        password: '',
        password_confirmation: '',
    });
    const [showPassword, setShowPassword] = useState(false);

    return (
        <AuthShell>
            <form
                className={ui.loginCard}
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/reset-password');
                }}
            >
                <div>
                    <h1 className={ui.loginTitle}>{t('admin.login.reset_title', 'Set a new password')}</h1>
                    <p className={ui.loginLead}>
                        {t('admin.login.reset_body', 'Choose a new password for your admin account.')}
                    </p>
                </div>
                <label className="block space-y-1.5">
                    <span className={ui.label}>{t('admin.login.email', 'Email address')}</span>
                    <AuthField
                        icon="mail"
                        type="email"
                        autoComplete="username"
                        value={form.data.email}
                        invalid={!!form.errors.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </label>
                <label className="block space-y-1.5">
                    <span className={ui.label}>{t('admin.login.password', 'Password')}</span>
                    <AuthField
                        icon="lock"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={form.data.password}
                        invalid={!!form.errors.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        trailing={(
                            <button
                                type="button"
                                className={ui.loginFieldToggle}
                                onClick={() => setShowPassword((open) => !open)}
                                aria-label={showPassword
                                    ? t('admin.login.hide_password', 'Hide password')
                                    : t('admin.login.show_password', 'Show password')}
                            >
                                <Icon name={showPassword ? 'eye-off' : 'eye'} />
                            </button>
                        )}
                    />
                </label>
                <label className="block space-y-1.5">
                    <span className={ui.label}>{t('admin.login.password_confirm', 'Confirm password')}</span>
                    <AuthField
                        icon="lock"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        invalid={!!form.errors.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </label>
                {(form.errors.email || form.errors.password || form.errors.token) && (
                    <p className={ui.loginError} role="alert">
                        <Icon name="alert-circle" />
                        {form.errors.email || form.errors.password || form.errors.token}
                    </p>
                )}
                <Button variant="login" busy={form.processing}>
                    {t('admin.login.reset_submit', 'Save new password')}
                    {!form.processing ? <Icon name="arrow-right" /> : null}
                </Button>
                <AuthLink href="/admin/login" className={ui.loginGhost}>
                    <Icon name="arrow-left" />
                    {t('admin.login.back', 'Back to Sign in')}
                </AuthLink>
            </form>
        </AuthShell>
    );
}
