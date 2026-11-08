import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import SellerSearchSelect from '../../components/SellerSearchSelect.jsx';
import {
    buildFeatureValueTranslations,
    emptyFeatureValueTranslation,
    errText,
} from './featureValueFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

export default function SellerFeatureValueForm() {
    const { id, featureId: routeFeatureId } = useParams();
    const [searchParams] = useSearchParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const presetFeatureId = routeFeatureId || searchParams.get('feature_id') || '';

    const [languages, setLanguages] = useState([]);
    const [features, setFeatures] = useState([]);
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [featureId, setFeatureId] = useState(presetFeatureId);
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

    const selectedFeature = useMemo(
        () => features.find((row) => String(row.id) === String(featureId)) || null,
        [features, featureId],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyFeatureValueTranslation(languageCode);
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
                ...emptyFeatureValueTranslation(selectedLanguage),
                ...(current[selectedLanguage] || {}),
                language_code: selectedLanguage,
                [field]: value,
            },
        }));
    };

    const cancelHref = presetFeatureId
        ? `/account/seller/features/${presetFeatureId}/values`
        : '/account/seller/feature-values';

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            try {
                if (isNew) {
                    const query = presetFeatureId ? `?feature_id=${presetFeatureId}` : '';
                    const { data } = await api.get(`/account/seller/feature-values${query}`);
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setFeatures(data.features || []);
                    setTranslations(buildFeatureValueTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    if (presetFeatureId) {
                        setFeatureId(String(presetFeatureId));
                    }
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/feature-values/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const featureValue = data.feature_value || {};
                setLanguages(langs);
                setFeatures(data.features || []);
                setTranslations(buildFeatureValueTranslations(featureValue, langs, def));
                setSelectedLanguage(def);
                setFeatureId(String(featureValue.seller_feature_id || ''));
                setIsEnabled(featureValue.is_enabled !== false);
                setProductsCount(Number(featureValue.products_count || 0));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load feature value.'));
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };
        load();
        return () => { cancelled = true; };
    }, [id, isNew, presetFeatureId]);

    const buildBody = () => {
        const defaultRow = translationFor(defaultLanguage);
        return {
            feature_id: featureId ? Number(featureId) : null,
            value: defaultRow.value || '',
            is_enabled: isEnabled,
            translations,
        };
    };

    const submit = async (event) => {
        event.preventDefault();
        setError('');
        setFieldErrors({});
        if (!featureId) {
            setFieldErrors({ feature_id: t('storefront.seller.feature_required', 'Feature is required.') });
            return;
        }
        setBusy(true);
        try {
            await csrf();
            const body = buildBody();
            if (isNew) {
                await api.post('/account/seller/feature-values', body);
            } else {
                await api.patch(`/account/seller/feature-values/${id}`, body);
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
                'storefront.seller.feature_value_delete_blocked',
                `This feature value is assigned to ${productsCount} product${productsCount === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_feature_value_confirm', 'Delete this feature value?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/feature-values/${id}`);
            nav(cancelHref);
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete feature value.'));
        }
    };

    if (loading) {
        return <PageSkeleton />;
    }

    const noFeatures = features.length === 0;

    return (
        <div>
            <div className="mv-toolbar">
                <div>
                    <h2 className="mv-page-title">
                        {isNew
                            ? t('storefront.seller.add_feature_value', 'Add feature value')
                            : t('storefront.seller.edit_feature_value', 'Edit feature value')}
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

            <form className="mv-form-layout mv-feature-value-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <SellerSearchSelect
                                label={t('storefront.seller.feature', 'Feature')}
                                required
                                value={featureId}
                                onChange={setFeatureId}
                                options={features}
                                selectedOptions={selectedFeature ? [selectedFeature] : []}
                                placeholder={t('storefront.seller.select_feature', 'Select a feature…')}
                                searchPlaceholder={t('storefront.seller.search_features', 'Search features…')}
                                error={errText(fieldErrors, 'feature_id')}
                                hint={noFeatures ? t('storefront.seller.create_feature_first', 'Create a feature first, then add values to it.') : t('storefront.seller.feature_value_feature_hint', 'A value always belongs to one feature.')}
                            />
                            <LocalizedFormField
                                label={t('storefront.seller.feature_value_label', 'Value')}
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
                                    placeholder={t('storefront.seller.feature_value_placeholder', 'e.g. 8 GB')}
                                />
                            </LocalizedFormField>
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
                            hint={t('storefront.seller.inactive_feature_value_hint', 'Disabled values cannot be newly assigned to products. Existing assignments are kept.')}
                        />
                        {!isNew ? (
                            <p className="mv-meta-k">
                                {productsCount} {t('storefront.seller.products_assigned', 'products assigned')}
                            </p>
                        ) : null}
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-action-list">
                            <button type="submit" className="shop-btn shop-btn-primary w-full" disabled={busy || noFeatures}>
                                {busy
                                    ? t('storefront.seller.working', 'Working…')
                                    : (
                                        <SaveBtnLabel>
                                            {isNew
                                                ? t('storefront.seller.create_feature_value', 'Create feature value')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to={cancelHref} className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                            {noFeatures ? (
                                <Link to="/account/seller/features/new" className="shop-btn shop-btn-ghost w-full">
                                    {t('storefront.seller.add_feature', 'Add feature')}
                                </Link>
                            ) : null}
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
