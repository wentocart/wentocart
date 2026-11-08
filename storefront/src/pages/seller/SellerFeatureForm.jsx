import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import {
    buildFeatureTranslations,
    emptyFeatureTranslation,
    errText,
} from './featureFormUtils.js';
import { AddBtnLabel, SaveBtnLabel } from './sellerUi.jsx';

export default function SellerFeatureForm() {
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const [languages, setLanguages] = useState([]);
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
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

    const translationFor = (languageCode) => translations[languageCode] || emptyFeatureTranslation(languageCode);
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
                ...emptyFeatureTranslation(selectedLanguage),
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
                    const { data } = await api.get('/account/seller/features');
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setTranslations(buildFeatureTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/features/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const feature = data.feature || {};
                setLanguages(langs);
                setTranslations(buildFeatureTranslations(feature, langs, def));
                setSelectedLanguage(def);
                setIsEnabled(feature.is_enabled !== false);
                setProductsCount(Number(feature.products_count || 0));
                setValuesCount(Number(feature.values_count || 0));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load feature.'));
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
                await api.post('/account/seller/features', body);
            } else {
                await api.patch(`/account/seller/features/${id}`, body);
            }
            nav('/account/seller/features');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteFeature = async () => {
        if (productsCount > 0) {
            window.alert(t(
                'storefront.seller.feature_delete_blocked',
                `This feature is assigned to ${productsCount} product${productsCount === 1 ? '' : 's'}. Remove it from those products before deleting.`,
            ));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_feature_confirm', 'Delete this feature?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/features/${id}`);
            nav('/account/seller/features');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete feature.'));
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
                            ? t('storefront.seller.add_feature', 'Add feature')
                            : t('storefront.seller.edit_feature', 'Edit feature')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteFeature}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-feature-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.feature_name', 'Feature name')}
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
                                    placeholder={t('storefront.seller.feature_name_placeholder', 'e.g. RAM')}
                                />
                            </LocalizedFormField>
                            <LocalizedFormField
                                label={t('storefront.seller.feature_description', 'Description')}
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
                            hint={t('storefront.seller.inactive_feature_hint', 'Disabled features cannot be newly assigned to products. Existing assignments are kept.')}
                        />
                        {!isNew ? (
                            <>
                                <p className="mv-meta-k">
                                    <Link className="mv-link" to={`/account/seller/features/${id}/values`}>
                                        {valuesCount} {t('storefront.seller.feature_values', 'values')}
                                    </Link>
                                </p>
                                <p className="mv-meta-k">
                                    {productsCount} {t('storefront.seller.products_assigned', 'products assigned')}
                                </p>
                                <Link to={`/account/seller/features/${id}/values/new`} className="shop-btn shop-btn-ghost w-full">
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
                                                ? t('storefront.seller.create_feature', 'Create feature')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to="/account/seller/features" className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
