import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    PageHeader,
    SearchSelect,
    Textarea,
    Tooltip,
    cx,
    ui,
    t,
} from '../../../ui';
import Editor from '../../../ui/Editor';
import { catalogImageUrl } from '../../../lib/catalogMedia';

const TRANSLATABLE_FIELDS = [
    'name',
    'slug',
    'description',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'canonical_url',
];

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function previewUrl(file, fallback) {
    if (file instanceof File) {
        return URL.createObjectURL(file);
    }
    return fallback || null;
}

function seoPreviewUrl(canonical, shopUrl, slug) {
    if (canonical) {
        return canonical;
    }
    const base = (shopUrl || '').replace(/\/$/, '');
    return `${base}/${slug || 'category-slug'}`;
}

function emptyTranslation(languageCode) {
    return {
        language_code: languageCode,
        name: '',
        slug: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
        canonical_url: '',
    };
}

function buildTranslations(category, languages, defaultLanguage) {
    const out = {};
    (languages || []).forEach((language) => {
        out[language.code] = emptyTranslation(language.code);
    });
    (category?.translations || []).forEach((row) => {
        if (!row?.language_code) {
            return;
        }
        out[row.language_code] = {
            ...emptyTranslation(row.language_code),
            language_code: row.language_code,
            name: row.name ?? '',
            slug: row.slug ?? '',
            description: row.description ?? '',
            meta_title: row.meta_title ?? '',
            meta_description: row.meta_description ?? '',
            meta_keywords: row.meta_keywords ?? '',
            canonical_url: row.canonical_url ?? '',
        };
    });
    if (!out[defaultLanguage]) {
        out[defaultLanguage] = emptyTranslation(defaultLanguage);
    }
    if (!category?.translations?.length && category) {
        out[defaultLanguage] = {
            ...out[defaultLanguage],
            name: category.name || '',
            slug: category.slug || '',
            description: category.description || '',
            meta_title: category.meta_title || '',
            meta_description: category.meta_description || '',
            meta_keywords: category.meta_keywords || '',
            canonical_url: category.canonical_url || '',
        };
    }

    return out;
}

