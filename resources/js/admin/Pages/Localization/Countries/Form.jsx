import { useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    ui,
    t,
} from '../../../ui';

export default function Form({ country, options = {} }) {
    const form = useForm({
        code: country?.code || '',
        iso3: country?.iso3 || '',
        name: country?.name || '',
        calling_code: country?.calling_code || '',
        default_currency_code: country?.default_currency_code || '',
        timezone: country?.timezone || '',
        address_format: country?.address_format || '',
        postal_code_label: country?.postal_code_label || '',
        tax_id_label: country?.tax_id_label || '',
        has_regions: country?.has_regions ?? false,
        is_enabled: country?.is_enabled ?? true,
        is_default: country?.is_default ?? false,
        zone_id: country?.zone_id || '',
        regions: country?.regions?.length
            ? country.regions.map((r) => ({
                code: r.code,
                name: r.name,
                is_enabled: r.is_enabled ?? true,
            }))
            : [],
        cities: country?.cities?.length
            ? country.cities.map((c) => ({
                name: c.name,
                region_code: c.region_code || '',
                is_enabled: c.is_enabled ?? true,
            }))
            : [],
    });

    const setRegion = (index, key, value) => {
        const next = form.data.regions.map((row, i) => (i === index ? { ...row, [key]: value } : row));
        form.setData('regions', next);
    };

    const addRegion = () => {
        form.setData('regions', [...form.data.regions, { code: '', name: '', is_enabled: true }]);
    };

    const removeRegion = (index) => {
        form.setData('regions', form.data.regions.filter((_, i) => i !== index));
    };

    const setCity = (index, key, value) => {
        const next = form.data.cities.map((row, i) => (i === index ? { ...row, [key]: value } : row));
        form.setData('cities', next);
    };

    const addCity = () => {
        form.setData('cities', [...form.data.cities, { name: '', region_code: '', is_enabled: true }]);
    };

    const removeCity = (index) => {
        form.setData('cities', form.data.cities.filter((_, i) => i !== index));
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            code: String(data.code || '').toUpperCase(),
            iso3: String(data.iso3 || '').toUpperCase() || null,
            has_regions: !!data.has_regions,
            is_enabled: !!data.is_enabled,
            is_default: !!data.is_default,
            zone_id: data.zone_id ? Number(data.zone_id) : null,
            regions: (data.regions || []).map((r) => ({
                ...r,
                code: String(r.code || '').toUpperCase(),
                is_enabled: !!r.is_enabled,
            })),
            cities: (data.cities || []).map((c) => ({
                ...c,
                region_code: String(c.region_code || '').toUpperCase(),
                is_enabled: !!c.is_enabled,
            })),
        }));
        if (country) {
            form.put(`/admin/localization/countries/${country.id}`);
        } else {
            form.post('/admin/localization/countries');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={country ? t('admin.countries.edit_country', 'Edit country') : t('admin.countries.add_country', 'Add country')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.countries.localization', 'Localization') },
                    { href: '/admin/localization/countries', label: t('admin.countries.countries', 'Countries') },
                    { label: country ? t('admin.common.edit', 'Edit') : t('admin.countries.add', 'Add') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/countries">{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" form="country-form" icon="check" busy={form.processing}>{t('admin.countries.save', 'Save')}</Button>
                    </div>
                )}
                className="mb-6"
            />

            <form id="country-form" onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} except={['name', 'code', 'iso3', 'calling_code', 'default_currency_code', 'timezone', 'address_format', 'postal_code_label', 'tax_id_label', 'zone_id']} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.countries.country_details', 'Country details')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.countries.name', 'Name')} error={form.errors.name} required>
                                <Input wide invalid={!!form.errors.name} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('admin.countries.india', 'India')} />
                            </Field>
                            <Field label={t('admin.nav.zones', 'Zone')} error={form.errors.zone_id} hint={t('admin.zones.country_hint', 'Each country belongs to one zone. Shipping methods use that zone.')} optional>
                                <Select wide invalid={!!form.errors.zone_id} value={form.data.zone_id || ''} onChange={(e) => form.setData('zone_id', e.target.value)}>
                                    <option value="">{t('admin.zones.no_zone', 'No zone')}</option>
                                    {(options.zones || []).map((zone) => (
                                        <option key={zone.id} value={zone.id}>{zone.name}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.countries.iso_2', 'ISO 2')} error={form.errors.code} hint={t('admin.countries.two_letter_code_e_g_in', 'Two-letter code, e.g. IN')} required>
                                <Input
                                    wide
                                    invalid={!!form.errors.code}
                                    disabled={!!country}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                    placeholder={t('admin.countries.in', 'IN')}
                                    maxLength={2}
                                />
                            </Field>
                            <Field label={t('admin.countries.iso_3', 'ISO 3')} error={form.errors.iso3} optional>
                                <Input wide invalid={!!form.errors.iso3} value={form.data.iso3} onChange={(e) => form.setData('iso3', e.target.value.toUpperCase())} placeholder={t('admin.countries.ind', 'IND')} maxLength={3} />
                            </Field>
                            <Field label={t('admin.countries.calling_code', 'Calling code')} error={form.errors.calling_code} optional>
                                <Input wide invalid={!!form.errors.calling_code} value={form.data.calling_code} onChange={(e) => form.setData('calling_code', e.target.value)} placeholder="+91" />
                            </Field>
                            <Field label={t('admin.countries.default_currency', 'Default currency')} error={form.errors.default_currency_code} optional>
                                <Select wide invalid={!!form.errors.default_currency_code} value={form.data.default_currency_code} onChange={(e) => form.setData('default_currency_code', e.target.value)}>
                                    <option value="">{t('admin.countries.none', 'None')}</option>
                                    {(options.currencies || []).map((c) => (
                                        <option key={c.code} value={c.code}>{c.code} — {c.name} ({c.symbol})</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.countries.default_timezone', 'Default timezone')} error={form.errors.timezone} optional>
                                <Input wide invalid={!!form.errors.timezone} value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} placeholder={t('admin.countries.asia_kolkata', 'Asia/Kolkata')} />
                            </Field>
                            <Field label={t('admin.countries.address_format', 'Address format')} error={form.errors.address_format} optional>
                                <Select wide invalid={!!form.errors.address_format} value={form.data.address_format} onChange={(e) => form.setData('address_format', e.target.value)}>
                                    <option value="">{t('admin.countries.default', 'Default')}</option>
                                    {(options.address_formats || []).map((f) => (
                                        <option key={f.value} value={f.value}>{f.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.countries.postal_code_label', 'Postal code label')} error={form.errors.postal_code_label} optional>
                                <Input wide invalid={!!form.errors.postal_code_label} value={form.data.postal_code_label} onChange={(e) => form.setData('postal_code_label', e.target.value)} placeholder={t('admin.countries.pin_code', 'PIN Code')} />
                            </Field>
                            <Field label={t('admin.countries.tax_identification_label', 'Tax identification label')} error={form.errors.tax_id_label} className="sm:col-span-2" optional>
                                <Input wide invalid={!!form.errors.tax_id_label} value={form.data.tax_id_label} onChange={(e) => form.setData('tax_id_label', e.target.value)} placeholder={t('admin.countries.gstin', 'GSTIN')} />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div>
                                <h2 className={ui.sectionTitle}>{t('admin.countries.states_regions', 'States / regions')}</h2>
                                <p className={ui.muted}>{t('admin.countries.optional_prefer_installing_a_localization_pack_f', 'Optional. Prefer installing a localization pack for full lists.')}</p>
                            </div>
                            <div className={ui.toolbar}>
                                {country?.code ? (
                                    <Button type="button" variant="secondary" href={`/admin/localization/packs`}>
                                        {t('admin.countries.install_pack', 'Install pack')}
                                    </Button>
                                ) : null}
                                <Button type="button" variant="secondary" icon="plus" onClick={addRegion}>{t('admin.countries.add_region', 'Add region')}</Button>
                            </div>
                        </div>
                        <Checkbox
                            label={t('admin.countries.states_regions_enabled_for_this_country', 'States / regions enabled for this country')}
                            checked={!!form.data.has_regions}
                            onChange={(e) => form.setData('has_regions', e.target.checked)}
                        />
                        <div className={`${ui.stack} mt-4`}>
                            {form.data.regions.length > 0 ? (
                                <div className="hidden md:grid md:grid-cols-[7rem_minmax(0,1fr)_6.5rem_6.5rem] md:gap-3 md:px-1">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('admin.countries.code', 'Code')}</span>
                                    <span className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('admin.countries.name_2', 'Name')}</span>
                                    <span className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('admin.common.active', 'Active')}</span>
                                    <span className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('admin.common.actions', 'Actions')}</span>
                                </div>
                            ) : null}
                            {form.data.regions.map((region, index) => (
                                <div
                                    key={index}
                                    className="grid gap-3 rounded-xl border border-neutral-200 p-3 md:grid-cols-[7rem_minmax(0,1fr)_6.5rem_6.5rem] md:items-center md:border-0 md:p-0"
                                >
                                    <Field label={t('admin.countries.code', 'Code')} className="md:[&>label]:sr-only">
                                        <Input
                                            wide
                                            invalid={!!form.errors[`regions.${index}.code`]}
                                            value={region.code}
                                            onChange={(e) => setRegion(index, 'code', e.target.value.toUpperCase())}
                                            placeholder={t('admin.countries.mh', 'MH')}
                                        />
                                    </Field>
                                    <Field label={t('admin.countries.name_2', 'Name')} className="md:[&>label]:sr-only">
                                        <Input
                                            wide
                                            invalid={!!form.errors[`regions.${index}.name`]}
                                            value={region.name}
                                            onChange={(e) => setRegion(index, 'name', e.target.value)}
                                            placeholder={t('admin.countries.maharashtra', 'Maharashtra')}
                                        />
                                    </Field>
                                    <Checkbox
                                        label={t('admin.common.active', 'Active')}
                                        checked={!!region.is_enabled}
                                        onChange={(e) => setRegion(index, 'is_enabled', e.target.checked)}
                                    />
                                    <Button type="button" variant="danger" onClick={() => removeRegion(index)}>{t('admin.common.remove', 'Remove')}</Button>
                                </div>
                            ))}
                            {form.data.regions.length === 0 ? (
                                <p className={ui.muted}>{t('admin.countries.no_regions_yet', 'No regions yet.')}</p>
                            ) : null}
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div>
                                <h2 className={ui.sectionTitle}>{t('admin.countries.cities', 'Cities')}</h2>
                                <p className={ui.muted}>{t('admin.countries.cities_hint', 'Optional. When a country has cities, checkout can offer them as a list.')}</p>
                            </div>
                            <Button type="button" variant="secondary" icon="plus" onClick={addCity}>{t('admin.countries.add_city', 'Add city')}</Button>
                        </div>
                        <div className={`${ui.stack} mt-3`}>
                            {form.data.cities.map((city, index) => (
                                <div key={index} className="grid gap-2 sm:grid-cols-[1fr_8rem_auto_auto] items-end">
                                    <Field label={t('admin.countries.city_name', 'City')}>
                                        <Input wide value={city.name} onChange={(e) => setCity(index, 'name', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.countries.region_code', 'Region')}>
                                        <Input value={city.region_code} onChange={(e) => setCity(index, 'region_code', e.target.value.toUpperCase())} placeholder={t('admin.countries.mh', 'MH')} />
                                    </Field>
                                    <Checkbox
                                        label={t('admin.common.active', 'Active')}
                                        checked={!!city.is_enabled}
                                        onChange={(e) => setCity(index, 'is_enabled', e.target.checked)}
                                    />
                                    <Button type="button" variant="danger" onClick={() => removeCity(index)}>{t('admin.common.remove', 'Remove')}</Button>
                                </div>
                            ))}
                            {form.data.cities.length === 0 ? (
                                <p className={ui.muted}>{t('admin.countries.no_cities_yet', 'No cities yet.')}</p>
                            ) : null}
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.countries.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.countries.active_available_in_checkout_addresses', 'Active (available in checkout / addresses)')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                            disabled={!!form.data.is_default}
                        />
                        <div className="mt-3">
                            <Checkbox
                                label={t('admin.countries.default_store_country', 'Default store country')}
                                checked={!!form.data.is_default}
                                onChange={(e) => form.setData({
                                    ...form.data,
                                    is_default: e.target.checked,
                                    is_enabled: e.target.checked ? true : form.data.is_enabled,
                                })}
                            />
                        </div>
                        <p className={`${ui.muted} mt-3`}>
                            {t('admin.countries.countries_are_never_hard_deleted_deactivate_inst', 'Countries are never hard-deleted. Deactivate instead so historical orders remain valid.')}
                        </p>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
