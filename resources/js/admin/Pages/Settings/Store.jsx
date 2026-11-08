import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Button,
    Card,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    SettingsSectionHead,
    Input,
    Label,
    PageHeader,
    PhoneField,
    Select,
    parsePhone,
    formatPhone,
    ui,
    t,
    cx,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.settings', 'Store') },
];

function FooterCheck({ checked, onChange }) {
    return (
        <Checkbox
            label={t('admin.store.show_on_footer', 'Show on footer')}
            checked={!!checked}
            onChange={(e) => onChange(e.target.checked)}
        />
    );
}

function IdentityField({ label, required, optional, error, hint, footerOn, onFooterChange, children }) {
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-2">
                {label ? <Label required={required} optional={optional}>{label}</Label> : <span />}
                {onFooterChange ? <FooterCheck checked={footerOn} onChange={onFooterChange} /> : null}
            </div>
            {children}
            {hint ? <p className={ui.fieldHint}>{hint}</p> : null}
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
        </div>
    );
}

function countryMeta(countries, code) {
    return (countries || []).find((row) => row.code === code) || null;
}

function defaultFooter() {
    return {
        name: true,
        email: true,
        phone: true,
        address: true,
        business_number: false,
    };
}

export default function Store({ store, countries = [] }) {
    const { i18n } = usePage().props;
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: i18n?.default || 'en',
        name: i18n?.default_name || 'English',
        is_default: true,
    }]);
    const defaultLang = i18n?.default || languages[0]?.code || 'en';
    const identity = store.identity || {};
    const initialNames = { ...(identity.names || {}) };
    if (!initialNames[defaultLang]) {
        initialNames[defaultLang] = store.shop_name || '';
    }
    const parsedPhone = parsePhone(identity.phone || '');
    const initial = {
        shop_name: initialNames[defaultLang] || store.shop_name || '',
        shop_email: identity.email || store.shop_email || '',
        names: initialNames,
        phone: identity.phone || '',
        line1: identity.line1 || '',
        line2: identity.line2 || '',
        city: identity.city || '',
        region: identity.region || '',
        postal: identity.postal || '',
        country_code: identity.country_code || '',
        business_number: identity.business_number || identity.gst || identity.vat || '',
        footer: { ...defaultFooter(), ...(identity.footer || {}) },
    };
    const form = useForm(initial);
    const [nameLang, setNameLang] = useState(defaultLang);
    const [phoneCode, setPhoneCode] = useState(parsedPhone.phone_code);
    const [phoneNational, setPhoneNational] = useState(parsedPhone.phone);

    const setFooter = (key, on) => {
        form.setData('footer', { ...form.data.footer, [key]: on });
    };

    const setNameForLang = (code, value) => {
        const names = { ...form.data.names, [code]: value };
        form.setData({
            ...form.data,
            names,
            shop_name: names[defaultLang] || value,
        });
    };

    const setPhoneParts = (code, national) => {
        setPhoneCode(code);
        setPhoneNational(national);
        form.setData('phone', formatPhone(code, national));
    };

    const selectedCountry = countryMeta(countries, form.data.country_code);
    const regions = selectedCountry?.regions || [];
    const regionStored = form.data.region || '';
    const regionMatch = regions.find((row) => row.code === regionStored)
        || regions.find((row) => row.name === regionStored);
    const regionValue = regionMatch ? (regionMatch.code || regionMatch.name) : regionStored;
    const cities = (selectedCountry?.cities || []).filter((row) => (
        !form.data.region || !row.region_code || row.region_code === form.data.region
    ));
    const postalLabel = selectedCountry?.postal_code_label || t('admin.store.pincode', 'PIN / ZIP');

    const setCountry = (code) => {
        const next = countryMeta(countries, code);
        const regionOk = (next?.regions || []).some((row) => row.code === form.data.region || row.name === form.data.region);
        form.setData({
            ...form.data,
            country_code: code,
            region: regionOk ? form.data.region : '',
            city: '',
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.store.title', 'Store settings')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.store.subtitle', "Manage your shop's name, contact details, and address. Configure how this information appears to your customers.")}
            />

            <Alert type="info" className="mb-4">
                <div className="font-medium">{t('admin.store.store_url', 'Store URL')}</div>
                {store.shop_url ? (
                    <a
                        href={store.shop_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-0.5 block break-all font-mono text-[13px] underline"
                    >
                        {store.shop_url}
                    </a>
                ) : (
                    <div className="mt-0.5 font-mono text-[13px]">—</div>
                )}
                <p className="mt-1 text-xs">
                    {t('admin.store.store_url_hint', 'Set when the shop was installed. It cannot be changed here.')}
                </p>
            </Alert>

            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    const names = { ...form.data.names, [nameLang]: form.data.names[nameLang] || '' };
                    names[defaultLang] = names[defaultLang] || form.data.shop_name;
                    form.transform((data) => ({
                        ...data,
                        names,
                        shop_name: names[defaultLang] || data.shop_name,
                        phone: formatPhone(phoneCode, phoneNational),
                        footer: {
                            name: !!data.footer.name,
                            email: !!data.footer.email,
                            phone: !!data.footer.phone,
                            address: !!data.footer.address,
                            business_number: !!data.footer.business_number,
                            vat: false,
                            gst: false,
                        },
                    }));
                    form.put('/admin/store');
                }}
            >
                <FormErrors errors={form.errors} />

                <div className={ui.formLayoutEqual}>
                    <div className="min-w-0 space-y-4">
                        <Card className="space-y-4">
                            <SettingsSectionHead
                                icon="bag"
                                tone={ui.statIconPrimary}
                                title={t('admin.store.shop_information', 'Shop Information')}
                                hint={t('admin.store.shop_information_hint', 'Legal and contact details. Only the store name can differ by language.')}
                            />
                            <IdentityField
                                label={t('admin.store.store_name', 'Store name')}
                                required
                                error={form.errors.shop_name || form.errors[`names.${nameLang}`]}
                                footerOn={form.data.footer.name}
                                onFooterChange={(on) => setFooter('name', on)}
                            >
                                <div className="flex items-center gap-2">
                                    <div className="w-1/2 min-w-0">
                                        <Input
                                            wide
                                            invalid={!!form.errors.shop_name}
                                            value={form.data.names[nameLang] || ''}
                                            onChange={(e) => setNameForLang(nameLang, e.target.value)}
                                        />
                                    </div>
                                    <Select
                                        className={ui.localizedFieldSelect}
                                        value={nameLang}
                                        onChange={(e) => setNameLang(e.target.value)}
                                    >
                                        {languages.map((row) => (
                                            <option key={row.code} value={row.code}>
                                                {row.code.toUpperCase()}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                            </IdentityField>
                            <IdentityField
                                label={t('admin.store.store_email', 'Store email')}
                                required
                                error={form.errors.shop_email}
                                footerOn={form.data.footer.email}
                                onFooterChange={(on) => setFooter('email', on)}
                            >
                                <Input wide type="email" invalid={!!form.errors.shop_email} value={form.data.shop_email} onChange={(e) => form.setData('shop_email', e.target.value)} />
                            </IdentityField>
                            <IdentityField
                                label={t('admin.store.phone', 'Phone')}
                                optional
                                error={form.errors.phone}
                                footerOn={form.data.footer.phone}
                                onFooterChange={(on) => setFooter('phone', on)}
                            >
                                <PhoneField
                                    label={null}
                                    hint={t('admin.common.phone_hint', 'Digits only, 6–15 characters.')}
                                    phoneCode={phoneCode}
                                    phone={phoneNational}
                                    onCodeChange={(code) => setPhoneParts(code, phoneNational)}
                                    onPhoneChange={(national) => setPhoneParts(phoneCode, national)}
                                    error={form.errors.phone}
                                />
                            </IdentityField>
                            <IdentityField
                                label={t('admin.store.business_number', 'Business Number')}
                                optional
                                hint={t('admin.store.business_number_hint', 'GST, VAT, CIN, or other registration number. Shown on invoices and, if enabled, the footer.')}
                                error={form.errors.business_number}
                                footerOn={form.data.footer.business_number}
                                onFooterChange={(on) => setFooter('business_number', on)}
                            >
                                <Input wide value={form.data.business_number} onChange={(e) => form.setData('business_number', e.target.value)} />
                            </IdentityField>
                        </Card>
                    </div>

                    <aside className="min-w-0 space-y-4">
                        <Card className="space-y-4">
                            <div className={ui.localizedFieldHead}>
                                <SettingsSectionHead
                                    icon="pin"
                                    tone={ui.statIconInfo}
                                    title={t('admin.store.address', 'Address')}
                                    hint={t('admin.store.address_hint', 'Shown as one block on the footer when enabled.')}
                                />
                                <FooterCheck checked={form.data.footer.address} onChange={(on) => setFooter('address', on)} />
                            </div>
                            <div className="space-y-3">
                                <Field label={t('admin.store.address1', 'Address line 1')} optional error={form.errors.line1}>
                                    <Input wide value={form.data.line1} onChange={(e) => form.setData('line1', e.target.value)} autoComplete="address-line1" placeholder={t('admin.store.address1_ph', 'Street, building, shop number')} />
                                </Field>
                                <Field label={t('admin.store.address2', 'Address line 2')} optional error={form.errors.line2}>
                                    <Input wide value={form.data.line2} onChange={(e) => form.setData('line2', e.target.value)} autoComplete="address-line2" placeholder={t('admin.store.address2_ph', 'Floor, landmark')} />
                                </Field>
                                <Field label={t('admin.store.city', 'City')} optional error={form.errors.city}>
                                    {cities.length ? (
                                        <Select
                                            wide
                                            value={form.data.city || ''}
                                            onChange={(e) => {
                                                const city = cities.find((row) => row.name === e.target.value);
                                                form.setData({
                                                    ...form.data,
                                                    city: e.target.value,
                                                    region: city?.region_code || city?.region_name || form.data.region,
                                                });
                                            }}
                                        >
                                            <option value="">{t('admin.common.select', 'Select')}</option>
                                            {cities.map((row) => (
                                                <option key={`${row.region_code || ''}-${row.name}`} value={row.name}>{row.name}</option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <Input wide value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                                    )}
                                </Field>
                                {regions.length ? (
                                    <Field label={t('admin.store.state', 'State / region')} optional error={form.errors.region}>
                                        <Select wide value={regionValue} onChange={(e) => form.setData('region', e.target.value)}>
                                            <option value="">{t('admin.common.select', 'Select')}</option>
                                            {regions.map((row) => (
                                                <option key={row.code || row.name} value={row.code || row.name}>{row.name}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                ) : null}
                                <Field label={postalLabel} optional error={form.errors.postal}>
                                    <Input wide value={form.data.postal} onChange={(e) => form.setData('postal', e.target.value)} autoComplete="postal-code" />
                                </Field>
                                <Field label={t('admin.store.country', 'Country')} optional error={form.errors.country_code}>
                                    <Select wide value={form.data.country_code || ''} onChange={(e) => setCountry(e.target.value)}>
                                        <option value="">{t('admin.common.select', 'Select')}</option>
                                        {countries.map((row) => (
                                            <option key={row.code} value={row.code}>{row.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                            </div>
                        </Card>
                    </aside>
                </div>

                <div className={cx(ui.formSticky, 'justify-end')}>
                    <Button icon="check" busy={form.processing}>
                        {t('admin.store.save_changes', 'Save changes')}
                    </Button>
                </div>
            </form>
        </Layout>
    );
}
