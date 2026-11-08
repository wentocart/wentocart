import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormField, FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import SellerSearchSelect from '../../components/SellerSearchSelect.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import {
    buildCategoryTranslations,
    emptyCategoryTranslation,
    errText,
    seoPreviewUrl,
    slugify,
} from './categoryFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }
    return fallback || null;
}

export default function SellerCategoryForm() {
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();
    const [searchParams] = useSearchParams();

    const [languages, setLanguages] = useState([]);
    const [shopUrl, setShopUrl] = useState('');
    const [customerGroups, setCustomerGroups] = useState([]);
    const [parentOptions, setParentOptions] = useState([]);
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [parentId, setParentId] = useState('');
    const [customerGroupIds, setCustomerGroupIds] = useState([]);
    const [isEnabled, setIsEnabled] = useState(true);
    const [productsCount, setProductsCount] = useState(0);
    const [image, setImage] = useState(null);
    const [thumbnail, setThumbnail] = useState(null);
    const [removeImage, setRemoveImage] = useState(false);
    const [removeThumbnail, setRemoveThumbnail] = useState(false);
    const [coverPreview, setCoverPreview] = useState(null);
    const [thumbPreview, setThumbPreview] = useState(null);
    const [existingCoverUrl, setExistingCoverUrl] = useState(null);
    const [existingThumbUrl, setExistingThumbUrl] = useState(null);
    const [fieldErrors, setFieldErrors] = useState({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);

    const defaultLanguage = useMemo(
        () => languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en',
        [languages],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyCategoryTranslation(languageCode);
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
                ...emptyCategoryTranslation(selectedLanguage),
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
                    const { data } = await api.get('/account/seller/categories');
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setShopUrl(data.shop_url || '');
                    setCustomerGroups(data.customer_groups || []);
                    setParentOptions(data.parent_options || []);
                    setTranslations(buildCategoryTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    setParentId(searchParams.get('parent_id') || '');
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/categories/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const category = data.category || {};
                setLanguages(langs);
                setShopUrl(data.shop_url || '');
                setCustomerGroups(data.customer_groups || []);
                setParentOptions(data.parent_options || []);
                setTranslations(buildCategoryTranslations(category, langs, def));
                setSelectedLanguage(def);
                setParentId(category.parent_id || '');
                setCustomerGroupIds(category.customer_group_ids || []);
                setIsEnabled(category.is_enabled !== false);
                setProductsCount(Number(category.products_count || 0));
                setExistingCoverUrl(category.image_url || null);
                setExistingThumbUrl(category.thumbnail_url || null);
                setCoverPreview(category.image_url || null);
                setThumbPreview(category.thumbnail_url || null);
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load category.'));
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };
        load();
        return () => { cancelled = true; };
    }, [id, isNew, searchParams]);

    const parentSelectOptions = useMemo(
        () => parentOptions.map((opt) => ({
            id: opt.id,
            name: opt.is_enabled === false ? `${opt.name} (${t('storefront.seller.disabled', 'Disabled')})` : opt.name,
        })),
        [parentOptions],
    );

    const selectedCustomerGroups = useMemo(
        () => customerGroups.filter((group) => customerGroupIds.includes(group.id)),
        [customerGroupIds, customerGroups],
    );

    const resolvedCoverPreview = removeImage ? null : previewUrl(image, coverPreview);
    const resolvedThumbPreview = removeThumbnail ? null : previewUrl(thumbnail, thumbPreview);

    const buildFormData = () => {
        const body = new FormData();
        const defaultRow = translationFor(defaultLanguage);
        body.append('name', defaultRow.name || '');
        body.append('slug', defaultRow.slug || '');
        body.append('description', defaultRow.description || '');
        body.append('meta_title', defaultRow.meta_title || '');
        body.append('meta_description', defaultRow.meta_description || '');
        body.append('meta_keywords', defaultRow.meta_keywords || '');
        body.append('canonical_url', defaultRow.canonical_url || '');
        body.append('is_enabled', isEnabled ? '1' : '0');

        if (parentId !== '' && parentId != null) {
            body.append('parent_id', String(parentId));
        }

        (customerGroupIds || []).forEach((groupId) => {
            body.append('customer_group_ids[]', String(groupId));
        });

        Object.entries(translations).forEach(([lang, row]) => {
            Object.entries(row).forEach(([key, value]) => {
                if (value === null || value === undefined) {
                    return;
                }
                body.append(`translations[${lang}][${key}]`, String(value));
            });
        });

        if (removeImage) body.append('remove_image', '1');
        if (removeThumbnail) body.append('remove_thumbnail', '1');
        if (image) body.append('image', image);
        if (thumbnail) body.append('thumbnail', thumbnail);

        return body;
    };

    const submit = async (event) => {
        event.preventDefault();
        setError('');
        setFieldErrors({});
        setBusy(true);
        try {
            await csrf();
            const body = buildFormData();
            if (isNew) {
                await api.post('/account/seller/categories', body);
            } else {
                await api.patch(`/account/seller/categories/${id}`, body);
            }
            nav('/account/seller/categories');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteCategory = async () => {
        if (!window.confirm(t('storefront.seller.delete_category_confirm', 'Delete this category?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/categories/${id}`);
            nav('/account/seller/categories');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete category.'));
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
                            ? t('storefront.seller.add_category', 'Add category')
                            : t('storefront.seller.edit_category', 'Edit category')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteCategory}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-category-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.category_name', 'Category name')}
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
                                />
                            </LocalizedFormField>

                            <FormField
                                label={t('storefront.seller.parent_category', 'Parent category')}
                                error={errText(fieldErrors, 'parent_id')}
                                hint={t('storefront.seller.parent_hint', 'Leave empty for a root category. Paths show the full hierarchy.')}
                                span
                            >
                                <select
                                    className="shop-input"
                                    value={parentId || ''}
                                    onChange={(e) => setParentId(e.target.value)}
                                >
                                    <option value="">{t('storefront.seller.no_parent', 'Top level')}</option>
                                    {parentSelectOptions.map((row) => (
                                        <option key={row.id} value={row.id}>{row.name}</option>
                                    ))}
                                </select>
                            </FormField>

                            <LocalizedFormField
                                label={t('storefront.seller.category_description', 'Category description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('description')}
                                span
                            >
                                <RichEditor
                                    value={valueForField('description')}
                                    onChange={(html) => setTranslationField('description', html)}
                                    placeholder={t('storefront.seller.category_editor_placeholder', 'Write category description, headings, lists, and insert images…')}
                                />
                            </LocalizedFormField>

                            <SellerSearchSelect
                                label={t('storefront.seller.customer_group_access', 'Customer group access')}
                                options={customerGroups}
                                selectedOptions={selectedCustomerGroups}
                                value={customerGroupIds}
                                onChange={setCustomerGroupIds}
                                multiple
                                error={errText(fieldErrors, 'customer_group_ids')}
                                placeholder={t('storefront.seller.filter_customer_groups', 'Filter customer groups…')}
                                hint={t('storefront.seller.customer_group_access_hint', 'Leave empty to allow all customer groups. Restrict to selected groups when this category should be visible to specific audiences only.')}
                            />
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.media', 'Media')}</h3>
                        </div>
                        <div className="mv-field-grid">
                            {[
                                {
                                    key: 'image',
                                    label: t('storefront.seller.cover_image', 'Category cover image'),
                                    preview: resolvedCoverPreview,
                                    existingUrl: existingCoverUrl,
                                    remove: removeImage,
                                    setRemove: setRemoveImage,
                                    setFile: setImage,
                                    setPreview: setCoverPreview,
                                },
                                {
                                    key: 'thumbnail',
                                    label: t('storefront.seller.thumbnail', 'Category thumbnail'),
                                    preview: resolvedThumbPreview,
                                    existingUrl: existingThumbUrl,
                                    remove: removeThumbnail,
                                    setRemove: setRemoveThumbnail,
                                    setFile: setThumbnail,
                                    setPreview: setThumbPreview,
                                    small: true,
                                },
                            ].map((row) => (
                                <FormField key={row.key} label={row.label} error={errText(fieldErrors, row.key)}>
                                    {row.preview ? (
                                        <img
                                            src={row.preview}
                                            alt=""
                                            className={`mv-form-preview${row.small ? ' mv-form-preview--sm' : ''}`}
                                        />
                                    ) : null}
                                    <input
                                        className="shop-input"
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] || null;
                                            row.setFile(file);
                                            if (file) {
                                                row.setPreview(URL.createObjectURL(file));
                                            }
                                            row.setRemove(false);
                                        }}
                                    />
                                    {row.existingUrl ? (
                                        <label className="mv-check-inline">
                                            <input
                                                type="checkbox"
                                                checked={!!row.remove}
                                                onChange={(e) => row.setRemove(e.target.checked)}
                                            />
                                            {t('storefront.seller.remove_image', 'Remove image')}
                                        </label>
                                    ) : null}
                                </FormField>
                            ))}
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.seo', 'SEO')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.slug', 'SEO URL / Slug')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('slug')}
                                span
                            >
                                <div className="mv-inline-actions">
                                    <input
                                        className="shop-input"
                                        value={valueForField('slug')}
                                        onChange={(e) => setTranslationField('slug', slugify(e.target.value))}
                                    />
                                    <button
                                        type="button"
                                        className="shop-btn shop-btn-ghost shop-btn--sm"
                                        onClick={() => setTranslationField('slug', slugify(valueForField('name')))}
                                    >
                                        {t('storefront.seller.auto_slug', 'Auto')}
                                    </button>
                                </div>
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.meta_title', 'Meta title')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('meta_title')}
                                span
                            >
                                <>
                                    <input
                                        className="shop-input"
                                        maxLength={60}
                                        value={valueForField('meta_title')}
                                        onChange={(e) => setTranslationField('meta_title', e.target.value)}
                                    />
                                    <p className="mv-char-count">{(valueForField('meta_title') || '').length} / 60</p>
                                </>
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.meta_description', 'Meta description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('meta_description')}
                                span
                            >
                                <>
                                    <textarea
                                        className="shop-input"
                                        rows={3}
                                        maxLength={160}
                                        value={valueForField('meta_description')}
                                        onChange={(e) => setTranslationField('meta_description', e.target.value)}
                                    />
                                    <p className="mv-char-count">{(valueForField('meta_description') || '').length} / 160</p>
                                </>
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.meta_keywords', 'Meta keywords')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('meta_keywords')}
                                span
                            >
                                <input
                                    className="shop-input"
                                    value={valueForField('meta_keywords')}
                                    onChange={(e) => setTranslationField('meta_keywords', e.target.value)}
                                />
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.canonical_url', 'Canonical URL')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('canonical_url')}
                                span
                            >
                                <input
                                    className="shop-input"
                                    value={valueForField('canonical_url')}
                                    onChange={(e) => setTranslationField('canonical_url', e.target.value)}
                                    placeholder="https://"
                                />
                            </LocalizedFormField>

                            <div className="mv-seo-preview">
                                <div className="mv-seo-preview-title">
                                    {valueForField('meta_title') || valueForField('name') || 'Category title'}
                                </div>
                                <div className="mv-seo-preview-url">
                                    {seoPreviewUrl(valueForField('canonical_url'), shopUrl, valueForField('slug'))}
                                </div>
                                <div className="mv-seo-preview-desc">
                                    {valueForField('meta_description') || t('storefront.seller.seo_preview_placeholder', 'Meta description will appear here.')}
                                </div>
                            </div>
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
                            hint={t('storefront.seller.inactive_category_hint', 'Inactive categories stay linked to existing products but are hidden when assigning new products.')}
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
                                                ? t('storefront.seller.create_category', 'Create category')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to="/account/seller/categories" className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
