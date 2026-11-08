import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    ChoiceCards,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    PageHeader,
    Textarea,
    ui,
    t,
} from '../../../ui';

function emptyTranslation(languageCode, fallback = {}) {
    return {
        language_code: languageCode,
        title: fallback.title || '',
        content: fallback.content || '',
    };
}

function buildTranslations(block, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    Object.entries(block?.translations || {}).forEach(([code, row]) => {
        out[code] = emptyTranslation(code, row || {});
    });
    if (!Object.keys(block?.translations || {}).length && block?.title) {
        out[defaultLanguage] = emptyTranslation(defaultLanguage, {
            title: block.title || '',
            content: block.content || '',
        });
    }

    return out;
}

export default function Form({ block, locations = [] }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(block, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage, block || {});
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        title: defaultTranslation.title || block?.title || '',
        code: block?.code || '',
        location: block?.location || 'home',
        content: defaultTranslation.content || block?.content || '',
        is_enabled: block?.is_enabled ?? true,
        position: block?.position ?? 0,
        translations: initialTranslations,
    });

    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const errorForField = (field) => (
        form.errors[`translations.${selectedLanguage}.${field}`]
        || (selectedLanguage === defaultLanguage ? form.errors[field] : undefined)
    );

    const setTranslationField = (field, value) => {
        const nextRow = {
            ...translationFor(selectedLanguage),
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
        if (selectedLanguage === defaultLanguage) {
            next[field] = value;
        }
        form.setData(next);
    };

    const submit = (e) => {
        e.preventDefault();
        if (block) {
            form.put(`/admin/blocks/${block.id}`);
        } else {
            form.post('/admin/blocks');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={block ? t('admin.blocks.edit_block', 'Edit block') : t('admin.blocks.add_block', 'Add block')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/blocks', label: t('admin.blocks.blocks', 'Blocks') },
                    { label: block ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    block ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.blocks.delete_this_block', 'Delete this block?'))) {
                                    router.delete(`/admin/blocks/${block.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.blocks.block', 'Block')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.blocks.title', 'Title')}
                                required={selectedLanguage === defaultLanguage}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('title')}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('title')}
                                    value={valueForField('title')}
                                    onChange={(e) => setTranslationField('title', e.target.value)}
                                    placeholder={t('admin.blocks.home_hero', 'Home hero')}
                                />
                            </LocalizedField>
                            <Field label={t('admin.blocks.code', 'Code')} error={form.errors.code} hint={t('admin.blocks.stable_key_for_the_theme_leave_blank_to_generate', 'Stable key for the theme. Leave blank to generate from the title.')}>
                                <Input
                                    wide
                                    invalid={!!form.errors.code}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                    placeholder={t('admin.blocks.home_hero_2', 'home_hero')}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.blocks.location', 'Location')} <span className="text-red-500">*</span></h2>
                        </div>
                        <ChoiceCards
                            name="location"
                            options={locations}
                            value={form.data.location}
                            onChange={(location) => form.setData('location', location)}
                        />
                        {form.errors.location ? <p className={ui.fieldError}>{form.errors.location}</p> : null}
                        <p className={`${ui.metaK} mt-3`}>
                            {t('admin.blocks.home_blocks_are_included_on_get_api_v1_catalog_h', 'Home blocks are included on GET /api/v1/catalog/home. Any location is available from GET /api/v1/cms/blocks?location=…')}
                        </p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.blocks.content', 'Content')}</h2>
                        </div>
                        <LocalizedField
                            label={t('admin.blocks.content', 'Content')}
                            languages={languages}
                            currentLanguage={selectedLanguage}
                            onLanguageChange={setSelectedLanguage}
                            error={errorForField('content')}
                            hint={t('admin.blocks.html_or_text_the_theme_decides_how_to_render_it', 'HTML or text. The theme decides how to render it.')}
                        >
                            <Textarea
                                rows={8}
                                value={valueForField('content')}
                                onChange={(e) => setTranslationField('content', e.target.value)}
                                placeholder={t('admin.blocks.welcome_to_the_shop', 'Welcome to the shop')}
                            />
                        </LocalizedField>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.blocks.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <Field label={t('admin.blocks.position', 'Position')} error={form.errors.position} hint={t('admin.blocks.lower_numbers_appear_first', 'Lower numbers appear first.')}>
                            <Input
                                wide
                                type="number"
                                min="0"
                                value={form.data.position}
                                onChange={(e) => form.setData('position', e.target.value)}
                            />
                        </Field>
                    </section>
                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {block ? t('admin.common.save', 'Save changes') : t('admin.blocks.create_block', 'Create block')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/blocks">{t('admin.common.cancel', 'Cancel')}</Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
