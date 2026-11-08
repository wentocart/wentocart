import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    AiAssist,
    PageHeader,
    Select,
    TagInput,
    Textarea,
    Toggle,
    ui,
    t,
} from '../../ui';
import Editor from '../../ui/Editor';
import PageCanvas from '../../ui/PageCanvas';

const TRANSLATABLE_FIELDS = [
    'title',
    'slug',
    'short_description',
    'body',
    'meta_title',
    'meta_description',
    'meta_keywords',
];

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function keywordsToList(value) {
    if (Array.isArray(value)) {
        return value.map((row) => String(row).trim()).filter(Boolean);
    }
    return String(value || '')
        .split(',')
        .map((row) => row.trim())
        .filter(Boolean);
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        title: '',
        slug: '',
        short_description: '',
        body: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
    };
}

function buildTranslations(page, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    const source = page?.translations || {};
    Object.keys(source).forEach((code) => {
        const row = source[code];
        if (!row) {
            return;
        }
        out[code] = {
            ...emptyTranslation(code),
            ...row,
            language_code: code,
            meta_keywords: keywordsToList(row.meta_keywords).join(', '),
        };
    });
    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyTranslation(defaultLanguage);
    }
    if (!Object.keys(source).length && page?.title) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            title: page.title || '',
            slug: page.slug || '',
            short_description: page.short_description || '',
            body: page.body || '',
            meta_title: page.meta_title || '',
            meta_description: page.meta_description || '',
            meta_keywords: keywordsToList(page.meta_keywords).join(', '),
        };
    }

    return out;
}

