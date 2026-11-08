import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Card,
    ChoiceMenu,
    FeatureTile,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    SettingsCount,
    SettingsSectionHead,
    t,
} from '../../ui';
import { useSaveOnChange } from './useSaveOnChange';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.gdpr.title', 'GDPR') },
];

const categoryFields = [
    ['strictly_necessary', 'admin.gdpr.cat_necessary', 'Strictly necessary'],
    ['basic', 'admin.gdpr.cat_basic', 'Basic interactions and functionalities'],
    ['experience', 'admin.gdpr.cat_experience', 'Experience enhancements'],
    ['measurements', 'admin.gdpr.cat_measurements', 'Measurements'],
    ['targeting', 'admin.gdpr.cat_targeting', 'Targeting and advertising'],
];

export default function Gdpr(props) {
    const { i18n } = usePage().props;
    const languages = i18n?.languages?.length ? i18n.languages : [{
        code: i18n?.default || 'en',
        name: i18n?.default_name || 'English',
    }];
    const defaultLang = i18n?.default || languages[0]?.code || 'en';
    const [lang, setLang] = useState(defaultLang);
    const pages = Array.isArray(props.cms_pages) ? props.cms_pages : [];
    const pageIds = (map, legacyId) => {
        const out = {};
        languages.forEach((row) => {
            const id = map?.[row.code] ?? (row.code === defaultLang ? legacyId : '');
            out[row.code] = id ? String(id) : '';
        });
        return out;
    };
    const form = useForm({
        enabled: !!props.enabled,
        agreement_enabled: !!props.agreement_enabled,
        agreement_pages: pageIds(props.agreement_pages, props.agreement_page_id),
        cookie_notice: !!props.cookie_notice,
        cookie_position: props.cookie_position || 'bottom_left',
        cookie_pages: pageIds(props.cookie_pages, props.cookie_page_id),
        copy: props.copy || {},
    });
    useSaveOnChange(form, '/admin/gdpr', {
        textKeys: ['copy'],
        transform: (data) => {
            const pagesOf = (map) => Object.fromEntries(
                Object.entries(map || {}).map(([code, id]) => [code, id ? Number(id) : null]),
            );
            const agreementPages = pagesOf(data.agreement_pages);
            const cookiePages = pagesOf(data.cookie_pages);
            return {
                ...data,
                agreement_pages: agreementPages,
                cookie_pages: cookiePages,
                agreement_page_id: agreementPages[defaultLang] || null,
                cookie_page_id: cookiePages[defaultLang] || null,
            };
        },
    });

    const text = (key) => form.data.copy?.[lang]?.[key] || '';
    const setText = (key, value) => {
        form.setData('copy', {
            ...form.data.copy,
            [lang]: { ...(form.data.copy[lang] || {}), [key]: value },
        });
    };

    const pageOptions = [
        { value: '', label: t('admin.gdpr.page_none', 'Select a CMS page') },
        ...pages.map((page) => {
            const title = page.titles?.[lang] || page.title;
            return {
                value: String(page.id),
                label: page.slug ? `${title} (/${page.slug})` : title,
            };
        }),
    ];
    const pageValue = (key) => form.data[key]?.[lang] || '';
    const setPage = (key, value) => form.setData(key, { ...(form.data[key] || {}), [lang]: value });
    const languageSelect = (
        <Select className="w-24 shrink-0" value={lang} onChange={(e) => setLang(e.target.value)} aria-label={t('admin.gdpr.language', 'Language')}>
            {languages.map((row) => (
                <option key={row.code} value={row.code}>{row.code.toUpperCase()}</option>
            ))}
        </Select>
    );

    return (
        <Layout>
            <PageHeader
                title={t('admin.gdpr.title', 'GDPR')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.gdpr.subtitle', 'Customer data requests, the signup agreement, and the cookie notice. Titles follow the language you pick. Page text comes from the CMS page in the shopper language.')}
            />
            <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
                <FormErrors errors={form.errors} />
                <Card>
                    <SettingsSectionHead
                        icon="shield"
                        title={t('admin.gdpr.compliance', 'GDPR compliance settings')}
                        hint={t('admin.gdpr.compliance_hint', 'Customers see GDPR Requests in their account only while this is on.')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 1 })}</SettingsCount>}
                    />
                    <FeatureTile
                        icon="shield"
                        tone="bg-sky-100 text-sky-700"
                        title={t('admin.gdpr.enabled', 'Enable GDPR')}
                        hint={t('admin.gdpr.enabled_hint', 'Turns on GDPR Requests, the customer agreement, and the cookie notice.')}
                        value={!!form.data.enabled}
                        onChange={(on) => form.setData('enabled', on)}
                    />
                </Card>
                {form.data.enabled ? (
                    <>
                        <Card>
                            <SettingsSectionHead
                                icon="check"
                                title={t('admin.gdpr.agreement', 'GDPR agreement')}
                                hint={t('admin.gdpr.agreement_hint', 'The title is the checkbox sentence. The CMS page is what Click Here opens.')}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="check"
                                    tone="bg-emerald-100 text-emerald-700"
                                    title={t('admin.gdpr.agreement_enabled', 'Enable customer agreement')}
                                    hint={t('admin.gdpr.agreement_enabled_hint', 'Shows the agreement checkbox on the sign-up form.')}
                                    value={!!form.data.agreement_enabled}
                                    onChange={(on) => form.setData('agreement_enabled', on)}
                                />
                                {form.data.agreement_enabled ? (
                                    <FeatureTile
                                        className="md:col-span-2"
                                        icon="file"
                                        tone="bg-sky-100 text-sky-700"
                                        title={t('admin.gdpr.agreement_copy', 'Checkbox and page')}
                                        hint={pages.length === 0
                                            ? t('admin.gdpr.needs_page', 'Create a page under Design, then choose it here.')
                                            : t('admin.gdpr.agreement_page_hint', 'The language menu sets the title and the CMS page together.')}
                                        control={languageSelect}
                                    >
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <Field label={t('admin.gdpr.agreement_title', 'Title')}>
                                                <Input
                                                    wide
                                                    value={text('agreement_title')}
                                                    onChange={(e) => setText('agreement_title', e.target.value)}
                                                />
                                            </Field>
                                            <Field label={t('admin.gdpr.agreement_page', 'CMS page')}>
                                                <ChoiceMenu
                                                    className="w-full"
                                                    ariaLabel={t('admin.gdpr.agreement_page', 'CMS page')}
                                                    value={pageValue('agreement_pages')}
                                                    options={pageOptions}
                                                    onChange={(value) => setPage('agreement_pages', value)}
                                                />
                                            </Field>
                                        </div>
                                    </FeatureTile>
                                ) : null}
                            </div>
                        </Card>
                        <Card>
                            <SettingsSectionHead
                                icon="info"
                                title={t('admin.gdpr.cookie_notice', 'Cookie notice settings')}
                                hint={t('admin.gdpr.cookie_notice_hint', 'The title is the notice heading. The CMS page is the cookie text.')}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                <FeatureTile
                                    icon="info"
                                    tone="bg-amber-100 text-amber-700"
                                    title={t('admin.gdpr.cookie_notice_enabled', 'Enable cookie notice')}
                                    hint={t('admin.gdpr.cookie_notice_enabled_hint', 'Shows Accept, Reject, and Learn More and Customize.')}
                                    value={!!form.data.cookie_notice}
                                    onChange={(on) => form.setData('cookie_notice', on)}
                                />
                                {form.data.cookie_notice ? (
                                    <>
                                        <FeatureTile
                                            icon="grid"
                                            tone="bg-violet-100 text-violet-700"
                                            title={t('admin.gdpr.cookie_position', 'Cookie block display position')}
                                            control={(
                                                <Select className="w-40 shrink-0" value={form.data.cookie_position} onChange={(e) => form.setData('cookie_position', e.target.value)}>
                                                    <option value="bottom_left">{t('admin.gdpr.pos_bottom_left', 'Bottom left')}</option>
                                                    <option value="bottom_right">{t('admin.gdpr.pos_bottom_right', 'Bottom right')}</option>
                                                    <option value="top_left">{t('admin.gdpr.pos_top_left', 'Top left')}</option>
                                                    <option value="top_right">{t('admin.gdpr.pos_top_right', 'Top right')}</option>
                                                    <option value="center">{t('admin.gdpr.pos_center', 'Center')}</option>
                                                </Select>
                                            )}
                                        />
                                        <FeatureTile
                                            className="md:col-span-2"
                                            icon="file"
                                            tone="bg-sky-100 text-sky-700"
                                            title={t('admin.gdpr.cookie_copy', 'Notice and page')}
                                            hint={pages.length === 0
                                                ? t('admin.gdpr.needs_page', 'Create a page under Design, then choose it here.')
                                                : t('admin.gdpr.cookie_page_hint', 'The language menu sets the title and the CMS page together.')}
                                            control={languageSelect}
                                        >
                                            <div className="grid gap-3 md:grid-cols-2">
                                                <Field label={t('admin.gdpr.cookie_title', 'Title')}>
                                                    <Input
                                                        wide
                                                        value={text('cookie_title')}
                                                        onChange={(e) => setText('cookie_title', e.target.value)}
                                                    />
                                                </Field>
                                                <Field label={t('admin.gdpr.cookie_page', 'CMS page')}>
                                                    <ChoiceMenu
                                                        className="w-full"
                                                        ariaLabel={t('admin.gdpr.cookie_page', 'CMS page')}
                                                        value={pageValue('cookie_pages')}
                                                        options={pageOptions}
                                                        onChange={(value) => setPage('cookie_pages', value)}
                                                    />
                                                </Field>
                                            </div>
                                        </FeatureTile>
                                    </>
                                ) : null}
                            </div>
                        </Card>
                        <Card>
                            <SettingsSectionHead
                                icon="list"
                                title={t('admin.gdpr.cookie_preferences', 'Manage your cookie preferences')}
                                hint={t('admin.gdpr.cookie_preferences_hint', 'Short description beside each choice. The language menu applies to every description.')}
                                extra={languageSelect}
                            />
                            <div className="grid gap-3 md:grid-cols-2">
                                {categoryFields.map(([key, labelKey, label]) => (
                                    <FeatureTile
                                        key={key}
                                        icon="list"
                                        tone="bg-neutral-100 text-neutral-700"
                                        title={t(labelKey, label)}
                                    >
                                        <Input wide value={text(key)} onChange={(e) => setText(key, e.target.value)} />
                                    </FeatureTile>
                                ))}
                            </div>
                        </Card>
                    </>
                ) : null}
            </form>
        </Layout>
    );
}
