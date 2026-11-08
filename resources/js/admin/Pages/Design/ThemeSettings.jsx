import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout from '../Layout';
import { catalogImageUrl } from '../../lib/catalogMedia';
import { adminUrl } from '../../webPath';
import {
    Button, Card, ColorField, FeatureTile, Field, FormErrors, Icon, Input, LocalizedField, PageHeader, Select, Toggle, ui, t, cx,
    Table, THead, Th, Tr, Td, OrderCell, useDragReorder,
} from '../../ui';

function asLocalizedMap(value, defaultLanguage, fieldDefault = '') {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return { ...value };
    }
    if (typeof value === 'string' && value.trim() !== '') {
        return { [defaultLanguage]: value };
    }
    if (typeof fieldDefault === 'string' && fieldDefault.trim() !== '') {
        return { [defaultLanguage]: fieldDefault };
    }

    return { [defaultLanguage]: '' };
}

/** Matches ThemeSettingsCatalog::DEFAULT_LOGO_URL (Classic install logo). */
const DEFAULT_THEME_LOGO_URL = '/themes/classic/assets/merchant/ui/logo.png';
const DEFAULT_THEME_FAVICON_URL = '/themes/classic/assets/img/favicon.png';
/** Matches ThemeSettingsCatalog::LEGACY_DEFAULT_LOGO_URL. */
const LEGACY_DEFAULT_THEME_LOGO_URL = '/themes/classic/assets/merchant/ui/logo.svg';

const COLOR_FIELDS = [
    ['accent', 'Accent'],
    ['accent_soft', 'Accent soft'],
    ['header', 'Top bar'],
    ['sale', 'Sale badge'],
    ['ink', 'Text'],
    ['muted', 'Muted text'],
    ['surface', 'Page background'],
    ['card', 'Card background'],
];

const FONT_OPTIONS = [
    'Plus Jakarta Sans',
    'Heebo',
    'DM Sans',
    'Manrope',
    'Inter',
    'Poppins',
    'Nunito Sans',
    'Outfit',
    'Rubik',
    'Source Sans 3',
    'Work Sans',
    'Lato',
    'Montserrat',
    'Open Sans',
    'Roboto',
    'Space Grotesk',
    'Figtree',
    'Sora',
    'IBM Plex Sans',
    'Mulish',
];

const FONT_SIZE_OPTIONS = ['14px', '15px', '16px', '17px', '18px'];

const DEFAULT_TOP_BAR = {
    enabled: true,
    background: '#3361cf',
    text_color: '#f8fafc',
    show_currency: true,
    show_language: true,
    announcement: '',
    announcement_url: '/catalog',
    links: [],
    contact: { phone: '', label: 'Contact us', url: '/contact', email: '' },
};

const DEFAULT_HEADER = {
    nav_source: 'categories',
    show_search: true,
    show_compare: true,
    show_wishlist: true,
    show_login: true,
    show_cart: true,
    cart_style: 'pill',
    search_placeholder: 'Search for products',
    category_ids: [],
};

const DEFAULT_OPEN = new Set(['presets', 'favicon', 'header', 'typography', 'layout', 'density']);

