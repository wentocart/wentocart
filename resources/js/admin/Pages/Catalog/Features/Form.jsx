import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    LocalizedField,
    PageHeader,
    SearchSelect,
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

function buildTranslations(feature, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (feature?.translations || []).forEach((row) => {
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
    if (!feature?.translations?.length && feature) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: feature.name || '',
            description: feature.description || '',
        };
    }

    return out;
}

export default function Form({ feature, multivendor = null }) {
    const isMultivendor = !!multivendor;
    const featuresIndexPath = isMultivendor ? multivendor.indexPath : '/admin/features';
    const multivendorDashboardPath = '/admin/multivendor/dashboard';
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(feature, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        seller_id: multivendor?.sellerId ? String(multivendor.sellerId) : '',
        name: defaultTranslation.name || '',
        description: defaultTranslation.description || '',
        translations: initialTranslations,
        is_enabled: feature?.is_enabled ?? true,
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
            form.setError(`translations.${defaultLanguage}.name`, 'Feature name is required.');
            return;
        }
        if (isMultivendor && !String(form.data.seller_id || '').trim()) {
            form.setError('seller_id', t('admin.multivendor.choose_seller', 'Choose a seller.'));
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
        if (isMultivendor) {
            if (multivendor.saveMethod === 'put') {
                form.put(multivendor.savePath);
            } else {
                form.post(multivendor.savePath);
            }
            return;
        }
        if (feature) {
            form.put(`/admin/features/${feature.id}`);
        } else {
            form.post('/admin/features');
        }
    };

    const multivendorSidebar = isMultivendor ? (
        <section className={ui.sectionCard}>
            <div className={ui.sectionHead}>
                <h2 className={ui.sectionTitle}>{t('admin.multivendor.seller', 'Seller')}</h2>
            </div>
            <Field label={t('admin.multivendor.seller', 'Seller')} required error={form.errors.seller_id}>
                <SearchSelect
                    multiple={false}
                    options={(multivendor.sellers || []).map((seller) => ({ id: seller.id, name: seller.label }))}
                    value={form.data.seller_id}
                    selectedOptions={(multivendor.sellers || [])
                        .filter((seller) => String(seller.id) === String(form.data.seller_id))
                        .map((seller) => ({ id: seller.id, name: seller.label }))}
                    onChange={(value) => {
                        const nextSellerId = value === '' || value == null ? '' : String(value);
                        form.setData('seller_id', nextSellerId);
                        if (!multivendor.listingId && nextSellerId) {
                            router.get(multivendor.createPath || `${multivendor.indexPath}/create`, { seller_id: nextSellerId }, {
                                preserveScroll: true,
                            });
                        }
                    }}
                    disabled={!!multivendor.listingId}
                    placeholder={t('admin.multivendor.choose_seller', 'Choose seller…')}
                />
            </Field>
        </section>
    ) : null;

    return (
        <Layout>
            <PageHeader
                title={feature ? 'Edit feature' : 'Add feature'}
                meta={isMultivendor && multivendor.shopName ? (
                    <Badge tone="neutral">{multivendor.shopName}</Badge>
                ) : null}
                crumbs={isMultivendor ? [
                    { href: '/admin', label: 'Dashboard' },
                    { href: multivendorDashboardPath, label: 'Multivendor Management' },
                    { href: featuresIndexPath, label: t('admin.multivendor.features', 'Features') },
                    { label: feature ? 'Edit' : 'Add new' },
                ] : [
                    { href: '/admin', label: 'Dashboard' },
                    { href: '/admin/features', label: 'Features' },
                    { label: feature ? 'Edit' : 'Add new' },
                ]}
                actions={
                    feature ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                const assigned = Number(feature.products_count) || 0;
                                if (assigned > 0) {
                                    window.alert(`This feature is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please remove it from those products before deleting.`);
                                    return;
                                }
                                if (window.confirm(`Delete “${feature.name}”?`)) {
                                    if (isMultivendor && multivendor.deletePath) {
                                        router.delete(multivendor.deletePath, { preserveScroll: true });
                                        return;
                                    }
                                    router.delete(`/admin/features/${feature.id}`);
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
                            <LocalizedField
                                label="Feature name"
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
                                    placeholder="e.g. RAM"
                                />
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.features.description', 'Description')}
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
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    {multivendorSidebar}
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>Status</h2>
                        </div>
                        <Checkbox
                            label="Enabled"
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>Disabled features cannot be newly assigned to products. Existing product assignments are kept.</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {feature ? 'Save changes' : 'Create feature'}
                            </Button>
                            <Button type="button" variant="secondary" href={featuresIndexPath}>
                                Cancel
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
