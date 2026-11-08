import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    PageHeader,
    Select,
    ui,
    t,
} from '../../../ui';
import Editor from '../../../ui/Editor';

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

function buildTranslations(attribute, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (attribute?.translations || []).forEach((row) => {
        if (!row?.language_code) {
            return;
        }
        out[row.language_code] = {
            ...emptyTranslation(row.language_code),
            ...row,
            language_code: row.language_code,
        };
    });
    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyTranslation(defaultLanguage);
    }
    if (!attribute?.translations?.length && attribute) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: attribute.name || '',
            description: attribute.description || '',
        };
    }

    return out;
}

export default function Form({ attribute, typeOptions = [] }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(attribute, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        name: defaultTranslation.name || '',
        description: defaultTranslation.description || '',
        translations: initialTranslations,
        type: attribute?.type || 'select',
        supports_swatches: attribute?.supports_swatches ?? false,
        is_enabled: attribute?.is_enabled ?? true,
    });

    const fallbackLanguage = languages.find((row) => row.code === defaultLanguage) || null;
    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const hasValueForField = (field) => String(valueForField(field) || '').trim() !== '';
    const fallbackForField = (field) => {
        if (selectedLanguage === defaultLanguage) {
            return '';
        }
        return translationFor(defaultLanguage)[field] || '';
    };
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
        if (selectedLanguage === defaultLanguage) {
            next[field] = value;
        }
        form.setData(next);
    };

    const submit = (e) => {
        e.preventDefault();
        const translations = Object.fromEntries(
            Object.entries(form.data.translations || {}).map(([languageCode, row]) => [
                languageCode,
                {
                    ...emptyTranslation(languageCode),
                    ...row,
                    language_code: languageCode,
                },
            ]),
        );
        const baseTranslation = translations[defaultLanguage] || emptyTranslation(defaultLanguage);
        if (!String(baseTranslation.name || '').trim()) {
            form.setError(`translations.${defaultLanguage}.name`, t('admin.attributes.name_required', 'Attribute name is required.'));
            return;
        }
        form.transform((data) => {
            const { position: _omitPosition, ...rest } = data;

            return {
                ...rest,
                name: baseTranslation.name,
                description: baseTranslation.description,
                translations,
            };
        });
        if (attribute) {
            form.put(`/admin/attributes/${attribute.id}`);
        } else {
            form.post('/admin/attributes');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={attribute ? t('admin.attributes.edit_attribute', 'Edit attribute') : t('admin.attributes.add_attribute_title', 'Add attribute')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/attributes', label: t('admin.attributes.attributes', 'Attributes') },
                    { label: attribute ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    attribute ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                const assigned = Number(attribute.products_count) || 0;
                                if (assigned > 0) {
                                    window.alert(assigned === 1
                                        ? t('admin.attributes.delete_blocked_one', 'This attribute is currently assigned to 1 product. Please remove it from those products before deleting.')
                                        : t('admin.attributes.delete_blocked_many', 'This attribute is currently assigned to :count products. Please remove it from those products before deleting.').replace(':count', String(assigned)));
                                    return;
                                }
                                if (window.confirm(`${t('admin.common.delete', 'Delete')} “${attribute.name}”?`)) {
                                    router.delete(`/admin/attributes/${attribute.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    ) : null
                }
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.common.general', 'General')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.attributes.attribute_name', 'Attribute name')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('name')}
                                hasValue={hasValueForField('name')}
                                fallbackValue={fallbackForField('name')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('name')}
                                    value={valueForField('name')}
                                    onChange={(e) => setTranslationField('name', e.target.value)}
                                    placeholder={t('admin.attributes.name_placeholder', 'e.g. Color')}
                                />
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.attributes.description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('description')}
                                hasValue={hasValueForField('description')}
                                fallbackValue={fallbackForField('description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Editor value={valueForField('description')} onChange={(html) => setTranslationField('description', html)} />
                            </LocalizedField>
                            <Field label={t('admin.attributes.attribute_type', 'Attribute type')} required error={form.errors.type}>
                                <Select wide value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                    {typeOptions.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Checkbox
                                label={t('admin.attributes.supports_swatches', 'Supports swatches')}
                                checked={!!form.data.supports_swatches}
                                onChange={(e) => form.setData('supports_swatches', e.target.checked)}
                            />
                            <p className={ui.muted}>{t('admin.attributes.supports_swatches_hint', 'Enable for color/image swatches on attribute values (e.g. Color).')}</p>
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.common.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.enabled', 'Enabled')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>{t('admin.attributes.disabled_hint', 'Disabled attributes cannot be used for new product combinations. Existing combinations are kept.')}</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {attribute ? t('admin.common.save', 'Save changes') : t('admin.attributes.create_attribute', 'Create attribute')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/attributes">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
