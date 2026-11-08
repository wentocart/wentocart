import { useMemo, useState } from 'react';
import {
    Alert,
    Badge,
    Button,
    Card,
    Icon,
    cx,
    t,
    ui,
} from '../../ui';

function MiniScreen({ title, children }) {
    return (
        <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 border-b border-neutral-100 bg-neutral-50 px-3 py-2">
                <span className="h-2 w-2 rounded-full bg-rose-300" />
                <span className="h-2 w-2 rounded-full bg-amber-300" />
                <span className="h-2 w-2 rounded-full bg-emerald-300" />
                <span className="ml-2 truncate text-xs text-neutral-500">{title}</span>
            </div>
            <div className="space-y-2 p-3 text-sm">{children}</div>
        </div>
    );
}

function FlowStep({ icon, tone, label, hint }) {
    return (
        <div className={ui.statCard}>
            <span className={`${ui.statIcon} ${tone}`}>
                <Icon name={icon} />
            </span>
            <div className={ui.statBody}>
                <div className={ui.statLabel}>{label}</div>
                <div className={ui.statHint}>{hint}</div>
            </div>
        </div>
    );
}

function GuideSection({ id, icon, tone, title, hint, open, onToggle, children }) {
    return (
        <div className="border-b border-neutral-200 last:border-b-0">
            <button
                type="button"
                className={open ? ui.groupAccordionBtnOpen : ui.groupAccordionBtn}
                aria-expanded={open}
                onClick={() => onToggle(id)}
            >
                <span className={ui.panelIntroMain}>
                    <span className={`${ui.statIcon} ${tone}`}>
                        <Icon name={icon} />
                    </span>
                    <span className={ui.cellStack}>
                        <span className={ui.cellStrong}>{title}</span>
                        <span className={ui.cellSub}>{hint}</span>
                    </span>
                </span>
                <Icon name={open ? 'chevron-down' : 'chevron-right'} className="transition-transform duration-200" />
            </button>
            <div className={cx(ui.accordion, open ? ui.accordionOpen : ui.accordionClosed)}>
                <div className="overflow-hidden">
                    {open ? <div className={cx(ui.groupAccordionBody, 'space-y-4 pt-0')}>{children}</div> : null}
                </div>
            </div>
        </div>
    );
}

