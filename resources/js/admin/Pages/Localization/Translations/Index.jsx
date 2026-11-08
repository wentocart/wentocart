import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Icon,
    Input,
    PageHeader,
    Select,
    StatusTabs,
    ui,
    t,
} from '../../../ui';

function valuesFrom(rows) {
    return Object.fromEntries((rows || []).map((row) => [row.key, row.text ?? '']));
}

function isFilled(value) {
    return String(value ?? '').trim() !== '';
}

export default function Index({
    languages = [],
    locale,
    surface,
    page,
    pages = [],
    strings = [],
    filters = {},
    surface_counts = {},
}) {
    const [pageQuery, setPageQuery] = useState('');

    const query = useMemo(() => ({
        language: locale,
        surface: surface || 'admin',
        page: page || '',
        q: filters.q || '',
    }), [locale, surface, page, filters.q]);

    const form = useForm({
        language: locale,
        page,
        strings: valuesFrom(strings),
    });

    useEffect(() => {
        form.setData({
            language: locale,
            page,
            strings: valuesFrom(strings),
        });
        form.clearErrors();
    }, [locale, page, strings]);

    const apply = (next) => {
        const payload = { ...query, ...next };
        if (!payload.q) delete payload.q;
        router.get('/admin/localization/translations', payload, { preserveState: true, preserveScroll: true, replace: true });
    };

    const missingTotal = pages.reduce((sum, item) => sum + (item.missing || 0), 0);
    const current = pages.find((item) => item.id === page);
    const languageName = languages.find((item) => item.code === locale)?.name || locale;

    const visiblePages = useMemo(() => {
        const needle = pageQuery.trim().toLowerCase();
        if (!needle) return pages;
        return pages.filter((item) => item.name.toLowerCase().includes(needle));
    }, [pages, pageQuery]);

    const filledCount = useMemo(() => {
        if (!strings.length) return 0;
        return strings.filter((row) => isFilled(form.data.strings?.[row.key] ?? row.text)).length;
    }, [strings, form.data.strings]);

    const progressPct = strings.length ? Math.round((filledCount / strings.length) * 100) : 0;

    const submit = (e) => {
        e.preventDefault();
        form.put('/admin/localization/translations', { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.translations.title', 'Translations')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.nav.localization', 'Localization') },
                    { label: t('admin.translations.title', 'Translations') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/languages">
                            {t('admin.nav.languages', 'Languages')}
                        </Button>
                        <Button type="submit" form="translations-form" icon="check" busy={form.processing} disabled={!page}>
                            {t('admin.translations.save_page', 'Save this page')}
                        </Button>
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {surface === 'module'
                    ? t('admin.translations.hint_modules', 'Translate each installed extension separately. Extensions ship English keys in i18n/ui.json; edit the right-hand boxes, then save.')
                    : surface === 'theme'
                        ? t('admin.translations.hint_themes', 'Translate each theme separately. Themes may ship i18n/ui.json for admin labels; storefront theme content uses the language fields on Theme settings.')
                        : t('admin.translations.hint', 'Words start in English. Choose Translate into, edit the right-hand boxes, then save. The top-bar current language is what the admin shows.')}
            </p>

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2">
                    <span className={ui.muted}>{t('admin.translations.translate_into', 'Translate into')}</span>
                    <Select
                        value={locale}
                        onChange={(e) => apply({ language: e.target.value, page: '' })}
                        aria-label={t('admin.translations.translate_into', 'Translate into')}
                    >
                        {languages.map((language) => (
                            <option key={language.code} value={language.code}>
                                {language.name} ({language.code})
                            </option>
                        ))}
                    </Select>
                </label>
                <StatusTabs
                    tabs={[
                        { key: 'admin', label: t('admin.translations.admin', 'Admin'), count: surface_counts.admin ?? (surface === 'admin' ? pages.length : undefined) },
                        { key: 'storefront', label: t('admin.translations.storefront', 'Storefront'), count: surface_counts.storefront ?? (surface === 'storefront' ? pages.length : undefined) },
                        { key: 'module', label: t('admin.translations.modules', 'Modules'), count: surface_counts.module ?? (surface === 'module' ? pages.length : undefined) },
                        { key: 'theme', label: t('admin.translations.themes', 'Themes'), count: surface_counts.theme ?? (surface === 'theme' ? pages.length : undefined) },
                    ]}
                    value={query.surface}
                    onChange={(next) => apply({ surface: next, page: '' })}
                />
                {locale !== 'en' && missingTotal > 0 ? (
                    <Badge tone="warning">
                        {missingTotal} {t('admin.translations.missing', 'Missing')}
                    </Badge>
                ) : (
                    <Badge tone="success">{t('admin.translations.done', 'Translated')}</Badge>
                )}
            </div>

            <div className={ui.i18nLayout}>
                <aside className={ui.i18nSide}>
                    <div className={ui.i18nSideHead}>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className={ui.sectionTitle}>{t('admin.translations.pages', 'Pages')}</h2>
                            <span className={ui.cellSub}>{pages.length}</span>
                        </div>
                        <Input
                            wide
                            value={pageQuery}
                            onChange={(e) => setPageQuery(e.target.value)}
                            placeholder={t('admin.translations.search_page', 'Search pages…')}
                            aria-label={t('admin.translations.search_page', 'Search pages…')}
                        />
                    </div>
                    {visiblePages.length === 0 ? (
                        <p className={`${ui.muted} px-4 py-6`}>{t('admin.translations.no_pages', 'No pages in this group yet.')}</p>
                    ) : (
                        <nav className={ui.i18nSideNav}>
                            {visiblePages.map((item) => {
                                const active = item.id === page;
                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        className={active ? ui.i18nPageBtnActive : ui.i18nPageBtn}
                                        onClick={() => apply({ page: item.id })}
                                        title={item.name}
                                    >
                                        <span className="min-w-0 truncate">{item.name}</span>
                                        {item.missing > 0 ? (
                                            <Badge tone="warning" dot={false}>{item.missing}</Badge>
                                        ) : (
                                            <span className={ui.cellSub}>{item.total}</span>
                                        )}
                                    </button>
                                );
                            })}
                        </nav>
                    )}
                </aside>

                <section className={ui.i18nMain}>
                    <div className={ui.i18nMainHead}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className={ui.sectionKicker}>{languageName}</p>
                                <h2 className={`${ui.sectionTitle} text-lg`}>
                                    {current?.name || t('admin.translations.page', 'Page')}
                                </h2>
                                <p className={ui.sectionLead}>
                                    {current
                                        ? `${current.total} ${t('admin.translations.phrases', 'phrases')}`
                                        : ''}
                                </p>
                            </div>
                            {strings.length > 0 ? (
                                <div className="min-w-[10rem] text-right">
                                    <p className="text-sm font-medium text-neutral-800">
                                        {filledCount}/{strings.length}{' '}
                                        {t('admin.translations.translated_of', 'translated')}
                                    </p>
                                    {locale !== 'en' && current?.missing > 0 ? (
                                        <p className={ui.muted}>
                                            {current.missing} {t('admin.translations.left_to_do', 'left')}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                        {strings.length > 0 ? (
                            <div className={ui.i18nProgressTrack} aria-hidden="true">
                                <div className={ui.i18nProgressBar} style={{ width: `${progressPct}%` }} />
                            </div>
                        ) : null}
                        <Input
                            wide
                            defaultValue={query.q}
                            key={`q-${query.q}-${page}`}
                            placeholder={t('admin.translations.search_phrase', 'Search a phrase…')}
                            aria-label={t('admin.translations.search_phrase', 'Search a phrase…')}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    apply({ q: e.currentTarget.value });
                                }
                            }}
                            onBlur={(e) => {
                                if (e.currentTarget.value !== query.q) {
                                    apply({ q: e.currentTarget.value });
                                }
                            }}
                        />
                    </div>

                    <form id="translations-form" onSubmit={submit}>
                        {strings.length === 0 ? (
                            <div className={`${ui.empty} px-5 py-12`}>
                                {t('admin.translations.no_strings', 'No phrases on this page yet.')}
                            </div>
                        ) : (
                            <div className={ui.i18nPhraseList}>
                                {strings.map((row) => {
                                    const value = form.data.strings?.[row.key] ?? '';
                                    const filled = isFilled(value);
                                    const rowClass = locale === 'en'
                                        ? ui.i18nPhraseRow
                                        : (filled ? ui.i18nPhraseRowDone : ui.i18nPhraseRowTodo);

                                    return (
                                        <div key={row.key} className={rowClass}>
                                            <div className="min-w-0">
                                                <p className={ui.i18nPhraseMeta}>
                                                    {t('admin.translations.english_original', 'English original')}
                                                </p>
                                                <p className={ui.i18nPhraseSource}>{row.source}</p>
                                            </div>
                                            <div className="min-w-0 space-y-1.5">
                                                <div className="flex items-center justify-between gap-2">
                                                    <label
                                                        htmlFor={`i18n-${row.key}`}
                                                        className={ui.i18nPhraseMeta}
                                                    >
                                                        {t('admin.translations.your_translation', 'Your translation')}
                                                    </label>
                                                    {locale !== 'en' ? (
                                                        filled ? (
                                                            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700">
                                                                <Icon name="check" className="h-3.5 w-3.5" />
                                                                {t('admin.translations.filled', 'Filled')}
                                                            </span>
                                                        ) : (
                                                            <span className="text-[11px] font-medium text-amber-700">
                                                                {t('admin.translations.needs_translation', 'Needs translation')}
                                                            </span>
                                                        )
                                                    ) : null}
                                                </div>
                                                <Input
                                                    id={`i18n-${row.key}`}
                                                    wide
                                                    value={value}
                                                    placeholder={row.source}
                                                    onChange={(e) => form.setData('strings', {
                                                        ...form.data.strings,
                                                        [row.key]: e.target.value,
                                                    })}
                                                    aria-label={`${row.source} — ${languageName}`}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </form>
                </section>
            </div>
        </Layout>
    );
}
