import { useForm } from '@inertiajs/react';
import { useSaveOnChange } from './useSaveOnChange';
import { useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Button,
    Card,
    FeatureTile,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Select,
    SettingsSectionHead,
    ui,
    t,
    cx,
} from '../../ui';
import { StorefrontClubTabs } from './clubTabs';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.urls', 'URLs') },
];

const TYPE_META = {
    product: {
        label: t('admin.urls.type_product', 'Product'),
        icon: 'package',
        tone: ui.statIconInfo,
        sample: { id: '123', rewrite: 'smart-phone', id_category: '45', category: 'electronics', id_parent: '1', parent: 'catalog', brand: 'nike', supplier: 'acme' },
        help: t('admin.urls.help_product', 'Product pages. {rewrite} is the product slug, {id} the product ID, {category} and {id_category} the default category, and {ipa} a variant.'),
        presets: [
            { value: '{id}-{rewrite}', label: '{id}-{rewrite}' },
            { value: '{rewrite}', label: '{rewrite}' },
            { value: '{category}/{rewrite}', label: '{category}/{rewrite}' },
            { value: '{id_category}-{rewrite}', label: '{id_category}-{rewrite}' },
            { value: '{id}-{rewrite}-{ipa}', label: '{id}-{rewrite}-{ipa}' },
        ],
    },
    category: {
        label: t('admin.urls.type_category', 'Category'),
        icon: 'folder',
        tone: ui.statIconSuccess,
        sample: { id: '45', rewrite: 'electronics', id_category: '45', category: 'electronics', id_parent: '1', parent: 'catalog', brand: 'nike', supplier: 'acme' },
        help: t('admin.urls.help_category', 'Category pages. {rewrite} is the category slug, {id} its ID, and {parent} and {id_parent} the parent category.'),
        presets: [
            { value: '{id}-{rewrite}', label: '{id}-{rewrite}' },
            { value: '{rewrite}', label: '{rewrite}' },
            { value: '{parent}/{rewrite}', label: '{parent}/{rewrite}' },
            { value: '{id_parent}-{rewrite}', label: '{id_parent}-{rewrite}' },
        ],
    },
    cms: {
        label: t('admin.urls.type_cms', 'CMS Pages'),
        icon: 'file',
        tone: ui.statIconWarning,
        sample: { id: '12', rewrite: 'about-us', id_category: '', category: '', id_parent: '', parent: '', brand: '', supplier: '' },
        help: t('admin.urls.help_cms', 'CMS pages. {rewrite} is the page slug and {id} the page ID. A prefix such as content/ groups those pages.'),
        presets: [
            { value: '{id}-{rewrite}', label: '{id}-{rewrite}' },
            { value: '{rewrite}', label: '{rewrite}' },
            { value: 'content/{rewrite}', label: 'content/{rewrite}' },
        ],
    },
    brand: {
        label: t('admin.urls.type_brand', 'Brand'),
        icon: 'tag',
        tone: ui.statIconDanger,
        sample: { id: '7', rewrite: 'nike', id_category: '', category: '', id_parent: '', parent: '', brand: 'nike', supplier: '' },
        help: t('admin.urls.help_brand', 'Brand pages. brand/{rewrite} keeps every brand under /brand/. {id} adds the brand ID.'),
        presets: [
            { value: 'brand/{rewrite}', label: 'brand/{rewrite}' },
            { value: '{rewrite}', label: '{rewrite}' },
            { value: 'brand/{id}-{rewrite}', label: 'brand/{id}-{rewrite}' },
        ],
    },
    supplier: {
        label: t('admin.urls.type_supplier', 'Supplier'),
        icon: 'truck',
        tone: ui.statIconPrimary,
        sample: { id: '3', rewrite: 'samsung', id_category: '', category: '', id_parent: '', parent: '', brand: '', supplier: 'samsung' },
        help: t('admin.urls.help_supplier', 'Supplier pages. supplier/{rewrite} keeps every supplier under /supplier/. {id} adds the supplier ID.'),
        presets: [
            { value: 'supplier/{rewrite}', label: 'supplier/{rewrite}' },
            { value: '{rewrite}', label: '{rewrite}' },
            { value: 'supplier/{id}-{rewrite}', label: 'supplier/{id}-{rewrite}' },
        ],
    },
};

const CUSTOM = 'custom';

