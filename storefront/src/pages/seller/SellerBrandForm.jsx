import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { useStore } from '../../state/StoreState.jsx';
import PhoneField from '../../components/PhoneField.jsx';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { FormField, FormNotice, FormSwitch, LocalizedFormField } from '../../components/Form.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import {
    buildBrandTranslations,
    emptyBrandTranslation,
    errText,
    slugify,
} from './brandFormUtils.js';
import { SaveBtnLabel } from './sellerUi.jsx';

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }
    return fallback || null;
}

export default function SellerBrandForm() {
    const store = useStore();
    const { id } = useParams();
    const isNew = !id || id === 'new';
    const nav = useNavigate();

    const [languages, setLanguages] = useState([]);
    const [countries, setCountries] = useState([]);
    const [shopUrl, setShopUrl] = useState('');
    const [translations, setTranslations] = useState({});
    const [selectedLanguage, setSelectedLanguage] = useState('en');
    const [website, setWebsite] = useState('');
    const [countryCode, setCountryCode] = useState('');
    const [establishedYear, setEstablishedYear] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [facebookUrl, setFacebookUrl] = useState('');
    const [instagramUrl, setInstagramUrl] = useState('');
    const [youtubeUrl, setYoutubeUrl] = useState('');
    const [twitterUrl, setTwitterUrl] = useState('');
    const [linkedinUrl, setLinkedinUrl] = useState('');
    const [tiktokUrl, setTiktokUrl] = useState('');
    const [isEnabled, setIsEnabled] = useState(true);
    const [productsCount, setProductsCount] = useState(0);
    const [logo, setLogo] = useState(null);
    const [banner, setBanner] = useState(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [removeBanner, setRemoveBanner] = useState(false);
    const [logoPreview, setLogoPreview] = useState(null);
    const [bannerPreview, setBannerPreview] = useState(null);
    const [existingLogoUrl, setExistingLogoUrl] = useState(null);
    const [existingBannerUrl, setExistingBannerUrl] = useState(null);
    const [existingGallery, setExistingGallery] = useState([]);
    const [galleryKeep, setGalleryKeep] = useState([]);
    const [galleryFiles, setGalleryFiles] = useState([]);
    const [fieldErrors, setFieldErrors] = useState({});
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);

    const defaultLanguage = useMemo(
        () => languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en',
        [languages],
    );

    const translationFor = (languageCode) => translations[languageCode] || emptyBrandTranslation(languageCode);
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
                ...emptyBrandTranslation(selectedLanguage),
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
                    const { data } = await api.get('/account/seller/brands');
                    if (cancelled) return;
                    const langs = data.languages || [];
                    const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                    setLanguages(langs);
                    setCountries(data.countries || []);
                    setShopUrl(data.shop_url || '');
                    setTranslations(buildBrandTranslations(null, langs, def));
                    setSelectedLanguage(def);
                    setLoading(false);
                    return;
                }

                const { data } = await api.get(`/account/seller/brands/${id}`);
                if (cancelled) return;
                const langs = data.languages || [];
                const def = langs.find((row) => row.is_default)?.code || langs[0]?.code || 'en';
                const brand = data.brand || {};
                setLanguages(langs);
                setCountries(data.countries || []);
                setShopUrl(data.shop_url || '');
                setTranslations(buildBrandTranslations(brand, langs, def));
                setSelectedLanguage(def);
                setWebsite(brand.website || '');
                setCountryCode(brand.country_code || '');
                setEstablishedYear(brand.established_year ?? '');
                setEmail(brand.email || '');
                setPhone(brand.phone || '');
                setFacebookUrl(brand.facebook_url || '');
                setInstagramUrl(brand.instagram_url || '');
                setYoutubeUrl(brand.youtube_url || '');
                setTwitterUrl(brand.twitter_url || '');
                setLinkedinUrl(brand.linkedin_url || '');
                setTiktokUrl(brand.tiktok_url || '');
                setIsEnabled(brand.is_enabled !== false);
                setProductsCount(Number(brand.products_count || 0));
                setExistingLogoUrl(brand.logo_url || null);
                setExistingBannerUrl(brand.banner_url || null);
                setLogoPreview(brand.logo_url || null);
                setBannerPreview(brand.banner_url || null);
                setExistingGallery(brand.gallery || []);
                setGalleryKeep((brand.gallery || []).map((item) => item.id));
            } catch {
                if (!cancelled) {
                    setError(t('storefront.seller.load_failed', 'Could not load brand.'));
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

    const visibleGallery = useMemo(
        () => existingGallery.filter((item) => galleryKeep.includes(item.id)),
        [existingGallery, galleryKeep],
    );

    const resolvedLogoPreview = removeLogo ? null : previewUrl(logo, logoPreview);
    const resolvedBannerPreview = removeBanner ? null : previewUrl(banner, bannerPreview);

    const moveGallery = (itemId, direction) => {
        setGalleryKeep((prev) => {
            const index = prev.indexOf(itemId);
            if (index < 0) return prev;
            const next = [...prev];
            const swap = index + direction;
            if (swap < 0 || swap >= next.length) return prev;
            [next[index], next[swap]] = [next[swap], next[index]];
            return next;
        });
    };

    const buildFormData = () => {
        const body = new FormData();
        const defaultRow = translationFor(defaultLanguage);
        body.append('name', defaultRow.name || '');
        body.append('slug', defaultRow.slug || '');
        body.append('short_description', defaultRow.short_description || '');
        body.append('tagline', defaultRow.tagline || '');
        body.append('description', defaultRow.description || '');
        body.append('meta_title', defaultRow.meta_title || '');
        body.append('meta_description', defaultRow.meta_description || '');
        body.append('meta_keywords', defaultRow.meta_keywords || '');
        body.append('is_enabled', isEnabled ? '1' : '0');
        body.append('website', website || '');
        body.append('country_code', countryCode || '');
        if (establishedYear !== '') body.append('established_year', String(establishedYear));
        body.append('email', email || '');
        body.append('phone', phone || '');
        body.append('facebook_url', facebookUrl || '');
        body.append('instagram_url', instagramUrl || '');
        body.append('youtube_url', youtubeUrl || '');
        body.append('twitter_url', twitterUrl || '');
        body.append('linkedin_url', linkedinUrl || '');
        body.append('tiktok_url', tiktokUrl || '');

        Object.entries(translations).forEach(([lang, row]) => {
            Object.entries(row).forEach(([key, value]) => {
                if (value === null || value === undefined) return;
                body.append(`translations[${lang}][${key}]`, String(value));
            });
        });

        galleryKeep.forEach((itemId) => body.append('gallery_keep[]', String(itemId)));
        galleryFiles.forEach((file) => body.append('gallery[]', file));

        if (removeLogo) body.append('remove_logo', '1');
        if (removeBanner) body.append('remove_banner', '1');
        if (logo) body.append('logo', logo);
        if (banner) body.append('banner', banner);

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
                await api.post('/account/seller/brands', body);
            } else {
                await api.patch(`/account/seller/brands/${id}`, body);
            }
            nav('/account/seller/brands');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    const deleteBrand = async () => {
        if (productsCount > 0) {
            window.alert(t('storefront.seller.brand_delete_blocked', 'This brand is assigned to products. Reassign them before deleting.'));
            return;
        }
        if (!window.confirm(t('storefront.seller.delete_brand_confirm', 'Delete this brand?'))) {
            return;
        }
        try {
            await csrf();
            await api.delete(`/account/seller/brands/${id}`);
            nav('/account/seller/brands');
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.seller.delete_failed', 'Could not delete brand.'));
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
                            ? t('storefront.seller.add_brand', 'Add brand')
                            : t('storefront.seller.edit_brand', 'Edit brand')}
                    </h2>
                </div>
                <div className="mv-toolbar-actions">
                    {!isNew ? (
                        <button type="button" className="shop-btn shop-btn-ghost" onClick={deleteBrand}>
                            {t('storefront.seller.delete', 'Delete')}
                        </button>
                    ) : null}
                </div>
            </div>

            <form className="mv-form-layout mv-brand-form" onSubmit={submit} noValidate>
                <div className="mv-form-main">
                    {error ? <FormNotice tone="error">{error}</FormNotice> : null}

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.general', 'General')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.brand_name', 'Brand name')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('name')}
                                span
                            >
                                <input className="shop-input" value={valueForField('name')} onChange={(e) => setTranslationField('name', e.target.value)} placeholder="e.g. Acme" />
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.slug', 'Slug / SEO URL')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('slug')}
                                hint={t('storefront.seller.auto_slug_hint', 'Auto-generated from the name. You can edit it.')}
                                span
                            >
                                <div className="mv-inline-actions">
                                    <input className="shop-input" value={valueForField('slug')} onChange={(e) => setTranslationField('slug', slugify(e.target.value))} />
                                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => setTranslationField('slug', slugify(valueForField('name')))}>
                                        {t('storefront.seller.auto_slug', 'Auto')}
                                    </button>
                                </div>
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.short_description', 'Short description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('short_description')}
                                span
                            >
                                <textarea className="shop-input" rows={3} value={valueForField('short_description')} onChange={(e) => setTranslationField('short_description', e.target.value)} />
                            </LocalizedFormField>

                            <LocalizedFormField
                                label={t('storefront.seller.brand_description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('description')}
                                span
                            >
                                <RichEditor value={valueForField('description')} onChange={(html) => setTranslationField('description', html)} placeholder={t('storefront.seller.brand_editor_placeholder', 'Write brand description, headings, lists, and insert images…')} />
                            </LocalizedFormField>
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.brand_details', 'Brand details')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField
                                label={t('storefront.seller.tagline', 'Tagline')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={fe('tagline')}
                                span
                            >
                                <input className="shop-input" value={valueForField('tagline')} onChange={(e) => setTranslationField('tagline', e.target.value)} />
                            </LocalizedFormField>

                            <FormField label={t('storefront.seller.website', 'Website')} error={errText(fieldErrors, 'website')}>
                                <input className="shop-input" type="url" value={website} onChange={(e) => setWebsite(e.target.value)} placeholder="https://" />
                            </FormField>
                            <FormField label={t('storefront.seller.country_of_origin', 'Country of origin')} error={errText(fieldErrors, 'country_code')}>
                                <select className="shop-input" value={countryCode} onChange={(e) => setCountryCode(e.target.value)}>
                                    <option value="">{t('storefront.seller.select_country', 'Select country')}</option>
                                    {countries.map((country) => (
                                        <option key={country.code} value={country.code}>{country.name}</option>
                                    ))}
                                </select>
                            </FormField>
                            <FormField label={t('storefront.seller.established_year', 'Established year')} error={errText(fieldErrors, 'established_year')}>
                                <input className="shop-input" type="number" min="1800" max={new Date().getFullYear() + 1} value={establishedYear} onChange={(e) => setEstablishedYear(e.target.value)} />
                            </FormField>
                            <FormField label={t('storefront.seller.brand_contact_email', 'Contact email')} error={errText(fieldErrors, 'email')}>
                                <input className="shop-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                            </FormField>
                            <FormField label={t('storefront.seller.brand_contact_phone', 'Contact phone')} error={errText(fieldErrors, 'phone')}>
                                <PhoneField countries={store.shop?.countries || []} value={phone} onChange={setPhone} />
                            </FormField>
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.media', 'Media')}</h3>
                        </div>
                        <div className="mv-field-grid">
                            {[
                                { key: 'logo', label: t('storefront.seller.brand_logo', 'Logo'), preview: resolvedLogoPreview, existingUrl: existingLogoUrl, remove: removeLogo, setRemove: setRemoveLogo, setFile: setLogo, setPreview: setLogoPreview },
                                { key: 'banner', label: t('storefront.seller.brand_banner', 'Banner / cover image'), preview: resolvedBannerPreview, existingUrl: existingBannerUrl, remove: removeBanner, setRemove: setRemoveBanner, setFile: setBanner, setPreview: setBannerPreview },
                            ].map((row) => (
                                <FormField key={row.key} label={row.label} error={errText(fieldErrors, row.key)}>
                                    {row.preview ? <img src={row.preview} alt="" className="mv-form-preview" /> : null}
                                    <input className="shop-input" type="file" accept="image/*" onChange={(e) => { const file = e.target.files?.[0] || null; row.setFile(file); if (file) row.setPreview(URL.createObjectURL(file)); row.setRemove(false); }} />
                                    {row.existingUrl ? (
                                        <label className="mv-check-inline">
                                            <input type="checkbox" checked={!!row.remove} onChange={(e) => row.setRemove(e.target.checked)} />
                                            {t('storefront.seller.remove_image', 'Remove image')}
                                        </label>
                                    ) : null}
                                </FormField>
                            ))}
                        </div>
                        <div className="mv-section-grid mt-4">
                            <p className="mv-muted">{t('storefront.seller.gallery', 'Gallery')}</p>
                            <div className="mv-gallery-list">
                                {visibleGallery.map((item, index) => (
                                    <div key={item.id} className="mv-gallery-row">
                                        <img src={item.url} alt="" className="mv-form-preview mv-form-preview--sm" />
                                        <span className="mv-muted-sm">{t('storefront.seller.image_n', 'Image {n}').replace('{n}', String(index + 1))}</span>
                                        <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => moveGallery(item.id, -1)}>{t('storefront.seller.move_up', 'Up')}</button>
                                        <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => moveGallery(item.id, 1)}>{t('storefront.seller.move_down', 'Down')}</button>
                                        <button type="button" className="mv-link" onClick={() => setGalleryKeep((prev) => prev.filter((gid) => gid !== item.id))}>{t('storefront.seller.remove', 'Remove')}</button>
                                    </div>
                                ))}
                            </div>
                            <FormField label={t('storefront.seller.add_gallery_images', 'Add gallery images')} error={errText(fieldErrors, 'gallery')} hint={t('storefront.seller.gallery_hint', 'Select one or more images. They are saved with the brand.')} span>
                                <input className="shop-input" type="file" accept="image/*" multiple onChange={(e) => setGalleryFiles(Array.from(e.target.files || []))} />
                                {galleryFiles.length ? <p className="mv-meta-k">{galleryFiles.length} {t('storefront.seller.new_images_selected', 'new image(s) selected')}</p> : null}
                            </FormField>
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.social_media', 'Social media')}</h3>
                        </div>
                        <div className="mv-field-grid">
                            {[
                                [facebookUrl, setFacebookUrl, 'facebook_url', 'Facebook URL'],
                                [instagramUrl, setInstagramUrl, 'instagram_url', 'Instagram URL'],
                                [youtubeUrl, setYoutubeUrl, 'youtube_url', 'YouTube URL'],
                                [twitterUrl, setTwitterUrl, 'twitter_url', 'X / Twitter URL'],
                                [linkedinUrl, setLinkedinUrl, 'linkedin_url', 'LinkedIn URL'],
                                [tiktokUrl, setTiktokUrl, 'tiktok_url', 'TikTok URL'],
                            ].map(([value, setter, key, label]) => (
                                <FormField key={key} label={label} error={errText(fieldErrors, key)}>
                                    <input className="shop-input" type="url" value={value} onChange={(e) => setter(e.target.value)} placeholder="https://" />
                                </FormField>
                            ))}
                        </div>
                    </section>

                    <section className="mv-section-card">
                        <div className="mv-section-head">
                            <h3 className="mv-section-title">{t('storefront.seller.seo', 'SEO')}</h3>
                        </div>
                        <div className="mv-section-grid">
                            <LocalizedFormField label={t('storefront.seller.meta_title', 'Meta title')} languages={languages} currentLanguage={selectedLanguage} onLanguageChange={setSelectedLanguage} error={fe('meta_title')} span>
                                <>
                                    <input className="shop-input" maxLength={60} value={valueForField('meta_title')} onChange={(e) => setTranslationField('meta_title', e.target.value)} />
                                    <p className="mv-char-count">{(valueForField('meta_title') || '').length} / 60</p>
                                </>
                            </LocalizedFormField>
                            <LocalizedFormField label={t('storefront.seller.meta_description', 'Meta description')} languages={languages} currentLanguage={selectedLanguage} onLanguageChange={setSelectedLanguage} error={fe('meta_description')} span>
                                <>
                                    <textarea className="shop-input" rows={3} maxLength={160} value={valueForField('meta_description')} onChange={(e) => setTranslationField('meta_description', e.target.value)} />
                                    <p className="mv-char-count">{(valueForField('meta_description') || '').length} / 160</p>
                                </>
                            </LocalizedFormField>
                            <LocalizedFormField label={t('storefront.seller.meta_keywords', 'Meta keywords')} languages={languages} currentLanguage={selectedLanguage} onLanguageChange={setSelectedLanguage} error={fe('meta_keywords')} span>
                                <input className="shop-input" value={valueForField('meta_keywords')} onChange={(e) => setTranslationField('meta_keywords', e.target.value)} />
                            </LocalizedFormField>
                            <div className="mv-seo-preview">
                                <div className="mv-seo-preview-title">{valueForField('meta_title') || valueForField('name') || 'Brand title'}</div>
                                <div className="mv-seo-preview-url">{shopUrl ? `${shopUrl.replace(/\/$/, '')}/brand/${valueForField('slug') || 'slug'}` : `/brand/${valueForField('slug') || 'slug'}`}</div>
                                <div className="mv-seo-preview-desc">{valueForField('meta_description') || valueForField('short_description') || t('storefront.seller.seo_preview_placeholder', 'Meta description will appear here.')}</div>
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
                            label={t('storefront.seller.enabled', 'Enabled')}
                            hint={t('storefront.seller.inactive_brand_hint', 'Disabled brands cannot be newly assigned to products. Existing product assignments are kept.')}
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
                                                ? t('storefront.seller.create_brand', 'Create brand')
                                                : t('storefront.account.save', 'Save changes')}
                                        </SaveBtnLabel>
                                    )}
                            </button>
                            <Link to="/account/seller/brands" className="shop-btn shop-btn-ghost w-full">
                                {t('storefront.seller.cancel', 'Cancel')}
                            </Link>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    );
}