export default function Form({ page, options = {} }) {
    const { i18n, shop, app: appMeta } = usePage().props;
    const shopName = shop?.name || appMeta?.name || '';
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(page, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const [visual, setVisual] = useState(false);
    const canvasRef = useRef(null);

    const form = useForm({
        title: defaultTranslation.title || '',
        slug: defaultTranslation.slug || '',
        short_description: defaultTranslation.short_description || '',
        body: defaultTranslation.body || '',
        meta_title: defaultTranslation.meta_title || '',
        meta_description: defaultTranslation.meta_description || '',
        meta_keywords: keywordsToList(defaultTranslation.meta_keywords),
        translations: initialTranslations,
        status: page?.status || 'published',
        is_visible: page?.is_visible ?? true,
        is_enabled: page?.is_enabled ?? true,
        template: (options.templates || []).some((row) => row.value === page?.template)
            ? page.template
            : 'blank',
        cms_category_id: page?.cms_category_id ? String(page.cms_category_id) : '',
        canonical_url: page?.canonical_url || '',
        robots: page?.robots || 'index,follow',
    });

    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const errorForField = (field) => (
        form.errors[`translations.${selectedLanguage}.${field}`]
        || (selectedLanguage === defaultLanguage ? form.errors[field] : undefined)
    );

    const setTranslationField = (field, value) => {
        const current = translationFor(selectedLanguage);
        const nextRow = {
            ...current,
            language_code: selectedLanguage,
            [field]: value,
        };
        const next = {
            ...form.data,
            translations: {
                ...form.data.translations,
                [selectedLanguage]: nextRow,
            },
        };
        if (selectedLanguage === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
            if (field === 'meta_keywords') {
                next.meta_keywords = keywordsToList(value);
            } else {
                next[field] = value;
            }
        }
        form.setData(next);
    };

    const metaTitleLen = String(valueForField('meta_title') || '').length;
    const metaDescLen = String(valueForField('meta_description') || '').length;
    const shortLen = String(valueForField('short_description') || '').length;

    const previewPath = useMemo(() => {
        const slug = valueForField('slug') || slugify(valueForField('title'));
        return slug ? `/${slug}` : '/';
    }, [form.data.translations, selectedLanguage]);

    const applyTemplate = () => {
        const bodies = options.template_bodies || {};
        const html = bodies[form.data.template] ?? '';
        if (!html && form.data.template !== 'blank') {
            return;
        }
        if (valueForField('body') && String(valueForField('body')).replace(/<[^>]+>/g, '').trim() !== '') {
            if (!window.confirm(t('admin.cms.replace_the_current_page_content_with_this_templ', 'Replace the current page content with this template?'))) {
                return;
            }
        }
        setTranslationField('body', html);
    };

    const submit = (e) => {
        e.preventDefault();
        const send = page?.id ? form.put.bind(form) : form.post.bind(form);
        form.transform((data) => {
            const translations = {};
            Object.entries(data.translations || {}).forEach(([code, row]) => {
                translations[code] = {
                    ...row,
                    language_code: code,
                    slug: row.slug || slugify(row.title),
                    meta_keywords: keywordsToList(row.meta_keywords).join(', '),
                };
            });
            if (visual && canvasRef.current?.getValue) {
                const html = canvasRef.current.getValue()?.html || '';
                translations[selectedLanguage] = {
                    ...(translations[selectedLanguage] || emptyTranslation(selectedLanguage)),
                    body: html,
                    language_code: selectedLanguage,
                };
            }
            const def = translations[defaultLanguage] || emptyTranslation(defaultLanguage);
            return {
                ...data,
                title: def.title,
                slug: def.slug || slugify(def.title),
                short_description: def.short_description,
                body: def.body,
                meta_title: def.meta_title,
                meta_description: def.meta_description,
                meta_keywords: keywordsToList(def.meta_keywords).join(', '),
                translations,
                cms_category_id: data.cms_category_id || null,
                is_visible: !!data.is_visible,
                is_enabled: !!data.is_enabled,
            };
        });
        send(page?.id ? `/admin/cms/${page.id}` : '/admin/cms');
    };

    return (
        <Layout>
            <PageHeader
                title={page?.id ? t('admin.cms.edit_cms_page', 'Edit CMS Page') : t('admin.cms.add_cms_page', 'Add CMS Page')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/cms', label: t('admin.cms.pages', 'Pages') },
                    { label: page?.id ? t('admin.common.edit', 'Edit') : t('admin.cms.add_cms_page', 'Add CMS Page') },
                ]}
                actions={
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/cms">
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                        {page?.id ? (
                            <Button
                                type="button"
                                variant="danger"
                                onClick={() => {
                                    if (window.confirm(t('admin.cms.delete_this_page', 'Delete this page?'))) {
                                        router.delete(`/admin/cms/${page.id}`);
                                    }
                                }}
                            >
                                {t('admin.common.delete', 'Delete')}
                            </Button>
                        ) : null}
                        <Button type="submit" form="cms-page-form" icon="check" busy={form.processing}>
                            {t('admin.cms.save_page', 'Save Page')}
                        </Button>
                    </div>
                }
                className="mb-6"
            />

            <form id="cms-page-form" onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.cms.page_information', 'Page Information')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.cms.page_title', 'Page Title')}
                                required={selectedLanguage === defaultLanguage}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('title')}
                                ai={(
                                    <AiAssist
                                        value={valueForField('title')}
                                        field="cms.title"
                                        locale={selectedLanguage}
                                        context={{ title: valueForField('title') }}
                                        onApply={(text) => {
                                            const current = translationFor(selectedLanguage);
                                            const nextSlug = (!page?.id && !current.slug) ? slugify(text) : current.slug;
                                            const nextRow = { ...current, language_code: selectedLanguage, title: text, slug: nextSlug };
                                            const next = {
                                                ...form.data,
                                                translations: { ...form.data.translations, [selectedLanguage]: nextRow },
                                            };
                                            if (selectedLanguage === defaultLanguage) {
                                                next.title = text;
                                                next.slug = nextSlug;
                                            }
                                            form.setData(next);
                                        }}
                                    />
                                )}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('title')}
                                    value={valueForField('title')}
                                    onChange={(e) => {
                                        const title = e.target.value;
                                        const current = translationFor(selectedLanguage);
                                        const nextSlug = (!page?.id && !current.slug) ? slugify(title) : current.slug;
                                        const nextRow = {
                                            ...current,
                                            language_code: selectedLanguage,
                                            title,
                                            slug: nextSlug,
                                        };
                                        const next = {
                                            ...form.data,
                                            translations: {
                                                ...form.data.translations,
                                                [selectedLanguage]: nextRow,
                                            },
                                        };
                                        if (selectedLanguage === defaultLanguage) {
                                            next.title = title;
                                            next.slug = nextSlug;
                                        }
                                        form.setData(next);
                                    }}
                                    placeholder={t('admin.cms.enter_page_title', 'Enter page title')}
                                />
                            </LocalizedField>

                            <LocalizedField
                                label={t('admin.cms.url_slug', 'URL Slug')}
                                required={selectedLanguage === defaultLanguage}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('slug')}
                                hint={t('admin.cms.url_slug_hint', 'This is the {rewrite} token. The public path uses the schema on Configure URLs.')}
                            >
                                <div className={ui.slugRow}>
                                    <span className={ui.slugPrefix}>/</span>
                                    <input
                                        className={ui.slugInput}
                                        value={valueForField('slug')}
                                        onChange={(e) => setTranslationField('slug', slugify(e.target.value))}
                                        placeholder={t('admin.cms.enter_page_url', 'enter-page-url')}
                                        aria-invalid={!!errorForField('slug') || undefined}
                                    />
                                </div>
                            </LocalizedField>

                            <LocalizedField
                                label={t('admin.cms.meta_title', 'Meta Title')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_title')}
                                hint={t('admin.cms.recommended_50_60_characters_for_search_results', 'Recommended 50–60 characters for search results')}
                                ai={(
                                    <AiAssist
                                        value={valueForField('meta_title')}
                                        field="cms.meta_title"
                                        locale={selectedLanguage}
                                        context={{ title: valueForField('title') }}
                                        onApply={(text) => setTranslationField('meta_title', text)}
                                    />
                                )}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('meta_title')}
                                    value={valueForField('meta_title')}
                                    onChange={(e) => setTranslationField('meta_title', e.target.value)}
                                    placeholder={t('admin.cms.seo_title_shown_in_search_engines', 'SEO title shown in search engines')}
                                />
                                <div className={`${ui.charCount} mt-1 text-right`}>{metaTitleLen} / 60</div>
                            </LocalizedField>

                            <LocalizedField
                                label={t('admin.cms.meta_description', 'Meta Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_description')}
                                hint={t('admin.cms.recommended_150_160_characters', 'Recommended 150–160 characters')}
                                ai={(
                                    <AiAssist
                                        value={valueForField('meta_description')}
                                        field="cms.meta_description"
                                        locale={selectedLanguage}
                                        context={{ title: valueForField('title') }}
                                        onApply={(text) => setTranslationField('meta_description', text)}
                                    />
                                )}
                            >
                                <Textarea
                                    rows={3}
                                    invalid={!!errorForField('meta_description')}
                                    value={valueForField('meta_description')}
                                    onChange={(e) => setTranslationField('meta_description', e.target.value)}
                                    placeholder={t('admin.cms.short_summary_for_search_engines', 'Short summary for search engines')}
                                />
                                <div className={`${ui.charCount} mt-1 text-right`}>{metaDescLen} / 160</div>
                            </LocalizedField>

                            <LocalizedField
                                label={t('admin.cms.meta_keywords', 'Meta Keywords')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_keywords')}
                                hint={t('admin.cms.type_a_keyword_and_press_enter_to_add_it', 'Type a keyword and press Enter to add it')}
                            >
                                <TagInput
                                    value={keywordsToList(valueForField('meta_keywords'))}
                                    onChange={(tags) => setTranslationField('meta_keywords', tags.join(', '))}
                                    placeholder={t('admin.cms.type_keyword_enter', 'Type keyword + Enter')}
                                />
                            </LocalizedField>

                            <LocalizedField
                                label={t('admin.cms.short_description_optional', 'Short Description (optional)')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('short_description')}
                                hint={t('admin.cms.this_description_may_be_used_in_listings_or_prev', 'This description may be used in listings or previews.')}
                            >
                                <Textarea
                                    rows={2}
                                    invalid={!!errorForField('short_description')}
                                    value={valueForField('short_description')}
                                    onChange={(e) => setTranslationField('short_description', e.target.value.slice(0, 255))}
                                    placeholder={t('admin.cms.brief_page_summary', 'Brief page summary')}
                                />
                                <div className={`${ui.charCount} mt-1 text-right`}>{shortLen} / 255</div>
                            </LocalizedField>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.cms.page_content', 'Page Content *')}</h2>
                            <div className={ui.toolbar}>
                                <Select
                                    value={form.data.template}
                                    onChange={(e) => form.setData('template', e.target.value)}
                                    aria-label={t('admin.cms.template', 'Template')}
                                >
                                    {(options.templates || []).map((tpl) => (
                                        <option key={tpl.value} value={tpl.value}>{tpl.label}</option>
                                    ))}
                                </Select>
                                <Button type="button" variant="secondary" onClick={applyTemplate}>
                                    {t('admin.cms.apply_template', 'Apply template')}
                                </Button>
                            </div>
                        </div>
                        <p className={`${ui.muted} mb-3`}>
                            {t('admin.cms.pick_a_predefined_template_then_edit_with_the_fu', 'Pick a predefined template, then edit with the full rich-text editor.')}
                        </p>
                        <Toggle
                            label={t('admin.cms.visual_builder', 'Visual builder')}
                            value={visual}
                            onChange={(on) => {
                                if (visual && canvasRef.current?.getValue) {
                                    setTranslationField('body', canvasRef.current.getValue()?.html || '');
                                }
                                setVisual(on);
                            }}
                            hint={t('admin.cms.visual_builder_hint', 'Drag a block from the list onto the page, then click the text to edit it. Switch off to use the rich-text editor.')}
                        />
                        <LocalizedField
                            label={t('admin.cms.content_language', 'Content language')}
                            languages={languages}
                            currentLanguage={selectedLanguage}
                            onLanguageChange={(code) => {
                                if (visual && canvasRef.current?.getValue) {
                                    setTranslationField('body', canvasRef.current.getValue()?.html || '');
                                }
                                setSelectedLanguage(code);
                            }}
                            error={errorForField('body')}
                        >
                            {visual ? (
                                <PageCanvas
                                    key={`${page?.id || 'new'}-${selectedLanguage}`}
                                    html={valueForField('body')}
                                    onReady={(api) => {
                                        canvasRef.current = api;
                                    }}
                                />
                            ) : (
                                <Editor
                                    value={valueForField('body')}
                                    onChange={(html) => setTranslationField('body', html)}
                                    aiField="cms.body"
                                    aiLocale={selectedLanguage}
                                    aiContext={{
                                        title: valueForField('title'),
                                        slug: valueForField('slug'),
                                        template: form.data.template,
                                        shop_name: shopName,
                                    }}
                                />
                            )}
                        </LocalizedField>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.cms.preview', 'Preview')}</h2>
                        </div>
                        <Field label={t('admin.cms.preview_url', 'Preview URL')} hint={t('admin.cms.opens_the_storefront_path_for_this_slug_page_mus', 'Opens the storefront path for this slug (page must be published and visible).')}>
                            <div className={ui.row}>
                                <Input wide readOnly value={previewPath} />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    native
                                    href={previewPath}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {t('admin.cms.preview_page', 'Preview Page')}
                                </Button>
                            </div>
                        </Field>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.cms.page_settings', 'Page Settings')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.cms.status', 'Status')} error={form.errors.status}>
                                <Select
                                    wide
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    {(options.statuses || []).map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label={t('admin.cms.visibility', 'Visibility')} error={form.errors.is_visible}>
                                <Select
                                    wide
                                    value={form.data.is_visible ? '1' : '0'}
                                    onChange={(e) => form.setData('is_visible', e.target.value === '1')}
                                >
                                    <option value="1">{t('admin.cms.visible', 'Visible')}</option>
                                    <option value="0">{t('admin.cms.hidden', 'Hidden')}</option>
                                </Select>
                            </Field>

                            <Field label={t('admin.cms.cms_category', 'CMS Category')} error={form.errors.cms_category_id}>
                                <Select
                                    wide
                                    value={form.data.cms_category_id}
                                    onChange={(e) => form.setData('cms_category_id', e.target.value)}
                                >
                                    <option value="">{t('admin.cms.no_category', 'No category')}</option>
                                    {(options.categories || []).map((cat) => (
                                        <option key={cat.id} value={String(cat.id)}>{cat.name}</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.cms.seo_advanced', 'SEO & Advanced')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.cms.canonical_url', 'Canonical URL')} error={form.errors.canonical_url} hint={t('admin.cms.leave_blank_to_use_the_page_slug_url', 'Leave blank to use the page slug URL')}>
                                <Input
                                    wide
                                    invalid={!!form.errors.canonical_url}
                                    value={form.data.canonical_url}
                                    onChange={(e) => form.setData('canonical_url', e.target.value)}
                                    placeholder="https://example.com/about-us"
                                />
                            </Field>

                            <Field label={t('admin.cms.robots_index', 'Robots Index')} error={form.errors.robots}>
                                <Select
                                    wide
                                    value={form.data.robots}
                                    onChange={(e) => form.setData('robots', e.target.value)}
                                >
                                    {(options.robots || []).map((item) => (
                                        <option key={item.value} value={item.value}>{item.label}</option>
                                    ))}
                                </Select>
                            </Field>

                            <Checkbox
                                label={t('admin.cms.enable_page', 'Enable page')}
                                checked={!!form.data.is_enabled}
                                onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            />
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
