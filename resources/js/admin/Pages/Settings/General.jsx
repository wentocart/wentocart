import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Layout from '../Layout';
import JumpShortcutCapture from '../../ui/JumpShortcutCapture';
import {
    Button,
    Card,
    Field,
    FieldError,
    FeatureTile,
    FormErrors,
    useGrowl,
    Icon,
    Input,
    PageHeader,
    RequiredMark,
    Select,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    ui,
    t,
    cx,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.general_configuration', 'General') },
];

function SearchShortcutCard({ searchShortcut }) {
    const form = useForm({
        shortcut: searchShortcut?.shortcut || '',
        reset: false,
    });

    return (
        <Card className="space-y-3">
            <SettingsSectionHead
                icon="search"
                title={t('admin.search_shortcut.title', 'Search shortcut')}
                hint={t('admin.search_shortcut.hint', 'Opens the search bar from any admin page. Pick a modifier and a key, then save. It works on the next page right away.')}
            />
            <Field label={t('admin.search_shortcut.label', 'Shortcut')} error={form.errors.shortcut}>
                <JumpShortcutCapture
                    value={form.data.reset ? '' : form.data.shortcut}
                    fallback="ctrl+f"
                    effectiveLabel={searchShortcut?.effective?.label || searchShortcut?.label}
                    effectiveSource={form.data.shortcut && !form.data.reset ? 'shop' : 'default'}
                    hint={t('admin.search_shortcut.capture_hint', 'Choose Ctrl, Alt, Window, or Shift, then a letter or number.')}
                    clearLabel={t('admin.search_shortcut.reset', 'Reset to Ctrl+F')}
                    onChange={(chord) => {
                        form.setData({ shortcut: chord, reset: false });
                    }}
                    onClear={() => {
                        form.setData({ shortcut: '', reset: true });
                    }}
                />
            </Field>
            <div className="flex justify-end">
                <Button
                    type="button"
                    icon="check"
                    busy={form.processing}
                    onClick={() => form.put('/admin/store/search-shortcut')}
                >
                    {t('admin.common.save', 'Save')}
                </Button>
            </div>
        </Card>
    );
}