export default function ConnectGuide({ webhooks = [], keys = [], deliveries = [] }) {
    const [open, setOpen] = useState(() => new Set(['purpose', 'two-ways']));
    const live = useMemo(() => ({
        destinations: webhooks.length,
        keys: keys.length,
        failed: deliveries.filter((row) => row.status === 'failed').length,
        sent: deliveries.filter((row) => row.status === 'sent').length,
    }), [webhooks, keys, deliveries]);

    const toggle = (id) => {
        setOpen((cur) => {
            const next = new Set(cur);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const sections = [
        'purpose',
        'two-ways',
        'webhooks',
        'webservices',
        'try',
        'security',
        'logs',
    ];

    return (
        <div className="space-y-4">
            <div className={ui.helpCard}>
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,18rem)]">
                    <div className="space-y-3">
                        <p className={ui.sectionKicker}>{t('admin.ops_tools.docs', 'Helps')}</p>
                        <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                            {t('admin.ops_tools.guide_title', 'Connect this shop to another app')}
                        </h2>
                        <p className={ui.muted}>
                            {t('admin.ops_tools.guide_lead', 'Use this page when a developer, accountant, warehouse, or mobile app needs your store data. There are only two safe ways: we send them a message (webhooks), or they fetch a list with a key (web services).')}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button href="/admin/ops-tools?tab=webhooks" variant="secondary" icon="bell">
                                {t('admin.ops_tools.webhooks', 'Webhook Events')}
                            </Button>
                            <Button href="/admin/ops-tools?tab=keys" variant="secondary" icon="lock">
                                {t('admin.ops_tools.keys', 'Web Services')}
                            </Button>
                        </div>
                    </div>
                    <div className="mx-auto grid h-32 w-full max-w-[14rem] place-items-center rounded-2xl bg-gradient-to-br from-violet-100 via-white to-sky-50" aria-hidden="true">
                        <span className="grid h-16 w-16 place-items-center rounded-2xl bg-white text-violet-600 shadow-sm ring-1 ring-violet-100">
                            <Icon name="link" className="h-8 w-8" />
                        </span>
                    </div>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                <div className={ui.statCard}>
                    <span className={`${ui.statIcon} ${ui.statIconPrimary}`}><Icon name="bell" /></span>
                    <div className={ui.statBody}>
                        <div className={ui.statLabel}>{t('admin.ops_tools.guide_live_hooks', 'Destinations')}</div>
                        <div className={ui.statHint}>{live.destinations} · {t('admin.ops_tools.guide_live_hooks_hint', 'Saved HTTPS addresses we can post to')}</div>
                    </div>
                </div>
                <div className={ui.statCard}>
                    <span className={`${ui.statIcon} ${ui.statIconInfo}`}><Icon name="lock" /></span>
                    <div className={ui.statBody}>
                        <div className={ui.statLabel}>{t('admin.ops_tools.guide_live_keys', 'Access keys')}</div>
                        <div className={ui.statHint}>{live.keys} · {t('admin.ops_tools.guide_live_keys_hint', 'Keys partners use to read this shop')}</div>
                    </div>
                </div>
                <div className={ui.statCard}>
                    <span className={`${ui.statIcon} ${live.failed ? ui.statIconDanger : ui.statIconSuccess}`}><Icon name="file" /></span>
                    <div className={ui.statBody}>
                        <div className={ui.statLabel}>{t('admin.ops_tools.deliveries', 'Webhook Logs')}</div>
                        <div className={ui.statHint}>
                            {live.sent} {t('admin.ops_tools.log_sent', 'sent')}
                            {' · '}
                            {live.failed} {t('admin.ops_tools.log_failed', 'failed')}
                        </div>
                    </div>
                </div>
            </div>

            <div className={ui.filterBar}>
                <Button type="button" variant="ghost" onClick={() => setOpen(new Set(sections))}>
                    {t('admin.ops_tools.expand_all', 'Expand all')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => setOpen(new Set())}>
                    {t('admin.ops_tools.collapse_all', 'Collapse all')}
                </Button>
            </div>

            <Card padded={false}>
                <GuideSection
                    id="purpose"
                    icon="lightbulb"
                    tone={ui.statIconWarning}
                    title={t('admin.ops_tools.guide_purpose', 'What is the purpose?')}
                    hint={t('admin.ops_tools.guide_purpose_hint', 'Keep your shop in sync with tools you already use.')}
                    open={open.has('purpose')}
                    onToggle={toggle}
                >
                    <p className={ui.muted}>
                        {t('admin.ops_tools.guide_purpose_body', 'This shop holds products, stock, orders, and customers. Other software (accounts, a warehouse, a custom app) should not log into admin. Instead they connect here. You stay in control: you choose the events, you create the key, and you can turn either off.')}
                    </p>
                    <div className={ui.choiceGrid}>
                        <div className={ui.choiceCard}>
                            <div className={ui.choiceTitle}>{t('admin.ops_tools.example_app', 'Mobile app')}</div>
                            <div className={ui.choiceHint}>{t('admin.ops_tools.example_app_hint', 'Create a key, tick only what the app needs, and give it to their developer. They should keep it on their server, not inside the downloadable app.')}</div>
                        </div>
                        <div className={ui.choiceCard}>
                            <div className={ui.choiceTitle}>{t('admin.ops_tools.example_erp', 'Stock or accounts software')}</div>
                            <div className={ui.choiceHint}>{t('admin.ops_tools.example_erp_hint', 'Ask them for a https address. Save it as a destination and tick Order placed and Stock updated. Send a test from the form at the bottom of Webhook Events.')}</div>
                        </div>
                        <div className={ui.choiceCard}>
                            <div className={ui.choiceTitle}>{t('admin.ops_tools.example_sheet', 'Spreadsheets')}</div>
                            <div className={ui.choiceHint}>{t('admin.ops_tools.example_sheet_hint', 'We do not connect a spreadsheet ourselves. Their developer can fetch product and order lists with a key, then put the rows in a sheet.')}</div>
                        </div>
                    </div>
                </GuideSection>

                <GuideSection
                    id="two-ways"
                    icon="layers"
                    tone={ui.statIconInfo}
                    title={t('admin.ops_tools.guide_two', 'Two ways to connect')}
                    hint={t('admin.ops_tools.guide_two_hint', 'Push when something happens, or pull lists when they ask.')}
                    open={open.has('two-ways')}
                    onToggle={toggle}
                >
                    <div className="grid gap-3 lg:grid-cols-2">
                        <div className="space-y-3">
                            <h3 className={ui.sectionTitle}>{t('admin.ops_tools.guide_push', 'Webhooks — we send them a message')}</h3>
                            <div className="grid gap-2">
                                <FlowStep icon="bag" tone={ui.statIconPrimary} label={t('admin.ops_tools.guide_push_1', '1. Something happens in the shop')} hint={t('admin.ops_tools.guide_push_1h', 'A customer places an order, stock changes, a product is saved.')} />
                                <FlowStep icon="bell" tone={ui.statIconWarning} label={t('admin.ops_tools.guide_push_2', '2. We POST to their HTTPS address')} hint={t('admin.ops_tools.guide_push_2h', 'Only events you enabled, only destinations you saved.')} />
                                <FlowStep icon="check" tone={ui.statIconSuccess} label={t('admin.ops_tools.guide_push_3', '3. Their app updates itself')} hint={t('admin.ops_tools.guide_push_3h', 'They prove it came from this shop using the signing secret.')} />
                            </div>
                        </div>
                        <div className="space-y-3">
                            <h3 className={ui.sectionTitle}>{t('admin.ops_tools.guide_pull', 'Web Services — they fetch from us')}</h3>
                            <div className="grid gap-2">
                                <FlowStep icon="lock" tone={ui.statIconInfo} label={t('admin.ops_tools.guide_pull_1', '1. You create an access key')} hint={t('admin.ops_tools.guide_pull_1h', 'Tick Shop / Products / Orders / Customers. Copy the key once.')} />
                                <FlowStep icon="globe" tone={ui.statIconPrimary} label={t('admin.ops_tools.guide_pull_2', '2. Their server calls /api/v1/ws')} hint={t('admin.ops_tools.guide_pull_2h', 'Bearer key in the header. Never in the browser or a public app.')} />
                                <FlowStep icon="package" tone={ui.statIconSuccess} label={t('admin.ops_tools.guide_pull_3', '3. They receive the list')} hint={t('admin.ops_tools.guide_pull_3h', 'Try it yourself on View Webservices before you go live.')} />
                            </div>
                        </div>
                    </div>
                </GuideSection>

                <GuideSection
                    id="webhooks"
                    icon="bell"
                    tone={ui.statIconPrimary}
                    title={t('admin.ops_tools.guide_hook_how', 'How to set up a webhook')}
                    hint={t('admin.ops_tools.guide_hook_how_hint', 'Four steps on the Webhook Events tab.')}
                    open={open.has('webhooks')}
                    onToggle={toggle}
                >
                    <ol className="space-y-3">
                        {[
                            t('admin.ops_tools.guide_hook_s1', 'Open Webhook Events. Turn on only the groups you need (Orders, Products, and so on). Off events are never sent.'),
                            t('admin.ops_tools.guide_hook_s2', 'Ask their developer for a public https address. Paste it as a destination. Localhost and private IPs are refused.'),
                            t('admin.ops_tools.guide_hook_s3', 'Copy the signing secret when it appears. We cannot show it again. They verify X-Wento-Signature.'),
                            t('admin.ops_tools.guide_hook_s4', 'Use Send test at the bottom. Then open Webhook Logs to see status, payload, and their reply.'),
                        ].map((row, index) => (
                            <li key={row} className="flex items-start gap-3">
                                <span className={ui.tipCheck}>{index + 1}</span>
                                <p className="text-sm text-neutral-700">{row}</p>
                            </li>
                        ))}
                    </ol>
                    <div className="grid gap-3 lg:grid-cols-2">
                        <MiniScreen title={t('admin.ops_tools.webhooks', 'Webhook Events')}>
                            <p className="font-medium text-neutral-900">{t('admin.ops_tools.guide_mock_on', 'Orders · 4 enabled')}</p>
                            <p className={ui.muted}>{t('admin.ops_tools.guide_mock_on_hint', 'Order placed, paid, refunded…')}</p>
                            <div className="rounded-xl border border-violet-100 bg-violet-50 px-3 py-2 text-xs text-violet-900">
                                {t('admin.ops_tools.guide_mock_url', 'https://partner.example/hooks/shop')}
                            </div>
                        </MiniScreen>
                        <MiniScreen title={t('admin.ops_tools.test_title', 'Test webhook')}>
                            <p className="font-medium text-neutral-900">{t('admin.ops_tools.guide_mock_test', 'Send test webhook')}</p>
                            <p className={ui.muted}>{t('admin.ops_tools.test_hint', 'Send a test message to a saved destination so their developer can confirm it arrives. Save a destination first if the list is empty.')}</p>
                        </MiniScreen>
                    </div>
                    <Button href="/admin/ops-tools?tab=webhooks">{t('admin.ops_tools.guide_open_hooks', 'Open Webhook Events')}</Button>
                </GuideSection>

                <GuideSection
                    id="webservices"
                    icon="lock"
                    tone={ui.statIconInfo}
                    title={t('admin.ops_tools.guide_ws_how', 'How to set up a web service key')}
                    hint={t('admin.ops_tools.guide_ws_how_hint', 'Create a key, then try a sample fetch.')}
                    open={open.has('webservices')}
                    onToggle={toggle}
                >
                    <ol className="space-y-3">
                        {[
                            t('admin.ops_tools.guide_ws_s1', 'Open Web Services → Create Service Key. Name it after the partner.'),
                            t('admin.ops_tools.guide_ws_s2', 'Tick read access first. Tick write only if they must change products, orders, or customers.'),
                            t('admin.ops_tools.guide_ws_s3', 'Optional: lock the key to their server IPs and set an expiry date.'),
                            t('admin.ops_tools.guide_ws_s4', 'Copy the key once. Open View Webservices, paste it, and Fetch sample.'),
                        ].map((row, index) => (
                            <li key={row} className="flex items-start gap-3">
                                <span className={ui.tipCheck}>{index + 1}</span>
                                <p className="text-sm text-neutral-700">{row}</p>
                            </li>
                        ))}
                    </ol>
                    <div className="grid gap-3 lg:grid-cols-2">
                        <MiniScreen title={t('admin.ops_tools.create_ws_key', 'Create Webservice Key')}>
                            <p className="font-medium text-neutral-900">{t('admin.ops_tools.guide_mock_key_name', 'Accounting app')}</p>
                            <p className={ui.muted}>{t('admin.ops_tools.guide_mock_scopes', 'Shop (read), Catalog (read), Orders (read)')}</p>
                        </MiniScreen>
                        <MiniScreen title={t('admin.ops_tools.view_webservices', 'View Webservices')}>
                            <p className="font-medium text-neutral-900">{t('admin.ops_tools.try_title', 'Try it now')}</p>
                            <p className={ui.muted}>{t('admin.ops_tools.try_hint', 'Paste the access key you copied when you created it. We will fetch a sample from your shop so you can see what a partner app would receive. Creating or changing records is not available from this form.')}</p>
                        </MiniScreen>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button href="/admin/ops-tools?tab=keys">{t('admin.ops_tools.keys', 'Web Services')}</Button>
                        <Button href="/admin/ops-tools/webservices" variant="secondary">{t('admin.ops_tools.view_webservices', 'View Webservices')}</Button>
                    </div>
                </GuideSection>

                <GuideSection
                    id="try"
                    icon="play"
                    tone={ui.statIconSuccess}
                    title={t('admin.ops_tools.guide_try', 'Try it before you go live')}
                    hint={t('admin.ops_tools.guide_try_hint', 'You do not need a checkout sandbox. Use Try on each tab.')}
                    open={open.has('try')}
                    onToggle={toggle}
                >
                    <Alert type="info">
                        {t('admin.ops_tools.guide_try_body', 'On Webhook Events, Send test posts a ping to their URL. On View Webservices, Fetch sample reads a real list with your key. Watch Webhook Logs for the push, and the sample JSON for the pull.')}
                    </Alert>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className={ui.choiceCard}>
                            <div className={ui.choiceTitle}>{t('admin.ops_tools.send_test', 'Send test webhook')}</div>
                            <div className={ui.choiceHint}>{t('admin.ops_tools.guide_try_hook', 'Confirms their address accepts HTTPS and can read the signature.')}</div>
                        </div>
                        <div className={ui.choiceCard}>
                            <div className={ui.choiceTitle}>{t('admin.ops_tools.send_request', 'Fetch sample')}</div>
                            <div className={ui.choiceHint}>{t('admin.ops_tools.guide_try_ws', 'Confirms the key, scopes, and the JSON they will receive.')}</div>
                        </div>
                    </div>
                </GuideSection>

                <GuideSection
                    id="security"
                    icon="lock"
                    tone={ui.statIconDanger}
                    title={t('admin.ops_tools.guide_security', 'Security')}
                    hint={t('admin.ops_tools.guide_security_hint', 'Keys and secrets stay on servers, never in public apps.')}
                    open={open.has('security')}
                    onToggle={toggle}
                >
                    <ul className="space-y-2 text-sm text-neutral-700">
                        <li className="flex items-start gap-2"><span className={ui.tipCheckSuccess}><Icon name="check" className="h-3.5 w-3.5" /></span>{t('admin.ops_tools.guide_sec_1', 'Webhook URLs must be public HTTPS. We block localhost, private networks, and redirects.')}</li>
                        <li className="flex items-start gap-2"><span className={ui.tipCheckSuccess}><Icon name="check" className="h-3.5 w-3.5" /></span>{t('admin.ops_tools.guide_sec_2', 'Each destination has a signing secret shown once. Partners check X-Wento-Signature: t=,v1=.')}</li>
                        <li className="flex items-start gap-2"><span className={ui.tipCheckSuccess}><Icon name="check" className="h-3.5 w-3.5" /></span>{t('admin.ops_tools.guide_sec_3', 'Access keys start with wntk_. We store a hash, not the key. Put Authorization: Bearer on their server only.')}</li>
                        <li className="flex items-start gap-2"><span className={ui.tipCheckSuccess}><Icon name="check" className="h-3.5 w-3.5" /></span>{t('admin.ops_tools.guide_sec_4', 'Do not put a key inside an app customers download. Optional IP allowlist and expiry limit stolen keys.')}</li>
                        <li className="flex items-start gap-2"><span className={ui.tipCheckSuccess}><Icon name="check" className="h-3.5 w-3.5" /></span>{t('admin.ops_tools.guide_sec_5', 'Payloads never include passwords, payment tokens, cost prices, or carrier API keys.')}</li>
                    </ul>
                    <Alert type="warning">
                        {t('admin.ops_tools.key_safety', 'Do not put this key inside a public app that customers download. Their developer should keep it on their own server.')}
                    </Alert>
                </GuideSection>

                <GuideSection
                    id="logs"
                    icon="file"
                    tone={ui.statIconNeutral}
                    title={t('admin.ops_tools.guide_logs', 'When a webhook fails')}
                    hint={t('admin.ops_tools.guide_logs_hint', 'Open Webhook Logs. Each row is one send.')}
                    open={open.has('logs')}
                    onToggle={toggle}
                >
                    <p className={ui.muted}>
                        {t('admin.ops_tools.guide_logs_body', 'Open the row to see the JSON we posted and the reply from their server. Failed rows can be retried. After many failures we pause that destination so a broken URL cannot flood their server.')}
                    </p>
                    <Button href="/admin/ops-tools?tab=deliveries" variant="secondary">
                        {t('admin.ops_tools.deliveries', 'Webhook Logs')}
                    </Button>
                </GuideSection>
            </Card>
        </div>
    );
}
