import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Alert, Button, Checkbox, Icon, ui, t, setI18nMessages } from '../../ui';
import AuthShell, { AuthField, AuthLink } from './AuthShell';

export default function Login() {
    const { flash = {}, i18n } = usePage().props;
    setI18nMessages(i18n?.messages);
    const form = useForm({ email: '', password: '', remember: false });
    const [showPassword, setShowPassword] = useState(false);

    useEffect(() => {
        let active = true;

        fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        }).then((response) => {
            if (!active || !response.redirected) return;

            const target = new URL(response.url);
            if (target.pathname !== window.location.pathname) {
                window.location.replace(response.url);
            }
        }).catch(() => {});

        return () => {
            active = false;
        };
    }, []);

    return (
        <AuthShell>
            <form
                className={ui.loginCard}
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/login');
                }}
            >
                <div>
                    <h1 className={ui.loginTitle}>{t('admin.login.title', 'Sign in')}</h1>
                    <p className={ui.loginLead}>{t('admin.login.subtitle', 'Access your store administration')}</p>
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
                    />
                </label>
                <label className="block space-y-1.5">
                    <span className={ui.label}>{t('admin.login.password', 'Password')}</span>
                    <AuthField
                        icon="lock"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="current-password"
                        placeholder={t('admin.login.password_placeholder', 'Enter your password')}
                        value={form.data.password}
                        invalid={!!form.errors.email || !!form.errors.password}
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
                <div className={ui.loginRow}>
                    <Checkbox
                        label={t('admin.login.remember', 'Remember me')}
                        checked={form.data.remember}
                        onChange={(e) => form.setData('remember', e.target.checked)}
                    />
                    <AuthLink href="/admin/forgot-password" className={ui.loginForgot}>
                        {t('admin.login.forgot', 'Forgot password?')}
                    </AuthLink>
                </div>
                {(form.errors.email || form.errors.password) && (
                    <p className={ui.loginError} role="alert">
                        <Icon name="alert-circle" />
                        {form.errors.email || form.errors.password}
                    </p>
                )}
                {flash.success ? <Alert type="ok">{flash.success}</Alert> : null}
                <Button variant="login" busy={form.processing}>
                    {t('admin.login.submit', 'Sign in')}
                    {!form.processing ? <Icon name="arrow-right" /> : null}
                </Button>
            </form>
        </AuthShell>
    );
}