export default function General() {
    const { shop, adminAddress, sessions, errors, searchShortcut } = usePage().props;
    const [smsWebhook, setSmsWebhook] = useState(shop?.community?.sms_webhook || '');
    const [smsDriver, setSmsDriver] = useState(shop?.community?.sms_driver || 'log');
    const [twilioSid, setTwilioSid] = useState(shop?.community?.sms_twilio_sid || '');
    const [twilioFrom, setTwilioFrom] = useState(shop?.community?.sms_twilio_from || '');
    const [twilioToken, setTwilioToken] = useState('');
    const [otpExpiry, setOtpExpiry] = useState(String(shop?.community?.otp_expiry_minutes ?? 5));
    const [otpAttempts, setOtpAttempts] = useState(String(shop?.community?.otp_max_attempts ?? 5));
    const [otpResend, setOtpResend] = useState(String(shop?.community?.otp_resend_seconds ?? 60));
    const growl = useGrowl();
    const [segment, setSegment] = useState(adminAddress?.segment || 'admin');
    const addressForm = useForm({ segment: adminAddress?.segment || 'admin' });
    const [adminMinutes, setAdminMinutes] = useState(String(sessions?.admin_minutes || 120));
    const [customerMinutes, setCustomerMinutes] = useState(String(sessions?.customer_minutes || 480));
    const [addressOpen, setAddressOpen] = useState(true);
    const origin = String(adminAddress?.origin || '').replace(/\/$/, '');
    const preview = useMemo(() => {
        const name = String(segment || '').trim() || 'admin';

        return `${origin}/${name}/login`;
    }, [segment, origin]);
    const setMultishop = (enabled) => {
        router.post('/admin/store/multishop', { enabled: enabled ? 1 : 0 }, { preserveScroll: true });
    };

    const setMultivendor = (enabled) => {
        router.post('/admin/store/multivendor', { enabled: enabled ? 1 : 0 }, { preserveScroll: true });
    };

    const communityFailed = (errs) => {
        const message = errs?.enabled || errs?.key || errs?.value;
        if (message) {
            growl?.push({ type: 'error', message: Array.isArray(message) ? message[0] : message });
        }
    };

    const setCommunity = (key, enabled) => {
        router.post('/admin/store/community', { key, enabled: enabled ? 1 : 0 }, {
            preserveScroll: true,
            onError: communityFailed,
        });
    };

    const setCommunityValue = (key, value) => {
        router.post('/admin/store/community', { key, value: String(value ?? '') }, {
            preserveScroll: true,
            onError: communityFailed,
        });
    };

    const mobileLoginOn = !!shop?.community?.login_mobile_password || !!shop?.community?.login_mobile_otp;
    const smsPanelOn = !!shop?.community?.sms_otp || mobileLoginOn;

    const features = [
        {
            icon: 'store',
            tone: 'bg-violet-100 text-violet-600',
            title: t('admin.store.enable_multishop', 'Enable Multishop'),
            hint: shop?.multishop && !shop?.can_disable
                ? t('admin.store.cannot_disable', 'Turn off extra shops before disabling multishop.')
                : t('admin.store.enable_hint', 'Stay on one shop until you need another URL, catalog, or brand.'),
            value: !!shop?.multishop,
            disabled: shop?.multishop && !shop?.can_disable,
            onChange: setMultishop,
            extra: shop?.multishop ? (
                <Button type="button" variant="link" href="/admin/shops" className="mt-1 px-0">
                    {t('admin.store.manage_shops', 'Manage Multishop')}
                </Button>
            ) : null,
        },
        {
            icon: 'shield',
            tone: 'bg-emerald-100 text-emerald-600',
            title: t('admin.store.enable_multivendor', 'Enable Multivendor'),
            hint: t('admin.store.multivendor_hint', 'Enable marketplace features, seller management, seller portal, seller catalog and multi-vendor commerce functionality.'),
            value: !!shop?.multivendor,
            onChange: setMultivendor,
            extra: shop?.multivendor ? (
                <Button type="button" variant="link" href="/admin/multivendor/dashboard" className="mt-1 px-0">
                    {t('admin.store.manage_multivendor', 'Manage Multivendor')}
                </Button>
            ) : null,
        },
        {
            icon: 'file',
            tone: 'bg-sky-100 text-sky-600',
            title: t('admin.store.contact_form', 'Shop contact form'),
            hint: t('admin.store.contact_form_hint', 'Allow shoppers to send a message to the webmaster or support team.'),
            value: shop?.community?.contact_form !== false,
            onChange: (on) => setCommunity('contact_form', on),
            extra: (
                <Button type="button" variant="link" href="/admin/support" className="mt-1 px-0">
                    {t('admin.store.view_contact_messages', 'View Messages')}
                </Button>
            ),
        },
        {
            icon: 'code',
            tone: 'bg-indigo-100 text-indigo-600',
            title: t('admin.store.sms_otp', 'SMS sign-in codes'),
            hint: t('admin.store.sms_otp_hint', 'After password, send a one-time code to the customer phone. Codes are logged until you set a webhook.'),
            value: !!shop?.community?.sms_otp,
            onChange: (on) => setCommunity('sms_otp', on),
        },
        {
            icon: 'mail',
            tone: 'bg-emerald-100 text-emerald-600',
            title: t('admin.store.login_email_password', 'Email + password sign-in'),
            hint: t('admin.store.login_email_password_hint', 'Classic sign-in. Keep at least one sign-in method on.'),
            value: shop?.community?.login_email_password !== false,
            error: errors?.enabled,
            onChange: (on) => setCommunity('login_email_password', on),
        },
        {
            icon: 'smartphone',
            tone: 'bg-amber-100 text-amber-600',
            title: t('admin.store.login_mobile_password', 'Mobile + password sign-in'),
            hint: t('admin.store.login_mobile_password_hint', 'Shoppers sign in with their verified mobile number and password.'),
            value: !!shop?.community?.login_mobile_password,
            onChange: (on) => setCommunity('login_mobile_password', on),
        },
        {
            icon: 'message',
            tone: 'bg-rose-100 text-rose-600',
            title: t('admin.store.login_mobile_otp', 'Mobile + code sign-in'),
            hint: t('admin.store.login_mobile_otp_hint', 'Passwordless sign-in with a texted code. Needs an SMS provider below.'),
            value: !!shop?.community?.login_mobile_otp,
            onChange: (on) => setCommunity('login_mobile_otp', on),
        },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.general.title', 'General')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.general.subtitle', 'Turn store features on or off. Related menus stay hidden until a feature is enabled.')}
            />

            <div className="space-y-4">
                <SearchShortcutCard searchShortcut={searchShortcut} />

                <Card
                    as="form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        addressForm.clearErrors();
                        const next = String(segment || '').trim();
                        if (!next) {
                            addressForm.setError({ segment: t('admin.general.admin_address_required', 'The address name is required.') });
                            return;
                        }
                        addressForm.setData('segment', next);
                        addressForm.post('/admin/store/admin-path', {
                            preserveScroll: true,
                            onSuccess: (page) => {
                                const nextUrl = page.props?.flash?.admin_path_next;
                                growl?.push({
                                    type: 'success',
                                    message: page.props?.flash?.success || t('admin.general.admin_address_saved', 'Admin address saved.'),
                                });
                                if (nextUrl) {
                                    window.setTimeout(() => window.location.assign(nextUrl), 1600);
                                }
                            },
                        });
                    }}
                >
                    <SettingsSectionHead
                        icon="user"
                        title={t('admin.general.admin_address', 'Admin address')}
                        hint={t('admin.general.admin_address_hint', 'Replace the word admin in the address. The rest of the admin stays the same.')}
                        extra={(
                            <button
                                type="button"
                                className="mt-1 inline-flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                                aria-expanded={addressOpen}
                                aria-label={addressOpen
                                    ? t('admin.common.collapse', 'Collapse')
                                    : t('admin.common.expand', 'Expand')}
                                onClick={() => setAddressOpen((open) => !open)}
                            >
                                <Icon name={addressOpen ? 'chevron-down' : 'chevron-up'} className="h-4 w-4" />
                            </button>
                        )}
                    />
                    {addressOpen ? (
                        <>
                            <div className="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-4">
                                <div className={cx(ui.label, 'sm:w-36 sm:pt-2.5')}>
                                    <RequiredMark /> {t('admin.general.admin_address_name', 'Address name')}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <Input
                                        wide
                                        invalid={!!(addressForm.errors.segment || errors?.segment)}
                                        name="segment"
                                        value={segment}
                                        onChange={(event) => {
                                            setSegment(event.target.value);
                                            addressForm.setData('segment', event.target.value);
                                        }}
                                        autoComplete="off"
                                        spellCheck="false"
                                        maxLength={40}
                                    />
                                    <p className={ui.fieldHint}>
                                        {t('admin.general.admin_address_example', 'Example: secureAdminURL turns /admin/login into /secureAdminURL/login.')}
                                    </p>
                                    <FieldError>{addressForm.errors.segment || errors?.segment}</FieldError>
                                </div>
                            </div>
                            <div className="mt-4 flex gap-3 rounded-xl border border-violet-100 bg-violet-50 px-4 py-3">
                                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-violet-100 text-violet-600">
                                    <Icon name="link" className="h-4 w-4" />
                                </span>
                                <div className="min-w-0">
                                    <div className="text-sm font-medium text-neutral-900">
                                        {t('admin.general.admin_address_preview', 'New admin address')}
                                    </div>
                                    <div className="mt-0.5 break-all font-mono text-[13px] text-neutral-600">{preview}</div>
                                </div>
                            </div>
                            <div className="mt-4 flex justify-end">
                                <Button type="submit" busy={addressForm.processing}>{t('admin.common.save', 'Save')}</Button>
                            </div>
                        </>
                    ) : null}
                </Card>

                <Card>
                    <SettingsSectionHead
                        icon="store"
                        title={t('admin.store.store_features', 'Store Features')}
                        hint={t('admin.store.store_features_hint', 'Enable or disable features for your store')}
                        extra={(
                            <SettingsCount>
                                {t('admin.store.feature_count', '{count} features', { count: features.length })}
                            </SettingsCount>
                        )}
                    />
                    <FormErrors errors={errors} except={['segment']} />
                    <div className="grid gap-3 md:grid-cols-2">
                        {features.map((feature) => (
                            <FeatureTile key={feature.title} {...feature} />
                        ))}
                    </div>
                    {smsPanelOn ? (
                        <div className="mt-3 space-y-4 rounded-xl border border-neutral-200 bg-white px-4 py-3.5">
                            {mobileLoginOn && !shop?.community?.sms_configured ? (
                                <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                                    {t('admin.store.sms_not_configured', 'Mobile sign-in is on but no SMS provider is configured yet. Codes will only be logged until you add one.')}
                                </p>
                            ) : null}
                            <div>
                                <div className="text-sm font-semibold text-neutral-900">
                                    {t('admin.store.sms_provider', 'SMS provider')}
                                </div>
                                <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                    {t('admin.store.sms_provider_hint', 'Log codes while testing, then switch to a webhook or Twilio for real texts.')}
                                </p>
                                <div className="mt-2 max-w-xs">
                                    <Select value={smsDriver} onChange={(e) => { setSmsDriver(e.target.value); setCommunityValue('sms_driver', e.target.value); }}>
                                        <option value="log">{t('admin.store.sms_driver_log', 'Log only (testing)')}</option>
                                        <option value="webhook">{t('admin.store.sms_driver_webhook', 'Webhook')}</option>
                                        <option value="twilio">{t('admin.store.sms_driver_twilio', 'Twilio')}</option>
                                    </Select>
                                </div>
                            </div>
                            {smsDriver === 'webhook' ? (
                                <div>
                                    <div className="text-sm font-semibold text-neutral-900">
                                        {t('admin.store.sms_webhook', 'SMS webhook (optional)')}
                                    </div>
                                    <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                        {t('admin.store.sms_webhook_hint', 'POST JSON {to, body, purpose} to this URL. Leave blank to log codes only.')}
                                    </p>
                                    <div className="mt-2 flex gap-2">
                                        <Input
                                            wide
                                            type="url"
                                            value={smsWebhook}
                                            onChange={(e) => setSmsWebhook(e.target.value)}
                                            placeholder="https://sms.example/send"
                                        />
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => setCommunityValue('sms_webhook', smsWebhook)}
                                        >
                                            {t('admin.common.save', 'Save')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                            {smsDriver === 'twilio' ? (
                                <div>
                                    <div className="text-sm font-semibold text-neutral-900">
                                        {t('admin.store.sms_twilio', 'Twilio credentials')}
                                    </div>
                                    <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                        {t('admin.store.sms_twilio_hint', 'Find these in your Twilio console. The token is stored secretly and never shown again.')}
                                    </p>
                                    <div className="mt-2 grid gap-2 md:grid-cols-3">
                                        <Input
                                            wide
                                            value={twilioSid}
                                            onChange={(e) => setTwilioSid(e.target.value)}
                                            placeholder="Account SID"
                                            aria-label="Twilio account SID"
                                        />
                                        <Input
                                            wide
                                            value={twilioFrom}
                                            onChange={(e) => setTwilioFrom(e.target.value)}
                                            placeholder="+15550001111"
                                            aria-label="Twilio from number"
                                        />
                                        <Input
                                            wide
                                            type="password"
                                            value={twilioToken}
                                            onChange={(e) => setTwilioToken(e.target.value)}
                                            placeholder={shop?.community?.sms_twilio_token_set ? '•••••• (saved)' : 'Auth token'}
                                            aria-label="Twilio auth token"
                                        />
                                    </div>
                                    <div className="mt-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => {
                                                setCommunityValue('sms_twilio_sid', twilioSid);
                                                setCommunityValue('sms_twilio_from', twilioFrom);
                                                if (twilioToken.trim() !== '') setCommunityValue('sms_twilio_token', twilioToken);
                                                setTwilioToken('');
                                            }}
                                        >
                                            {t('admin.store.sms_twilio_save', 'Save Twilio settings')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                            <div>
                                <div className="text-sm font-semibold text-neutral-900">
                                    {t('admin.store.otp_policy', 'One-time-code policy')}
                                </div>
                                <p className="mt-0.5 text-xs leading-5 text-neutral-500">
                                    {t('admin.store.otp_policy_hint', 'How long codes stay valid, how many tries are allowed, and how fast a new code can be requested.')}
                                </p>
                                <div className="mt-2 grid gap-2 md:grid-cols-3">
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-neutral-600">{t('admin.store.otp_expiry', 'Expires after (minutes)')}</span>
                                        <Input wide type="number" min="2" max="10" value={otpExpiry} onChange={(e) => setOtpExpiry(e.target.value)} />
                                    </label>
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-neutral-600">{t('admin.store.otp_attempts', 'Max tries per code')}</span>
                                        <Input wide type="number" min="3" max="10" value={otpAttempts} onChange={(e) => setOtpAttempts(e.target.value)} />
                                    </label>
                                    <label className="block">
                                        <span className="mb-1 block text-xs font-medium text-neutral-600">{t('admin.store.otp_resend', 'Resend wait (seconds)')}</span>
                                        <Input wide type="number" min="0" max="300" value={otpResend} onChange={(e) => setOtpResend(e.target.value)} />
                                    </label>
                                </div>
                                <div className="mt-2">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => {
                                            setCommunityValue('otp_expiry_minutes', otpExpiry);
                                            setCommunityValue('otp_max_attempts', otpAttempts);
                                            setCommunityValue('otp_resend_seconds', otpResend);
                                        }}
                                    >
                                        {t('admin.store.otp_policy_save', 'Save code policy')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : null}
                    <SettingsNote>
                        {t('admin.store.features_note', 'You can change these settings anytime. Disabling a feature will hide related menus and functionality.')}
                    </SettingsNote>
                </Card>

                <Card>
                    <SettingsSectionHead
                        icon="clock"
                        title={t('admin.store.cookies', 'Cookies')}
                        hint={t('admin.store.cookies_hint', 'How long staff and shoppers stay signed in while idle. Values are in minutes.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 2 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="clock"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.store.cookie_bo', 'Lifetime of back office cookies')}
                            hint={t('admin.store.cookie_bo_hint', 'How long staff stay signed in while idle. 12 hours is the longest.')}
                            control={(
                                <div className="flex items-center gap-2">
                                    <Input
                                        className="w-28 shrink-0"
                                        type="number"
                                        min="1"
                                        max="720"
                                        value={adminMinutes}
                                        onChange={(e) => setAdminMinutes(e.target.value)}
                                    />
                                    <span className={ui.muted}>{t('admin.store.minutes', 'minutes')}</span>
                                </div>
                            )}
                        />
                        <FeatureTile
                            icon="clock"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.store.cookie_fo', 'Lifetime of front office cookies')}
                            hint={t('admin.store.cookie_fo_hint', 'How long shoppers stay signed in while idle. 7 days is the longest.')}
                            control={(
                                <div className="flex items-center gap-2">
                                    <Input
                                        className="w-28 shrink-0"
                                        type="number"
                                        min="1"
                                        max="10080"
                                        value={customerMinutes}
                                        onChange={(e) => setCustomerMinutes(e.target.value)}
                                    />
                                    <span className={ui.muted}>{t('admin.store.minutes', 'minutes')}</span>
                                </div>
                            )}
                        />
                    </div>
                    <div className="mt-4 flex justify-end">
                        <Button
                            type="button"
                            onClick={() => router.put('/admin/store/sessions', {
                                admin_minutes: Number(adminMinutes) || 120,
                                customer_minutes: Number(customerMinutes) || 480,
                            }, { preserveScroll: true })}
                        >
                            {t('admin.common.save', 'Save')}
                        </Button>
                    </div>
                </Card>
            </div>
        </Layout>
    );
}