function previewPath(schema, sample, suffix) {
    const map = {
        '{id}': sample.id || '',
        '{rewrite}': sample.rewrite || '',
        '{id_category}': sample.id_category || '',
        '{category}': sample.category || '',
        '{id_parent}': sample.id_parent || '',
        '{parent}': sample.parent || '',
        '{brand}': sample.brand || '',
        '{supplier}': sample.supplier || '',
    };
    let path = String(schema || '');
    Object.entries(map).forEach(([token, value]) => {
        path = path.split(token).join(value);
    });
    path = path.replace(/\/{2,}/g, '/').replace(/-{2,}/g, '-').replace(/^\/+|\/+$/g, '').replace(/^-+|-+$/g, '');
    if (suffix && !path.endsWith(suffix)) {
        path += suffix;
    }
    return path || '…';
}

/** Decorative URL browser graphic for the tips card (page-only). */
function TipsGraphic() {
    return (
        <div className="relative mx-auto mt-4 w-full max-w-[220px]" aria-hidden="true">
            <div className="rounded-xl border border-violet-200/80 bg-gradient-to-br from-violet-50 via-white to-sky-50 p-3 shadow-[0_12px_28px_-16px_rgba(109,40,217,0.55)]">
                <div className="mb-2 flex items-center gap-1.5">
                    <span className="h-2 w-2 rounded-full bg-rose-300" />
                    <span className="h-2 w-2 rounded-full bg-amber-300" />
                    <span className="h-2 w-2 rounded-full bg-emerald-300" />
                </div>
                <div className="flex items-center gap-2 rounded-lg border border-violet-100 bg-white px-2.5 py-2 shadow-sm">
                    <Icon name="globe" className="h-3.5 w-3.5 text-violet-400" />
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-violet-50">
                        <div className="h-full w-3/4 rounded-full bg-gradient-to-r from-violet-400 to-sky-400" />
                    </div>
                </div>
                <div className="mt-2 space-y-1.5 px-0.5">
                    <div className="h-1.5 w-full rounded-full bg-violet-100/80" />
                    <div className="h-1.5 w-4/5 rounded-full bg-sky-100/90" />
                    <div className="h-1.5 w-2/3 rounded-full bg-violet-100/60" />
                </div>
            </div>
            <span className="absolute -right-1 -top-1 flex h-9 w-9 items-center justify-center rounded-full bg-violet-600 text-white shadow-lg shadow-violet-300/50 ring-4 ring-white">
                <Icon name="settings" className="h-4 w-4" />
            </span>
            <span className="absolute -bottom-2 left-3 flex h-7 w-7 items-center justify-center rounded-full bg-sky-500 text-white shadow-md ring-2 ring-white">
                <Icon name="link" className="h-3.5 w-3.5" />
            </span>
        </div>
    );
}

function SchemaRow({ type, value, error, suffix, defaultValue, onChange }) {
    const meta = TYPE_META[type];
    const presetMatch = meta.presets.some((preset) => preset.value === value);
    const [forceCustom, setForceCustom] = useState(false);
    const editable = forceCustom || !presetMatch;
    const selectValue = editable ? CUSTOM : value;
    const example = useMemo(() => previewPath(value, meta.sample, suffix), [value, meta.sample, suffix]);

    const pick = (next) => {
        if (next === CUSTOM) {
            setForceCustom(true);
            return;
        }
        setForceCustom(false);
        onChange(next);
    };

    return (
        <div className="rounded-xl border border-neutral-200/80 bg-gradient-to-r from-white to-neutral-50/80 p-3 sm:p-4">
            <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                <div className="flex w-full items-center gap-2.5 lg:w-36 lg:shrink-0">
                    <span className={cx(ui.statIcon, 'h-9 w-9 rounded-xl', meta.tone)}>
                        <Icon name={meta.icon} className="h-4 w-4" />
                    </span>
                    <span className="text-sm font-semibold text-neutral-800">{meta.label}</span>
                </div>
                <div className="min-w-0 w-full flex-1">
                    <Input
                        wide
                        value={value}
                        readOnly={!editable}
                        invalid={!!error}
                        onChange={(e) => onChange(e.target.value)}
                        aria-label={meta.label}
                        className={cx('font-mono text-sm', !editable && 'cursor-default bg-neutral-50 text-neutral-600')}
                    />
                </div>
                <Select
                    value={selectValue}
                    onChange={(e) => pick(e.target.value)}
                    aria-label={t('admin.urls.pattern', '{label} pattern', { label: meta.label })}
                    className="w-full shrink-0 font-mono text-xs lg:w-56"
                >
                    {meta.presets.map((preset) => (
                        <option key={preset.value} value={preset.value}>{preset.label}</option>
                    ))}
                    <option value={CUSTOM}>{t('admin.urls.custom', 'Custom')}</option>
                </Select>
                <Button
                    type="button"
                    variant="secondary"
                    icon="refresh"
                    className="shrink-0"
                    onClick={() => {
                        setForceCustom(false);
                        onChange(defaultValue);
                    }}
                >
                    {t('admin.urls.reset_field', 'Reset')}
                </Button>
            </div>
            <div className="mt-2 space-y-1 lg:pl-[calc(9rem+0.5rem)]">
                <p className="text-xs leading-5 text-neutral-500">{meta.help}</p>
                <p className={cx(ui.muted, 'font-mono text-xs')}>
                    {t('admin.urls.example', 'Example')}: {example}
                </p>
                {error ? <p className="text-xs text-rose-600">{error}</p> : null}
            </div>
        </div>
    );
}