export default function ThemeSettings({ settings, presets = [], modules = [] }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length
        ? i18n.languages
        : [{ code: defaultLanguage, name: defaultLanguage.toUpperCase(), is_default: true }]);
    const [placeholderLang, setPlaceholderLang] = useState(defaultLanguage);
    const [copyLang, setCopyLang] = useState(defaultLanguage);
    const topBarIn = settings.top_bar || {};
    const headerIn = settings.home?.header || {};
    const [openSections, setOpenSections] = useState(() => new Set(DEFAULT_OPEN));
    const form = useForm({
        preset: settings.preset || 'azure',
        logo_text: settings.logo_text || '',
        logo_url: settings.logo_url || DEFAULT_THEME_LOGO_URL,
        favicon_url: settings.favicon_url || DEFAULT_THEME_FAVICON_URL,
        announcement: settings.announcement || topBarIn.announcement || '',
        announcement_url: settings.announcement_url || topBarIn.announcement_url || '/catalog',
        phone: settings.phone || topBarIn.contact?.phone || '',
        top_bar: {
            ...DEFAULT_TOP_BAR,
            ...topBarIn,
            announcement: topBarIn.announcement || settings.announcement || '',
            announcement_url: topBarIn.announcement_url || settings.announcement_url || '/catalog',
            links: Array.isArray(topBarIn.links) ? topBarIn.links : DEFAULT_TOP_BAR.links,
            contact: {
                ...DEFAULT_TOP_BAR.contact,
                ...(topBarIn.contact || {}),
                phone: topBarIn.contact?.phone || settings.phone || '',
            },
        },
        colors: settings.colors || {},
        radius: settings.radius || '12px',
        font: settings.font || 'Plus Jakarta Sans',
        font_size: settings.font_size || '16px',
        home: {
            sections: settings.home?.sections || {},
            header: {
                ...DEFAULT_HEADER,
                ...headerIn,
                search_placeholder: asLocalizedMap(
                    headerIn.search_placeholder,
                    defaultLanguage,
                    DEFAULT_HEADER.search_placeholder,
                ),
            },
            density: settings.home?.density || {
                products_per_row: 5,
                categories_per_row: 6,
                recent_per_row: 4,
                products_limit: 12,
                categories_limit: 20,
                recent_limit: 4,
            },
            order: (settings.home?.order?.length ? settings.home.order : modules.map((row) => row.code)),
        },
    });
    const placeholderMap = asLocalizedMap(
        form.data.home.header?.search_placeholder,
        defaultLanguage,
        DEFAULT_HEADER.search_placeholder,
    );
    const copyLangCode = languages.some((row) => row.code === copyLang) ? copyLang : defaultLanguage;
    const announcementMap = asLocalizedMap(form.data.top_bar?.announcement, defaultLanguage);
    const contactLabelMap = asLocalizedMap(form.data.top_bar?.contact?.label, defaultLanguage);
    const placeholderLangCode = languages.some((row) => row.code === placeholderLang)
        ? placeholderLang
        : defaultLanguage;

    const toggleSection = (id) => {
        setOpenSections((cur) => {
            const next = new Set(cur);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const setTopBar = (partial) => {
        form.setData('top_bar', { ...form.data.top_bar, ...partial });
    };

    const setTopContact = (partial) => {
        setTopBar({ contact: { ...form.data.top_bar.contact, ...partial } });
    };

    const setHeader = (partial) => {
        form.setData('home', {
            ...form.data.home,
            header: { ...form.data.home.header, ...partial },
        });
    };

    const isCustom = form.data.preset === 'custom';
    const layoutRows = (form.data.home.order || []).map((code) => {
        const row = modules.find((item) => item.code === code) || { code, label: code, hint: '', enabled: true, configure: `/admin/themes/modules/${code}` };
        return {
            ...row,
            enabled: form.data.home.sections?.[code] !== false,
        };
    });

    const drag = useDragReorder(layoutRows, {
        onReorder: (next) => {
            form.setData('home', {
                ...form.data.home,
                order: next.map((row) => row.code),
            });
        },
    });

    const setColor = (key, value) => {
        form.setData('colors', { ...form.data.colors, [key]: value });
    };

    const setSection = (key, value) => {
        form.setData('home', {
            ...form.data.home,
            sections: { ...form.data.home.sections, [key]: value },
        });
    };

    const setDensity = (key, value) => {
        form.setData('home', {
            ...form.data.home,
            density: { ...form.data.home.density, [key]: Number(value) },
        });
    };

    const onPreset = (id) => {
        const next = { ...form.data, preset: id };
        if (id !== 'custom') {
            const map = {
                azure: {
                    accent: '#4545E6', accent_soft: '#EEEEFE', header: '#FFFFFF', footer: '#1A1D23', sale: '#E11D28',
                    ink: '#111111', muted: '#6B7280', surface: '#F4F6F8', card: '#FFFFFF', highlight: '#F97316',
                },
                forest: {
                    accent: '#1C523B', accent_soft: '#E8F0EC', header: '#1C523B', footer: '#1C523B', sale: '#FF4D4D',
                    ink: '#111111', muted: '#6B7280', surface: '#F7F8FA', card: '#FFFFFF', highlight: '#F97316',
                },
                purple: {
                    accent: '#6D3AED', accent_soft: '#EDE7FF', header: '#5B21B6', footer: '#1E1145', sale: '#EF4444',
                    ink: '#111111', muted: '#6B7280', surface: '#F6F7FB', card: '#FFFFFF', highlight: '#F97316',
                },
                marketplace: {
                    accent: '#A855F7', accent_soft: '#F3E8FF', header: '#0F172A', footer: '#0F172A', sale: '#EC4899',
                    ink: '#0F172A', muted: '#64748B', surface: '#F8FAFC', card: '#FFFFFF', highlight: '#F97316',
                },
                dark: {
                    accent: '#C8F542', accent_soft: '#1C2710', header: '#05060A', footer: '#05060A', sale: '#FB7185',
                    ink: '#F4F4F5', muted: '#A1A1AA', surface: '#07080D', card: '#12141C', highlight: '#F97316',
                },
            };
            if (map[id]) {
                next.colors = map[id];
            }
        }
        form.setData(next);
    };

    const productsPerRow = Number(form.data.home.density?.products_per_row || 5);
    const categoriesPerRow = Number(form.data.home.density?.categories_per_row || 6);

    return (
        <Layout>
            <PageHeader
                title={t('admin.theme_settings.title', 'Theme settings')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/themes', label: t('admin.nav.themes', 'Themes') },
                    { label: t('admin.theme_settings.title', 'Theme settings') },
                ]}
                actions={(
                    <Button href="/admin/themes" variant="secondary" icon="arrow-left">
                        {t('admin.theme_settings.back', 'Back to Themes')}
                    </Button>
                )}
            />

            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put('/admin/themes/settings');
                }}
            >
                <div className="space-y-4 pb-20">
                <FormErrors errors={form.errors} />

                <SettingsSection
                    id="favicon"
                    title={t('admin.theme_settings.favicon', 'Favicon')}
                    hint={t('admin.theme_settings.favicon_hint', 'The small icon in the browser tab.')}
                    open={openSections.has('favicon')}
                    onToggle={toggleSection}
                >
                    <FaviconUploadField
                        value={form.data.favicon_url || DEFAULT_THEME_FAVICON_URL}
                        onChange={(url) => form.setData('favicon_url', url)}
                    />
                </SettingsSection>

                <SettingsSection
                    id="presets"
                    title={t('admin.theme_settings.preset', 'Color preset')}
                    hint={t('admin.theme_settings.preset_hint', 'Quick color themes for the storefront.')}
                    open={openSections.has('presets')}
                    onToggle={toggleSection}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        {presets.map((preset) => {
                            const active = form.data.preset === preset.id;
                            return (
                                <button
                                    key={preset.id}
                                    type="button"
                                    onClick={() => onPreset(preset.id)}
                                    className={`rounded-xl border p-4 text-left transition ${active ? 'border-violet-500 bg-violet-50 ring-2 ring-violet-200' : 'border-slate-200 hover:border-slate-300'}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-semibold text-slate-900">{preset.label}</span>
                                        {active ? <span className="text-xs font-medium text-violet-700">{t('admin.theme_settings.selected', 'Selected')}</span> : null}
                                    </div>
                                    <p className="mt-1 text-sm text-slate-600">{preset.description}</p>
                                    {preset.id !== 'custom' && preset.colors ? (
                                        <div className="mt-3 flex gap-1.5">
                                            {['accent', 'header', 'sale', 'accent_soft'].map((key) => (
                                                <span
                                                    key={key}
                                                    className="h-5 w-5 rounded-full border border-black/10"
                                                    style={{ background: preset.colors[key] }}
                                                />
                                            ))}
                                        </div>
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>
                    {isCustom ? (
                        <div className="grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
                            {COLOR_FIELDS.map(([key, label]) => (
                                <HexColorField
                                    key={key}
                                    label={colorLabel(key, label)}
                                    value={form.data.colors?.[key] || ''}
                                    onChange={(v) => setColor(key, v)}
                                />
                            ))}
                        </div>
                    ) : null}
                </SettingsSection>

                <SettingsSection
                    id="top_bar"
                    title={t('admin.theme_settings.top_bar', 'Top bar (Announcement)')}
                    hint={t('admin.theme_settings.top_bar_hint', 'Thin strip above the header: announcement, phone, and links.')}
                    open={openSections.has('top_bar')}
                    onToggle={toggleSection}
                >
                    <div className="grid gap-3 md:grid-cols-2">
                        <SettingToggle
                            icon="bell"
                            tone="bg-violet-100 text-violet-600"
                            label={t('admin.theme_settings.top_bar_enabled', 'Show top bar')}
                            hint={t('admin.theme_settings.top_bar_enabled_hint', 'Announcement, phone, and links above the header.')}
                            value={form.data.top_bar?.enabled !== false}
                            onChange={(v) => setTopBar({ enabled: v })}
                        />
                    </div>
                    {form.data.top_bar?.enabled !== false ? (
                    <>
                    <LocalizedField
                        beside
                        label={t('admin.theme_settings.announcement', 'Announcement text')}
                        languages={languages}
                        currentLanguage={copyLangCode}
                        onLanguageChange={setCopyLang}
                    >
                        <Input
                            wide
                            value={announcementMap[copyLangCode] || ''}
                            onChange={(e) => {
                                const announcement = { ...announcementMap, [copyLangCode]: e.target.value };
                                setTopBar({ announcement });
                                form.setData('announcement', announcement);
                            }}
                        />
                    </LocalizedField>
                    <Field label={t('admin.theme_settings.announcement_url', 'Announcement link')}>
                        <Input
                            wide
                            value={form.data.top_bar?.announcement_url || ''}
                            onChange={(e) => {
                                setTopBar({ announcement_url: e.target.value });
                                form.setData('announcement_url', e.target.value);
                            }}
                        />
                    </Field>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <HexColorField
                            label={t('admin.theme_settings.top_bar_bg', 'Background')}
                            value={form.data.top_bar?.background || ''}
                            onChange={(v) => setTopBar({ background: v })}
                        />
                        <HexColorField
                            label={t('admin.theme_settings.top_bar_text', 'Text color')}
                            value={form.data.top_bar?.text_color || ''}
                            onChange={(v) => setTopBar({ text_color: v })}
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SettingToggle
                            icon="price-tag"
                            tone="bg-amber-100 text-amber-600"
                            label={t('admin.theme_settings.show_currency', 'Show currency')}
                            hint={t('admin.theme_settings.show_currency_hint', 'Let shoppers switch currency from the top bar.')}
                            value={!!form.data.top_bar?.show_currency}
                            onChange={(v) => setTopBar({ show_currency: v })}
                        />
                        <SettingToggle
                            icon="globe"
                            tone="bg-sky-100 text-sky-600"
                            label={t('admin.theme_settings.show_language', 'Show language')}
                            hint={t('admin.theme_settings.show_language_hint', 'Let shoppers switch language from the top bar.')}
                            value={!!form.data.top_bar?.show_language}
                            onChange={(v) => setTopBar({ show_language: v })}
                        />
                    </div>
                    <Field label={t('admin.theme_settings.top_bar_phone', 'Contact phone')}>
                        <Input
                            wide
                            value={form.data.top_bar?.contact?.phone || ''}
                            onChange={(e) => {
                                setTopContact({ phone: e.target.value });
                                form.setData('phone', e.target.value);
                            }}
                        />
                    </Field>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <LocalizedField
                            beside
                            label={t('admin.theme_settings.contact_label', 'Contact label')}
                            languages={languages}
                            currentLanguage={copyLangCode}
                            onLanguageChange={setCopyLang}
                        >
                            <Input
                                wide
                                value={contactLabelMap[copyLangCode] || ''}
                                onChange={(e) => setTopContact({ label: { ...contactLabelMap, [copyLangCode]: e.target.value } })}
                            />
                        </LocalizedField>
                        <Field label={t('admin.theme_settings.contact_url', 'Contact URL')}>
                            <Input wide value={form.data.top_bar?.contact?.url || ''} onChange={(e) => setTopContact({ url: e.target.value })} />
                        </Field>
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-800">{t('admin.theme_settings.top_bar_links', 'Custom links')}</h3>
                            <Select
                                className="h-10 w-24 shrink-0"
                                aria-label={t('admin.theme_settings.link_language', 'Link language')}
                                value={copyLangCode}
                                onChange={(e) => setCopyLang(e.target.value)}
                            >
                                {languages.map((row) => (
                                    <option key={row.code} value={row.code}>{row.code.toUpperCase()}</option>
                                ))}
                            </Select>
                        </div>
                        {(form.data.top_bar?.links || []).map((link, index) => (
                            <div key={index} className="grid gap-2 rounded-lg border border-slate-100 p-3 sm:grid-cols-[1fr_1fr_auto]">
                                <Field label={t('admin.theme_settings.link_label', 'Link label')}>
                                    <Input
                                        wide
                                        value={asLocalizedMap(link.label, defaultLanguage)[copyLangCode] || ''}
                                        onChange={(e) => {
                                            const links = [...(form.data.top_bar.links || [])];
                                            const map = asLocalizedMap(link.label, defaultLanguage);
                                            links[index] = { ...links[index], label: { ...map, [copyLangCode]: e.target.value } };
                                            setTopBar({ links });
                                        }}
                                    />
                                </Field>
                                <Field label={t('admin.theme_settings.url', 'URL')}>
                                    <Input
                                        wide
                                        value={link.url || ''}
                                        onChange={(e) => {
                                            const links = [...(form.data.top_bar.links || [])];
                                            links[index] = { ...links[index], url: e.target.value };
                                            setTopBar({ links });
                                        }}
                                    />
                                </Field>
                                <div className="flex items-end gap-2 pb-0.5">
                                    <SettingToggle
                                        compact
                                        label={t('admin.theme_settings.new_tab', 'New tab')}
                                        value={!!link.open_in_new_tab}
                                        onChange={(v) => {
                                            const links = [...(form.data.top_bar.links || [])];
                                            links[index] = { ...links[index], open_in_new_tab: v };
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setTopBar({ links: (form.data.top_bar.links || []).filter((_, i) => i !== index) })}
                                    >
                                        {t('admin.common.remove', 'Remove')}
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {(form.data.top_bar?.links || []).length < 8 ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setTopBar({ links: [...(form.data.top_bar.links || []), { label: '', url: '/', open_in_new_tab: false }] })}
                            >
                                {t('admin.theme_settings.add_link', 'Add link')}
                            </Button>
                        ) : null}
                    </div>
                    </>
                    ) : null}
                </SettingsSection>

                <SettingsSection
                    id="header"
                    title={t('admin.theme_settings.header', 'Header')}
                    hint={t('admin.theme_settings.header_hint', 'Logo, search, and the menu in the header.')}
                    open={openSections.has('header')}
                    onToggle={toggleSection}
                >
                    <LocalizedField
                        beside
                        label={t('admin.theme_settings.logo_text', 'Logo text')}
                        languages={languages}
                        currentLanguage={copyLangCode}
                        onLanguageChange={setCopyLang}
                        hint={t('admin.theme_settings.logo_hint', 'Leave blank to use the shop name.')}
                    >
                        <Input
                            wide
                            value={asLocalizedMap(form.data.logo_text, defaultLanguage)[copyLangCode] || ''}
                            onChange={(e) => form.setData('logo_text', {
                                ...asLocalizedMap(form.data.logo_text, defaultLanguage),
                                [copyLangCode]: e.target.value,
                            })}
                        />
                    </LocalizedField>
                    <LogoUploadField
                        value={form.data.logo_url || ''}
                        onChange={(url) => form.setData('logo_url', url)}
                    />
                    <LocalizedField
                        beside
                        label={t('admin.theme_settings.search_placeholder', 'Search placeholder')}
                        languages={languages}
                        currentLanguage={placeholderLangCode}
                        onLanguageChange={setPlaceholderLang}
                        hint={t('admin.theme_settings.search_placeholder_hint', 'Text inside the header search box.')}
                    >
                        <Input
                            wide
                            value={placeholderMap[placeholderLangCode] || ''}
                            onChange={(e) => setHeader({
                                search_placeholder: {
                                    ...placeholderMap,
                                    [placeholderLangCode]: e.target.value,
                                },
                            })}
                        />
                    </LocalizedField>
                    <div className="grid items-stretch gap-3 md:grid-cols-2">
                        <SettingToggle fill icon="search" tone="bg-violet-100 text-violet-600" label={t('admin.theme_settings.show_search', 'Search')} hint={t('admin.theme_settings.show_search_hint', 'Search box in the header.')} value={form.data.home.header?.show_search !== false} onChange={(v) => setHeader({ show_search: v })} />
                        <SettingToggle fill icon="compare" tone="bg-sky-100 text-sky-600" label={t('admin.theme_settings.show_compare', 'Compare')} hint={t('admin.theme_settings.show_compare_hint', 'Compare icon in the header.')} value={form.data.home.header?.show_compare !== false} onChange={(v) => setHeader({ show_compare: v })} />
                        <SettingToggle fill icon="heart" tone="bg-rose-100 text-rose-600" label={t('admin.theme_settings.show_wishlist', 'Wishlist')} hint={t('admin.theme_settings.show_wishlist_hint', 'Wishlist icon in the header.')} value={form.data.home.header?.show_wishlist !== false} onChange={(v) => setHeader({ show_wishlist: v })} />
                        <SettingToggle fill icon="user" tone="bg-amber-100 text-amber-600" label={t('admin.theme_settings.show_login', 'Account')} hint={t('admin.theme_settings.show_login_hint', 'Sign in and account menu.')} value={form.data.home.header?.show_login !== false} onChange={(v) => setHeader({ show_login: v })} />
                        <SettingToggle fill icon="cart" tone="bg-emerald-100 text-emerald-600" label={t('admin.theme_settings.show_cart', 'Cart')} hint={t('admin.theme_settings.show_cart_hint', 'Cart icon in the header.')} value={form.data.home.header?.show_cart !== false} onChange={(v) => setHeader({ show_cart: v })} />
                        <CartStylePicker
                            value={form.data.home.header?.cart_style || 'pill'}
                            onChange={(cart_style) => setHeader({ cart_style })}
                        />
                    </div>
                    <Field label={t('admin.theme_settings.nav_source', 'Header menu')}>
                        <Select wide value={form.data.home.header?.nav_source || 'categories'} onChange={(e) => setHeader({ nav_source: e.target.value })}>
                            <option value="categories">{t('admin.theme_settings.nav_categories', 'Category menu')}</option>
                            <option value="main_menu">{t('admin.theme_settings.nav_main_menu', 'Custom menu')}</option>
                        </Select>
                    </Field>
                    {form.data.home.header?.nav_source === 'main_menu' ? (
                        <Button href="/admin/modules/weblytic/mainmenu/configure" variant="secondary">
                            {t('admin.theme_settings.open_main_menu', 'Edit custom menu')}
                        </Button>
                    ) : (
                        <Button href="/admin/themes/modules/header" variant="secondary">
                            {t('admin.theme_settings.configure_header', 'Choose categories')}
                        </Button>
                    )}
                </SettingsSection>

                <SettingsSection
                    id="typography"
                    title={t('admin.theme_settings.typography', 'Typography & corners')}
                    hint={t('admin.theme_settings.typography_hint', 'Storefront body font, base size, and card corner radius.')}
                    open={openSections.has('typography')}
                    onToggle={toggleSection}
                >
                    <div className="grid gap-3 lg:grid-cols-[1fr_minmax(220px,280px)]">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label={t('admin.theme_settings.font', 'Font')} error={form.errors.font}>
                                <Select wide value={form.data.font} onChange={(e) => form.setData('font', e.target.value)}>
                                    {FONT_OPTIONS.map((font) => (
                                        <option key={font} value={font}>{font}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.theme_settings.font_size', 'Font size')} error={form.errors.font_size}>
                                <Select wide value={form.data.font_size || '16px'} onChange={(e) => form.setData('font_size', e.target.value)}>
                                    {FONT_SIZE_OPTIONS.map((size) => (
                                        <option key={size} value={size}>{size}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.theme_settings.radius', 'Corner radius')} error={form.errors.radius}>
                                <Select wide value={form.data.radius} onChange={(e) => form.setData('radius', e.target.value)}>
                                    <option value="8px">8px</option>
                                    <option value="12px">12px</option>
                                    <option value="14px">14px</option>
                                    <option value="16px">16px</option>
                                    <option value="20px">20px</option>
                                </Select>
                            </Field>
                        </div>
                        <FontPreview font={form.data.font} size={form.data.font_size || '16px'} radius={form.data.radius} />
                    </div>
                </SettingsSection>

                <SettingsSection
                    id="layout"
                    title={t('admin.theme_settings.layout', 'Homepage layout')}
                    hint={t('admin.theme_settings.layout_hint', 'Drag to change the order after the header. Toggle a row off to hide it. Configure edits that module’s content.')}
                    open={openSections.has('layout')}
                    onToggle={toggleSection}
                >
                    <Table>
                        <THead>
                            <tr>
                                <Th>{t('admin.common.position', 'Pos')}</Th>
                                <Th>{t('admin.theme_settings.module', 'Module')}</Th>
                                <Th>{t('admin.common.enabled', 'Enabled')}</Th>
                                <Th>{t('admin.common.actions', 'Actions')}</Th>
                            </tr>
                        </THead>
                        <tbody>
                            {drag.orderedRows.map((row, index) => (
                                <Tr key={row.code} {...drag.rowProps(index)}>
                                    <Td>
                                        <OrderCell
                                            position={index + 1}
                                            canReorder={drag.canReorder}
                                            dragHandleProps={drag.dragHandleProps(index, row.label)}
                                        />
                                    </Td>
                                    <Td>
                                        <div className="font-medium text-slate-900">{row.code === 'hero' ? t('admin.theme_settings.hero_banner', 'Hero Banner') : row.label}</div>
                                        <p className={ui.muted}>{row.code === 'hero' ? t('admin.theme_settings.hero_row_hint', 'Pick layout 1 / 2 / 3 and configure it.') : row.hint}</p>
                                    </Td>
                                    <Td>
                                        <Toggle
                                            label=""
                                            value={row.enabled}
                                            onChange={(v) => setSection(row.code, v)}
                                        />
                                    </Td>
                                    <Td>
                                        <Button href={row.configure} variant="secondary">
                                            {t('admin.theme_settings.configure', 'Configure')}
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </SettingsSection>

                <SettingsSection
                    id="footer"
                    title={t('admin.theme_settings.footer', 'Footer')}
                    hint={t('admin.theme_settings.footer_hint', 'The footer stays at the bottom of every page. Edit columns and links on its Configure screen.')}
                    open={openSections.has('footer')}
                    onToggle={toggleSection}
                >
                    <Button href="/admin/themes/modules/footer" variant="secondary">
                        {t('admin.theme_settings.configure_footer', 'Configure footer')}
                    </Button>
                </SettingsSection>

                <SettingsSection
                    id="density"
                    title={t('admin.theme_settings.density', 'Items per row')}
                    hint={t('admin.theme_settings.density_hint', 'Controls how many cards fit across Featured, New, Bestsellers, and Shop by category carousels on desktop. Narrower screens still show fewer.')}
                    open={openSections.has('density')}
                    onToggle={toggleSection}
                >
                    <div className="grid gap-4 lg:grid-cols-2">
                        <div className="space-y-3">
                            <Field
                                label={t('admin.theme_settings.products_per_row', 'Products per row')}
                                hint={t('admin.theme_settings.products_per_row_hint', 'Affects Featured Products, New Products, and Bestsellers on the homepage.')}
                            >
                                <Select wide value={String(productsPerRow)} onChange={(e) => setDensity('products_per_row', e.target.value)}>
                                    {[2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}
                                </Select>
                            </Field>
                            <DensityPreview
                                count={productsPerRow}
                                label={t('admin.theme_settings.products_preview', 'Desktop product row preview')}
                            />
                        </div>
                        <div className="space-y-3">
                            <Field
                                label={t('admin.theme_settings.categories_per_row', 'Categories per row')}
                                hint={t('admin.theme_settings.categories_per_row_hint', 'Affects the Shop by Featured Categories carousel.')}
                            >
                                <Select wide value={String(categoriesPerRow)} onChange={(e) => setDensity('categories_per_row', e.target.value)}>
                                    {[3, 4, 5, 6, 7, 8].map((n) => <option key={n} value={n}>{n}</option>)}
                                </Select>
                            </Field>
                            <DensityPreview
                                count={categoriesPerRow}
                                label={t('admin.theme_settings.categories_preview', 'Desktop category row preview')}
                            />
                        </div>
                    </div>
                </SettingsSection>
                </div>

                <div className={ui.formStickyBar}>
                    <div className={ui.formStickyBarActions}>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {t('admin.theme_settings.save', 'Save theme settings')}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                if (window.confirm(t('admin.theme_settings.reset_confirm', 'Reset to theme defaults? Your customizations will be cleared.'))) {
                                    router.post('/admin/themes/settings/reset');
                                }
                            }}
                        >
                            {t('admin.theme_settings.reset', 'Reset to defaults')}
                        </Button>
                    </div>
                </div>
            </form>
        </Layout>
    );
}

function colorLabel(key, fallback) {
    switch (key) {
        case 'accent': return t('admin.theme_settings.color_accent', 'Accent');
        case 'accent_soft': return t('admin.theme_settings.color_accent_soft', 'Accent soft');
        case 'header': return t('admin.theme_settings.color_header', 'Top bar');
        case 'sale': return t('admin.theme_settings.color_sale', 'Sale badge');
        case 'ink': return t('admin.theme_settings.color_ink', 'Text');
        case 'muted': return t('admin.theme_settings.color_muted', 'Muted text');
        case 'surface': return t('admin.theme_settings.color_surface', 'Page background');
        case 'card': return t('admin.theme_settings.color_card', 'Card background');
        default: return fallback;
    }
}

function CartGlyph() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0" />
        </svg>
    );
}

function CartLook({ style }) {
    const badge = (
        <span className="absolute -right-1.5 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-amber-500 px-1 text-[10px] font-bold leading-none text-white">2</span>
    );

    if (style === 'icon') {
        return (
            <span className="relative inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-neutral-950 text-white">
                <CartGlyph />
                {badge}
            </span>
        );
    }

    return (
        <span className="relative inline-flex h-7 shrink-0 items-center gap-1.5 rounded-full bg-neutral-950 pl-2.5 pr-3 text-white">
            <CartGlyph />
            <span className="text-[11px] font-semibold tracking-tight">0.00</span>
            {badge}
        </span>
    );
}

function CartStylePicker({ value, onChange }) {
    const current = value === 'icon' ? 'icon' : 'pill';
    const options = [
        { id: 'pill', label: t('admin.theme_settings.cart_pill', 'Pill') },
        { id: 'icon', label: t('admin.theme_settings.cart_icon', 'Icon only') },
    ];

    return (
        <div className="flex h-full items-center gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3.5">
            <div className="shrink-0 text-sm font-semibold text-neutral-900">{t('admin.theme_settings.cart_style', 'Cart style')}</div>
            <div className="ml-auto flex min-w-0 flex-1 items-center justify-end gap-2">
                {options.map((option) => {
                    const active = current === option.id;

                    return (
                        <button
                            key={option.id}
                            type="button"
                            onClick={() => onChange(option.id)}
                            aria-pressed={active}
                            className={cx(
                                'flex min-w-0 items-center justify-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-medium',
                                active
                                    ? 'border-violet-600 bg-violet-50 text-violet-700'
                                    : 'border-neutral-200 bg-neutral-50 text-neutral-600 hover:border-violet-300',
                            )}
                        >
                            <CartLook style={option.id} />
                            <span className="truncate">{option.label}</span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function SettingToggle({ icon, tone, label, hint, value, onChange, compact = false, fill = false }) {
    if (compact) {
        return (
            <div className="flex items-center gap-2">
                <Toggle value={value} onChange={onChange} ariaLabel={label} />
                <span className={ui.label}>{label}</span>
            </div>
        );
    }

    return (
        <FeatureTile
            fill={fill}
            className={fill ? 'h-full' : undefined}
            icon={icon}
            tone={tone}
            title={label}
            hint={hint}
            value={value}
            onChange={onChange}
        />
    );
}

function SettingsSection({ id, title, hint, open, onToggle, children }) {
    return (
        <Card padded={false} className="overflow-hidden">
            <button
                type="button"
                className={open ? ui.groupAccordionBtnOpen : ui.groupAccordionBtn}
                aria-expanded={open}
                onClick={() => onToggle(id)}
            >
                <span className={ui.cellStack}>
                    <span className={ui.cellStrong}>{title}</span>
                    {hint ? <span className={ui.cellSub}>{hint}</span> : null}
                </span>
                <Icon name={open ? 'chevron-down' : 'chevron-right'} className="shrink-0 transition-transform duration-200" />
            </button>
            <div className={cx(ui.accordion, open ? ui.accordionOpen : ui.accordionClosed)}>
                <div className="overflow-hidden">
                    {open ? <div className={cx(ui.groupAccordionBody, 'space-y-4 pt-0')}>{children}</div> : null}
                </div>
            </div>
        </Card>
    );
}

function HexColorField({ label, value, onChange, disabled = false }) {
    return (
        <Field label={label}>
            <ColorField
                value={value}
                onChange={onChange}
                disabled={disabled}
                placeholder="#111111"
                fallback="#111111"
                ariaLabel={`${label} picker`}
            />
        </Field>
    );
}

function FontPreview({ font, size, radius }) {
    useEffect(() => {
        const id = 'wento-theme-font-preview';
        let link = document.getElementById(id);
        if (!link) {
            link = document.createElement('link');
            link.id = id;
            link.rel = 'stylesheet';
            document.head.appendChild(link);
        }
        const family = encodeURIComponent(font).replace(/%20/g, '+');
        link.href = `https://fonts.googleapis.com/css2?family=${family}:wght@400;600;700&display=swap`;
    }, [font]);

    return (
        <div
            className="rounded-xl border border-slate-200 bg-slate-50 p-4"
            style={{ fontFamily: `'${font}', ui-sans-serif, system-ui, sans-serif`, fontSize: size }}
        >
            <p className="text-xs uppercase tracking-wide text-slate-500">
                {t('admin.theme_settings.font_preview', 'Preview')}
            </p>
            <p className="mt-2 font-semibold text-slate-900" style={{ fontSize: `calc(${size} + 4px)` }}>
                {font}
            </p>
            <p className="mt-1 text-slate-600">
                {t('admin.theme_settings.font_preview_sample', 'The quick brown fox jumps over the lazy dog. 0123456789')}
            </p>
            <div
                className="mt-3 border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm"
                style={{ borderRadius: radius }}
            >
                {t('admin.theme_settings.radius_preview', 'Card corner sample')}
            </div>
        </div>
    );
}

function DensityPreview({ count, label }) {
    const n = Math.max(1, Number(count) || 1);
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="mb-2 text-xs text-slate-500">{label}</p>
            <div className="flex gap-1.5">
                {Array.from({ length: n }).map((_, index) => (
                    <div
                        key={index}
                        className="h-12 flex-1 rounded-md border border-slate-200 bg-white shadow-sm"
                    />
                ))}
            </div>
        </div>
    );
}

function csrfHeaders() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const xsrf = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(meta ? { 'X-CSRF-TOKEN': meta } : {}),
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
    };
}

