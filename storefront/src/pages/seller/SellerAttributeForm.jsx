import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import {
    buildAttributeTranslations,
    emptyAttributeTranslation,
    errText,
} from './attributeFormUtils.js';
import { AddBtnLabel, SaveBtnLabel } from './sellerUi.jsx';

export default function SellerAttributeForm() {
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const [languages, setLanguages] = useState([]);
    const [typeOptions, setTypeOptions] = useState([]);
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [type, setType] = useState('select');
    const [supportsSwatches, setSupportsSwatches] = useState(false);
    const [isEnabled, setIsEnabled] = useState(true);
    const [productsCount, setProductsCount] = useState(0);
    const [valuesCount, setValuesCount] = useState(0);
    const [fieldErrors, setFieldErrors] = useState({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);

    const defaultLanguage = useMemo(
        () => languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en',
        [languages],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyAttributeTranslation(languageCode);
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
                ...emptyAttributeTranslation(selectedLanguage),
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
                    const { data } = await api.get('/account/seller/attributes');
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setTypeOptions(data.type_options || []);
                    setTranslations(buildAttributeTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/attributes/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const attribute = data.attribute || {};
                setLanguages(langs);
                setTypeOptions(data.type_options || []);
                setTranslations(buildAttributeTranslations(attribute, langs, def));
                setSelectedLanguage(def);
                setType(attribute.type || 'select');
                setSupportsSwatches(!!attribute.supports_swatches);
                setIsEnabled(attribute.is_enabled !== false);
                setProductsCount(Number(attribute.products_count || 0));
                setValuesCount(Number(attribute.values_count || 0));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load attribute.'));
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
            type,
            supports_swatches: supportsSwatches,
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
                await api.post('/account/seller/attributes', body);
            } else {
                await api.patch(`/account/seller/attributes/${id}`, body);
            }
            nav('/account/seller/attributes');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteAttribute = async () => {
        if (productsCount > 0) {
            window.alert(t(
                'storefront.seller.attribute_delete_blocked',
                `This attribute is assigned to ${productsCount} product${productsCount === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_attribute_confirm', 'Delete this attribute?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/attributes/${id}`);
            nav('/account/seller/attributes');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete attribute.'));
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
                            ? t('storefront.seller.add_attribute', 'Add attribute')
                            : t('storefront.seller.edit_attribute', 'Edit attribute')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteAttribute}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-attribute-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.attribute_name', 'Attribute name')}
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
                                    placeholder={t('storefront.seller.attribute_name_placeholder', 'e.g. Color')}
                                />
                            </LocalizedFormField>
                            <LocalizedFormField
                                label={t('storefront.seller.attribute_description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('description')}
                                span
                            >
                                <RichEditor
                                    value={valueForField('description')}
                                    onChange={(html) => setTranslationField('description', html)}
                                />
                            </LocalizedFormField>
                            <label className="mv-field">
                                <span className="mv-field-label">{t('storefront.seller.attribute_type', 'Attribute type')}</span>
                                <select className="shop-input" value={type} onChange={(e) => setType(e.target.value)}>
                                    {typeOptions.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </select>
                                {errText(fieldErrors, 'type') ? (
                                    <span className="mv-field-error">{errText(fieldErrors, 'type')}</span>
                                ) : null}
                            </label>
                            <FormSwitch
                                checked={supportsSwatches}
                                onChange={setSupportsSwatches}
                                label={t('storefront.seller.supports_swatches', 'Supports swatches')}
                                hint={t('storefront.seller.supports_swatches_hint', 'Enable for color/image swatches on attribute values (e.g. Color).')}
                            />
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
                            label={t('storefront.seller.enabled', 'Enabled')}
                            hint={t('storefront.seller.inactive_attribute_hint', 'Disabled attributes cannot be used for new product combinations. Existing combinations are kept.')}
                        />
                        {!isNew ? (
                            <>
                                <p className="mv-meta-k">
                                    <Link className="mv-link" to={`/account/seller/attributes/${id}/values`}>
                                        {valuesCount} {t('storefront.seller.attribute_values', 'values')}
                                    </Link>
                                </p>
                                <p className="mv-meta-k">
                                    {productsCount} {t('storefront.seller.products_assigned', 'products assigned')}
                                </p>
                                <Link to={`/account/seller/attributes/${id}/values/new`} className="shop-btn shop-btn-ghost w-full">
                                    <AddBtnLabel>{t('storefront.seller.add_value', 'Add value')}</AddBtnLabel>
                                </Link>
                            </>
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
                                                ? t('storefront.seller.create_attribute', 'Create attribute')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to="/account/seller/attributes" className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
