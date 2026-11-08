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
    SearchSelect,
    ui,
} from '../../../ui';

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        value: '',
    };
}

function buildTranslations(featureValue, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (featureValue?.translations || []).forEach((row) => {
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
    if (!featureValue?.translations?.length && featureValue) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            value: featureValue.value || '',
        };
    }

    return out;
}

export default function Form({ featureValue, features = [], featureId = null }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(featureValue, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        feature_id: featureValue?.feature_id || featureId || '',
        value: defaultTranslation.value || '',
        translations: initialTranslations,
        is_enabled: featureValue?.is_enabled ?? true,
    });

    const selectedFeature = features.find((item) => String(item.id) === String(form.data.feature_id));
    const noFeatures = features.length === 0;
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
        if (!form.data.feature_id) {
            form.setError('feature_id', 'Feature is required.');
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
            form.setError(`translations.${defaultLanguage}.value`, 'Value is required.');
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
        if (featureValue) {
            form.put(`/admin/features/values/${featureValue.id}`);
        } else {
            form.post('/admin/features/values');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={featureValue ? 'Edit feature value' : 'Add feature value'}
                crumbs={[
                    { href: '/admin', label: 'Dashboard' },
                    { href: '/admin/features', label: 'Features' },
                    { href: '/admin/features/values', label: 'Feature values' },
                    { label: featureValue ? 'Edit' : 'Add new' },
                ]}
                actions={
                    featureValue ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                const assigned = Number(featureValue.products_count) || 0;
                                if (assigned > 0) {
                                    window.alert(`This feature value is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please remove it from those products before deleting.`);
                                    return;
                                }
                                if (window.confirm(`Delete “${featureValue.value}”?`)) {
                                    router.delete(`/admin/features/values/${featureValue.id}`);
                                }
                            }}
                        >
                            Delete
                        </Button>
                    ) : null
                }
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>General</h2>
                        </div>
                        <div className="grid gap-3">
                            <SearchSelect
                                label="Feature"
                                required
                                multiple={false}
                                options={features}
                                selectedOptions={selectedFeature ? [selectedFeature] : []}
                                value={form.data.feature_id}
                                onChange={(id) => form.setData('feature_id', id)}
                                placeholder="Search features…"
                                error={form.errors.feature_id}
                                hint={noFeatures ? 'Create a feature first, then add values to it.' : 'A value always belongs to one feature.'}
                            />
                            <LocalizedField
                                label="Value"
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('value')}
                                hasValue={hasValueForField('value')}
                                fallbackValue={fallbackForField('value')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('value')}
                                    value={valueForField('value')}
                                    onChange={(e) => setTranslationField('value', e.target.value)}
                                    placeholder="e.g. 8 GB"
                                />
                            </LocalizedField>
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>Status</h2>
                        </div>
                        <Checkbox
                            label="Enabled"
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>Disabled values cannot be newly assigned to products. Existing assignments are kept.</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit" disabled={noFeatures}>
                                {featureValue ? 'Save changes' : 'Create feature value'}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/features/values">
                                Cancel
                            </Button>
                            {noFeatures ? (
                                <Button type="button" variant="secondary" href="/admin/features/create">
                                    Add Feature
                                </Button>
                            ) : null}
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
