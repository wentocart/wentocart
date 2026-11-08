import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Alert,
    Badge,
    Button,
    Card,
    EmptyState,
    FeatureTile,
    Field,
    FilterBar,
    FormErrors,
    Icon,
    Input,
    ListFooter,
    ListPanel,
    PageHeader,
    ScoreCards,
    Select,
    StatCards,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    SettingsCount,
    SettingsSectionHead,
    Textarea,
    Toggle,
    ui,
    t,
    cx,
} from '../../ui';
import { StorefrontClubTabs } from './clubTabs';
import { jobsFromIssues, issueRunStatus, LiveFixPanel, useLiveFix } from './SeoFixLive';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.seo', 'SEO') },
];

const TAB_CLASS = 'mb-4 bg-white rounded-xl border border-neutral-200 px-2';

const tips = [
    t('admin.seo.tip_titles', 'Keep titles short and descriptive.'),
    t('admin.seo.tip_meta', 'Write unique meta descriptions for key pages.'),
    t('admin.seo.tip_og', 'Add an Open Graph image for social sharing.'),
    t('admin.seo.tip_sitemap', 'Keep sitemap.xml enabled for search engines.'),
];

const tokens = [
    {
        code: '{name}',
        text: t('admin.seo.token_name', 'Replaced with the current page name.'),
    },
    {
        code: '{shop}',
        text: t('admin.seo.token_shop', 'Replaced with your shop name.'),
    },
];

const ROBOTS = [
    { value: 'index,follow', label: 'index, follow' },
    { value: 'noindex,follow', label: 'noindex, follow' },
    { value: 'index,nofollow', label: 'index, nofollow' },
    { value: 'noindex,nofollow', label: 'noindex, nofollow' },
];

function issueTone(severity) {
    if (severity === 'ok' || severity === 'success') {
        return 'success';
    }
    if (severity === 'warning' || severity === 'warn') {
        return 'warning';
    }

    return 'danger';
}

function IssueList({ issues = [], busy, onFix, showOk = false, live = null, fixing = false }) {
    const rows = showOk ? issues : issues.filter((row) => row.severity !== 'ok' && row.status !== 'ok');
    if (!rows.length) {
        return (
            <Alert type="ok">
                {t('admin.seo.all_clear', 'No open SEO or store issues. Keep titles unique as you add products.')}
            </Alert>
        );
    }

    return (
        <div className="space-y-3">
            {rows.map((row) => (
                <Card key={row.id} className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="mb-1 flex flex-wrap items-center gap-2">
                            <Badge tone={issueTone(row.severity || row.status)}>{row.severity || row.status}</Badge>
                            {row.fix && issueRunStatus(live, row.fix) ? (
                                <Badge tone={issueRunStatus(live, row.fix) === 'done' ? 'success' : issueRunStatus(live, row.fix) === 'running' ? 'info' : 'neutral'}>
                                    {issueRunStatus(live, row.fix) === 'done'
                                        ? t('admin.health.fix_done', 'Fixed')
                                        : issueRunStatus(live, row.fix) === 'running'
                                            ? t('admin.health.fix_working', 'Working')
                                            : t('admin.health.fix_queued', 'Queued')}
                                </Badge>
                            ) : null}
                            <span className={ui.muted}>{row.area}</span>
                            {row.count ? <span className={ui.muted}>{Number(row.count).toLocaleString()}</span> : null}
                        </div>
                        <p className="font-medium">{row.title}</p>
                        <p className={ui.muted}>{row.detail}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {row.fix ? (
                            <Button
                                type="button"
                                variant="primary"
                                icon="check"
                                busy={issueRunStatus(live, row.fix) === 'running'}
                                disabled={fixing}
                                onClick={() => onFix(row.fix)}
                            >
                                {t('admin.seo.fix', 'Fix')}
                            </Button>
                        ) : null}
                        {row.href ? (
                            <Button href={row.href} variant="secondary">
                                {t('admin.seo.open', 'Open')}
                            </Button>
                        ) : null}
                    </div>
                </Card>
            ))}
        </div>
    );
}

function editHref(type, id) {
    if (type === 'category') {
        return `/admin/categories/${id}/edit`;
    }
    if (type === 'cms') {
        return `/admin/cms/${id}/edit`;
    }

    return `/admin/products/${id}/edit`;
}