function uploadErrorMessage(payload, status) {
    if (payload?.errors?.image?.[0]) {
        return payload.errors.image[0];
    }
    if (payload?.message) {
        return payload.message;
    }
    if (status === 413) {
        return t('admin.theme_settings.upload_too_large', 'That image is too large for the server upload limit.');
    }
    return t('admin.theme_settings.upload_failed', 'Image upload failed.');
}

function LogoUploadField({ value, onChange }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const [localPreview, setLocalPreview] = useState('');
    const [previewBroken, setPreviewBroken] = useState(false);

    const storedUrl = String(value || '').trim() || DEFAULT_THEME_LOGO_URL;
    const previewSrc = localPreview || (!previewBroken ? catalogImageUrl(storedUrl) : '');
    const canRemoveLogo = Boolean(localPreview || (
        storedUrl
        && storedUrl !== DEFAULT_THEME_LOGO_URL
        && storedUrl !== LEGACY_DEFAULT_THEME_LOGO_URL
    ));

    useEffect(() => {
        setPreviewBroken(false);
    }, [value]);

    useEffect(() => () => {
        if (localPreview) {
            URL.revokeObjectURL(localPreview);
        }
    }, [localPreview]);

    const clearLocalPreview = () => {
        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return '';
        });
    };

    const upload = async (event) => {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return URL.createObjectURL(file);
        });
        setPreviewBroken(false);
        setUploading(true);
        setError('');
        try {
            const body = new FormData();
            body.append('image', file);
            // Use fetch (not axios) so FormData stays multipart — axios can JSON-encode it
            // when a Content-Type: application/json default is present.
            const response = await fetch(adminUrl('/admin/themes/settings/logo'), {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                clearLocalPreview();
                setError(uploadErrorMessage(payload, response.status));
                return;
            }
            clearLocalPreview();
            onChange(payload.url || DEFAULT_THEME_LOGO_URL);
        } catch (uploadError) {
            clearLocalPreview();
            setError(uploadError?.message || t('admin.theme_settings.upload_failed', 'Image upload failed.'));
        } finally {
            setUploading(false);
            event.target.value = '';
        }
    };

    const removeCustomLogo = () => {
        clearLocalPreview();
        setPreviewBroken(false);
        // Restore Classic install default — do not blank the setting (normalize would map '' → /catalog).
        onChange(DEFAULT_THEME_LOGO_URL);
    };

    return (
        <Field label={t('admin.theme_settings.logo', 'Logo')} error={error}>
            <div className="space-y-3">
                <Input
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,.svg"
                    disabled={uploading}
                    onChange={upload}
                />
                {uploading ? <span className={ui.muted}>{t('admin.theme_settings.uploading', 'Uploading…')}</span> : null}
                <div className="flex items-start gap-2">
                    <div className="flex h-24 w-full max-w-sm items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4">
                        {previewSrc ? (
                            <img
                                src={previewSrc}
                                alt=""
                                className="max-h-20 max-w-full object-contain"
                                onError={() => {
                                    if (localPreview) {
                                        clearLocalPreview();
                                    } else {
                                        setPreviewBroken(true);
                                    }
                                }}
                            />
                        ) : (
                            <span className={ui.muted}>{t('admin.theme_settings.logo_preview_empty', 'Logo preview')}</span>
                        )}
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        icon="trash"
                        disabled={!canRemoveLogo}
                        aria-label={t('admin.theme_settings.remove_logo', 'Remove logo')}
                        title={canRemoveLogo
                            ? t('admin.theme_settings.remove_logo', 'Remove logo')
                            : t('admin.theme_settings.remove_logo_default', 'Default logo')}
                        onClick={removeCustomLogo}
                    />
                </div>
                <p className={ui.muted}>
                    {t('admin.theme_settings.logo_upload_hint', 'Upload a PNG, JPG, WebP, or SVG (max about 2 MB). Save theme settings after uploading.')}
                </p>
            </div>
        </Field>
    );
}

