import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { useStore } from '../../state/StoreState.jsx';
import PhoneField from '../../components/PhoneField.jsx';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormField, FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import {
    buildSupplierTranslations,
    emptySupplierTranslation,
    errText,
} from './supplierFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

export default function SellerSupplierForm() {
    const store = useStore();
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const [languages, setLanguages] = useState([]);
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [website, setWebsite] = useState('');
    const [address, setAddress] = useState('');
    const [city, setCity] = useState('');
    const [postcode, setPostcode] = useState('');
    const [country, setCountry] = useState('');
    const [isEnabled, setIsEnabled] = useState(true);
    const [productsCount, setProductsCount] = useState(0);
    const [fieldErrors, setFieldErrors] = useState({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);

    const defaultLanguage = useMemo(
        () => languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en',
        [languages],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptySupplierTranslation(languageCode);
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
                ...emptySupplierTranslation(selectedLanguage),
                ...(current[selectedLanguage] || {}),
                language_code: selectedLanguage,
                [field]: value,
            },
        }));
    };

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            try {
                if (isNew) {
                    const { data } = await api.get('/account/seller/suppliers');
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setTranslations(buildSupplierTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/suppliers/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const supplier = data.supplier || {};
                setLanguages(langs);
                setTranslations(buildSupplierTranslations(supplier, langs, def));
                setSelectedLanguage(def);
                setEmail(supplier.email || '');
                setPhone(supplier.phone || '');
                setWebsite(supplier.website || '');
                setAddress(supplier.address || '');
                setCity(supplier.city || '');
                setPostcode(supplier.postcode || '');
                setCountry(supplier.country || '');
                setIsEnabled(supplier.is_enabled !== false);
                setProductsCount(Number(supplier.products_count || 0));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load supplier.'));
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };
        load();
        return () => { cancelled = true; };
    }, [id, isNew]);

    const buildBody = () => {
        const defaultRow = translationFor(defaultLanguage);
        return {
            name: defaultRow.name || '',
            description: defaultRow.description || '',
            email: email || '',
            phone: phone || '',
            website: website || '',
            address: address || '',
            city: city || '',
            postcode: postcode || '',
            country: country || '',
            is_enabled: isEnabled,
            translations,
        };
    };

    const submit = async (event) => {
        event.preventDefault();
        setError('');
        setFieldErrors({});
        setBusy(true);
        try {
            await csrf();
            const body = buildBody();
            if (isNew) {
                await api.post('/account/seller/suppliers', body);
            } else {
                await api.patch(`/account/seller/suppliers/${id}`, body);
            }
            nav('/account/seller/suppliers');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteSupplier = async () => {
        if (!window.confirm(t('storefront.seller.delete_supplier_confirm', 'Delete this supplier? Products stay in the catalog.'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/suppliers/${id}`);
            nav('/account/seller/suppliers');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete supplier.'));
        }
    };

    if (loading) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">
                        {isNew
                            ? t('storefront.seller.add_supplier', 'Add supplier')
                            : t('storefront.seller.edit_supplier', 'Edit supplier')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteSupplier}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-supplier-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.supplier', 'Supplier')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.supplier_name', 'Name')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('name')}
                                span
                            >
                                <input
                                    className="shop-input"
                                    value={valueForField('name')}
                                    onChange={(e) => setTranslationField('name', e.target.value)}
                                    placeholder={t('storefront.seller.supplier_name_placeholder', 'Acme Wholesale')}
                                />
                            </LocalizedFormField>
                            <LocalizedFormField
                                label={t('storefront.seller.supplier_description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('description')}
                                span
                            >
                                <textarea
                                    className="shop-input"
                                    rows={3}
                                    value={valueForField('description')}
                                    onChange={(e) => setTranslationField('description', e.target.value)}
                                    placeholder={t('storefront.seller.supplier_description_placeholder', 'Who they are and what they supply')}
                                />
                            </LocalizedFormField>
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.contact', 'Contact')}</h3>
                        </div>
                        <div className="mv-field-grid">
                            <FormField label={t('storefront.seller.supplier_email', 'Email')} error={errText(fieldErrors, 'email')}>
                                <input className="shop-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                            </FormField>
                            <FormField label={t('storefront.seller.supplier_phone', 'Phone')} error={errText(fieldErrors, 'phone')}>
                                <PhoneField countries={store.shop?.countries || []} value={phone} onChange={setPhone} />
                            </FormField>
                            <FormField label={t('storefront.seller.website', 'Website')} error={errText(fieldErrors, 'website')}>
                                <input className="shop-input" type="url" value={website} onChange={(e) => setWebsite(e.target.value)} placeholder="https://" />
                            </FormField>
                            <FormField label={t('storefront.seller.supplier_country', 'Country')} error={errText(fieldErrors, 'country')}>
                                <input className="shop-input" value={country} onChange={(e) => setCountry(e.target.value)} />
                            </FormField>
                            <FormField label={t('storefront.seller.supplier_city', 'City')} error={errText(fieldErrors, 'city')}>
                                <input className="shop-input" value={city} onChange={(e) => setCity(e.target.value)} />
                            </FormField>
                            <FormField label={t('storefront.seller.supplier_postcode', 'Postcode')} error={errText(fieldErrors, 'postcode')}>
                                <input className="shop-input" value={postcode} onChange={(e) => setPostcode(e.target.value)} />
                            </FormField>
                        </div>
                        <div className="mv-section-grid mt-3">
                            <FormField label={t('storefront.seller.supplier_address', 'Address')} error={errText(fieldErrors, 'address')} span>
                                <input className="shop-input" value={address} onChange={(e) => setAddress(e.target.value)} />
                            </FormField>
                        </div>
                    </section>
                </div>

                <aside className="mv-form-side">
                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.status', 'Status')}</h3>
                        </div>
                        <FormSwitch
                            checked={isEnabled}
                            onChange={setIsEnabled}
                            label={t('storefront.seller.active', 'Active')}
                            hint={t('storefront.seller.inactive_supplier_hint', 'Inactive suppliers stay linked but are hidden when adding new products.')}
                        />
                        {!isNew ? (
                            <p className="mv-meta-k">
                                {productsCount} {t('storefront.seller.products_assigned', 'products assigned')}
                            </p>
                        ) : null}
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-action-list">
                            <button type="submit" className="shop-btn shop-btn-primary w-full" disabled={busy}>
                                {busy
                                    ? t('storefront.seller.working', 'Working…')
                                    : (
                                        <SaveBtnLabel>
                                            {isNew
                                                ? t('storefront.seller.create_supplier', 'Create supplier')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to="/account/seller/suppliers" className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
