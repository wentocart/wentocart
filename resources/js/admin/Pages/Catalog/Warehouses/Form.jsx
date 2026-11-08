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

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        description: '',
    };
}

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

const TRANSLATABLE_FIELDS = ['name', 'description'];

function buildTranslations(warehouse, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (warehouse?.translations || []).forEach((row) => {
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
    if (!warehouse?.translations?.length && warehouse) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: warehouse.name || '',
        };
    }

    return out;
}

export default function Form({ warehouse = null, countries = [] }) {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(warehouse, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const initialCountry = warehouse?.country || '';
    const initialRegions = regionsForCountry(countries, initialCountry);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);

    const form = useForm({
        name: defaultTranslation.name || '',
        code: warehouse?.code || '',
        translations: initialTranslations,
        email: warehouse?.email || '',
        phone: warehouse?.phone || '',
        fax: warehouse?.fax || '',
        website: warehouse?.website || '',
        address: warehouse?.address || '',
        address_line2: warehouse?.address_line2 || '',
        city: warehouse?.city || '',
        state: resolveStateCode(warehouse?.state || '', initialRegions),
        postcode: warehouse?.postcode || '',
        country: initialCountry,
        latitude: warehouse?.latitude ?? '',
        longitude: warehouse?.longitude ?? '',
        logo: null,
        remove_logo: false,
        is_enabled: warehouse?.is_enabled ?? true,
        is_default: warehouse?.is_default ?? false,
    });

    const canDelete = warehouse && warehouse.actions?.delete !== false;
    const isDefault = !!warehouse?.is_default;
    const countryRegions = regionsForCountry(countries, form.data.country);
    const showState = countryRegions.length > 0;
    const stateValue = resolveStateCode(form.data.state, countryRegions);
    const logoPreview = form.data.remove_logo ? null : previewUrl(form.data.logo, warehouse?.logo_url);

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
            remove_logo: !!warehouse?.logo_url,
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
            form.setError(`translations.${defaultLanguage}.name`, t('admin.warehouses.name_required', 'Warehouse name is required.'));
            return;
        }
        const hasFiles = form.data.logo instanceof File;
        form.transform((data) => ({
            ...data,
            name: baseTranslation.name,
            code: String(data.code || '').toUpperCase().trim(),
            translations,
            logo: data.logo instanceof File ? data.logo : undefined,
            remove_logo: data.remove_logo ? 1 : 0,
            state: regionsForCountry(countries, data.country).length > 0 ? data.state : '',
            latitude: data.latitude === '' || data.latitude == null ? '' : data.latitude,
            longitude: data.longitude === '' || data.longitude == null ? '' : data.longitude,
            is_enabled: data.is_default ? true : !!data.is_enabled,
            is_default: !!data.is_default,
            ...(warehouse && hasFiles ? { _method: 'put' } : {}),
        }));
        if (warehouse) {
            if (hasFiles) {
                form.post(`/admin/warehouses/${warehouse.id}`, { forceFormData: true });
            } else {
                form.put(`/admin/warehouses/${warehouse.id}`);
            }
        } else {
            form.post('/admin/warehouses', hasFiles ? { forceFormData: true } : {});
        }
    };

    return (
        <Layout>
            <PageHeader
                title={warehouse
                    ? t('admin.warehouses.edit', 'Edit warehouse')
                    : t('admin.warehouses.add', 'Add warehouse')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.nav.catalog', 'Catalog') },
                    { href: '/admin/warehouses', label: t('admin.nav.warehouses', 'Warehouses') },
                    { label: warehouse ? t('admin.common.edit', 'Edit') : t('admin.common.add', 'Add') },
                ]}
                actions={warehouse ? (
                    <Tooltip content={t('admin.common.delete', 'Delete')} placement="bottom">
                        <button
                            type="button"
                            className={cx(
                                ui.rowActionsBtn,
                                canDelete
                                    ? 'text-red-600 hover:bg-red-50 hover:text-red-700'
                                    : 'cursor-not-allowed text-neutral-300',
                            )}
                            aria-label={t('admin.common.delete', 'Delete')}
                            disabled={!canDelete}
                            onClick={() => {
                                if (!canDelete) {
                                    if (isDefault) {
                                        window.alert(t(
                                            'admin.warehouses.cannot_delete_default',
                                            'Set another warehouse as default before deleting this one.',
                                        ));
                                    }
                                    return;
                                }
                                if (window.confirm(`${t('admin.common.delete', 'Delete')} “${warehouse.name}”?`)) {
                                    router.delete(`/admin/warehouses/${warehouse.id}`);
                                }
                            }}
                        >
                            <Icon name="trash" className={ui.rowActionsIcon} />
                        </button>
                    </Tooltip>
                ) : null}
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout} encType="multipart/form-data">
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.warehouses.details', 'Warehouse details')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.warehouses.name', 'Name')}
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
                                    placeholder={t('admin.warehouses.description_hint', 'Optional notes about this location')}
                                />
                            </LocalizedField>
                            <Field
                                label={t('admin.warehouses.code', 'Code')}
                                required
                                error={form.errors.code}
                                hint={t('admin.warehouses.code_hint', 'Uppercase location code, unique across warehouses.')}
                            >
                                <Input
                                    wide
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                />
                            </Field>
                            <Checkbox
                                label={t('admin.warehouses.default', 'Default warehouse (fulfillment)')}
                                checked={!!form.data.is_default}
                                onChange={(e) => {
                                    const next = e.target.checked;
                                    form.setData({
                                        ...form.data,
                                        is_default: next,
                                        is_enabled: next ? true : form.data.is_enabled,
                                    });
                                }}
                            />
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.suppliers.contact', 'Contact')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.suppliers.email', 'Email')} error={form.errors.email} required>
                                <Input wide type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                            <Field label={t('admin.suppliers.phone', 'Phone')} error={form.errors.phone}>
                                <Input wide value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            </Field>
                            <Field label={t('admin.warehouses.fax', 'Fax')} error={form.errors.fax}>
                                <Input wide value={form.data.fax} onChange={(e) => form.setData('fax', e.target.value)} />
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
                            <h2 className={ui.sectionTitle}>{t('admin.warehouses.geo', 'Geolocation')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.warehouses.latitude', 'Latitude')} error={form.errors.latitude}>
                                <Input
                                    wide
                                    type="number"
                                    step="any"
                                    value={form.data.latitude}
                                    onChange={(e) => form.setData('latitude', e.target.value)}
                                    placeholder="e.g. 42.3601"
                                />
                            </Field>
                            <Field label={t('admin.warehouses.longitude', 'Longitude')} error={form.errors.longitude}>
                                <Input
                                    wide
                                    type="number"
                                    step="any"
                                    value={form.data.longitude}
                                    onChange={(e) => form.setData('longitude', e.target.value)}
                                    placeholder="e.g. -71.0589"
                                />
                            </Field>
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
                            <h2 className={ui.sectionTitle}>{t('admin.products.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled || !!form.data.is_default}
                            disabled={!!form.data.is_default}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>
                            {form.data.is_enabled || form.data.is_default
                                ? t('admin.warehouses.status_active', 'This warehouse can receive and fulfill stock.')
                                : t('admin.warehouses.status_inactive', 'This warehouse is disabled and hidden from new fulfillment.')}
                        </p>
                        {form.data.is_default ? (
                            <p className={`${ui.metaK} mt-2`}>
                                {t('admin.warehouses.default_always_active', 'The default warehouse must stay active.')}
                            </p>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {warehouse
                                    ? t('admin.common.save', 'Save changes')
                                    : t('admin.warehouses.create', 'Create warehouse')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/warehouses">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
