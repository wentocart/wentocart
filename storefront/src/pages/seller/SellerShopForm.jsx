import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { FormActions, FormNotice, FormSection, FormShell, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import { Icon } from '../../components/Icons.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import { mediaUrl } from '../../lib/format.js';
import { Field } from './SellerHome.jsx';
import { buildShopTranslations, emptyShopTranslation, errText } from './shopFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }

    return mediaUrl(fallback) || fallback || null;
}

function ShopMediaField({
    label,
    preview,
    previewClass = '',
    inputKey,
    onFileChange,
    onRemove,
    removeLabel,
}) {
    return (
        <Field label={label} span>
            <input
                key={inputKey}
                type="file"
                accept="image/*"
                className="shop-input"
                onChange={onFileChange}
            />
            {preview ? (
                <div className="mv-media-preview">
                    <img src={preview} alt="" className={`mv-form-preview ${previewClass}`.trim()} />
                    <button
                        type="button"
                        className="mv-media-preview-remove"
                        onClick={onRemove}
                        aria-label={removeLabel}
                    >
                        <Icon name="trash" className="h-4 w-4" />
                    </button>
                </div>
            ) : null}
        </Field>
    );
}

export function shopFormFromSeller(seller) {
    return {
        shop_name: seller?.shop_name || '',
        shop_slug: seller?.shop_slug || '',
        address_line1: seller?.address_line1 || '',
        address_line2: seller?.address_line2 || '',
        city: seller?.city || '',
        state: seller?.state || '',
        postal_code: seller?.postal_code || '',
        country_code: seller?.country_code || 'US',
        facebook_url: seller?.facebook_url || '',
        twitter_url: seller?.twitter_url || '',
        instagram_url: seller?.instagram_url || '',
        youtube_url: seller?.youtube_url || '',
        is_active: seller?.is_active !== false,
        vacation_enabled: !!seller?.vacation_enabled,
    };
}

