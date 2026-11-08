import { useForm, usePage } from '@inertiajs/react';
import { Button, Icon, Input, ui, t, setI18nMessages } from '../../ui';
import { adminUrl } from '../../webPath';
import AuthShell from './AuthShell';

function csrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

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

export default function MfaEnroll({ otpauth, secret, has_passkeys = false }) {
    const { i18n } = usePage().props;
    setI18nMessages(i18n?.messages);
    const form = useForm({ code: '' });

    const registerPasskey = async () => {
        const optionsRes = await fetch('/admin/mfa/passkey/register-options', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            credentials: 'same-origin',
        });
        const options = await optionsRes.json();
        const cred = await navigator.credentials.create({
            publicKey: {
                rp: options.rp,
                user: {
                    ...options.user,
                    id: b64uToBuf(options.user.id),
                },
                challenge: b64uToBuf(options.challenge),
                pubKeyCredParams: options.pubKeyCredParams,
                timeout: options.timeout,
                attestation: options.attestation,
                authenticatorSelection: options.authenticatorSelection,
                excludeCredentials: (options.excludeCredentials || []).map((row) => ({
                    type: 'public-key',
                    id: b64uToBuf(row.id),
                })),
            },
        });
        const att = cred.response;
        const res = await fetch('/admin/mfa/passkey/register', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                credential_id: bufToB64u(cred.rawId),
                client_data_json: bufToB64u(att.clientDataJSON),
                public_key: bufToB64u(att.getPublicKey()),
                authenticator_data: bufToB64u(att.getAuthenticatorData()),
                name: 'Admin passkey',
            }),
        });
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data?.message || data?.passkey?.[0] || 'Could not save passkey.');
        }
        window.location.href = adminUrl('/admin/dashboards');
    };

    return (
        <AuthShell>
            <form
                className={ui.loginCard}
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/mfa/enroll');
                }}
            >
                <h1 className={ui.loginTitle}>{t('admin.mfa.enroll', 'Set up authenticator')}</h1>
                <p className={ui.loginMuted}>
                    {t('admin.mfa.enroll_hint', 'Add this shop to your authenticator app, then enter a code to confirm.')}
                </p>
                <p className="break-all font-mono text-xs text-neutral-600">{secret}</p>
                <p className="break-all text-[11px] text-neutral-500">{otpauth}</p>
                <Input
                    unstyled
                    className={ui.loginInput}
                    placeholder="000000"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value)}
                />
                {form.errors.code && (
                    <p className={ui.loginError} role="alert">
                        <Icon name="alert-circle" />
                        {form.errors.code}
                    </p>
                )}
                <Button variant="login" icon="check" busy={form.processing}>
                    {t('admin.mfa.confirm', 'Confirm and continue')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    onClick={async () => {
                        try {
                            await registerPasskey();
                        } catch (err) {
                            form.setError('code', err.message || t('admin.mfa.passkey_failed', 'Passkey failed.'));
                        }
                    }}
                >
                    {has_passkeys
                        ? t('admin.mfa.add_another_passkey', 'Add another passkey')
                        : t('admin.mfa.add_passkey', 'Use a hardware passkey instead')}
                </Button>
            </form>
        </AuthShell>
    );
}