export default function Urls({ schemas, suffix, defaults, tokens }) {
    const form = useForm({
        schemas: {
            product: schemas.product || defaults.product,
            category: schemas.category || defaults.category,
            cms: schemas.cms || defaults.cms,
            brand: schemas.brand || defaults.brand,
            supplier: schemas.supplier || defaults.supplier,
        },
        suffix: suffix || '',
    });
    useSaveOnChange(form, '/admin/urls', {
        textKeys: ['schemas'],
    });

    const tips = [
        t('admin.urls.tip_short', 'Keep paths short and descriptive.'),
        t('admin.urls.tip_keywords', 'Include relevant keywords in the slug ({rewrite}).'),
        t('admin.urls.tip_chars', 'Avoid special characters; use hyphens between words.'),
        t('admin.urls.tip_regenerate', 'Changes apply as you edit. Earlier addresses keep working as redirects.'),
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.urls.title', 'URL Settings')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.urls.subtitle', 'Manage SEO-friendly URLs for products, categories, CMS pages and more. Customize the structure and regenerate URLs when needed.')}
            />
            <StorefrontClubTabs current="urls" />

            <Alert type="warning" className="mb-4">
                {t('admin.urls.seo_warning', 'Changing these addresses changes the URLs search engines already know. The old address keeps working as a redirect, but rankings can still move.')}
            </Alert>

            <div className={cx(ui.formLayoutSplit, 'mb-6')}>
                <div className="min-w-0">
                    <Card
                        as="form"
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                        }}
                    >
                        <SettingsSectionHead
                            className="mb-1"
                            icon="link"
                            title={t('admin.urls.schemas', 'Friendly URL Schemas')}
                            hint={t('admin.urls.schemas_hint', 'Choose how public paths are built. {rewrite} is the slug on the product, category, or page form.')}
                        />
                        <FormErrors errors={form.errors} />

                        {['product', 'category', 'cms', 'brand', 'supplier'].map((type) => (
                            <SchemaRow
                                key={type}
                                type={type}
                                value={form.data.schemas[type]}
                                error={form.errors[`schemas.${type}`]}
                                suffix={form.data.suffix}
                                defaultValue={defaults[type]}
                                onChange={(value) => form.setData('schemas', { ...form.data.schemas, [type]: value })}
                            />
                        ))}

                        <FeatureTile
                            icon="link"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.urls.suffix', 'URL Suffix')}
                            hint={form.errors.suffix || t('admin.urls.suffix_hint', 'Optional ending appended to every generated path (for example .html).')}
                            control={(
                                <Select className="w-36 shrink-0" value={form.data.suffix} onChange={(e) => form.setData('suffix', e.target.value)}>
                                    <option value="">{t('admin.urls.suffix_none', 'None')}</option>
                                    <option value=".html">.html</option>
                                </Select>
                            )}
                        />
                    </Card>
                </div>

                <aside className={ui.formSide}>
                    <Card className="overflow-hidden bg-gradient-to-b from-violet-50/70 via-white to-white">
                        <SettingsSectionHead
                            className="mb-3"
                            icon="sparkles"
                            title={t('admin.urls.tips_title', 'Tips for SEO-friendly URLs')}
                        />
                        <ul className="space-y-2.5">
                            {tips.map((tip) => (
                                <li key={tip} className="flex items-start gap-2.5 text-sm text-neutral-700">
                                    <span className={ui.tipCheck}>
                                        <Icon name="check" className="h-3 w-3" />
                                    </span>
                                    <span>{tip}</span>
                                </li>
                            ))}
                        </ul>
                        <TipsGraphic />
                    </Card>

                    <Card className="space-y-3">
                        <SettingsSectionHead
                            className="mb-3"
                            icon="code"
                            title={t('admin.urls.tokens', 'Available Keyword')}
                        />
                        <ul className="space-y-2.5 text-sm">
                            {(tokens || []).map((row) => (
                                <li key={row.token} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <code className="rounded-md bg-violet-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-violet-700">
                                        {row.token}
                                    </code>
                                    <span className={ui.muted}>{row.hint}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </aside>
            </div>

        </Layout>
    );
}
