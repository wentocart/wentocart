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

async function assertPasskey() {
    const optionsRes = await fetch('/admin/mfa/passkey/options', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });
    const options = await optionsRes.json();
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
    const res = await fetch('/admin/mfa/passkey/assert', {
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
            client_data_json: bufToB64u(cred.response.clientDataJSON),
            authenticator_data: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature),
        }),
    });
    const data = await res.json();
    if (!res.ok) {
        throw new Error(data?.message || data?.passkey?.[0] || 'Passkey was rejected.');
    }
    window.location.href = adminUrl(data.redirect || '/admin/dashboards');
}

export default function MfaChallenge({ has_passkeys = false, has_totp = true }) {
    const { i18n } = usePage().props;
    setI18nMessages(i18n?.messages);
    const form = useForm({ code: '' });
    const showTotp = has_totp !== false;

    return (
        <AuthShell>
            <form
                className={ui.loginCard}
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/mfa/challenge');
                }}
            >
                <h1 className={ui.loginTitle}>{t('admin.mfa.title', 'Confirm it is you')}</h1>
                {showTotp ? (
                    <>
                        <p className={ui.loginMuted}>{t('admin.mfa.hint', 'Enter the 6-digit code from your authenticator app.')}</p>
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
                            {t('admin.mfa.verify', 'Verify')}
                        </Button>
                    </>
                ) : (
                    <p className={ui.loginMuted}>{t('admin.mfa.passkey_only', 'Use a passkey to continue.')}</p>
                )}
                {has_passkeys ? (
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={async () => {
                            try {
                                await assertPasskey();
                            } catch (err) {
                                form.setError('code', err.message || t('admin.mfa.passkey_failed', 'Passkey failed.'));
                            }
                        }}
                    >
                        {t('admin.mfa.use_passkey', 'Use a passkey')}
                    </Button>
                ) : null}
            </form>
        </AuthShell>
    );
}
