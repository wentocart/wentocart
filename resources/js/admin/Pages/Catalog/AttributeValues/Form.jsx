import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    ColorField,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    PageHeader,
    SearchSelect,
    Select,
    ui,
    t,
} from '../../../ui';

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        value: '',
    };
}

function buildTranslations(attributeValue, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (attributeValue?.translations || []).forEach((row) => {
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
    if (!attributeValue?.translations?.length && attributeValue) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            value: attributeValue.value || '',
        };
    }

    return out;
}

export default function Form({ attributeValue, attributes = [], attributeId = null, swatchTypes = {} }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(attributeValue, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        attribute_id: attributeValue?.attribute_id || attributeId || '',
        value: defaultTranslation.value || '',
        translations: initialTranslations,
        swatch_type: attributeValue?.swatch_type || 'none',
        color_code: attributeValue?.color_code || '',
        swatch_image: attributeValue?.swatch_image || '',
        is_enabled: attributeValue?.is_enabled ?? true,
    });

    const selectedAttribute = attributes.find((item) => String(item.id) === String(form.data.attribute_id));
    const noAttributes = attributes.length === 0;
    const swatchOptions = Object.entries(swatchTypes).map(([value, label]) => ({ value, label }));
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
        if (selectedLanguage === defaultLanguage) {
            next[field] = value;
        }
        form.setData(next);
    };

    const submit = (e) => {
        e.preventDefault();
        if (!form.data.attribute_id) {
            form.setError('attribute_id', t('admin.attribute_values.attribute_required', 'Attribute is required.'));
            return;
        }
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
        if (!String(baseTranslation.value || '').trim()) {
            form.setError(`translations.${defaultLanguage}.value`, t('admin.attribute_values.value_required', 'Value is required.'));
            return;
        }
        form.transform((data) => {
            const { position: _omitPosition, ...rest } = data;

            return {
                ...rest,
                value: baseTranslation.value,
                translations,
            };
        });
        if (attributeValue) {
            form.put(`/admin/attributes/values/${attributeValue.id}`);
        } else {
            form.post('/admin/attributes/values');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={attributeValue ? t('admin.attribute_values.edit_attribute_value', 'Edit attribute value') : t('admin.attribute_values.add_attribute_value_title', 'Add attribute value')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/attributes', label: t('admin.attributes.attributes', 'Attributes') },
                    { href: '/admin/attributes/values', label: t('admin.attribute_values.attribute_values', 'Attribute values') },
                    { label: attributeValue ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    attributeValue ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                const assigned = Number(attributeValue.products_count) || 0;
                                if (assigned > 0) {
                                    window.alert(assigned === 1
                                        ? t('admin.attribute_values.delete_blocked_one', 'This attribute value is currently assigned to 1 product. Please remove it from those products before deleting.')
                                        : t('admin.attribute_values.delete_blocked_many', 'This attribute value is currently assigned to :count products. Please remove it from those products before deleting.').replace(':count', String(assigned)));
                                    return;
                                }
                                if (window.confirm(`${t('admin.common.delete', 'Delete')} “${attributeValue.value}”?`)) {
                                    router.delete(`/admin/attributes/values/${attributeValue.id}`);
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
                            <SearchSelect
                                label={t('admin.attributes.attribute', 'Attribute')}
                                required
                                multiple={false}
                                options={attributes}
                                selectedOptions={selectedAttribute ? [selectedAttribute] : []}
                                value={form.data.attribute_id}
                                onChange={(id) => form.setData('attribute_id', id)}
                                placeholder={t('admin.attribute_values.search_attributes', 'Search attributes…')}
                                error={form.errors.attribute_id}
                                hint={noAttributes
                                    ? t('admin.attribute_values.create_attribute_first', 'Create an attribute first, then add values to it.')
                                    : t('admin.attribute_values.belongs_to_attribute', 'A value always belongs to one attribute.')}
                            />
                            <LocalizedField
                                label={t('admin.attribute_values.value', 'Value')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('value')}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('value')}
                                    value={valueForField('value')}
                                    onChange={(e) => setTranslationField('value', e.target.value)}
                                    placeholder={t('admin.attribute_values.value_placeholder', 'e.g. Black')}
                                />
                            </LocalizedField>
                            {selectedAttribute?.supports_swatches ? (
                                <div className={ui.fieldGrid}>
                                    <Field label={t('admin.attribute_values.swatch_type', 'Swatch type')} error={form.errors.swatch_type}>
                                        <Select wide value={form.data.swatch_type || 'none'} onChange={(e) => form.setData('swatch_type', e.target.value)}>
                                            {swatchOptions.map((opt) => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                    {form.data.swatch_type === 'color' ? (
                                        <Field label={t('admin.attribute_values.color_code', 'Color code')} error={form.errors.color_code}>
                                            <ColorField
                                                value={form.data.color_code}
                                                onChange={(color_code) => form.setData('color_code', color_code)}
                                                placeholder="#000000"
                                                fallback="#000000"
                                            />
                                        </Field>
                                    ) : null}
                                    {form.data.swatch_type === 'image' ? (
                                        <Field label={t('admin.attribute_values.swatch_image_path', 'Swatch image path')} error={form.errors.swatch_image} hint={t('admin.attribute_values.swatch_image_hint', 'Storage path from the media library.')}>
                                            <Input wide value={form.data.swatch_image} onChange={(e) => form.setData('swatch_image', e.target.value)} placeholder="catalog/swatches/black.png" />
                                        </Field>
                                    ) : null}
                                </div>
                            ) : null}
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
                        <p className={`${ui.metaK} mt-2`}>{t('admin.attribute_values.disabled_hint', 'Disabled values cannot be used for new combinations. Existing combinations are kept.')}</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit" disabled={noAttributes}>
                                {attributeValue ? t('admin.common.save', 'Save changes') : t('admin.attribute_values.create_attribute_value', 'Create attribute value')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/attributes/values">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                            {noAttributes ? (
                                <Button type="button" variant="secondary" href="/admin/attributes/create">
                                    {t('admin.attributes.add_attribute', 'Add Attribute')}
                                </Button>
                            ) : null}
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