function regionsFor(countries, code) {
    return (countries || []).find((row) => row.code === code)?.regions || [];
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

export default function SellerShopForm({ seller, setSeller }) {
    const [countries, setCountries] = useState([]);
    const [languages, setLanguages] = useState([]);
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [translations, setTranslations] = useState({});
    const [form, setForm] = useState(() => shopFormFromSeller(seller));
    const [logoFile, setLogoFile] = useState(null);
    const [bannerFile, setBannerFile] = useState(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [removeBanner, setRemoveBanner] = useState(false);
    const [logoInputKey, setLogoInputKey] = useState(0);
    const [bannerInputKey, setBannerInputKey] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [saved, setSaved] = useState(false);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const stateOptions = regionsFor(countries, form.country_code);
    const countriesReady = countries.length > 0;
    const countryKnown = !!countries.find((row) => row.code === form.country_code);
    const statesLoading = countriesReady && !countryKnown;
    const showStateField = stateOptions.length > 0;
    const stateValue = resolveStateCode(form.state, stateOptions);
    const logoPreview = removeLogo ? null : previewUrl(logoFile, seller?.logo_url);
    const bannerPreview = removeBanner ? null : previewUrl(bannerFile, seller?.banner_url);

    const defaultLanguage = useMemo(
        () => languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en',
        [languages],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyShopTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const fe = (field) => (
        errText(fieldErrors, `translations.${selectedLanguage}.${field}`)
        || (selectedLanguage === defaultLanguage ? errText(fieldErrors, field) : '')
    );

    const setTranslationField = (field, value) => {
        setFieldErrors((prev) => {
            const next = { ...prev };
            delete next[field];
            delete next[`translations.${selectedLanguage}.${field}`];
            return next;
        });
        setTranslations((current) => ({
            ...current,
            [selectedLanguage]: {
                ...emptyShopTranslation(selectedLanguage),
                ...(current[selectedLanguage] || {}),
                language_code: selectedLanguage,
                [field]: value,
            },
        }));
    };

    const setCountry = (code) => {
        setForm((current) => ({
            ...current,
            country_code: code,
            state: '',
        }));
    };

    useEffect(() => {
        api.get('/account/seller').then((r) => {
            const langs = r.data.languages || [];
            const def = r.data.default_locale || langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
            setCountries(r.data.countries || []);
            setLanguages(langs);
            setSelectedLanguage(def);
            if (r.data.seller) {
                setSeller(r.data.seller);
                setForm(shopFormFromSeller(r.data.seller));
                setTranslations(buildShopTranslations(r.data.seller, langs, def));
            } else if (langs.length) {
                setTranslations(buildShopTranslations(null, langs, def));
            }
        });
    }, [setSeller]);

    const buildFormData = () => {
        const body = new FormData();
        const defaultRow = translationFor(defaultLanguage);

        body.append('_method', 'PATCH');
        body.append('shop_name', form.shop_name);
        body.append('shop_slug', form.shop_slug);
        body.append('address_line1', form.address_line1);
        body.append('address_line2', form.address_line2 || '');
        body.append('city', form.city);
        body.append('state', showStateField ? stateValue : '');
        body.append('postal_code', form.postal_code);
        body.append('country_code', form.country_code);
        body.append('facebook_url', form.facebook_url || '');
        body.append('twitter_url', form.twitter_url || '');
        body.append('instagram_url', form.instagram_url || '');
        body.append('youtube_url', form.youtube_url || '');
        body.append('is_active', form.is_active ? '1' : '0');
        body.append('vacation_enabled', form.vacation_enabled ? '1' : '0');
        body.append('description', defaultRow.description || '');
        body.append('store_policy', defaultRow.store_policy || '');
        body.append('shipping_policy', defaultRow.shipping_policy || '');
        body.append('return_policy', defaultRow.return_policy || '');
        body.append('vacation_message', defaultRow.vacation_message || '');

        Object.entries(translations).forEach(([lang, row]) => {
            body.append(`translations[${lang}][language_code]`, lang);
            body.append(`translations[${lang}][shop_name]`, form.shop_name || '');
            ['description', 'store_policy', 'shipping_policy', 'return_policy', 'vacation_message'].forEach((key) => {
                body.append(`translations[${lang}][${key}]`, String(row?.[key] ?? ''));
            });
        });

        if (removeLogo) body.append('remove_logo', '1');
        if (removeBanner) body.append('remove_banner', '1');
        if (logoFile) body.append('logo', logoFile);
        if (bannerFile) body.append('banner', bannerFile);

        return body;
    };

    return (
        <FormShell
            size="wide"
            onSubmit={async (e) => {
                e.preventDefault();
                setError('');
                setFieldErrors({});
                setSaved(false);
                setBusy(true);
                try {
                    await csrf();
                    const { data } = await api.post('/account/seller/shop', buildFormData());
                    const def = defaultLanguage;
                    setSeller(data.seller);
                    setForm(shopFormFromSeller(data.seller));
                    setTranslations(buildShopTranslations(data.seller, languages, def));
                    setLogoFile(null);
                    setBannerFile(null);
                    setRemoveLogo(false);
                    setRemoveBanner(false);
                    setSaved(true);
                } catch (err) {
                    setFieldErrors(err.response?.data?.errors || {});
                    setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                } finally {
                    setBusy(false);
                }
            }}
        >
            <FormSection title={t('storefront.seller.section_identity', 'Seller identity')}>
                <Field label={t('storefront.seller.shop_name', 'Shop name')}><input className="shop-input" value={form.shop_name} onChange={(e) => set('shop_name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.shop_slug', 'Unique shop name')}><input className="shop-input" value={form.shop_slug} onChange={(e) => set('shop_slug', e.target.value.toLowerCase())} required /></Field>
                <LocalizedFormField
                    label={t('storefront.seller.description', 'Shop description')}
                    languages={languages}
                    currentLanguage={selectedLanguage}
                    onLanguageChange={setSelectedLanguage}
                    error={fe('description')}
                    span
                >
                    <RichEditor
                        value={valueForField('description')}
                        onChange={(html) => setTranslationField('description', html)}
                        placeholder={t('storefront.seller.shop_description_placeholder', 'Describe your shop, products, and what makes you unique…')}
                    />
                </LocalizedFormField>
                <ShopMediaField
                    label={t('storefront.seller.logo', 'Shop logo')}
                    preview={logoPreview}
                    previewClass="mv-form-preview--logo"
                    inputKey={logoInputKey}
                    removeLabel={t('storefront.seller.remove_logo', 'Remove logo')}
                    onFileChange={(e) => {
                        setLogoFile(e.target.files?.[0] || null);
                        setRemoveLogo(false);
                    }}
                    onRemove={() => {
                        setLogoFile(null);
                        setRemoveLogo(!!seller?.logo_url);
                        setLogoInputKey((key) => key + 1);
                    }}
                />
                <ShopMediaField
                    label={t('storefront.seller.banner', 'Shop banner')}
                    preview={bannerPreview}
                    previewClass="mv-form-preview--banner"
                    inputKey={bannerInputKey}
                    removeLabel={t('storefront.seller.remove_banner', 'Remove banner')}
                    onFileChange={(e) => {
                        setBannerFile(e.target.files?.[0] || null);
                        setRemoveBanner(false);
                    }}
                    onRemove={() => {
                        setBannerFile(null);
                        setRemoveBanner(!!seller?.banner_url);
                        setBannerInputKey((key) => key + 1);
                    }}
                />
            </FormSection>
            <FormSection title={t('storefront.seller.section_address', 'Shop address')}>
                <Field label={t('storefront.seller.address', 'Shop address')} required span><input className="shop-input" value={form.address_line1} onChange={(e) => set('address_line1', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.address2', 'Address line 2')} span><input className="shop-input" value={form.address_line2} onChange={(e) => set('address_line2', e.target.value)} /></Field>
                <Field label={t('storefront.seller.city', 'City')} required><input className="shop-input" value={form.city} onChange={(e) => set('city', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.postal', 'Postal code')} required><input className="shop-input" value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.country', 'Country')} required>
                    <select className="shop-input" value={form.country_code} onChange={(e) => setCountry(e.target.value)} disabled={!countriesReady} required>
                        {!countriesReady ? <option value="">{t('storefront.account.loading', 'Loading…')}</option> : null}
                        {(countries.length ? countries : [{ code: 'US', name: 'United States' }]).map((country) => (
                            <option key={country.code} value={country.code}>{country.name}</option>
                        ))}
                    </select>
                </Field>
                {statesLoading ? (
                    <Field label={t('storefront.seller.state', 'State')}>
                        <select className="shop-input" disabled aria-busy="true">
                            <option value="">{t('storefront.account.loading', 'Loading…')}</option>
                        </select>
                    </Field>
                ) : null}
                {showStateField ? (
                    <Field label={t('storefront.seller.state', 'State')} required>
                        <select className="shop-input" value={stateValue} onChange={(e) => set('state', e.target.value)} required>
                            <option value="">{t('storefront.account.select_state', 'Select state')}</option>
                            {stateOptions.map((region) => (
                                <option key={region.code} value={region.code}>{region.name}</option>
                            ))}
                        </select>
                    </Field>
                ) : null}
            </FormSection>
            <FormSection title={t('storefront.seller.section_policies', 'Store policies')}>
                <LocalizedFormField
                    label={t('storefront.seller.store_policy', 'Store policy')}
                    languages={languages}
                    currentLanguage={selectedLanguage}
                    onLanguageChange={setSelectedLanguage}
                    error={fe('store_policy')}
                    span
                >
                    <RichEditor
                        value={valueForField('store_policy')}
                        onChange={(html) => setTranslationField('store_policy', html)}
                        placeholder={t('storefront.seller.store_policy_placeholder', 'Explain your store terms and customer expectations…')}
                    />
                </LocalizedFormField>
                <LocalizedFormField
                    label={t('storefront.seller.shipping_policy', 'Shipping policy')}
                    languages={languages}
                    currentLanguage={selectedLanguage}
                    onLanguageChange={setSelectedLanguage}
                    error={fe('shipping_policy')}
                    span
                >
                    <RichEditor
                        value={valueForField('shipping_policy')}
                        onChange={(html) => setTranslationField('shipping_policy', html)}
                        placeholder={t('storefront.seller.shipping_policy_placeholder', 'Describe delivery times, carriers, and shipping costs…')}
                    />
                </LocalizedFormField>
                <LocalizedFormField
                    label={t('storefront.seller.return_policy', 'Return policy')}
                    languages={languages}
                    currentLanguage={selectedLanguage}
                    onLanguageChange={setSelectedLanguage}
                    error={fe('return_policy')}
                    span
                >
                    <RichEditor
                        value={valueForField('return_policy')}
                        onChange={(html) => setTranslationField('return_policy', html)}
                        placeholder={t('storefront.seller.return_policy_placeholder', 'Explain returns, refunds, and exchanges…')}
                    />
                </LocalizedFormField>
            </FormSection>
            <FormSection title={t('storefront.seller.section_social', 'Social links')}>
                <Field label={t('storefront.seller.facebook', 'Facebook URL')}><input className="shop-input" value={form.facebook_url} onChange={(e) => set('facebook_url', e.target.value)} /></Field>
                <Field label={t('storefront.seller.twitter', 'X URL')}><input className="shop-input" value={form.twitter_url} onChange={(e) => set('twitter_url', e.target.value)} /></Field>
                <Field label={t('storefront.seller.instagram', 'Instagram URL')}><input className="shop-input" value={form.instagram_url} onChange={(e) => set('instagram_url', e.target.value)} /></Field>
                <Field label={t('storefront.seller.youtube', 'YouTube URL')}><input className="shop-input" value={form.youtube_url} onChange={(e) => set('youtube_url', e.target.value)} /></Field>
            </FormSection>
            <FormSection title={t('storefront.seller.section_visibility', 'Visibility')} description={t('storefront.seller.visibility_help', 'Turn off to hide your public shop while keeping your seller account.')}>
                <FormSwitch checked={form.is_active} onChange={(on) => set('is_active', on)} label={t('storefront.seller.shop_visible', 'Shop is visible')} />
            </FormSection>
            <FormSection title={t('storefront.seller.section_vacation', 'Vacation mode')} description={t('storefront.seller.vacation_help', 'Products stay listed but cannot be ordered while vacation mode is on.')}>
                <FormSwitch checked={form.vacation_enabled} onChange={(on) => set('vacation_enabled', on)} label={t('storefront.seller.vacation', 'Vacation mode')} />
                <LocalizedFormField
                    label={t('storefront.seller.vacation_message', 'Vacation message')}
                    languages={languages}
                    currentLanguage={selectedLanguage}
                    onLanguageChange={setSelectedLanguage}
                    error={fe('vacation_message')}
                    span
                >
                    <RichEditor
                        value={valueForField('vacation_message')}
                        onChange={(html) => setTranslationField('vacation_message', html)}
                        compact
                        placeholder={t('storefront.seller.vacation_message_placeholder', 'Let customers know when you will be back…')}
                    />
                </LocalizedFormField>
            </FormSection>
            <FormNotice tone="error">{error}</FormNotice>
            {Object.keys(fieldErrors).length > 0 ? (
                <ul className="text-sm mv-error">
                    {Object.entries(fieldErrors).map(([key, messages]) => (
                        <li key={key}>{Array.isArray(messages) ? messages[0] : messages}</li>
                    ))}
                </ul>
            ) : null}
            <FormNotice tone="ok">{saved ? t('storefront.account.saved', 'Saved.') : null}</FormNotice>
            <FormActions>
                {seller?.shop_slug ? (
                    <Link
                        to={`/shop/${seller.shop_slug}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="shop-btn shop-btn-ghost"
                    >
                        <Icon name="store" className="h-4 w-4 shrink-0" />
                        {t('storefront.seller.view_shop', 'View public shop')}
                    </Link>
                ) : null}
                <button className="shop-btn shop-btn-primary" disabled={busy} aria-busy={busy || undefined}>
                    {busy ? t('storefront.account.saving', 'Saving…') : (
                        <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                    )}
                </button>
            </FormActions>
        </FormShell>
    );
}