function PageRow({ row, type }) {
    const [data, setData] = useState({
        meta_title: row.meta_title || '',
        meta_description: row.meta_description || '',
        meta_keywords: row.meta_keywords || '',
        focus_keyword: row.focus_keyword || '',
        robots: row.robots || 'index,follow',
    });
    const [busy, setBusy] = useState(false);
    const set = (key) => (e) => setData((current) => ({ ...current, [key]: e.target.value }));

    const save = () => {
        setBusy(true);
        router.patch(`/admin/seo/pages/${type}/${row.id}`, data, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Tr>
            <Td>
                <div className="min-w-0">
                    <p className="font-medium">{row.name || row.sku || `#${row.id}`}</p>
                    {row.sku ? <p className={ui.muted}>{row.sku}</p> : null}
                </div>
            </Td>
            <Td>
                <Input value={data.meta_title} onChange={set('meta_title')} />
            </Td>
            <Td>
                <Textarea rows="2" value={data.meta_description} onChange={set('meta_description')} />
            </Td>
            <Td>
                <Input value={data.meta_keywords} onChange={set('meta_keywords')} />
            </Td>
            {type === 'product' ? (
                <Td>
                    <Input value={data.focus_keyword} onChange={set('focus_keyword')} />
                </Td>
            ) : null}
            {type !== 'category' ? (
                <Td>
                    <Select value={data.robots} onChange={set('robots')}>
                        {ROBOTS.map((opt) => (
                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                        ))}
                    </Select>
                </Td>
            ) : null}
            <Td actions>
                <Button type="button" variant="sm" busy={busy} onClick={save}>
                    {t('admin.seo.save_page', 'Save')}
                </Button>
                <Button href={editHref(type, row.id)} variant="ghost">
                    {t('admin.seo.edit_full', 'Full editor')}
                </Button>
            </Td>
        </Tr>
    );
}

function SettingsForm({ seo }) {
    const form = useForm({
        title_pattern: seo.title_pattern || '{name} | {shop}',
        product_title_pattern: seo.product_title_pattern || '',
        default_description: seo.default_description || '',
        default_robots: seo.default_robots || 'index,follow',
        og_image: seo.og_image || '',
        robots_txt: seo.robots_txt || '',
        locale_url_mode: seo.locale_url_mode || 'cookie',
        sitemap_enabled: !!seo.sitemap_enabled,
        sitemap_include_products: seo.sitemap_include_products !== false,
        sitemap_include_categories: seo.sitemap_include_categories !== false,
        sitemap_include_cms: seo.sitemap_include_cms !== false,
        sitemap_include_brands: seo.sitemap_include_brands !== false,
        use_short_description_fallback: seo.use_short_description_fallback !== false,
        ga4_id: seo.ga4_id || '',
        gtm_id: seo.gtm_id || '',
        meta_pixel_id: seo.meta_pixel_id || '',
        cookie_consent: seo.cookie_consent !== false,
        age_gate: !!seo.age_gate,
        age_min: Number(seo.age_min ?? 18),
        merchant_feed: seo.merchant_feed !== false,
        admin_mfa: !!seo.admin_mfa,
        customer_mfa: !!seo.customer_mfa,
    });

    useSaveOnChange(form, '/admin/seo', {
        textKeys: [
            'title_pattern',
            'product_title_pattern',
            'default_description',
            'og_image',
            'robots_txt',
            'age_min',
            'ga4_id',
            'gtm_id',
            'meta_pixel_id',
        ],
    });

    return (
        <div className={ui.formLayoutSplit}>
            <div className="min-w-0 space-y-4">
                <Card
                    as="form"
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                    }}
                >
                    <SettingsSectionHead
                        icon="settings"
                        title={t('admin.seo.settings_title', 'SEO settings')}
                        hint={t('admin.seo.settings_hint', 'Configure how your store appears in search engines and social media.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 16 })}</SettingsCount>}
                    />

                    <FormErrors errors={form.errors} />

                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="file"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.seo.title_pattern', 'Default title pattern')}
                            hint={form.errors.title_pattern || t('admin.seo.title_pattern_hint', 'Used when a page has no SEO title. Tokens: {name}, {shop}.')}
                        >
                            <Input wide invalid={!!form.errors.title_pattern} value={form.data.title_pattern} onChange={(e) => form.setData('title_pattern', e.target.value)} />
                        </FeatureTile>
                        <FeatureTile
                            icon="package"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.seo.product_title_pattern', 'Product title pattern')}
                            hint={form.errors.product_title_pattern || t('admin.seo.product_title_pattern_hint', 'Optional override for product pages only. Leave blank to use the default pattern.')}
                        >
                            <Input wide invalid={!!form.errors.product_title_pattern} value={form.data.product_title_pattern} onChange={(e) => form.setData('product_title_pattern', e.target.value)} placeholder="{name} | {shop}" />
                        </FeatureTile>
                        <FeatureTile
                            icon="file"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.seo.default_description', 'Default meta description')}
                            hint={form.errors.default_description || t('admin.seo.default_description_hint', 'Used when a page has no meta description.')}
                        >
                            <Textarea rows="3" value={form.data.default_description} onChange={(e) => form.setData('default_description', e.target.value)} />
                        </FeatureTile>
                        <FeatureTile
                            icon="shield"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.seo.default_robots', 'Default robots')}
                            hint={form.errors.default_robots || t('admin.seo.default_robots_hint', 'Default robots meta tag for all pages.')}
                            control={(
                                <Select className="w-44 shrink-0" value={form.data.default_robots} onChange={(e) => form.setData('default_robots', e.target.value)}>
                                    {ROBOTS.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="globe"
                            tone="bg-indigo-100 text-indigo-600"
                            title={t('admin.seo.locale_url_mode', 'Language URL mode')}
                            hint={form.errors.locale_url_mode || t('admin.seo.locale_url_mode_hint', 'Path (/en/…, /fr/…) is required for full multilingual SEO (distinct home URLs, sitemap alternates). Cookie mode is fine for a single language; with multiple languages, entity hreflang still uses per-language rewrite paths.')}
                            control={(
                                <Select className="w-44 shrink-0" value={form.data.locale_url_mode} onChange={(e) => form.setData('locale_url_mode', e.target.value)}>
                                    <option value="cookie">{t('admin.seo.locale_cookie', 'Cookie / browser (default)')}</option>
                                    <option value="path">{t('admin.seo.locale_path', 'Path prefix (/en, /fr) — recommended for multilingual SEO')}</option>
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="photo"
                            tone="bg-pink-100 text-pink-600"
                            title={t('admin.seo.og_image', 'Open Graph image')}
                            hint={form.errors.og_image || t('admin.seo.og_image_hint', 'Square image (at least 1200 x 630 px) used when a page is shared on social media.')}
                        >
                            <Input wide value={form.data.og_image} onChange={(e) => form.setData('og_image', e.target.value)} placeholder={t('admin.seo.og_image_placeholder', 'Storage path or full URL')} />
                        </FeatureTile>
                        <FeatureTile
                            icon="globe"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.seo.sitemap', 'Include sitemap.xml')}
                            hint={t('admin.seo.sitemap_hint', 'Generate and include sitemap.xml for search engines.')}
                            value={form.data.sitemap_enabled}
                            onChange={(on) => form.setData('sitemap_enabled', on)}
                        />
                        {form.data.sitemap_enabled ? (
                            <>
                                <FeatureTile icon="package" tone="bg-violet-100 text-violet-600" title={t('admin.seo.sitemap_products', 'Include products in sitemap')} value={!!form.data.sitemap_include_products} onChange={(on) => form.setData('sitemap_include_products', on)} />
                                <FeatureTile icon="folder" tone="bg-amber-100 text-amber-600" title={t('admin.seo.sitemap_categories', 'Include categories in sitemap')} value={!!form.data.sitemap_include_categories} onChange={(on) => form.setData('sitemap_include_categories', on)} />
                                <FeatureTile icon="file" tone="bg-emerald-100 text-emerald-600" title={t('admin.seo.sitemap_cms', 'Include CMS pages in sitemap')} value={!!form.data.sitemap_include_cms} onChange={(on) => form.setData('sitemap_include_cms', on)} />
                                <FeatureTile icon="tag" tone="bg-indigo-100 text-indigo-600" title={t('admin.seo.sitemap_brands', 'Include brands in sitemap')} value={!!form.data.sitemap_include_brands} onChange={(on) => form.setData('sitemap_include_brands', on)} />
                            </>
                        ) : null}
                        <FeatureTile
                            icon="file"
                            tone="bg-blue-100 text-blue-600"
                            title={t('admin.seo.short_fallback', 'Use short description when meta description is empty')}
                            hint={t('admin.seo.short_fallback_hint', 'Product (and brand) pages fall back to short description before the shop default.')}
                            value={!!form.data.use_short_description_fallback}
                            onChange={(on) => form.setData('use_short_description_fallback', on)}
                        />
                        <FeatureTile
                            icon="bag"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.seo.merchant_feed', 'Google Merchant product feed')}
                            hint={t('admin.seo.merchant_feed_hint', 'Published at /feeds/google-merchant.xml')}
                            value={!!form.data.merchant_feed}
                            onChange={(on) => form.setData('merchant_feed', on)}
                        />
                        <FeatureTile
                            icon="bar-chart"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.seo.ga4', 'Google Analytics 4 measurement ID')}
                            control={<Input className="w-40 shrink-0" placeholder="G-XXXXXXXX" value={form.data.ga4_id} onChange={(e) => form.setData('ga4_id', e.target.value)} />}
                        />
                        <FeatureTile
                            icon="tag"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.seo.gtm', 'Google Tag Manager container ID')}
                            control={<Input className="w-40 shrink-0" placeholder="GTM-XXXXXXX" value={form.data.gtm_id} onChange={(e) => form.setData('gtm_id', e.target.value)} />}
                        />
                        <FeatureTile
                            icon="bar-chart"
                            tone="bg-indigo-100 text-indigo-600"
                            title={t('admin.seo.meta_pixel', 'Meta Pixel ID')}
                            control={<Input className="w-40 shrink-0" placeholder="1234567890" value={form.data.meta_pixel_id} onChange={(e) => form.setData('meta_pixel_id', e.target.value)} />}
                        />
                        <FeatureTile
                            icon="shield"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.seo.cookie_consent', 'Ask for cookie consent before loading pixels')}
                            hint={t('admin.seo.cookie_consent_hint', 'Pixels load only after the visitor accepts cookies.')}
                            value={!!form.data.cookie_consent}
                            onChange={(on) => form.setData('cookie_consent', on)}
                        />
                        <FeatureTile
                            icon="user"
                            tone="bg-rose-100 text-rose-600"
                            title={t('admin.seo.age_gate', 'Age gate')}
                            hint={t('admin.seo.age_gate_hint', 'Ask visitors to confirm they are old enough before browsing.')}
                            value={!!form.data.age_gate}
                            onChange={(on) => form.setData('age_gate', on)}
                        >
                            {form.data.age_gate ? (
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-neutral-700">{t('admin.seo.age_min', 'Minimum age')}</span>
                                    <Input className="w-24 shrink-0" type="number" min="13" max="21" value={form.data.age_min} onChange={(e) => form.setData('age_min', Number(e.target.value))} />
                                </div>
                            ) : null}
                        </FeatureTile>
                        <FeatureTile
                            icon="lock"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.seo.admin_mfa', 'Require authenticator app for admin sign-in')}
                            hint={t('admin.seo.admin_mfa_hint', 'Staff without TOTP are sent to enroll after password. Staff who already enrolled must enter a code.')}
                            value={!!form.data.admin_mfa}
                            onChange={(on) => form.setData('admin_mfa', on)}
                        />
                        <FeatureTile
                            icon="users"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.seo.customer_mfa', 'Prompt customers to enroll an authenticator app')}
                            hint={t('admin.seo.customer_mfa_hint', 'Customers can enroll TOTP from their account. When required, sign-in asks them to set it up.')}
                            value={!!form.data.customer_mfa}
                            onChange={(on) => form.setData('customer_mfa', on)}
                        />
                        <FeatureTile
                            icon="code"
                            tone="bg-neutral-100 text-neutral-600"
                            title={t('admin.seo.robots_txt', 'robots.txt')}
                            hint={form.errors.robots_txt || t('admin.seo.robots_txt_hint', 'Use {sitemap} for the shop sitemap URL.')}
                        >
                            <Textarea rows="8" value={form.data.robots_txt} onChange={(e) => form.setData('robots_txt', e.target.value)} />
                        </FeatureTile>
                    </div>
                </Card>
            </div>

            <aside className={cx(ui.formSide, 'space-y-4')}>
                <Card className="space-y-2 border-violet-100 bg-violet-50/40">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="info" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.seo.about_title', 'About SEO settings')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.seo.about_body', 'These settings improve how your store appears in search results and when shared on social media.')}
                            </p>
                        </div>
                    </div>
                </Card>

                <Card className="space-y-3 border-sky-100 bg-sky-50/40">
                    <div className="flex items-start gap-3">
                        <span className={cx(ui.sectionHeadIcon, 'bg-sky-100 text-sky-700')}>
                            <Icon name="lightbulb" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-sky-900')}>
                                {t('admin.seo.tips_title', 'Tips for better SEO')}
                            </h3>
                        </div>
                    </div>
                    <ul className="space-y-2">
                        {tips.map((tip) => (
                            <li key={tip} className="flex items-start gap-2 text-sm text-neutral-700">
                                <span className={ui.tipCheck}>
                                    <Icon name="check" className="h-3 w-3" />
                                </span>
                                <span>{tip}</span>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card className="space-y-3 border-emerald-100 bg-emerald-50/40">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIconSuccess}>
                            <Icon name="file" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-emerald-900')}>
                                {t('admin.seo.tokens_title', 'Available tokens')}
                            </h3>
                        </div>
                    </div>
                    <ul className="space-y-3 text-sm text-neutral-700">
                        {tokens.map((token) => (
                            <li key={token.code}>
                                <code className="rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs text-emerald-800 ring-1 ring-emerald-100">
                                    {token.code}
                                </code>
                                <p className={cx(ui.muted, 'mt-1')}>{token.text}</p>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card className="space-y-3 border-indigo-100 bg-indigo-50/40">
                    <div className="flex items-start gap-3">
                        <span className={cx(ui.sectionHeadIcon, 'bg-indigo-100 text-indigo-700')}>
                            <Icon name="eye" className="h-5 w-5" />
                        </span>
                        <div className="min-w-0 space-y-3">
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-indigo-900')}>
                                {t('admin.seo.preview_title', 'Preview your SEO')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.seo.preview_body', 'Open the storefront to check titles, meta tags, and social previews.')}
                            </p>
                        </div>
                    </div>
                </Card>
            </aside>
        </div>
    );
}

export default function Seo({ seo, tab = 'dashboard', audit = {}, pages = null }) {
    const scores = audit.scores || {};
    const issues = audit.issues || [];
    const counts = audit.counts || {};
    const sitemap = audit.sitemap || {};
    const { live, start } = useLiveFix();
    const [auditSnap, setAuditSnap] = useState(null);
    const currentAudit = auditSnap || audit;
    const liveScores = currentAudit.scores || scores;
    const liveIssues = currentAudit.issues || issues;
    const liveCounts = currentAudit.counts || counts;
    const openIssues = liveIssues.filter((row) => row.severity !== 'ok');
    const jobs = jobsFromIssues(openIssues);
    const fixing = live.running;
    const paginator = pages?.rows || {};
    const pageRows = paginator.data || [];

    useEffect(() => {
        if (live.complete && live.payload?.audit) {
            setAuditSnap(live.payload.audit);
        }
    }, [live.complete, live.payload]);

    const goTab = (next, extra = {}) => {
        const query = { tab: next, ...extra };
        Object.keys(query).forEach((key) => {
            if (query[key] === '' || query[key] == null) {
                delete query[key];
            }
        });
        router.get('/admin/seo', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const runFix = (id) => {
        const selected = id === 'all' ? jobs : jobs.filter((job) => job.id === id);
        start({ url: '/admin/seo/fix', id, jobs: selected });
    };

    const studioTabs = [
        { key: 'dashboard', label: t('admin.seo.tab_dashboard', 'Dashboard') },
        { key: 'issues', label: t('admin.seo.tab_issues', 'Issues'), count: liveCounts.issues || 0 },
        { key: 'pages', label: t('admin.seo.tab_pages', 'Pages') },
        { key: 'sitemap', label: t('admin.seo.tab_sitemap', 'Sitemap') },
        { key: 'settings', label: t('admin.seo.tab_settings', 'Settings') },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.seo.studio_title', 'SEO studio')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.seo.studio_subtitle', 'Store health, search listings, sitemap, and one-click fixes. No extra SEO app required.')}
            />
            <StorefrontClubTabs current="seo" />
            <StatusTabs
                className={TAB_CLASS}
                tabs={studioTabs}
                value={tab}
                onChange={(next) => goTab(next, next === 'pages' ? { type: pages?.type || 'product', filter: pages?.filter || 'all', q: pages?.q || '' } : {})}
            />

            {tab === 'dashboard' ? (
                <div className="space-y-4">
                    <ScoreCards scores={liveScores} />
                    <p className={ui.muted}>
                        {t('admin.seo.scores_disclaimer', 'These scores are store health checks (meta, sitemap, indexing hygiene). They are not Google rankings or Merchant Center approval.')}
                    </p>
                    <StatCards
                        items={[
                            { key: 'products', label: t('admin.seo.stat_products', 'Products'), value: Number(liveCounts.enabled_products || 0).toLocaleString(), icon: 'package', tone: 'primary' },
                            { key: 'issues', label: t('admin.seo.stat_issues', 'Open issues'), value: Number(liveCounts.issues || 0).toLocaleString(), icon: 'alert-triangle', tone: liveCounts.issues ? 'warning' : 'success' },
                            { key: 'fixes', label: t('admin.seo.stat_fixes', 'Auto fixes'), value: Number(liveCounts.fixes || 0).toLocaleString(), icon: 'check', tone: 'info' },
                            { key: 'urls', label: t('admin.seo.stat_urls', 'Sitemap URLs'), value: Number(liveCounts.sitemap_urls || 0).toLocaleString(), icon: 'link', tone: 'neutral' },
                        ]}
                    />
                    <LiveFixPanel live={live} />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.seo.open_issues', 'Open issues')}</h2>
                        {jobs.length ? (
                            <Button type="button" icon="check" busy={fixing} disabled={fixing} onClick={() => runFix('all')}>
                                {t('admin.seo.fix_all', 'Fix all automatic issues')}
                            </Button>
                        ) : null}
                    </div>
                    <IssueList issues={openIssues} onFix={runFix} live={live} fixing={fixing} />
                </div>
            ) : null}

            {tab === 'issues' ? (
                <div className="space-y-4">
                    <LiveFixPanel live={live} />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className={ui.muted}>
                            {t('admin.seo.issues_hint', 'Every check the shop runs. Fix fills missing titles, descriptions, keywords, alts, and sitemap flags.')}
                        </p>
                        {jobs.length ? (
                            <Button type="button" icon="check" busy={fixing} disabled={fixing} onClick={() => runFix('all')}>
                                {t('admin.seo.fix_all', 'Fix all automatic issues')}
                            </Button>
                        ) : null}
                    </div>
                    <IssueList issues={liveIssues} onFix={runFix} showOk live={live} fixing={fixing} />
                </div>
            ) : null}

            {tab === 'pages' && pages ? (
                <div className="space-y-4">
                    <StatusTabs
                        className={TAB_CLASS}
                        tabs={[
                            { key: 'product', label: t('admin.seo.pages_products', 'Products'), count: counts.enabled_products },
                            { key: 'category', label: t('admin.seo.pages_categories', 'Categories'), count: counts.categories },
                            { key: 'cms', label: t('admin.seo.pages_cms', 'CMS pages'), count: counts.cms },
                        ]}
                        value={pages.type}
                        onChange={(type) => goTab('pages', { type, filter: 'all' })}
                    />
                    <ListPanel
                        filters={(
                            <FilterBar
                                search={pages.q || ''}
                                searchPlaceholder={t('admin.seo.search_pages', 'Search name or SKU')}
                                onSearchSubmit={(q) => goTab('pages', { type: pages.type, filter: pages.filter, q })}
                            >
                                <Select
                                    value={pages.filter}
                                    onChange={(e) => goTab('pages', { type: pages.type, filter: e.target.value, q: pages.q })}
                                >
                                    <option value="all">{t('admin.seo.filter_all', 'All pages')}</option>
                                    <option value="missing_title">{t('admin.seo.filter_title', 'Missing title')}</option>
                                    <option value="missing_description">{t('admin.seo.filter_description', 'Missing description')}</option>
                                    <option value="missing_keywords">{t('admin.seo.filter_keywords', 'Missing keywords')}</option>
                                    {pages.type === 'product' ? (
                                        <option value="missing_focus">{t('admin.seo.filter_focus', 'Missing focus keyword')}</option>
                                    ) : null}
                                    <option value="duplicate">{t('admin.seo.filter_duplicate', 'Duplicate titles')}</option>
                                </Select>
                            </FilterBar>
                        )}
                        footer={<ListFooter pagination={paginator} query={{ tab: 'pages', type: pages.type, filter: pages.filter, q: pages.q }} />}
                    >
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('admin.seo.col_page', 'Page')}</Th>
                                    <Th>{t('admin.seo.col_title', 'Meta title')}</Th>
                                    <Th>{t('admin.seo.col_description', 'Meta description')}</Th>
                                    <Th>{t('admin.seo.col_keywords', 'Meta keywords')}</Th>
                                    {pages.type === 'product' ? <Th>{t('admin.seo.col_focus', 'Focus keyword')}</Th> : null}
                                    {pages.type !== 'category' ? <Th>{t('admin.seo.col_robots', 'Robots')}</Th> : null}
                                    <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                                </Tr>
                            </THead>
                            <tbody>
                                {pageRows.length ? pageRows.map((row) => (
                                    <PageRow key={`${pages.type}-${row.id}`} row={row} type={pages.type} />
                                )) : (
                                    <EmptyState colSpan={pages.type === 'product' ? 7 : 6} />
                                )}
                            </tbody>
                        </Table>
                    </ListPanel>
                </div>
            ) : null}

            {tab === 'sitemap' ? (
                <div className="space-y-4">
                    <LiveFixPanel live={live} />
                    <StatCards
                        items={[
                            { key: 'on', label: t('admin.seo.sitemap_status', 'XML sitemap'), value: sitemap.enabled ? t('admin.seo.on', 'On') : t('admin.seo.off', 'Off'), icon: 'globe', tone: sitemap.enabled ? 'success' : 'danger' },
                            { key: 'urls', label: t('admin.seo.stat_urls', 'Sitemap URLs'), value: Number(sitemap.urls || 0).toLocaleString(), icon: 'link', tone: 'primary' },
                            { key: 'products', label: t('admin.seo.sitemap_products', 'Include products in sitemap'), value: sitemap.include_products ? t('admin.common.yes', 'Yes') : t('admin.common.no', 'No'), icon: 'package', tone: 'neutral' },
                            { key: 'cms', label: t('admin.seo.sitemap_cms', 'Include CMS pages in sitemap'), value: sitemap.include_cms ? t('admin.common.yes', 'Yes') : t('admin.common.no', 'No'), icon: 'file', tone: 'neutral' },
                        ]}
                    />
                    <Card className="space-y-3">
                        <SettingsSectionHead
                            icon="globe"
                            title={t('admin.seo.sitemap_files', 'Public files')}
                            hint={t('admin.seo.sitemap_files_hint', 'Submit these URLs in Google Search Console and Bing Webmaster. Large catalogs split into a sitemap index.')}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button href={sitemap.url || '/sitemap.xml'} native target="_blank" rel="noreferrer" variant="secondary" icon="link">
                                {t('admin.seo.open_sitemap', 'Open sitemap.xml')}
                            </Button>
                            <Button href={sitemap.robots_url || '/robots.txt'} native target="_blank" rel="noreferrer" variant="secondary">
                                {t('admin.seo.open_robots', 'Open robots.txt')}
                            </Button>
                            <Button href={sitemap.feed_url || '/feeds/google-merchant.xml'} native target="_blank" rel="noreferrer" variant="secondary">
                                {t('admin.seo.open_feed', 'Open Merchant feed')}
                            </Button>
                            {!sitemap.enabled ? (
                                <Button type="button" icon="check" busy={fixing} disabled={fixing} onClick={() => runFix('enable_sitemap')}>
                                    {t('admin.seo.enable_sitemap', 'Turn sitemap on')}
                                </Button>
                            ) : null}
                        </div>
                    </Card>
                    <Alert type="info">
                        {t('admin.seo.sitemap_note', 'The map lists enabled products, categories, CMS pages, and brands only. Disabled products stay out. Use Settings to change what is included.')}
                    </Alert>
                </div>
            ) : null}

            {tab === 'settings' ? <SettingsForm seo={seo} /> : null}
        </Layout>
    );
}