export default function Form({
    category,
    parentOptions = [],
    shopUrl = '',
    customerGroups = [],
    selectedCustomerGroups = [],
    initialParentId = null,
    multivendor = null,
}) {
    const isMultivendor = !!multivendor;
    const categoriesIndexPath = isMultivendor ? multivendor.indexPath : '/admin/categories';
    const multivendorDashboardPath = '/admin/multivendor/dashboard';
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length ? i18n.languages : [{
        code: defaultLanguage,
        name: i18n?.default_name || defaultLanguage.toUpperCase(),
        is_default: true,
    }]);
    const initialTranslations = buildTranslations(category, languages, defaultLanguage);
    const defaultTranslation = initialTranslations[defaultLanguage] || emptyTranslation(defaultLanguage);
    const [selectedLanguage, setSelectedLanguage] = useState(defaultLanguage);
    const parentSelectOptions = parentOptions.map((opt) => ({
        id: opt.id,
        name: opt.is_enabled === false ? `${opt.name} (Disabled)` : opt.name,
    }));

    const form = useForm({
        seller_id: multivendor?.sellerId ? String(multivendor.sellerId) : '',
        name: defaultTranslation.name || '',
        slug: defaultTranslation.slug || '',
        parent_id: category?.parent_id || initialParentId || '',
        description: defaultTranslation.description || '',
        meta_title: defaultTranslation.meta_title || '',
        meta_description: defaultTranslation.meta_description || '',
        meta_keywords: defaultTranslation.meta_keywords || '',
        canonical_url: defaultTranslation.canonical_url || '',
        translations: initialTranslations,
        customer_group_ids: category?.customer_group_ids || [],
        is_enabled: category?.is_enabled ?? true,
        image: null,
        thumbnail: null,
        remove_image: false,
        remove_thumbnail: false,
    });

    const assigned = Number(category?.products_count) || 0;
    const childCount = Number(category?.children_count) || 0;
    const coverPreview = form.data.remove_image ? null : previewUrl(form.data.image, category?.image_url);
    const thumbnailPreview = form.data.remove_thumbnail ? null : previewUrl(form.data.thumbnail, category?.thumbnail_url);

    const clearCover = () => {
        form.setData((data) => ({
            ...data,
            image: null,
            remove_image: !!category?.image_url,
        }));
    };

    const clearThumbnail = () => {
        form.setData((data) => ({
            ...data,
            thumbnail: null,
            remove_thumbnail: !!category?.thumbnail_url,
        }));
    };
    const fallbackLanguage = languages.find((row) => row.code === defaultLanguage) || null;
    const translationFor = (languageCode) => form.data.translations?.[languageCode] || emptyTranslation(languageCode);
    const valueForField = (field) => translationFor(selectedLanguage)[field] || '';
    const hasValueForField = (field) => String(valueForField(field) || '').trim() !== '';
    const fallbackForField = (field) => {
        if (selectedLanguage === defaultLanguage) {
            return '';
        }
        return translationFor(defaultLanguage)[field] || '';
    };
    const errorForField = (field) => (
        form.errors[`translations.${selectedLanguage}.${field}`]
        || (selectedLanguage === defaultLanguage ? form.errors[field] : undefined)
    );

    const setTranslationField = (field, value) => {
        form.setData((data) => {
            const current = data.translations?.[selectedLanguage] || emptyTranslation(selectedLanguage);
            const nextRow = {
                ...current,
                language_code: selectedLanguage,
                [field]: value,
            };
            const next = {
                ...data,
                translations: {
                    ...data.translations,
                    [selectedLanguage]: nextRow,
                },
            };
            if (selectedLanguage === defaultLanguage && TRANSLATABLE_FIELDS.includes(field)) {
                next[field] = value;
            }

            return next;
        });
    };

    const submit = (e) => {
        e.preventDefault();
        form.clearErrors();

        const translations = Object.fromEntries(
            Object.entries(form.data.translations || {}).map(([languageCode, row]) => [
                languageCode,
                {
                    ...emptyTranslation(languageCode),
                    ...row,
                    language_code: languageCode,
                    name: String(row?.name ?? ''),
                    slug: String(row?.slug ?? ''),
                    description: row?.description ?? '',
                    meta_title: String(row?.meta_title ?? ''),
                    meta_description: String(row?.meta_description ?? ''),
                    meta_keywords: String(row?.meta_keywords ?? ''),
                    canonical_url: String(row?.canonical_url ?? ''),
                },
            ]),
        );
        const baseTranslation = translations[defaultLanguage] || emptyTranslation(defaultLanguage);
        const resolvedName = String(baseTranslation.name || form.data.name || '').trim();
        if (!resolvedName) {
            form.setError(`translations.${defaultLanguage}.name`, 'Category name is required.');
            return;
        }
        if (isMultivendor && !String(form.data.seller_id || '').trim()) {
            form.setError('seller_id', t('admin.multivendor.choose_seller', 'Choose a seller.'));
            return;
        }

        translations[defaultLanguage] = {
            ...(translations[defaultLanguage] || emptyTranslation(defaultLanguage)),
            language_code: defaultLanguage,
            name: resolvedName,
        };

        const hasFiles = (form.data.image instanceof File) || (form.data.thumbnail instanceof File);
        form.transform((data) => {
            const { position: _omitPosition, image, thumbnail, ...rest } = data;
            const payload = {
                ...rest,
                parent_id: data.parent_id === '' ? null : data.parent_id,
                name: resolvedName,
                slug: baseTranslation.slug,
                description: baseTranslation.description,
                meta_title: baseTranslation.meta_title,
                meta_description: baseTranslation.meta_description,
                meta_keywords: baseTranslation.meta_keywords,
                canonical_url: baseTranslation.canonical_url,
                translations,
                remove_image: data.remove_image ? 1 : 0,
                remove_thumbnail: data.remove_thumbnail ? 1 : 0,
                is_enabled: data.is_enabled ? 1 : 0,
                // PHP does not parse multipart PUT bodies; spoof via POST.
                ...(category && hasFiles ? { _method: 'put' } : {}),
            };
            if (image instanceof File) {
                payload.image = image;
            }
            if (thumbnail instanceof File) {
                payload.thumbnail = thumbnail;
            }

            return payload;
        });

        const options = hasFiles ? { forceFormData: true } : {};
        if (isMultivendor) {
            if (multivendor.saveMethod === 'put' && !hasFiles) {
                form.put(multivendor.savePath, options);
            } else {
                form.post(multivendor.savePath, options);
            }
            return;
        }
        if (category) {
            if (hasFiles) {
                form.post(`/admin/categories/${category.id}`, options);
            } else {
                form.put(`/admin/categories/${category.id}`, options);
            }
        } else {
            form.post('/admin/categories', options);
        }
    };

    const multivendorSidebar = isMultivendor ? (
        <>
            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{t('admin.multivendor.seller', 'Seller')}</h2>
                </div>
                <Field label={t('admin.multivendor.seller', 'Seller')} required error={form.errors.seller_id}>
                    <SearchSelect
                        multiple={false}
                        options={(multivendor.sellers || []).map((seller) => ({ id: seller.id, name: seller.label }))}
                        value={form.data.seller_id}
                        selectedOptions={(multivendor.sellers || [])
                            .filter((seller) => String(seller.id) === String(form.data.seller_id))
                            .map((seller) => ({ id: seller.id, name: seller.label }))}
                        onChange={(value) => {
                            const nextSellerId = value === '' || value == null ? '' : String(value);
                            form.setData('seller_id', nextSellerId);
                            if (!multivendor.listingId && nextSellerId) {
                                router.get(multivendor.createPath || `${multivendor.indexPath}/create`, { seller_id: nextSellerId }, {
                                    preserveScroll: true,
                                });
                            }
                        }}
                        disabled={!!multivendor.listingId}
                        placeholder={t('admin.multivendor.choose_seller', 'Choose seller…')}
                    />
                </Field>
            </section>
        </>
    ) : null;

    return (
        <Layout>
            <PageHeader
                title={category ? t('admin.categories.edit_category', 'Edit category') : t('admin.categories.add_category', 'Add category')}
                meta={isMultivendor && multivendor.shopName ? (
                    <Badge tone="neutral">{multivendor.shopName}</Badge>
                ) : null}
                crumbs={isMultivendor ? [
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: multivendorDashboardPath, label: 'Multivendor Management' },
                    { href: categoriesIndexPath, label: t('admin.multivendor.categories', 'Categories') },
                    { label: category ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ] : [
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/categories', label: t('admin.categories.categories', 'Categories') },
                    { label: category ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    category ? (
                        <Tooltip content={t('admin.common.delete', 'Delete')} placement="bottom">
                            <button
                                type="button"
                                className={cx(ui.rowActionsBtn, 'text-red-600 hover:bg-red-50 hover:text-red-700')}
                                aria-label={t('admin.common.delete', 'Delete')}
                                onClick={() => {
                                    if (assigned > 0) {
                                        window.alert(`This category is currently assigned to ${assigned} product${assigned === 1 ? '' : 's'}. Please reassign these products before deleting the category.`);
                                        return;
                                    }
                                    if (childCount > 0) {
                                        window.alert(`This category contains ${childCount} child categor${childCount === 1 ? 'y' : 'ies'}. Move or delete them before deleting this category.`);
                                        return;
                                    }
                                    if (window.confirm(t('admin.categories.delete_confirm', 'Delete this category?'))) {
                                        if (isMultivendor && multivendor.deletePath) {
                                            router.delete(multivendor.deletePath, { preserveScroll: true });
                                            return;
                                        }
                                        router.delete(`/admin/categories/${category.id}`);
                                    }
                                }}
                            >
                                <Icon name="trash" className={ui.rowActionsIcon} />
                            </button>
                        </Tooltip>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout} encType="multipart/form-data">
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.categories.general', 'General')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.categories.name', 'Category name')}
                                required
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('name')}
                                hasValue={hasValueForField('name')}
                                fallbackValue={fallbackForField('name')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input wide invalid={!!errorForField('name')} value={valueForField('name')} onChange={(e) => setTranslationField('name', e.target.value)} />
                            </LocalizedField>
                            <SearchSelect
                                label={t('admin.categories.parent_category', 'Parent category')}
                                multiple={false}
                                options={parentSelectOptions}
                                value={form.data.parent_id}
                                selectedOptions={parentSelectOptions.filter((opt) => String(opt.id) === String(form.data.parent_id))}
                                onChange={(value) => form.setData('parent_id', value === '' || value == null ? '' : value)}
                                error={form.errors.parent_id}
                                placeholder={t('admin.categories.search_parent', 'Search parent category…')}
                                hint={t('admin.categories.parent_hint', 'Leave empty for a root category. Paths show the full hierarchy.')}
                            />
                            <LocalizedField
                                label={t('admin.categories.description', 'Description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('description')}
                                hasValue={hasValueForField('description')}
                                fallbackValue={fallbackForField('description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Editor value={valueForField('description')} onChange={(html) => setTranslationField('description', html)} />
                            </LocalizedField>
                            <SearchSelect
                                label={t('admin.categories.customer_group_access', 'Customer group access')}
                                options={customerGroups}
                                value={form.data.customer_group_ids}
                                selectedOptions={selectedCustomerGroups}
                                onChange={(value) => form.setData('customer_group_ids', value)}
                                error={form.errors.customer_group_ids}
                                placeholder={t('admin.categories.filter_customer_groups', 'Filter customer groups…')}
                            />
                            <p className={ui.metaK}>
                                {t('admin.categories.customer_group_access_hint', 'Leave empty to allow all customer groups. Restrict to selected groups when this category should be visible to specific audiences only.')}
                            </p>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.categories.media', 'Media')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            {[
                                {
                                    key: 'image',
                                    label: t('admin.categories.cover_image', 'Category cover image'),
                                    preview: coverPreview,
                                    removeKey: 'remove_image',
                                    clear: clearCover,
                                    removeLabel: t('admin.categories.remove_cover', 'Remove cover image'),
                                },
                                {
                                    key: 'thumbnail',
                                    label: t('admin.categories.thumbnail', 'Category thumbnail'),
                                    preview: thumbnailPreview,
                                    removeKey: 'remove_thumbnail',
                                    clear: clearThumbnail,
                                    removeLabel: t('admin.categories.remove_thumbnail', 'Remove thumbnail'),
                                },
                            ].map(({ key, label, preview, removeKey, clear, removeLabel }) => (
                                <Field key={key} label={label} error={form.errors[key]} className="min-w-0">
                                    <div className="min-w-0 space-y-3">
                                        <div className="relative inline-block">
                                            <img
                                                src={preview || catalogImageUrl(null, 'md')}
                                                alt=""
                                                className="h-16 w-16 rounded-xl object-cover bg-neutral-100"
                                                onError={(e) => {
                                                    e.currentTarget.src = catalogImageUrl(null, 'md');
                                                }}
                                            />
                                            {preview ? (
                                                <button
                                                    type="button"
                                                    className="absolute -right-2 -top-2 inline-flex h-6 w-6 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-600 shadow-sm hover:bg-neutral-50 hover:text-red-600"
                                                    aria-label={removeLabel}
                                                    onClick={clear}
                                                >
                                                    <Icon name="x" className="h-3.5 w-3.5" />
                                                </button>
                                            ) : null}
                                        </div>
                                        <div className="min-w-0 w-full overflow-hidden">
                                            <Input
                                                type="file"
                                                accept="image/*"
                                                wide
                                                className="max-w-full text-sm file:mr-2 file:max-w-[calc(100%-7rem)] file:truncate file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200"
                                                onChange={(e) => {
                                                    form.setData((data) => ({
                                                        ...data,
                                                        [key]: e.target.files?.[0] || null,
                                                        [removeKey]: false,
                                                    }));
                                                }}
                                            />
                                        </div>
                                    </div>
                                </Field>
                            ))}
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.categories.seo', 'SEO')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <LocalizedField
                                label={t('admin.categories.slug', 'SEO URL / Slug')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('slug')}
                                hasValue={hasValueForField('slug')}
                                fallbackValue={fallbackForField('slug')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <div className={ui.row}>
                                    <Input
                                        wide
                                        value={valueForField('slug')}
                                        onChange={(e) => setTranslationField('slug', slugify(e.target.value))}
                                    />
                                    <Button type="button" variant="secondary" onClick={() => setTranslationField('slug', slugify(valueForField('name')))}>
                                        {t('admin.categories.auto_slug', 'Auto')}
                                    </Button>
                                </div>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.categories.meta_title', 'Meta title')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_title')}
                                hasValue={hasValueForField('meta_title')}
                                fallbackValue={fallbackForField('meta_title')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <>
                                    <Input wide maxLength={60} value={valueForField('meta_title')} onChange={(e) => setTranslationField('meta_title', e.target.value)} />
                                    <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_title') || '').length} / 60</p>
                                </>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.categories.meta_description', 'Meta description')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_description')}
                                hasValue={hasValueForField('meta_description')}
                                fallbackValue={fallbackForField('meta_description')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <>
                                    <Textarea rows="3" maxLength={160} value={valueForField('meta_description')} onChange={(e) => setTranslationField('meta_description', e.target.value)} />
                                    <p className={`${ui.muted} text-right mt-1`}>{(valueForField('meta_description') || '').length} / 160</p>
                                </>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.categories.meta_keywords', 'Meta keywords')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('meta_keywords')}
                                hasValue={hasValueForField('meta_keywords')}
                                fallbackValue={fallbackForField('meta_keywords')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <>
                                    <Input
                                        wide
                                        value={valueForField('meta_keywords')}
                                        onChange={(e) => setTranslationField('meta_keywords', e.target.value)}
                                        placeholder="e.g. coffee, espresso, home appliances"
                                    />
                                    <p className={ui.fieldHint}>
                                        {t('admin.categories.meta_keywords_hint', 'Optional. Add related search terms separated by commas. Example: coffee, wholesale, B2B. You can leave this blank.')}
                                    </p>
                                </>
                            </LocalizedField>
                            <LocalizedField
                                label={t('admin.categories.canonical_url', 'Canonical URL')}
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={setSelectedLanguage}
                                error={errorForField('canonical_url')}
                                hasValue={hasValueForField('canonical_url')}
                                fallbackValue={fallbackForField('canonical_url')}
                                fallbackLanguage={fallbackLanguage}
                            >
                                <Input wide value={valueForField('canonical_url')} onChange={(e) => setTranslationField('canonical_url', e.target.value)} placeholder="https://" />
                            </LocalizedField>
                            <div className={ui.seoPreview}>
                                <div className={ui.seoPreviewTitle}>{valueForField('meta_title') || valueForField('name') || 'Category title'}</div>
                                <div className={ui.seoPreviewUrl}>{seoPreviewUrl(valueForField('canonical_url'), shopUrl, valueForField('slug'))}</div>
                                <div className={ui.seoPreviewDesc}>{valueForField('meta_description') || 'Meta description will appear here.'}</div>
                            </div>
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    {multivendorSidebar}
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.categories.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>
                            {t('admin.categories.inactive_hint', 'Inactive categories stay linked to existing products but are hidden when assigning new products.')}
                        </p>
                        {category ? (
                            <p className={`${ui.metaK} mt-2`}>
                                {category.products_count || 0} {t('admin.categories.products_assigned', 'products assigned')}
                            </p>
                        ) : null}
                    </section>
                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {category ? t('admin.common.save', 'Save changes') : t('admin.categories.create_category', 'Create category')}
                            </Button>
                            <Button type="button" variant="secondary" href={categoriesIndexPath}>
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
