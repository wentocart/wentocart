import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    PageHeader,
    Select,
    Textarea,
    Tooltip,
    cx,
    ui,
    t,
} from '../../../ui';
import { catalogImageUrl } from '../../../lib/catalogMedia';

function regionsForCountry(countries, code) {
    const country = (countries || []).find((row) => row.code === code);
    return country?.regions || [];
}

function resolveStateCode(state, regions) {
    if (!state) {
        return '';
    }
    if (regions.some((row) => row.code === state)) {
        return state;
    }
    const byName = regions.find((row) => row.name === state);
    return byName?.code || '';
}

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }

    return fallback || null;
}

const TRANSLATABLE_FIELDS = ['name', 'slug', 'description', 'meta_title', 'meta_description', 'meta_keywords'];

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
    };
}

function buildTranslations(supplier, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (supplier?.translations || []).forEach((row) => {
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
    if (!supplier?.translations?.length && supplier) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: supplier.name || '',
            slug: supplier.slug || '',
            description: supplier.description || '',
            meta_title: supplier.meta_title || '',
            meta_description: supplier.meta_description || '',
            meta_keywords: supplier.meta_keywords || '',
        };
    }

    return out;
}

export default function Form({ supplier, countries = [] }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(supplier, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const initialCountry = supplier?.country || '';
    const initialRegions = regionsForCountry(countries, initialCountry);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        name: defaultTranslation.name || '',
        slug: defaultTranslation.slug || '',
        description: defaultTranslation.description || '',
        meta_title: defaultTranslation.meta_title || '',
        meta_description: defaultTranslation.meta_description || '',
        meta_keywords: defaultTranslation.meta_keywords || '',
        translations: initialTranslations,
        email: supplier?.email || '',
        phone: supplier?.phone || '',
        website: supplier?.website || '',
        facebook_url: supplier?.facebook_url || '',
        instagram_url: supplier?.instagram_url || '',
        youtube_url: supplier?.youtube_url || '',
        twitter_url: supplier?.twitter_url || '',
        linkedin_url: supplier?.linkedin_url || '',
        tiktok_url: supplier?.tiktok_url || '',
        address: supplier?.address || '',
        address_line2: supplier?.address_line2 || '',
        city: supplier?.city || '',
        state: resolveStateCode(supplier?.state || '', initialRegions),
        postcode: supplier?.postcode || '',
        country: initialCountry,
        logo: null,
        remove_logo: false,
        is_enabled: supplier?.is_enabled ?? true,
    });

    const countryRegions = regionsForCountry(countries, form.data.country);
    const showState = countryRegions.length > 0;
    const stateValue = resolveStateCode(form.data.state, countryRegions);
    const logoPreview = form.data.remove_logo ? null : previewUrl(form.data.logo, supplier?.logo_url);

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
        if (selectedLanguage === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
            next[field] = value;
        }
        form.setData(next);
    };

    const clearLogo = () => {
        form.setData((data) => ({
            ...data,
            logo: null,
            remove_logo: !!supplier?.logo_url,
        }));
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
            form.setError(`translations.${defaultLanguage}.name`, t('admin.suppliers.name_required', 'Supplier name is required.'));
            return;
        }
        const hasFiles = form.data.logo instanceof File;
        form.transform((data) => ({
            ...data,
            name: baseTranslation.name,
            slug: baseTranslation.slug,
            description: baseTranslation.description,
            meta_title: baseTranslation.meta_title,
            meta_description: baseTranslation.meta_description,
            meta_keywords: baseTranslation.meta_keywords,
            translations,
            logo: data.logo instanceof File ? data.logo : undefined,
            remove_logo: data.remove_logo ? 1 : 0,
            is_enabled: data.is_enabled ? 1 : 0,
            state: regionsForCountry(countries, data.country).length > 0 ? data.state : '',
            // PHP 8.3 does not parse multipart PUT bodies; spoof via POST like Account/Edit.
            ...(supplier && hasFiles ? { _method: 'put' } : {}),
        }));
        if (supplier) {
            if (hasFiles) {
                form.post(`/admin/suppliers/${supplier.id}`, { forceFormData: true });
            } else {
                form.put(`/admin/suppliers/${supplier.id}`);
            }
        } else {
            form.post('/admin/suppliers', hasFiles ? { forceFormData: true } : {});
        }
    };

    return (
        <Layout>
            <PageHeader
                title={supplier ? t('admin.suppliers.edit_supplier', 'Edit supplier') : t('admin.suppliers.add_supplier', 'Add supplier')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/suppliers', label: t('admin.suppliers.suppliers', 'Suppliers') },
                    { label: supplier ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    supplier ? (
                        <Tooltip content={t('admin.common.delete', 'Delete')} placement="bottom">
                            <button
                                type="button"
                                className={cx(ui.rowActionsBtn, 'text-red-600 hover:bg-red-50 hover:text-red-700')}
                                aria-label={t('admin.common.delete', 'Delete')}
                                onClick={() => {
                                    if (window.confirm(t('admin.suppliers.delete_this_supplier_products_stay_in_the_catalo', 'Delete this supplier? Products stay in the catalog.'))) {
                                        router.delete(`/admin/suppliers/${supplier.id}`);
                                    }
                                }}
                            >
                                <Icon name="trash" className={ui.rowActionsIcon} />
                            </button>
                        </Tooltip>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout} encType="multipart/form-data">
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.supplier', 'Supplier')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.suppliers.name', 'Name')}
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
                                    placeholder={t('admin.suppliers.acme_wholesale', 'Acme Wholesale')}
                                />
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.suppliers.description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('description')}
                                hasValue={hasValueForField('description')}
                                fallbackValue={fallbackForField('description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Textarea
                                    rows={3}
                                    value={valueForField('description')}
                                    onChange={(e) => setTranslationField('description', e.target.value)}
                                    placeholder={t('admin.suppliers.who_they_are_and_what_they_supply', 'Who they are and what they supply')}
                                />
                            </LocalizedField>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.contact', 'Contact')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.suppliers.email', 'Email')} error={form.errors.email}>
                                <Input wide type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.phone', 'Phone')} error={form.errors.phone}>
                                <Input wide value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.website', 'Website')} error={form.errors.website}>
                                <Input wide value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} placeholder="https://" />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.address', 'Address')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.suppliers.street_address', 'Street address')} error={form.errors.address} required>
                                <Input wide value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.apartment_suite', 'Apartment, suite')} error={form.errors.address_line2}>
                                <Input wide value={form.data.address_line2} onChange={(e) => form.setData('address_line2', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.city', 'City')} error={form.errors.city} required>
                                <Input wide value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.postcode', 'ZIP / Postal code')} error={form.errors.postcode} required>
                                <Input wide value={form.data.postcode} onChange={(e) => form.setData('postcode', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.country', 'Country')} error={form.errors.country} required>
                                <Select
                                    wide
                                    value={form.data.country}
                                    onChange={(e) => {
                                        const code = e.target.value;
                                        const regions = regionsForCountry(countries, code);
                                        const nextState = resolveStateCode(form.data.state, regions);
                                        form.setData({
                                            ...form.data,
                                            country: code,
                                            state: regions.some((row) => row.code === nextState) ? nextState : '',
                                        });
                                    }}
                                >
                                    <option value="">{t('admin.suppliers.select_country', 'Select country')}</option>
                                    {(countries || []).map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
                                </Select>
                            </Field>
                            {showState ? (
                                <Field label={t('admin.addresses.state', 'State / Province')} error={form.errors.state} required>
                                    <Select wide value={stateValue} onChange={(e) => form.setData('state', e.target.value)}>
                                        <option value="">{t('admin.addresses.select_state', 'Select state')}</option>
                                        {countryRegions.map((region) => (
                                            <option key={region.code} value={region.code}>{region.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : null}
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.brands.social_media', 'Social media')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            {[
                                ['facebook_url', 'Facebook URL'],
                                ['instagram_url', 'Instagram URL'],
                                ['youtube_url', 'YouTube URL'],
                                ['twitter_url', 'X / Twitter URL'],
                                ['linkedin_url', 'LinkedIn URL'],
                                ['tiktok_url', 'TikTok URL'],
                            ].map(([key, label]) => (
                                <Field key={key} label={label} error={form.errors[key]}>
                                    <Input
                                        wide
                                        type="url"
                                        invalid={!!form.errors[key]}
                                        value={form.data[key]}
                                        onChange={(e) => form.setData(key, e.target.value)}
                                        placeholder="https://"
                                    />
                                </Field>
                            ))}
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.brands.seo', 'SEO')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.brands.slug_seo_url', 'Slug / SEO URL')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('slug')}
                                hasValue={hasValueForField('slug')}
                                fallbackValue={fallbackForField('slug')}
                                fallbackLanguage={fallbackLanguage}
                                hint={t('admin.brands.auto_generated_from_name', 'Auto-generated from the name. You can edit it.')}
                            >
                                <div className={ui.row}>
                                    <Input
                                        grow
                                        invalid={!!errorForField('slug')}
                                        value={valueForField('slug')}
                                        onChange={(e) => setTranslationField('slug', slugify(e.target.value))}
                                    />
                                    <Button type="button" variant="secondary" onClick={() => setTranslationField('slug', slugify(valueForField('name')))}>
                                        {t('admin.common.auto', 'Auto')}
                                    </Button>
                                </div>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.brands.meta_title', 'Meta title')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_title')}
                                hasValue={hasValueForField('meta_title')}
                                fallbackValue={fallbackForField('meta_title')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input wide maxLength={60} value={valueForField('meta_title')} onChange={(e) => setTranslationField('meta_title', e.target.value)} />
                                <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_title') || '').length} / 60 characters</p>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.brands.meta_description', 'Meta description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_description')}
                                hasValue={hasValueForField('meta_description')}
                                fallbackValue={fallbackForField('meta_description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Textarea rows={3} maxLength={160} value={valueForField('meta_description')} onChange={(e) => setTranslationField('meta_description', e.target.value)} />
                                <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_description') || '').length} / 160 characters</p>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.brands.meta_keywords', 'Meta keywords')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_keywords')}
                                hasValue={hasValueForField('meta_keywords')}
                                fallbackValue={fallbackForField('meta_keywords')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input
                                    wide
                                    value={valueForField('meta_keywords')}
                                    onChange={(e) => setTranslationField('meta_keywords', e.target.value)}
                                    placeholder="e.g. wholesale, electronics, B2B"
                                />
                                <p className={ui.fieldHint}>
                                    {t('admin.common.meta_keywords_hint', 'Add related search terms separated by commas. Example: wholesale, electronics, B2B.')}
                                </p>
                            </LocalizedField>
                            <div className={ui.seoPreview}>
                                <div className={ui.seoPreviewTitle}>{valueForField('meta_title') || valueForField('name') || t('admin.suppliers.supplier', 'Supplier')}</div>
                                <div className={ui.seoPreviewUrl}>/supplier/{valueForField('slug') || 'slug'}</div>
                                <div className={ui.seoPreviewDesc}>{valueForField('meta_description') || t('admin.brands.meta_description_will_appear_here', 'Meta description will appear here.')}</div>
                            </div>
                        </div>
                    </section>
                </div>

                <div className={`${ui.formSide} min-w-0 max-w-full`}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.logo', 'Logo')}</h2>
                        </div>
                        <Field error={form.errors.logo} className="min-w-0">
                            <div className="min-w-0 space-y-3">
                                <div className="relative inline-block">
                                    <img
                                        src={logoPreview || catalogImageUrl(null, 'md')}
                                        alt=""
                                        className="h-16 w-16 rounded-xl object-cover bg-neutral-100"
                                        onError={(e) => {
                                            e.currentTarget.src = catalogImageUrl(null, 'md');
                                        }}
                                    />
                                    {logoPreview ? (
                                        <button
                                            type="button"
                                            className="absolute -right-2 -top-2 inline-flex h-6 w-6 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-600 shadow-sm hover:bg-neutral-50 hover:text-red-600"
                                            aria-label={t('admin.suppliers.remove_logo', 'Remove logo')}
                                            onClick={clearLogo}
                                        >
                                            <Icon name="x" className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                <div className="min-w-0 w-full overflow-hidden">
                                    <Input
                                        type="file"
                                        accept="image/*"
                                        wide
                                        className="max-w-full text-sm file:mr-2 file:max-w-[calc(100%-7rem)] file:truncate file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200"
                                        onChange={(e) => {
                                            form.setData((data) => ({
                                                ...data,
                                                logo: e.target.files?.[0] || null,
                                                remove_logo: false,
                                            }));
                                        }}
                                    />
                                </div>
                            </div>
                        </Field>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>{t('admin.suppliers.inactive_suppliers_stay_linked_but_are_hidden_wh', 'Inactive suppliers stay linked but are hidden when adding new products.')}</p>
                    </section>
                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {supplier ? t('admin.common.save', 'Save changes') : t('admin.suppliers.create_supplier', 'Create supplier')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/suppliers">{t('admin.common.cancel', 'Cancel')}</Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
