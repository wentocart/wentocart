import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    PageHeader,
    SearchSelect,
    Select,
    Textarea,
    Tooltip,
    cx,
    ui,
    t,
} from '../../../ui';
import Editor from '../../../ui/Editor';
import { catalogImageUrl } from '../../../lib/catalogMedia';

const TRANSLATABLE_FIELDS = [
    'name',
    'slug',
    'short_description',
    'description',
    'meta_title',
    'meta_description',
    'meta_keywords',
];

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

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }

    return fallback || null;
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        short_description: '',
        tagline: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
    };
}

function buildTranslations(brand, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (brand?.translations || []).forEach((row) => {
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
    if (!brand?.translations?.length && brand) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: brand.name || '',
            slug: brand.slug || '',
            short_description: brand.short_description || '',
            tagline: brand.tagline || '',
            description: brand.description || '',
            meta_title: brand.meta_title || '',
            meta_description: brand.meta_description || '',
            meta_keywords: brand.meta_keywords || '',
        };
    }

    return out;
}

export default function Form({ brand, countries = [], multivendor = null }) {
    const isMultivendor = !!multivendor;
    const brandsIndexPath = isMultivendor ? multivendor.indexPath : '/admin/brands';
    const multivendorDashboardPath = '/admin/multivendor/dashboard';
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(brand, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const initialCountry = brand?.country || '';
    const initialRegions = regionsForCountry(countries, initialCountry);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        seller_id: multivendor?.sellerId ? String(multivendor.sellerId) : '',
        name: defaultTranslation.name || '',
        slug: defaultTranslation.slug || '',
        description: defaultTranslation.description || '',
        website: brand?.website || '',
        email: brand?.email || '',
        phone: brand?.phone || '',
        address: brand?.address || '',
        address_line2: brand?.address_line2 || '',
        city: brand?.city || '',
        state: resolveStateCode(brand?.state || '', initialRegions),
        postcode: brand?.postcode || '',
        country: initialCountry,
        facebook_url: brand?.facebook_url || '',
        instagram_url: brand?.instagram_url || '',
        youtube_url: brand?.youtube_url || '',
        twitter_url: brand?.twitter_url || '',
        linkedin_url: brand?.linkedin_url || '',
        tiktok_url: brand?.tiktok_url || '',
        meta_title: defaultTranslation.meta_title || '',
        meta_description: defaultTranslation.meta_description || '',
        meta_keywords: defaultTranslation.meta_keywords || '',
        translations: initialTranslations,
        is_enabled: brand?.is_enabled ?? true,
        logo: null,
        remove_logo: false,
    });

    const assigned = Number(brand?.products_count) || 0;
    const countryRegions = regionsForCountry(countries, form.data.country);
    const showState = countryRegions.length > 0;
    const stateValue = resolveStateCode(form.data.state, countryRegions);
    const logoPreview = form.data.remove_logo ? null : previewUrl(form.data.logo, brand?.logo_url);
    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const errorForField = (field) => (
        form.errors[`translations.${selectedLanguage}.${field}`]
        || (selectedLanguage === defaultLanguage ? form.errors[field] : undefined)
    );

    const setTranslationField = (field, value) => {
        form.setData((data) => {
            const current = data.translations?.[selectedLanguage] || emptyTranslation(selectedLanguage);
            const nextRow = {
                ...current,
                language_code: selectedLanguage,
                [field]: value,
            };
            const next = {
                ...data,
                translations: {
                    ...data.translations,
                    [selectedLanguage]: nextRow,
                },
            };
            if (selectedLanguage === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
                next[field] = value;
            }

            return next;
        });
    };

    const clearLogo = () => {
        form.setData((data) => ({
            ...data,
            logo: null,
            remove_logo: !!brand?.logo_url,
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
            form.setError(`translations.${defaultLanguage}.name`, 'Brand name is required.');
            return;
        }
        if (isMultivendor && !String(form.data.seller_id || '').trim()) {
            form.setError('seller_id', t('admin.multivendor.choose_seller', 'Choose a seller.'));
            return;
        }
        const hasFiles = form.data.logo instanceof File;
        form.transform((data) => {
            const { position: _omitPosition, ...rest } = data;

            return {
                ...rest,
                name: baseTranslation.name,
                slug: baseTranslation.slug,
                short_description: baseTranslation.short_description || '',
                tagline: baseTranslation.tagline || '',
                description: baseTranslation.description,
                meta_title: baseTranslation.meta_title,
                meta_description: baseTranslation.meta_description,
                meta_keywords: baseTranslation.meta_keywords,
                translations,
                logo: data.logo instanceof File ? data.logo : undefined,
                remove_logo: data.remove_logo ? 1 : 0,
                is_enabled: data.is_enabled ? 1 : 0,
                state: regionsForCountry(countries, data.country).length > 0 ? data.state : '',
                ...(brand && hasFiles ? { _method: 'put' } : {}),
            };
        });
        const options = hasFiles ? { forceFormData: true } : {};
        if (isMultivendor) {
            if (multivendor.saveMethod === 'put' && !hasFiles) {
                form.put(multivendor.savePath, options);
            } else {
                form.post(multivendor.savePath, options);
            }
            return;
        }
        if (brand) {
            if (hasFiles) {
                form.post(`/admin/brands/${brand.id}`, options);
            } else {
                form.put(`/admin/brands/${brand.id}`, options);
            }
        } else {
            form.post('/admin/brands', hasFiles ? { forceFormData: true } : {});
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
                title={brand ? 'Edit brand' : 'Add brand'}
                meta={isMultivendor && multivendor.shopName ? (
                    <Badge tone="neutral">{multivendor.shopName}</Badge>
                ) : null}
                crumbs={isMultivendor ? [
                    { href: '/admin', label: 'Dashboard' },
                    { href: multivendorDashboardPath, label: 'Multivendor Management' },
                    { href: brandsIndexPath, label: t('admin.multivendor.brands', 'Brands') },
                    { label: brand ? 'Edit' : 'Add new' },
                ] : [
                    { href: '/admin', label: 'Dashboard' },
                    { href: '/admin/brands', label: 'Brands' },
                    { label: brand ? 'Edit' : 'Add new' },
                ]}
                actions={
                    brand ? (
                        <Tooltip content={t('admin.common.delete', 'Delete')} placement="bottom">
                            <button
                                type="button"
                                className={cx(ui.rowActionsBtn, 'text-red-600 hover:bg-red-50 hover:text-red-700')}
                                aria-label={t('admin.common.delete', 'Delete')}
                                onClick={() => {
                                    if (assigned > 0) {
                                        window.alert(`This brand is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please reassign these products before deleting the brand.`);
                                        return;
                                    }
                                    if (window.confirm(`Delete “${brand.name}”?`)) {
                                        if (isMultivendor && multivendor.deletePath) {
                                            router.delete(multivendor.deletePath, { preserveScroll: true });
                                            return;
                                        }
                                        router.delete(`/admin/brands/${brand.id}`);
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
                            <h2 className={ui.sectionTitle}>General</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label="Brand name"
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('name')}
                            >
                                <Input
                                    wide
                                    invalid={!!errorForField('name')}
                                    value={valueForField('name')}
                                    onChange={(e) => setTranslationField('name', e.target.value)}
                                    placeholder="e.g. Acme"
                                />
                            </LocalizedField>
                            <LocalizedField
                                label="Description"
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('description')}
                            >
                                <Editor value={valueForField('description')} onChange={(html) => setTranslationField('description', html)} />
                            </LocalizedField>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>Contact</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label="Contact email" error={form.errors.email}>
                                <Input
                                    wide
                                    type="email"
                                    invalid={!!form.errors.email}
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>
                            <Field label="Contact phone" error={form.errors.phone}>
                                <Input wide invalid={!!form.errors.phone} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            </Field>
                            <Field label="Website" error={form.errors.website}>
                                <Input
                                    wide
                                    type="url"
                                    invalid={!!form.errors.website}
                                    value={form.data.website}
                                    onChange={(e) => form.setData('website', e.target.value)}
                                    placeholder="https://"
                                />
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
                            <h2 className={ui.sectionTitle}>Social media</h2>
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
                            <h2 className={ui.sectionTitle}>SEO</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label="Slug / SEO URL"
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('slug')}
                                hint="Auto-generated from the name. You can edit it."
                            >
                                <div className={ui.row}>
                                    <Input
                                        grow
                                        invalid={!!errorForField('slug')}
                                        value={valueForField('slug')}
                                        onChange={(e) => setTranslationField('slug', slugify(e.target.value))}
                                    />
                                    <Button type="button" variant="secondary" onClick={() => setTranslationField('slug', slugify(valueForField('name')))}>
                                        Auto
                                    </Button>
                                </div>
                            </LocalizedField>
                            <LocalizedField
                                label="Meta title"
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_title')}
                            >
                                <Input wide maxLength={60} value={valueForField('meta_title')} onChange={(e) => setTranslationField('meta_title', e.target.value)} />
                                <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_title') || '').length} / 60 characters</p>
                            </LocalizedField>
                            <LocalizedField
                                label="Meta description"
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_description')}
                            >
                                <Textarea rows="3" maxLength={160} value={valueForField('meta_description')} onChange={(e) => setTranslationField('meta_description', e.target.value)} />
                                <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_description') || '').length} / 160 characters</p>
                            </LocalizedField>
                            <LocalizedField
                                label="Meta keywords"
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_keywords')}
                            >
                                <Input
                                    wide
                                    value={valueForField('meta_keywords')}
                                    onChange={(e) => setTranslationField('meta_keywords', e.target.value)}
                                    placeholder="e.g. coffee, espresso, home appliances"
                                />
                                <p className={ui.fieldHint}>
                                    Optional. Add related search terms separated by commas. Example: coffee, wholesale, B2B. You can leave this blank.
                                </p>
                            </LocalizedField>
                            <div className={ui.seoPreview}>
                                <div className={ui.seoPreviewTitle}>{valueForField('meta_title') || valueForField('name') || 'Brand title'}</div>
                                <div className={ui.seoPreviewUrl}>/brand/{valueForField('slug') || 'slug'}</div>
                                <div className={ui.seoPreviewDesc}>{valueForField('meta_description') || 'Meta description will appear here.'}</div>
                            </div>
                        </div>
                    </section>
                </div>

                <div className={`${ui.formSide} min-w-0 max-w-full`}>
                    {multivendorSidebar}
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
                            <h2 className={ui.sectionTitle}>Status</h2>
                        </div>
                        <Checkbox
                            label="Enabled"
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>Disabled brands cannot be newly assigned to products. Existing product assignments are kept.</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {brand ? 'Save changes' : 'Create brand'}
                            </Button>
                            <Button type="button" variant="secondary" href={brandsIndexPath}>
                                Cancel
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
