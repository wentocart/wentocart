import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import SellerSearchSelect from '../../components/SellerSearchSelect.jsx';
import {
    buildAttributeValueTranslations,
    emptyAttributeValueTranslation,
    errText,
} from './attributeValueFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

export default function SellerAttributeValueForm() {
    const { id, attributeId: routeAttributeId } = useParams();
    const [searchParams] = useSearchParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const presetAttributeId = routeAttributeId || searchParams.get('attribute_id') || '';

    const [languages, setLanguages] = useState([]);
    const [attributes, setAttributes] = useState([]);
    const [swatchTypes, setSwatchTypes] = useState({});
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [attributeId, setAttributeId] = useState(presetAttributeId);
    const [swatchType, setSwatchType] = useState('none');
    const [colorCode, setColorCode] = useState('');
    const [swatchImage, setSwatchImage] = useState('');
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

    const selectedAttribute = useMemo(
        () => attributes.find((row) => String(row.id) === String(attributeId)) || null,
        [attributes, attributeId],
    );

    const swatchOptions = useMemo(
        () => Object.entries(swatchTypes).map(([value, label]) => ({ value, label })),
        [swatchTypes],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyAttributeValueTranslation(languageCode);
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
                ...emptyAttributeValueTranslation(selectedLanguage),
                ...(current[selectedLanguage] || {}),
                language_code: selectedLanguage,
                [field]: value,
            },
        }));
    };

    const cancelHref = presetAttributeId
        ? `/account/seller/attributes/${presetAttributeId}/values`
        : '/account/seller/attribute-values';

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            try {
                if (isNew) {
                    const query = presetAttributeId ? `?attribute_id=${presetAttributeId}` : '';
                    const { data } = await api.get(`/account/seller/attribute-values${query}`);
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setAttributes(data.attributes || []);
                    setSwatchTypes(data.swatch_types || {});
                    setTranslations(buildAttributeValueTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    if (presetAttributeId) {
                        setAttributeId(String(presetAttributeId));
                    }
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/attribute-values/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const attributeValue = data.attribute_value || {};
                setLanguages(langs);
                setAttributes(data.attributes || []);
                setSwatchTypes(data.swatch_types || {});
                setTranslations(buildAttributeValueTranslations(attributeValue, langs, def));
                setSelectedLanguage(def);
                setAttributeId(String(attributeValue.seller_attribute_id || ''));
                setSwatchType(attributeValue.swatch_type || 'none');
                setColorCode(attributeValue.color_code || '');
                setSwatchImage(attributeValue.swatch_image || '');
                setIsEnabled(attributeValue.is_enabled !== false);
                setProductsCount(Number(attributeValue.products_count || 0));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load attribute value.'));
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };
        load();
        return () => { cancelled = true; };
    }, [id, isNew, presetAttributeId]);

    const buildBody = () => {
        const defaultRow = translationFor(defaultLanguage);
        return {
            attribute_id: attributeId ? Number(attributeId) : null,
            value: defaultRow.value || '',
            swatch_type: swatchType,
            color_code: colorCode || null,
            swatch_image: swatchImage || null,
            is_enabled: isEnabled,
            translations,
        };
    };

    const submit = async (event) => {
        event.preventDefault();
        setError('');
        setFieldErrors({});
        if (!attributeId) {
            setFieldErrors({ attribute_id: t('storefront.seller.attribute_required', 'Attribute is required.') });
            return;
        }
        setBusy(true);
        try {
            await csrf();
            const body = buildBody();
            if (isNew) {
                await api.post('/account/seller/attribute-values', body);
            } else {
                await api.patch(`/account/seller/attribute-values/${id}`, body);
            }
            nav(cancelHref);
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteValue = async () => {
        if (productsCount > 0) {
            window.alert(t(
                'storefront.seller.attribute_value_delete_blocked',
                `This attribute value is assigned to ${productsCount} product${productsCount === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_attribute_value_confirm', 'Delete this attribute value?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/attribute-values/${id}`);
            nav(cancelHref);
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete attribute value.'));
        }
    };

    if (loading) {
        return <PageSkeleton />;
    }

    const noAttributes = attributes.length === 0;
    const showSwatches = !!selectedAttribute?.supports_swatches;

    return (
        <div>
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">
                        {isNew
                            ? t('storefront.seller.add_attribute_value', 'Add attribute value')
                            : t('storefront.seller.edit_attribute_value', 'Edit attribute value')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteValue}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-attribute-value-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <SellerSearchSelect
                                label={t('storefront.seller.attribute', 'Attribute')}
                                required
                                value={attributeId}
                                onChange={setAttributeId}
                                options={attributes}
                                selectedOptions={selectedAttribute ? [selectedAttribute] : []}
                                placeholder={t('storefront.seller.select_attribute', 'Select an attribute…')}
                                searchPlaceholder={t('storefront.seller.search_attributes', 'Search attributes…')}
                                error={errText(fieldErrors, 'attribute_id')}
                                hint={noAttributes ? t('storefront.seller.create_attribute_first', 'Create an attribute first, then add values to it.') : t('storefront.seller.attribute_value_attribute_hint', 'A value always belongs to one attribute.')}
                            />
                            <LocalizedFormField
                                label={t('storefront.seller.attribute_value_label', 'Value')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('value')}
                                span
                            >
                                <input
                                    className="shop-input"
                                    value={valueForField('value')}
                                    onChange={(e) => setTranslationField('value', e.target.value)}
                                    placeholder={t('storefront.seller.attribute_value_placeholder', 'e.g. Black')}
                                />
                            </LocalizedFormField>
                            {showSwatches ? (
                                <>
                                    <label className="mv-field">
                                        <span className="mv-field-label">{t('storefront.seller.swatch_type', 'Swatch type')}</span>
                                        <select className="shop-input" value={swatchType} onChange={(e) => setSwatchType(e.target.value)}>
                                            {swatchOptions.map((opt) => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </select>
                                    </label>
                                    {swatchType === 'color' ? (
                                        <label className="mv-field">
                                            <span className="mv-field-label">{t('storefront.seller.color_code', 'Color code')}</span>
                                            <input className="shop-input" value={colorCode} onChange={(e) => setColorCode(e.target.value)} placeholder="#000000" />
                                        </label>
                                    ) : null}
                                    {swatchType === 'image' ? (
                                        <label className="mv-field">
                                            <span className="mv-field-label">{t('storefront.seller.swatch_image', 'Swatch image URL')}</span>
                                            <input className="shop-input" value={swatchImage} onChange={(e) => setSwatchImage(e.target.value)} placeholder="https://…" />
                                        </label>
                                    ) : null}
                                </>
                            ) : null}
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
                            hint={t('storefront.seller.inactive_attribute_value_hint', 'Disabled values cannot be newly assigned to products. Existing assignments are kept.')}
                        />
                        {!isNew ? (
                            <p className="mv-meta-k">
                                {productsCount} {t('storefront.seller.products_assigned', 'products assigned')}
                            </p>
                        ) : null}
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-action-list">
                            <button type="submit" className="shop-btn shop-btn-primary w-full" disabled={busy || noAttributes}>
                                {busy
                                    ? t('storefront.seller.working', 'Working…')
                                    : (
                                        <SaveBtnLabel>
                                            {isNew
                                                ? t('storefront.seller.create_attribute_value', 'Create attribute value')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to={cancelHref} className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                            {noAttributes ? (
                                <Link to="/account/seller/attributes/new" className="shop-btn shop-btn-ghost w-full">
                                    {t('storefront.seller.add_attribute', 'Add attribute')}
                                </Link>
                            ) : null}
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