function FaviconUploadField({ value, onChange }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const [localPreview, setLocalPreview] = useState('');
    const [previewBroken, setPreviewBroken] = useState(false);

    const storedUrl = String(value || '').trim() || DEFAULT_THEME_FAVICON_URL;
    const previewSrc = localPreview || (!previewBroken && storedUrl ? catalogImageUrl(storedUrl) : '');
    const usingDefault = storedUrl === DEFAULT_THEME_FAVICON_URL;

    useEffect(() => {
        setPreviewBroken(false);
    }, [value]);

    useEffect(() => () => {
        if (localPreview) {
            URL.revokeObjectURL(localPreview);
        }
    }, [localPreview]);

    const clearLocalPreview = () => {
        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return '';
        });
    };

    const upload = async (event) => {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }

        setLocalPreview((prev) => {
            if (prev) {
                URL.revokeObjectURL(prev);
            }

            return URL.createObjectURL(file);
        });
        setPreviewBroken(false);
        setUploading(true);
        setError('');
        try {
            const body = new FormData();
            body.append('image', file);
            const response = await fetch(adminUrl('/admin/themes/settings/favicon'), {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                clearLocalPreview();
                setError(uploadErrorMessage(payload, response.status));
                return;
            }
            clearLocalPreview();
            onChange(payload.url || DEFAULT_THEME_FAVICON_URL);
        } catch (uploadError) {
            clearLocalPreview();
            setError(uploadError?.message || t('admin.theme_settings.upload_failed', 'Image upload failed.'));
        } finally {
            setUploading(false);
            event.target.value = '';
        }
    };

    return (
        <Field label={t('admin.theme_settings.favicon_image', 'Favicon image')} error={error}>
            <div className="space-y-3">
                <Input
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif"
                    disabled={uploading}
                    onChange={upload}
                />
                {uploading ? <span className={ui.muted}>{t('admin.theme_settings.uploading', 'Uploading…')}</span> : null}
                <div className="flex items-start gap-2">
                    <div className="flex h-16 w-16 items-center justify-center rounded-xl border border-slate-200 bg-slate-50">
                        {previewSrc ? (
                            <img
                                src={previewSrc}
                                alt=""
                                className="h-10 w-10 object-contain"
                                onError={() => {
                                    if (localPreview) {
                                        clearLocalPreview();
                                    } else {
                                        setPreviewBroken(true);
                                    }
                                }}
                            />
                        ) : (
                            <span className="text-xs text-slate-400">{t('admin.theme_settings.favicon_empty', 'None')}</span>
                        )}
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        icon="trash"
                        disabled={usingDefault && !localPreview}
                        aria-label={t('admin.theme_settings.remove_favicon', 'Remove favicon')}
                        title={usingDefault
                            ? t('admin.theme_settings.remove_favicon_default', 'Default icon')
                            : t('admin.theme_settings.remove_favicon', 'Remove favicon')}
                        onClick={() => {
                            clearLocalPreview();
                            setPreviewBroken(false);
                            onChange(DEFAULT_THEME_FAVICON_URL);
                        }}
                    />
                </div>
                <p className={ui.muted}>
                    {t('admin.theme_settings.favicon_upload_hint', 'Square PNG, JPG, WebP, or GIF (max 2 MB). Save theme settings after uploading.')}
                </p>
            </div>
        </Field>
    );
}
