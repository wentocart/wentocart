import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { useStore } from '../../state/StoreState.jsx';
import { PageSkeleton } from '../../components/Feedback.jsx';
import {
    FormField,
    FormNotice,
    FormSection,
    FormShell,
    FormSwitch,
    LocalizedFormField,
} from '../../components/Form.jsx';
import RichEditor from '../../components/RichEditor.jsx';
import CategoryTreePicker from './CategoryTreePicker.jsx';
import { useSellerBreadcrumb } from './useSellerBreadcrumb.js';
import { cartesianVariants, normalizeProductType, tabsForType, typeSupports } from './productTypes.js';
import { SaveBtnLabel } from './sellerUi.jsx';

function emptyLocale() {
    return {
        name: '',
        slug: '',
        short_description: '',
        description: '',
        meta_title: '',
        meta_description: '',
        meta_keywords: '',
        focus_keyword: '',
        canonical_url: '',
        robots: 'index,follow',
    };
}

function emptyForm(type = 'simple') {
    return {
        type: normalizeProductType(type),
        sku: '',
        price: '',
        compare_price: '',
        sale_price: '',
        sale_price_type: 'fixed',
        sale_starts_at: '',
        sale_ends_at: '',
        cost_price: '',
        stock_qty: '0',
        manage_stock: true,
        stock_status: 'in_stock',
        backorder_status: 'deny',
        low_stock_threshold: '5',
        low_stock_alert: '10',
        critical_stock: '3',
        min_qty: '1',
        max_qty: '',
        qty_step: '1',
        brand_id: '',
        tax_class_id: '',
        category_ids: [],
        default_category_id: '',
        visibility: 'both',
        show_price: true,
        is_enabled: true,
        weight: '',
        weight_class: 'kg',
        length: '',
        width: '',
        height: '',
        length_class: 'cm',
        processing_time: '',
        processing_time_unit: 'days',
        delivery_from: '',
        delivery_to: '',
        additional_shipping_cost: '',
        available_all_carriers: true,
        shipping_method_ids: [],
        barcode: '',
        isbn: '',
        upc: '',
        mpn: '',
        gtin: '',
        display_condition: false,
        condition: 'new',
        feature_value_ids: [],
        related_ids: [],
        selection_mode: 'all',
        bundle_type: 'fixed',
        pricing_mode: 'sum',
        inventory_mode: 'derived',
        shipping_mode: 'derived',
        components: [],
        combination_axes: [],
        combination_variants: [],
        gift_card: {
            amount_type: 'fixed',
            delivery_method: 'email',
            validity_type: 'never',
            usage_type: 'single',
        },
        gift_card_amounts: [],
        service: {
            service_type: 'one_time',
            duration_unit: 'minutes',
            pricing_model: 'fixed',
            delivery_method: 'online',
        },
        rental: {
            rental_unit: 'day',
            pricing_model: 'per_day',
            security_deposit_type: 'none',
            inventory_mode: 'quantity',
        },
        rental_pricing_tiers: [],
        preorder_enabled: false,
        preorder: {
            payment_type: 'full',
            min_quantity: 1,
            show_badge: true,
            show_release_date: true,
            show_delivery_date: true,
            show_countdown_pdp: false,
            show_countdown_listing: false,
            show_image_label: false,
            fulfillment_rule: 'auto',
        },
    };
}

function errText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}

function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}

function ProductImagesPanel({
    images,
    pendingFiles,
    pendingCoverIndex,
    onPickFiles,
    onRemovePending,
    onPendingCoverChange,
    onReorderPending,
    onSetCover,
    onReorderSaved,
    onDelete,
    canDelete,
}) {
    const [draggingUpload, setDraggingUpload] = useState(false);
    const [inputKey, setInputKey] = useState(0);
    const [pendingUrls, setPendingUrls] = useState([]);
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);

    useEffect(() => {
        const urls = pendingFiles.map((file) => URL.createObjectURL(file));
        setPendingUrls(urls);
        return () => {
            urls.forEach((url) => URL.revokeObjectURL(url));
        };
    }, [pendingFiles]);

    const takeFiles = (list) => {
        const files = Array.from(list || []).filter((file) => file && String(file.type || '').startsWith('image/'));
        if (files.length) {
            onPickFiles(files);
        }
        setInputKey((key) => key + 1);
    };

    const rawItems = [
        ...images.map((image) => ({
            key: `saved:${image.id}`,
            kind: 'saved',
            image,
            url: image.url,
            isCover: !!image.is_cover,
        })),
        ...pendingFiles.map((file, index) => ({
            key: `pending:${index}:${file.name}:${file.size}`,
            kind: 'pending',
            index,
            file,
            url: pendingUrls[index],
            isCover: images.length === 0 && Number(pendingCoverIndex) === index,
        })),
    ];
    const coverIdx = rawItems.findIndex((item) => item.isCover);
    const items = coverIdx > 0
        ? [rawItems[coverIdx], ...rawItems.filter((_, i) => i !== coverIdx)]
        : rawItems;

    const coverKey = items.find((item) => item.isCover)?.key || null;
    const coverLocked = Boolean(coverKey);
    const hasGallery = items.length > 0;

    return (
        <div className="space-y-3">
            <label
                className={`grid cursor-pointer place-items-center gap-2 rounded-xl border border-dashed px-3 py-5 text-center transition-colors ${
                    draggingUpload
                        ? 'border-[var(--shop-accent,#4545e6)] bg-[var(--shop-accent-soft,#e7f0ff)]'
                        : 'border-neutral-300 bg-neutral-50 hover:border-neutral-400'
                }`}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDraggingUpload(true);
                }}
                onDragLeave={() => setDraggingUpload(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDraggingUpload(false);
                    takeFiles(e.dataTransfer.files);
                }}
            >
                <input
                    key={inputKey}
                    type="file"
                    accept="image/*"
                    multiple
                    className="sr-only"
                    onChange={(e) => takeFiles(e.target.files)}
                />
                <span className="text-sm font-semibold text-neutral-700">
                    <span className="text-[var(--shop-accent,#4545e6)]">
                        {t('storefront.seller.choose_files', 'Choose Files')}
                    </span>
                    {' '}
                    {t('storefront.seller.or_drag_drop', 'or drag and drop')}
                </span>
                <span className="text-xs text-neutral-500">
                    {t('storefront.seller.upload_images_help', 'Upload one or more product images. On create they are saved with the product.')}
                </span>
            </label>

            {hasGallery ? (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {items.map((item, index) => {
                        const isCover = coverKey === item.key;
                        const sortable = coverLocked ? index > 0 : items.length > 1;
                        const allowDrop = !(coverLocked && index === 0);
                        const isOver = allowDrop && overIndex === index && fromIndex !== null && fromIndex !== index;

                        return (
                            <div
                                key={item.key}
                                className={`min-w-0 ${sortable ? 'cursor-grab' : ''} ${isOver ? 'rounded-xl outline outline-2 outline-offset-2 outline-[var(--shop-accent,#4545e6)]' : ''}`}
                                draggable={!!sortable}
                                onDragStart={(e) => {
                                    if (!sortable || e.target.closest('input, label, button')) {
                                        e.preventDefault();
                                        return;
                                    }
                                    e.dataTransfer.effectAllowed = 'move';
                                    e.dataTransfer.setData('text/plain', item.key);
                                    setFromIndex(index);
                                }}
                                onDragOver={(e) => {
                                    if (!allowDrop) {
                                        return;
                                    }
                                    e.preventDefault();
                                    e.dataTransfer.dropEffect = 'move';
                                    setOverIndex(index);
                                }}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    const origin = fromIndex;
                                    setFromIndex(null);
                                    setOverIndex(null);
                                    if (origin == null || origin === index || (coverLocked && (origin === 0 || index === 0))) {
                                        return;
                                    }
                                    const next = moveItem(items, origin, index);
                                    const savedIds = next.filter((row) => row.kind === 'saved').map((row) => row.image.id);
                                    const pendingNext = next.filter((row) => row.kind === 'pending').map((row) => row.file);
                                    if (savedIds.length === next.length && onReorderSaved) {
                                        onReorderSaved(savedIds);
                                    } else if (pendingNext.length === next.length && onReorderPending) {
                                        onReorderPending(pendingNext);
                                        if (coverLocked) {
                                            onPendingCoverChange?.(0);
                                        } else {
                                            const coverAt = next.findIndex((row) => row.isCover);
                                            onPendingCoverChange?.(coverAt >= 0 ? coverAt : 0);
                                        }
                                    } else {
                                        if (savedIds.length && onReorderSaved) {
                                            onReorderSaved(savedIds);
                                        }
                                        if (pendingNext.length && onReorderPending) {
                                            onReorderPending(pendingNext);
                                        }
                                    }
                                }}
                                onDragEnd={() => {
                                    setFromIndex(null);
                                    setOverIndex(null);
                                }}
                            >
                                <div className="group relative aspect-square overflow-hidden rounded-xl bg-neutral-100">
                                    {item.url ? (
                                        <img src={item.url} alt="" draggable={false} className="h-full w-full object-contain" />
                                    ) : null}
                                    {(item.kind === 'pending' || (item.kind === 'saved' && canDelete)) ? (
                                        <button
                                            type="button"
                                            className="absolute top-1.5 right-1.5 z-[2] cursor-pointer text-red-600 hover:text-red-700"
                                            title={t('storefront.seller.remove', 'Remove')}
                                            aria-label={t('storefront.seller.remove', 'Remove')}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                if (item.kind === 'pending') {
                                                    onRemovePending(item.index);
                                                } else {
                                                    onDelete(item.image);
                                                }
                                            }}
                                        >
                                            <TrashIcon />
                                        </button>
                                    ) : null}
                                    <label
                                        className={`absolute inset-x-0 bottom-0 z-[1] m-0 flex cursor-pointer items-center gap-1.5 border-t border-neutral-200 bg-white px-2 py-1 ${
                                            isCover ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100'
                                        }`}
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <input
                                            type="checkbox"
                                            className="h-3.5 w-3.5 cursor-pointer accent-[var(--shop-accent,#4545e6)]"
                                            checked={isCover}
                                            onChange={(e) => {
                                                if (!e.target.checked) {
                                                    return;
                                                }
                                                if (item.kind === 'saved') {
                                                    onSetCover?.(item.image);
                                                } else {
                                                    onPendingCoverChange?.(item.index);
                                                }
                                            }}
                                        />
                                        <span className={`text-[11px] font-semibold ${isCover ? 'text-[var(--shop-accent,#4545e6)]' : 'text-neutral-600'}`}>
                                            {isCover
                                                ? t('storefront.seller.cover', 'Cover')
                                                : t('storefront.seller.set_as_cover', 'Set as cover')}
                                        </span>
                                    </label>
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <p className="mv-muted-sm">{t('storefront.seller.no_images_yet', 'No images yet.')}</p>
            )}
        </div>
    );
}

function TrashIcon() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <path d="M3 6h18" strokeLinecap="round" />
            <path d="M8 6V4h8v2" strokeLinecap="round" />
            <path d="M19 6l-1 14H6L5 6" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M10 11v6M14 11v6" strokeLinecap="round" />
        </svg>
    );
}

function moderationLabel(status) {
    if (status === 'pending') return t('storefront.seller.moderation_pending', 'Pending moderation');
    if (status === 'approved') return t('storefront.seller.moderation_approved', 'Approved');
    if (status === 'rejected') return t('storefront.seller.moderation_rejected', 'Rejected');
    return status || '';
}

function buildLocaleMap(languages, translations) {
    const map = {};
    (languages || []).forEach((lang) => {
        map[lang.code] = { ...emptyLocale(), ...(translations?.[lang.code] || {}) };
    });
    if (Object.keys(map).length === 0) {
        map.en = emptyLocale();
    }
    return map;
}

export default function SellerProductForm() {
    const { id } = useParams();
    const [search] = useSearchParams();
    const nav = useNavigate();
    const store = useStore();
    const isNew = !id;

    const [options, setOptions] = useState(null);
    const [tab, setTab] = useState('general');
    const [form, setForm] = useState(() => emptyForm(search.get('type') || 'simple'));
    const [locales, setLocales] = useState({});
    const [localeCode, setLocaleCode] = useState('en');
    const [images, setImages] = useState([]);
    const [pendingFiles, setPendingFiles] = useState([]);
    const [pendingCoverIndex, setPendingCoverIndex] = useState(0);
    const [meta, setMeta] = useState({ approval_status: null, approval_note: null });
    const [movements, setMovements] = useState([]);
    const [inventorySummary, setInventorySummary] = useState(null);
    const [adjustDelta, setAdjustDelta] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [ready, setReady] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);

    const languages = options?.languages || [];
    const defaultLocale = languages.find((l) => l.is_default)?.code || languages[0]?.code || 'en';
    const activeLocale = locales[localeCode] || emptyLocale();
    const productType = normalizeProductType(form.type);
    const tabs = tabsForType(productType);
    const breadcrumbName = isNew
        ? null
        : (activeLocale.name || locales[defaultLocale]?.name || '').trim() || null;

    useSellerBreadcrumb(breadcrumbName);

    const set = (key, value) => {
        setFieldErrors((prev) => {
            if (!prev[key]) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
        setForm((current) => ({ ...current, [key]: value }));
    };

    const setLocaleField = (key, value) => {
        setFieldErrors((prev) => {
            const next = { ...prev };
            delete next[key];
            delete next[`translations.${localeCode}.${key}`];
            return next;
        });
        setLocales((current) => ({
            ...current,
            [localeCode]: { ...(current[localeCode] || emptyLocale()), [key]: value },
        }));
    };

    const loadInventory = () => api.get(`/account/seller/products/${id}/inventory`)
        .then((inv) => {
            setMovements(inv.data.movements || []);
            setInventorySummary(inv.data.summary || null);
        })
        .catch(() => {
            setMovements([]);
            setInventorySummary(null);
        });

    useEffect(() => {
        let cancelled = false;
        api.get('/account/seller/options').then((r) => {
            if (cancelled) return;
            const opts = r.data;
            setOptions(opts);
            const langs = opts.languages || [];
            const def = langs.find((l) => l.is_default)?.code || langs[0]?.code || 'en';
            setLocaleCode(def);

            if (isNew) {
                setLocales(buildLocaleMap(langs, {}));
                setReady(true);
                return;
            }

            Promise.all([
                api.get(`/account/seller/products/${id}`),
                api.get(`/account/seller/products/${id}/inventory`).catch(() => ({ data: {} })),
            ]).then(([prodRes, invRes]) => {
                if (cancelled) return;
                const p = prodRes.data.product;
                const next = emptyForm(p.type || 'simple');
                Object.keys(next).forEach((key) => {
                    if (p[key] !== undefined && p[key] !== null && typeof next[key] !== 'object') {
                        next[key] = p[key];
                    }
                });
                next.type = p.type || 'simple';
                next.category_ids = p.category_ids || [];
                next.default_category_id = p.default_category_id || p.category_id || '';
                next.shipping_method_ids = p.shipping_method_ids || [];
                next.feature_value_ids = p.feature_value_ids || [];
                next.related_ids = p.related_ids || [];
                next.combination_axes = (p.combination_axes || []).map((axis) => ({
                    attribute_id: axis.attribute_id,
                    value_ids: axis.value_ids || [],
                }));
                next.combination_variants = (p.combination_variants || []).map((row) => ({
                    id: row.id,
                    attribute_value_ids: row.attribute_value_ids || (row.values || []).map((v) => v.id),
                    sku: row.sku || '',
                    barcode: row.barcode || '',
                    price: row.price ?? '',
                    sale_price: row.sale_price ?? '',
                    stock_qty: row.stock_qty ?? 0,
                    manage_stock: row.manage_stock ?? true,
                    stock_status: row.stock_status || 'in_stock',
                    weight: row.weight ?? '',
                    is_enabled: row.is_enabled ?? true,
                    is_default: !!row.is_default,
                    position: row.position ?? 0,
                }));
                next.components = (p.components || []).map((row) => ({
                    id: row.child_product_id || row.id,
                    quantity: row.quantity || 1,
                    min_quantity: row.min_quantity || 1,
                    max_quantity: row.max_quantity || '',
                    is_required: row.is_required ?? true,
                    qty_editable: row.qty_editable ?? false,
                    default_selected: row.default_selected ?? true,
                    option_group: row.option_group || '',
                }));
                if (p.gift_card) next.gift_card = { ...next.gift_card, ...p.gift_card };
                if (p.gift_card_amounts) next.gift_card_amounts = p.gift_card_amounts;
                if (p.service) next.service = { ...next.service, ...p.service };
                if (p.rental) next.rental = { ...next.rental, ...p.rental };
                if (p.rental_pricing_tiers) next.rental_pricing_tiers = p.rental_pricing_tiers;
                next.preorder_enabled = !!p.preorder_enabled;
                if (p.preorder) next.preorder = { ...next.preorder, ...p.preorder };
                next.stock_qty = String(p.stock_qty ?? 0);
                next.price = p.price ?? '';
                next.compare_price = p.compare_price ?? '';
                next.sale_price = p.sale_price ?? '';
                next.cost_price = p.cost_price ?? '';
                setForm(next);

                const localeMap = buildLocaleMap(langs, p.translations || {});
                if (p.name && localeMap[def]) {
                    localeMap[def] = {
                        ...localeMap[def],
                        name: localeMap[def].name || p.name || '',
                        slug: localeMap[def].slug || p.slug || '',
                        short_description: localeMap[def].short_description || p.short_description || '',
                        description: localeMap[def].description || p.description || '',
                    };
                }
                setLocales(localeMap);
                setImages(p.images || []);
                setMeta({
                    approval_status: p.approval_status || null,
                    approval_note: p.approval_note || null,
                });
                setMovements(invRes.data.movements || []);
                setInventorySummary(invRes.data.summary || null);
                setReady(true);
            }).catch(() => {
                if (!cancelled) {
                    setError(t('storefront.seller.product_missing', 'Product not found.'));
                    setReady(true);
                }
            });
        }).catch(() => {
            if (!cancelled) {
                setError(t('storefront.seller.load_failed', 'Could not load products.'));
                setReady(true);
            }
        });
        return () => { cancelled = true; };
    }, [id, isNew]);

    useEffect(() => {
        if (!tabs.some((row) => row.id === tab)) {
            setTab(tabs[0]?.id || 'general');
        }
    }, [productType, tab, tabs]);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }
        const onDoc = (event) => {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setMenuOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        window.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            window.removeEventListener('keydown', onKey);
        };
    }, [menuOpen]);

    const typeOptions = useMemo(
        () => options?.product_types || [
            { value: 'simple', label: 'Simple' },
            { value: 'combination', label: 'Combination' },
            { value: 'virtual', label: 'Virtual' },
        ],
        [options],
    );

    const generateCombinations = () => {
        const variants = cartesianVariants(form.combination_axes);
        set('combination_variants', variants);
    };

    const buildPayload = () => {
        const translations = languages
            .map((lang) => {
                const row = locales[lang.code] || emptyLocale();
                if (!String(row.name || '').trim() && lang.code !== defaultLocale) {
                    return null;
                }
                return { language_code: lang.code, ...row };
            })
            .filter(Boolean);

        const defaultRow = locales[defaultLocale] || emptyLocale();
        const payload = {
            ...form,
            name: defaultRow.name || form.name || '',
            slug: defaultRow.slug || '',
            short_description: defaultRow.short_description || '',
            description: defaultRow.description || '',
            translations,
            brand_id: form.brand_id || null,
            tax_class_id: form.tax_class_id || null,
            category_ids: form.category_ids,
            default_category_id: form.default_category_id || form.category_ids[0] || null,
            category_id: form.default_category_id || form.category_ids[0] || null,
            compare_price: form.compare_price === '' ? null : form.compare_price,
            sale_price: form.sale_price === '' ? null : form.sale_price,
            cost_price: form.cost_price === '' ? null : form.cost_price,
            stock_qty: Number(form.stock_qty || 0),
            manage_stock: !!form.manage_stock,
            show_price: !!form.show_price,
            is_enabled: !!form.is_enabled,
            available_all_carriers: !!form.available_all_carriers,
            display_condition: !!form.display_condition,
            preorder_enabled: !!form.preorder_enabled,
            preorder: form.preorder_enabled ? { ...form.preorder } : null,
        };

        if (!typeSupports(productType, 'combinations') && productType !== 'combination') {
            delete payload.combination_axes;
            delete payload.combination_variants;
        }
        if (!typeSupports(productType, 'components')) {
            delete payload.components;
        }
        if (!typeSupports(productType, 'gift_card')) {
            delete payload.gift_card;
            delete payload.gift_card_amounts;
        }
        if (!typeSupports(productType, 'service')) {
            delete payload.service;
        }
        if (!typeSupports(productType, 'rental')) {
            delete payload.rental;
            delete payload.rental_pricing_tiers;
        }
        if (!typeSupports(productType, 'preorder')) {
            delete payload.preorder_enabled;
            delete payload.preorder;
        }
        if (productType === 'combination') {
            payload.remove_missing_combinations = true;
        }
        if (isNew) {
            payload.type = productType;
        } else {
            delete payload.type;
        }

        return payload;
    };

    const uploadPending = async (productId) => {
        if (!pendingFiles.length) return;
        const body = new FormData();
        pendingFiles.forEach((file) => body.append('images[]', file));
        if (pendingCoverIndex >= 0 && pendingCoverIndex < pendingFiles.length) {
            body.append('cover_index', String(pendingCoverIndex));
        }
        await csrf();
        await api.post(`/account/seller/products/${productId}/images`, body);
        setPendingFiles([]);
        setPendingCoverIndex(0);
    };

    const pickPendingFiles = (files) => {
        setPendingFiles((current) => {
            const next = [...current, ...files];
            if (current.length === 0) {
                setPendingCoverIndex(0);
            }
            return next;
        });
    };

    const removePendingFile = (index) => {
        setPendingFiles((current) => {
            const next = current.filter((_, i) => i !== index);
            setPendingCoverIndex((cover) => {
                if (next.length === 0) return 0;
                if (cover === index) return 0;
                if (cover > index) return cover - 1;
                return cover;
            });
            return next;
        });
    };

    const reorderPendingFiles = (files) => {
        setPendingFiles(files);
        setPendingCoverIndex(0);
    };

    const setPendingCover = (index) => {
        setPendingFiles((current) => {
            if (index < 0 || index >= current.length) {
                return current;
            }
            if (index === 0) {
                setPendingCoverIndex(0);
                return current;
            }
            const next = [...current];
            const [file] = next.splice(index, 1);
            next.unshift(file);
            setPendingCoverIndex(0);
            return next;
        });
    };

    const setSavedCover = async (image) => {
        if (!id || !image?.id) return;
        try {
            const imageOrder = [image.id, ...images.filter((row) => Number(row.id) !== Number(image.id)).map((row) => row.id)];
            await csrf();
            const { data } = await api.patch(`/account/seller/products/${id}/images/order`, {
                image_order: imageOrder,
                cover_image_id: image.id,
            });
            setImages(data.product?.images || []);
        } catch (err) {
            store.ping(err.response?.data?.message || t('storefront.seller.cover_failed', 'Could not set cover image'), 'error');
        }
    };

    const reorderSavedImages = async (imageIds) => {
        if (!id || !imageIds?.length) return;
        try {
            const coverId = images.find((row) => row.is_cover)?.id || imageIds[0];
            await csrf();
            const { data } = await api.patch(`/account/seller/products/${id}/images/order`, {
                image_order: imageIds,
                cover_image_id: coverId,
            });
            setImages(data.product?.images || []);
        } catch (err) {
            store.ping(err.response?.data?.message || t('storefront.seller.reorder_failed', 'Could not reorder images'), 'error');
        }
    };

    const addProductImages = async (files) => {
        const list = Array.from(files || []).filter((file) => file && String(file.type || '').startsWith('image/'));
        if (!list.length) {
            return;
        }
        if (isNew || !id) {
            pickPendingFiles(list);
            return;
        }
        try {
            const body = new FormData();
            list.forEach((file) => body.append('images[]', file));
            await csrf();
            const { data } = await api.post(`/account/seller/products/${id}/images`, body);
            setImages(data.product?.images || []);
            store.ping(t('storefront.seller.images_uploaded', 'Images uploaded'));
        } catch (err) {
            store.ping(err.response?.data?.message || t('storefront.seller.images_upload_failed', 'Could not upload images'), 'error');
        }
    };

    const onSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setFieldErrors({});
        setBusy(true);
        try {
            await csrf();
            const payload = buildPayload();
            let productId = id;
            if (isNew) {
                const { data } = await api.post('/account/seller/products', payload);
                productId = data.product?.id;
                await uploadPending(productId);
            } else {
                await api.patch(`/account/seller/products/${id}`, payload);
                await uploadPending(id);
            }
            nav('/account/seller/products');
        } catch (err) {
            setFieldErrors(err.response?.data?.errors || {});
            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
        } finally {
            setBusy(false);
        }
    };

    if (!ready || !options) {
        return <PageSkeleton />;
    }

    if (error && !form.price && isNew === false && !meta.approval_status) {
        return (
            <div>
                <FormNotice tone="error">{error}</FormNotice>
                <Link to="/account/seller/products" className="shop-btn shop-btn-ghost">{t('storefront.seller.cancel', 'Cancel')}</Link>
            </div>
        );
    }

    const fe = (key) => errText(fieldErrors, key);

    const typeLabel = (typeOptions.find((row) => row.value === productType)?.label) || productType;
    const saveLabel = isNew
        ? t('storefront.seller.save_product', 'Save product')
        : t('storefront.seller.save_changes', 'Save Changes');
    const previewSlug = String(activeLocale.slug || locales[defaultLocale]?.slug || '').trim();
    const previewHref = previewSlug ? `/${previewSlug.replace(/^\/+/, '')}` : '';

    return (
        <FormShell onSubmit={onSubmit} noValidate size="wide" className="mv-product-form">
            <div className="mv-product-chrome">
                <div className="mv-product-head">
                    <div className="mv-product-head-meta min-w-0">
                        <h2 className="mv-product-head-title">
                            {isNew
                                ? t('storefront.seller.add_product', 'Add product')
                                : t('storefront.seller.edit_product', 'Edit product')}
                        </h2>
                        <span className="mv-product-head-type">
                            {typeLabel}
                        </span>
                    </div>
                    <div className="mv-product-head-actions">
                        <button
                            type="button"
                            className={`shop-btn shop-btn--sm border ${form.is_enabled
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                : 'border-neutral-300 bg-white text-neutral-700'}`}
                            aria-pressed={!!form.is_enabled}
                            onClick={() => {
                                const next = !form.is_enabled;
                                set('is_enabled', next);
                                store.ping(next
                                    ? t('storefront.seller.product_activated', 'Product set to Active')
                                    : t('storefront.seller.product_deactivated', 'Product set to Inactive'));
                            }}
                        >
                            <span
                                className={`h-1.5 w-1.5 rounded-full ${form.is_enabled ? 'bg-emerald-500' : 'bg-neutral-400'}`}
                                aria-hidden="true"
                            />
                            {form.is_enabled
                                ? t('storefront.seller.active', 'Active')
                                : t('storefront.seller.inactive', 'Inactive')}
                        </button>
                        <button type="submit" className="shop-btn shop-btn-primary shop-btn--sm" disabled={busy}>
                            {busy ? t('storefront.account.saving', 'Saving…') : <SaveBtnLabel>{saveLabel}</SaveBtnLabel>}
                        </button>
                        <div className="relative" ref={menuRef}>
                            <button
                                type="button"
                                className="shop-btn shop-btn-ghost shop-btn--sm shop-btn-icon text-lg leading-none"
                                aria-haspopup="menu"
                                aria-expanded={menuOpen}
                                aria-label={t('storefront.seller.more_actions', 'More actions')}
                                onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    setMenuOpen((open) => !open);
                                }}
                            >
                                ⋯
                            </button>
                            {menuOpen ? (
                                <div
                                    className="absolute right-0 z-[70] mt-1 min-w-[12rem] overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 shadow-lg"
                                    role="menu"
                                >
                                    <button
                                        type="button"
                                        role="menuitem"
                                        className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50"
                                        onClick={() => {
                                            setMenuOpen(false);
                                            nav(`/account/seller/products/new?type=${encodeURIComponent(productType)}`);
                                        }}
                                    >
                                        <span aria-hidden="true">+</span>
                                        {t('storefront.seller.new_product', 'New Product')}
                                    </button>
                                    {previewHref ? (
                                        <a
                                            href={previewHref}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            role="menuitem"
                                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50"
                                            onClick={() => setMenuOpen(false)}
                                        >
                                            <span aria-hidden="true">◎</span>
                                            {t('storefront.seller.preview', 'Preview')}
                                        </a>
                                    ) : (
                                        <button
                                            type="button"
                                            role="menuitem"
                                            disabled
                                            title={t('storefront.seller.preview_needs_slug', 'Add a URL key to preview.')}
                                            className="flex w-full cursor-not-allowed items-center gap-2 px-3 py-2 text-left text-sm text-neutral-400"
                                        >
                                            <span aria-hidden="true">◎</span>
                                            {t('storefront.seller.preview', 'Preview')}
                                        </button>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="shop-tabs" role="tablist">
                    {tabs.map((row) => (
                        <button
                            key={row.id}
                            type="button"
                            role="tab"
                            className={tab === row.id ? 'is-on' : ''}
                            aria-selected={tab === row.id}
                            onClick={(e) => {
                                setTab(row.id);
                                e.currentTarget.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' });
                            }}
                        >
                            {t(`storefront.seller.tab.${row.id}`, row.label)}
                        </button>
                    ))}
                </div>
            </div>

            {!isNew && meta.approval_status ? (
                <FormNotice tone={meta.approval_status === 'rejected' ? 'error' : meta.approval_status === 'approved' ? 'ok' : 'info'}>
                    {moderationLabel(meta.approval_status)}
                    {meta.approval_status === 'rejected' && meta.approval_note ? ` — ${meta.approval_note}` : ''}
                    {meta.approval_status === 'rejected' ? ` ${t('storefront.seller.resubmit_help', 'Save your changes to resubmit for review.')}` : ''}
                    {meta.approval_status === 'approved' ? ` ${t('storefront.seller.reedit_help', 'Saving changes may send this listing back for review.')}` : ''}
                </FormNotice>
            ) : null}

            {tab === 'general' ? (
                <div className="mv-product-layout">
                    <FormSection title={t('storefront.seller.section_details', 'Product details')}>
                        <LocalizedFormField
                            label={t('storefront.seller.product_name', 'Name')}
                            languages={languages}
                            currentLanguage={localeCode}
                            onLanguageChange={setLocaleCode}
                            required
                            span
                            error={fe('name') || fe(`translations.${localeCode}.name`)}
                        >
                            <input className="shop-input" value={activeLocale.name} onChange={(e) => setLocaleField('name', e.target.value)} />
                        </LocalizedFormField>
                        <LocalizedFormField
                            label={t('storefront.seller.slug', 'URL key')}
                            languages={languages}
                            currentLanguage={localeCode}
                            onLanguageChange={setLocaleCode}
                            span
                            error={fe('slug') || fe(`translations.${localeCode}.slug`)}
                        >
                            <input className="shop-input" value={activeLocale.slug} onChange={(e) => setLocaleField('slug', e.target.value)} />
                        </LocalizedFormField>
                        <LocalizedFormField
                            label={t('storefront.seller.short_description', 'Short description')}
                            languages={languages}
                            currentLanguage={localeCode}
                            onLanguageChange={setLocaleCode}
                            span
                            error={fe('short_description')}
                        >
                            <RichEditor
                                compact
                                value={activeLocale.short_description}
                                onChange={(html) => setLocaleField('short_description', html)}
                                placeholder={t('storefront.seller.short_description_placeholder', 'Write a short product summary…')}
                            />
                        </LocalizedFormField>
                        <LocalizedFormField
                            label={t('storefront.seller.description', 'Description')}
                            languages={languages}
                            currentLanguage={localeCode}
                            onLanguageChange={setLocaleCode}
                            span
                            error={fe('description')}
                        >
                            <RichEditor
                                value={activeLocale.description}
                                onChange={(html) => setLocaleField('description', html)}
                            />
                        </LocalizedFormField>
                        <FormField label={t('storefront.seller.brand', 'Brand')} error={fe('brand_id')}>
                            <select className="shop-input" value={form.brand_id || ''} onChange={(e) => set('brand_id', e.target.value)}>
                                <option value="">{t('storefront.seller.no_brand', 'No brand')}</option>
                                {(options.brands || []).map((row) => (
                                    <option key={row.id} value={row.id}>{row.name}</option>
                                ))}
                            </select>
                        </FormField>
                    </FormSection>

                    <div className="mv-product-side">
                        <FormSection title={t('storefront.seller.section_images', 'Images')} layout="stack">
                            <ProductImagesPanel
                                images={images}
                                pendingFiles={pendingFiles}
                                pendingCoverIndex={pendingCoverIndex}
                                onPickFiles={addProductImages}
                                onRemovePending={removePendingFile}
                                onPendingCoverChange={setPendingCover}
                                onReorderPending={reorderPendingFiles}
                                onSetCover={setSavedCover}
                                onReorderSaved={reorderSavedImages}
                                canDelete={!isNew}
                                onDelete={async (image) => {
                                    await csrf();
                                    const { data } = await api.delete(`/account/seller/products/${id}/images/${image.id}`);
                                    setImages(data.product?.images || []);
                                }}
                            />
                        </FormSection>

                        <FormSection title={t('storefront.seller.categories', 'Categories')} layout="stack">
                            <CategoryTreePicker
                                categories={options.categories || []}
                                selectedIds={form.category_ids}
                                defaultCategoryId={form.default_category_id}
                                onChange={(category_ids) => setForm((current) => ({
                                    ...current,
                                    category_ids,
                                    default_category_id: category_ids.map(Number).includes(Number(current.default_category_id))
                                        ? current.default_category_id
                                        : (category_ids[0] || ''),
                                }))}
                                onDefaultChange={(default_category_id) => set('default_category_id', default_category_id)}
                                error={fe('category_ids') || fe('default_category_id')}
                            />
                        </FormSection>

                        <FormSection title={t('storefront.seller.section_visibility', 'Visibility')} layout="stack">
                            <FormField label={t('storefront.seller.visibility', 'Visibility')} error={fe('visibility')}>
                                <select className="shop-input" value={form.visibility} onChange={(e) => set('visibility', e.target.value)}>
                                    {(options.visibility || ['both', 'catalog', 'search', 'hidden']).map((row) => (
                                        <option key={row} value={row}>{row}</option>
                                    ))}
                                </select>
                            </FormField>
                            <FormSwitch checked={form.show_price} onChange={(on) => set('show_price', on)} label={t('storefront.seller.show_price', 'Show price')} />
                            <FormSwitch
                                checked={form.is_enabled}
                                onChange={(on) => {
                                    set('is_enabled', on);
                                    store.ping(on
                                        ? t('storefront.seller.product_activated', 'Product set to Active')
                                        : t('storefront.seller.product_deactivated', 'Product set to Inactive'));
                                }}
                                label={t('storefront.seller.enabled', 'Enabled')}
                                hint={t('storefront.seller.enabled_moderation_hint', 'Pending moderation may keep the listing disabled until approved.')}
                            />
                        </FormSection>
                    </div>
                </div>
            ) : null}

            {tab === 'details' ? (
                <FormSection title={t('storefront.seller.section_identifiers', 'Identifiers')}>
                    <FormField label={t('storefront.seller.sku', 'SKU')} error={fe('sku')}><input className="shop-input" value={form.sku} onChange={(e) => set('sku', e.target.value)} /></FormField>
                    <FormField label={t('storefront.seller.barcode', 'Barcode')} error={fe('barcode')}><input className="shop-input" value={form.barcode || ''} onChange={(e) => set('barcode', e.target.value)} /></FormField>
                    <FormField label="GTIN" error={fe('gtin')}><input className="shop-input" value={form.gtin || ''} onChange={(e) => set('gtin', e.target.value)} /></FormField>
                    <FormField label="MPN" error={fe('mpn')}><input className="shop-input" value={form.mpn || ''} onChange={(e) => set('mpn', e.target.value)} /></FormField>
                    <FormField label="UPC" error={fe('upc')}><input className="shop-input" value={form.upc || ''} onChange={(e) => set('upc', e.target.value)} /></FormField>
                    <FormField label="ISBN" error={fe('isbn')}><input className="shop-input" value={form.isbn || ''} onChange={(e) => set('isbn', e.target.value)} /></FormField>
                    <FormSwitch checked={form.display_condition} onChange={(on) => set('display_condition', on)} label={t('storefront.seller.display_condition', 'Show condition')} />
                    {form.display_condition ? (
                        <FormField label={t('storefront.seller.condition', 'Condition')}>
                            <select className="shop-input" value={form.condition || 'new'} onChange={(e) => set('condition', e.target.value)}>
                                {(options.conditions || []).map((row) => (
                                    <option key={row} value={row}>{row}</option>
                                ))}
                            </select>
                        </FormField>
                    ) : null}
                    {(options.features || []).length > 0 ? (
                        <FormField label={t('storefront.seller.features', 'Features')} span>
                            <div className="flex flex-col gap-3">
                                {(options.features || []).map((feature) => (
                                    <label key={feature.id} className="text-sm">
                                        <span className="shop-field-label">{feature.name}</span>
                                        <select
                                            className="shop-input mt-1"
                                            value={form.feature_value_ids.find((row) => Number(row.feature_id) === Number(feature.id))?.feature_value_id || ''}
                                            onChange={(e) => {
                                                const valueId = e.target.value;
                                                setForm((current) => {
                                                    const rest = current.feature_value_ids.filter((row) => Number(row.feature_id) !== Number(feature.id));
                                                    if (!valueId) return { ...current, feature_value_ids: rest };
                                                    return {
                                                        ...current,
                                                        feature_value_ids: [...rest, { feature_id: feature.id, feature_value_id: Number(valueId) }],
                                                    };
                                                });
                                            }}
                                        >
                                            <option value="">{t('storefront.seller.none', 'None')}</option>
                                            {(feature.values || []).map((value) => (
                                                <option key={value.id} value={value.id}>{value.value}</option>
                                            ))}
                                        </select>
                                    </label>
                                ))}
                            </div>
                        </FormField>
                    ) : null}
                </FormSection>
            ) : null}

            {tab === 'pricing' ? (
                <FormSection title={t('storefront.seller.section_pricing', 'Pricing')}>
                    <FormField label={t('storefront.seller.price', 'Price')} required error={fe('price')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.price} onChange={(e) => set('price', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.compare_price', 'Compare at price')} error={fe('compare_price')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.compare_price} onChange={(e) => set('compare_price', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.sale_price', 'Sale price')} error={fe('sale_price')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.sale_price} onChange={(e) => set('sale_price', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.sale_price_type', 'Sale type')}>
                        <select className="shop-input" value={form.sale_price_type || 'fixed'} onChange={(e) => set('sale_price_type', e.target.value)}>
                            {(options.sale_price_types || ['fixed', 'percent']).map((row) => (
                                <option key={row} value={row}>{row}</option>
                            ))}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.sale_starts', 'Sale starts')}>
                        <input type="date" className="shop-input" value={form.sale_starts_at || ''} onChange={(e) => set('sale_starts_at', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.sale_ends', 'Sale ends')}>
                        <input type="date" className="shop-input" value={form.sale_ends_at || ''} onChange={(e) => set('sale_ends_at', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.cost_price', 'Cost')} error={fe('cost_price')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.cost_price} onChange={(e) => set('cost_price', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.tax_class', 'Tax class')} error={fe('tax_class_id')}>
                        <select className="shop-input" value={form.tax_class_id || ''} onChange={(e) => set('tax_class_id', e.target.value)}>
                            <option value="">{t('storefront.seller.none', 'None')}</option>
                            {(options.tax_classes || []).map((row) => (
                                <option key={row.id} value={row.id}>{row.name}</option>
                            ))}
                        </select>
                    </FormField>
                </FormSection>
            ) : null}

            {tab === 'inventory' ? (
                <FormSection title={t('storefront.seller.inventory', 'Inventory')}>
                    <FormSwitch checked={form.manage_stock} onChange={(on) => set('manage_stock', on)} label={t('storefront.seller.manage_stock', 'Manage stock')} />
                    <FormField label={t('storefront.seller.stock', 'Stock')} error={fe('stock_qty')}>
                        <input type="number" min="0" className="shop-input" value={form.stock_qty} onChange={(e) => set('stock_qty', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.stock_status', 'Stock status')}>
                        <select className="shop-input" value={form.stock_status || 'in_stock'} onChange={(e) => set('stock_status', e.target.value)}>
                            {(options.stock_statuses || []).map((row) => (
                                <option key={row} value={row}>{row}</option>
                            ))}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.out_of_stock_behavior', 'Out-of-stock behavior')}>
                        <select className="shop-input" value={form.backorder_status || 'deny'} onChange={(e) => set('backorder_status', e.target.value)}>
                            {(options.backorder_statuses || ['allow', 'deny', 'hide']).map((row) => (
                                <option key={row} value={row}>
                                    {row === 'allow'
                                        ? t('storefront.seller.oos_allow', 'Allow')
                                        : row === 'hide'
                                            ? t('storefront.seller.oos_hide', 'Hide products')
                                            : t('storefront.seller.oos_deny', 'Do not allow')}
                                </option>
                            ))}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.low_stock', 'Low stock threshold')}>
                        <input type="number" min="0" className="shop-input" value={form.low_stock_threshold || ''} onChange={(e) => set('low_stock_threshold', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.min_qty', 'Min qty')}>
                        <input type="number" min="1" className="shop-input" value={form.min_qty || ''} onChange={(e) => set('min_qty', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.max_qty', 'Max qty')}>
                        <input type="number" min="1" className="shop-input" value={form.max_qty || ''} onChange={(e) => set('max_qty', e.target.value)} />
                    </FormField>
                    {!isNew ? (
                        <>
                            <p className="sm:col-span-2 mv-muted-sm">
                                {inventorySummary
                                    ? `${t('storefront.seller.available', 'Available')}: ${inventorySummary.available} · ${t('storefront.seller.reserved', 'Reserved')}: ${inventorySummary.reserved_qty}`
                                    : t('storefront.seller.inventory_adjust_help', 'Use a stock delta to restock or remove units without rewriting product fields.')}
                            </p>
                            <FormField label={t('storefront.seller.delta', '± qty')}>
                                <input type="number" className="shop-input" value={adjustDelta} onChange={(e) => setAdjustDelta(e.target.value)} />
                            </FormField>
                            <div className="flex items-end">
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-ghost"
                                    disabled={busy}
                                    onClick={async () => {
                                        const delta = Number.parseInt(adjustDelta, 10);
                                        if (!Number.isFinite(delta) || delta === 0) {
                                            setError(t('storefront.seller.inventory_delta_required', 'Enter a non-zero stock change.'));
                                            return;
                                        }
                                        setBusy(true);
                                        setError('');
                                        try {
                                            await csrf();
                                            await api.post(`/account/seller/products/${id}/inventory/adjust`, { delta });
                                            setAdjustDelta('');
                                            await loadInventory();
                                        } catch (err) {
                                            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                                        } finally {
                                            setBusy(false);
                                        }
                                    }}
                                >
                                    {t('storefront.seller.adjust', 'Adjust')}
                                </button>
                            </div>
                            {movements.length > 0 ? (
                                <ul className="space-y-2 text-sm sm:col-span-2">
                                    {movements.slice(0, 10).map((row) => (
                                        <li key={row.id} className="flex justify-between gap-3 border-b border-neutral-100 pb-2">
                                            <span className="capitalize text-neutral-600">
                                                {String(row.action || '').replaceAll('_', ' ')}
                                                {row.reference ? ` · ${row.reference}` : ''}
                                            </span>
                                            <span className="font-semibold">{row.qty > 0 ? `+${row.qty}` : row.qty}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </>
                    ) : null}
                </FormSection>
            ) : null}

            {tab === 'shipping' ? (
                <FormSection title={t('storefront.seller.section_shipping', 'Shipping')}>
                    <FormField label={t('storefront.seller.weight', 'Weight')}>
                        <input type="number" min="0" step="0.001" className="shop-input" value={form.weight || ''} onChange={(e) => set('weight', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.weight_class', 'Weight unit')}>
                        <select className="shop-input" value={form.weight_class || 'kg'} onChange={(e) => set('weight_class', e.target.value)}>
                            {(options.weight_classes || []).map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.length', 'Length')}>
                        <input type="number" min="0" step="0.001" className="shop-input" value={form.length || ''} onChange={(e) => set('length', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.width', 'Width')}>
                        <input type="number" min="0" step="0.001" className="shop-input" value={form.width || ''} onChange={(e) => set('width', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.height', 'Height')}>
                        <input type="number" min="0" step="0.001" className="shop-input" value={form.height || ''} onChange={(e) => set('height', e.target.value)} />
                    </FormField>
                    <FormField label={t('storefront.seller.length_class', 'Length unit')}>
                        <select className="shop-input" value={form.length_class || 'cm'} onChange={(e) => set('length_class', e.target.value)}>
                            {(options.length_classes || []).map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.additional_shipping', 'Additional shipping cost')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.additional_shipping_cost || ''} onChange={(e) => set('additional_shipping_cost', e.target.value)} />
                    </FormField>
                    <FormSwitch checked={form.available_all_carriers} onChange={(on) => set('available_all_carriers', on)} label={t('storefront.seller.all_carriers', 'Available for all carriers')} />
                    {!form.available_all_carriers ? (
                        <FormField label={t('storefront.seller.shipping_methods', 'Shipping methods')} span>
                            <div className="flex flex-col gap-2">
                                {(options.shipping_methods || []).map((method) => (
                                    <label key={method.id} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={form.shipping_method_ids.map(Number).includes(Number(method.id))}
                                            onChange={() => {
                                                setForm((current) => {
                                                    const has = current.shipping_method_ids.map(Number).includes(Number(method.id));
                                                    return {
                                                        ...current,
                                                        shipping_method_ids: has
                                                            ? current.shipping_method_ids.filter((row) => Number(row) !== Number(method.id))
                                                            : [...current.shipping_method_ids, method.id],
                                                    };
                                                });
                                            }}
                                        />
                                        {method.name}
                                    </label>
                                ))}
                            </div>
                        </FormField>
                    ) : null}
                </FormSection>
            ) : null}

            {tab === 'seo' ? (
                <FormSection title={t('storefront.seller.section_seo', 'SEO')}>
                    <LocalizedFormField label={t('storefront.seller.meta_title', 'Meta title')} languages={languages} currentLanguage={localeCode} onLanguageChange={setLocaleCode} span>
                        <input className="shop-input" value={activeLocale.meta_title} onChange={(e) => setLocaleField('meta_title', e.target.value)} />
                    </LocalizedFormField>
                    <LocalizedFormField label={t('storefront.seller.meta_description', 'Meta description')} languages={languages} currentLanguage={localeCode} onLanguageChange={setLocaleCode} span>
                        <textarea className="shop-input" rows={3} value={activeLocale.meta_description} onChange={(e) => setLocaleField('meta_description', e.target.value)} />
                    </LocalizedFormField>
                    <LocalizedFormField label={t('storefront.seller.focus_keyword', 'Focus keyword')} languages={languages} currentLanguage={localeCode} onLanguageChange={setLocaleCode} span>
                        <input className="shop-input" value={activeLocale.focus_keyword} onChange={(e) => setLocaleField('focus_keyword', e.target.value)} />
                    </LocalizedFormField>
                    <LocalizedFormField label={t('storefront.seller.canonical_url', 'Canonical URL')} languages={languages} currentLanguage={localeCode} onLanguageChange={setLocaleCode} span>
                        <input className="shop-input" value={activeLocale.canonical_url} onChange={(e) => setLocaleField('canonical_url', e.target.value)} />
                    </LocalizedFormField>
                    <LocalizedFormField label={t('storefront.seller.robots', 'Robots')} languages={languages} currentLanguage={localeCode} onLanguageChange={setLocaleCode}>
                        <select className="shop-input" value={activeLocale.robots || 'index,follow'} onChange={(e) => setLocaleField('robots', e.target.value)}>
                            {['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'].map((row) => (
                                <option key={row} value={row}>{row}</option>
                            ))}
                        </select>
                    </LocalizedFormField>
                </FormSection>
            ) : null}

            {tab === 'media' ? (
                <FormSection title={t('storefront.seller.section_media', 'Media')}>
                    <div className="sm:col-span-2 max-w-xl">
                        <ProductImagesPanel
                            images={images}
                            pendingFiles={pendingFiles}
                            pendingCoverIndex={pendingCoverIndex}
                            onPickFiles={addProductImages}
                            onRemovePending={removePendingFile}
                            onPendingCoverChange={setPendingCover}
                            onReorderPending={reorderPendingFiles}
                            onSetCover={setSavedCover}
                            onReorderSaved={reorderSavedImages}
                            canDelete={!isNew}
                            onDelete={async (image) => {
                                await csrf();
                                const { data } = await api.delete(`/account/seller/products/${id}/images/${image.id}`);
                                setImages(data.product?.images || []);
                            }}
                        />
                    </div>
                </FormSection>
            ) : null}

            {tab === 'combinations' ? (
                <FormSection title={t('storefront.seller.section_combinations', 'Combinations')} description={t('storefront.seller.combinations_help', 'Choose attributes and values, then generate variants.')}>
                    {(form.combination_axes || []).map((axis, axisIndex) => {
                        const catalog = (options.combination_attributes || []).find((row) => Number(row.id) === Number(axis.attribute_id));
                        return (
                            <div key={axisIndex} className="sm:col-span-2 shop-card p-4 space-y-3">
                                <FormField label={t('storefront.seller.attribute', 'Attribute')}>
                                    <select
                                        className="shop-input"
                                        value={axis.attribute_id || ''}
                                        onChange={(e) => {
                                            const attribute_id = e.target.value ? Number(e.target.value) : '';
                                            setForm((current) => {
                                                const combination_axes = [...current.combination_axes];
                                                combination_axes[axisIndex] = { attribute_id, value_ids: [] };
                                                return { ...current, combination_axes };
                                            });
                                        }}
                                    >
                                        <option value="">{t('storefront.seller.choose', 'Choose…')}</option>
                                        {(options.combination_attributes || []).map((row) => (
                                            <option key={row.id} value={row.id}>{row.name}</option>
                                        ))}
                                    </select>
                                </FormField>
                                {catalog ? (
                                    <div className="flex flex-wrap gap-2">
                                        {(catalog.values || []).map((value) => {
                                            const checked = (axis.value_ids || []).map(Number).includes(Number(value.id));
                                            return (
                                                <label key={value.id} className="shop-choice">
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={() => {
                                                            setForm((current) => {
                                                                const combination_axes = [...current.combination_axes];
                                                                const ids = new Set((combination_axes[axisIndex].value_ids || []).map(Number));
                                                                if (ids.has(Number(value.id))) ids.delete(Number(value.id));
                                                                else ids.add(Number(value.id));
                                                                combination_axes[axisIndex] = {
                                                                    ...combination_axes[axisIndex],
                                                                    value_ids: [...ids],
                                                                };
                                                                return { ...current, combination_axes };
                                                            });
                                                        }}
                                                    />
                                                    <span>{value.value}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                ) : null}
                                <button
                                    type="button"
                                    className="mv-danger text-sm"
                                    onClick={() => setForm((current) => ({
                                        ...current,
                                        combination_axes: current.combination_axes.filter((_, i) => i !== axisIndex),
                                    }))}
                                >
                                    {t('storefront.seller.remove', 'Remove')}
                                </button>
                            </div>
                        );
                    })}
                    <div className="sm:col-span-2 flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="shop-btn shop-btn-ghost"
                            onClick={() => setForm((current) => ({
                                ...current,
                                combination_axes: [...current.combination_axes, { attribute_id: '', value_ids: [] }],
                            }))}
                        >
                            {t('storefront.seller.add_attribute', 'Add attribute')}
                        </button>
                        <button type="button" className="shop-btn shop-btn-primary" onClick={generateCombinations}>
                            {t('storefront.seller.generate_variants', 'Generate variants')}
                        </button>
                    </div>
                    {(form.combination_variants || []).map((variant, index) => (
                        <div key={index} className="sm:col-span-2 shop-card p-4 grid gap-3 sm:grid-cols-2">
                            <p className="sm:col-span-2 text-sm font-semibold">
                                {(variant.attribute_value_ids || []).join(' / ') || t('storefront.seller.variant', 'Variant')} #{index + 1}
                            </p>
                            <FormField label="SKU">
                                <input className="shop-input" value={variant.sku || ''} onChange={(e) => {
                                    const sku = e.target.value;
                                    setForm((current) => {
                                        const combination_variants = [...current.combination_variants];
                                        combination_variants[index] = { ...combination_variants[index], sku };
                                        return { ...current, combination_variants };
                                    });
                                }} />
                            </FormField>
                            <FormField label={t('storefront.seller.price', 'Price')}>
                                <input type="number" min="0" step="0.01" className="shop-input" value={variant.price ?? ''} onChange={(e) => {
                                    const price = e.target.value;
                                    setForm((current) => {
                                        const combination_variants = [...current.combination_variants];
                                        combination_variants[index] = { ...combination_variants[index], price };
                                        return { ...current, combination_variants };
                                    });
                                }} />
                            </FormField>
                            <FormField label={t('storefront.seller.stock', 'Stock')}>
                                <input type="number" min="0" className="shop-input" value={variant.stock_qty ?? 0} onChange={(e) => {
                                    const stock_qty = e.target.value;
                                    setForm((current) => {
                                        const combination_variants = [...current.combination_variants];
                                        combination_variants[index] = { ...combination_variants[index], stock_qty };
                                        return { ...current, combination_variants };
                                    });
                                }} />
                            </FormField>
                            <FormSwitch
                                checked={!!variant.is_enabled}
                                onChange={(on) => {
                                    setForm((current) => {
                                        const combination_variants = [...current.combination_variants];
                                        combination_variants[index] = { ...combination_variants[index], is_enabled: on };
                                        return { ...current, combination_variants };
                                    });
                                }}
                                label={t('storefront.seller.enabled', 'Enabled')}
                            />
                        </div>
                    ))}
                </FormSection>
            ) : null}

            {(tab === 'products' || tab === 'bundle') ? (
                <FormSection title={t('storefront.seller.section_components', 'Component products')}>
                    <FormField label={t('storefront.seller.add_component', 'Add product')}>
                        <select
                            className="shop-input"
                            value=""
                            onChange={(e) => {
                                const childId = Number(e.target.value);
                                if (!childId) return;
                                setForm((current) => {
                                    if (current.components.some((row) => Number(row.id) === childId)) return current;
                                    return {
                                        ...current,
                                        components: [...current.components, {
                                            id: childId,
                                            quantity: 1,
                                            min_quantity: 1,
                                            max_quantity: '',
                                            is_required: true,
                                            qty_editable: false,
                                            default_selected: true,
                                        }],
                                    };
                                });
                            }}
                        >
                            <option value="">{t('storefront.seller.choose', 'Choose…')}</option>
                            {(options.own_products || []).filter((row) => Number(row.id) !== Number(id)).map((row) => (
                                <option key={row.id} value={row.id}>{row.name}</option>
                            ))}
                        </select>
                    </FormField>
                    {(form.components || []).map((row, index) => {
                        const label = (options.own_products || []).find((p) => Number(p.id) === Number(row.id))?.name || row.id;
                        return (
                            <div key={`${row.id}-${index}`} className="sm:col-span-2 flex flex-wrap items-end gap-3">
                                <FormField label={label}>
                                    <input
                                        type="number"
                                        min="1"
                                        className="shop-input"
                                        value={row.quantity}
                                        onChange={(e) => {
                                            const quantity = e.target.value;
                                            setForm((current) => {
                                                const components = [...current.components];
                                                components[index] = { ...components[index], quantity };
                                                return { ...current, components };
                                            });
                                        }}
                                    />
                                </FormField>
                                <button
                                    type="button"
                                    className="mv-danger"
                                    onClick={() => setForm((current) => ({
                                        ...current,
                                        components: current.components.filter((_, i) => i !== index),
                                    }))}
                                >
                                    {t('storefront.seller.remove', 'Remove')}
                                </button>
                            </div>
                        );
                    })}
                </FormSection>
            ) : null}

            {tab === 'gift_card' ? (
                <FormSection title={t('storefront.seller.section_gift_card', 'Gift card')}>
                    <FormField label={t('storefront.seller.amount_type', 'Amount type')}>
                        <select className="shop-input" value={form.gift_card?.amount_type || 'fixed'} onChange={(e) => set('gift_card', { ...form.gift_card, amount_type: e.target.value })}>
                            <option value="fixed">fixed</option>
                            <option value="custom">custom</option>
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.delivery_method', 'Delivery')}>
                        <select className="shop-input" value={form.gift_card?.delivery_method || 'email'} onChange={(e) => set('gift_card', { ...form.gift_card, delivery_method: e.target.value })}>
                            <option value="email">email</option>
                            <option value="account">account</option>
                            <option value="both">both</option>
                        </select>
                    </FormField>
                    <button
                        type="button"
                        className="shop-btn shop-btn-ghost"
                        onClick={() => set('gift_card_amounts', [...(form.gift_card_amounts || []), { value: '', selling_price: '', is_enabled: true, position: form.gift_card_amounts?.length || 0 }])}
                    >
                        {t('storefront.seller.add_amount', 'Add amount')}
                    </button>
                    {(form.gift_card_amounts || []).map((row, index) => (
                        <div key={index} className="sm:col-span-2 grid gap-3 sm:grid-cols-2">
                            <FormField label={t('storefront.seller.value', 'Value')}>
                                <input type="number" min="0" step="0.01" className="shop-input" value={row.value ?? ''} onChange={(e) => {
                                    const value = e.target.value;
                                    setForm((current) => {
                                        const gift_card_amounts = [...current.gift_card_amounts];
                                        gift_card_amounts[index] = { ...gift_card_amounts[index], value };
                                        return { ...current, gift_card_amounts };
                                    });
                                }} />
                            </FormField>
                            <FormField label={t('storefront.seller.selling_price', 'Selling price')}>
                                <input type="number" min="0" step="0.01" className="shop-input" value={row.selling_price ?? ''} onChange={(e) => {
                                    const selling_price = e.target.value;
                                    setForm((current) => {
                                        const gift_card_amounts = [...current.gift_card_amounts];
                                        gift_card_amounts[index] = { ...gift_card_amounts[index], selling_price };
                                        return { ...current, gift_card_amounts };
                                    });
                                }} />
                            </FormField>
                        </div>
                    ))}
                </FormSection>
            ) : null}

            {tab === 'service' ? (
                <FormSection title={t('storefront.seller.section_service', 'Service')}>
                    <FormField label={t('storefront.seller.service_type', 'Service type')}>
                        <select className="shop-input" value={form.service?.service_type || 'one_time'} onChange={(e) => set('service', { ...form.service, service_type: e.target.value })}>
                            {['one_time', 'recurring', 'hourly', 'appointment'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.pricing_model', 'Pricing model')}>
                        <select className="shop-input" value={form.service?.pricing_model || 'fixed'} onChange={(e) => set('service', { ...form.service, pricing_model: e.target.value })}>
                            {['fixed', 'per_hour', 'per_day', 'starting_from'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.duration', 'Duration')}>
                        <input type="number" min="0" className="shop-input" value={form.service?.duration ?? ''} onChange={(e) => set('service', { ...form.service, duration: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.duration_unit', 'Duration unit')}>
                        <select className="shop-input" value={form.service?.duration_unit || 'minutes'} onChange={(e) => set('service', { ...form.service, duration_unit: e.target.value })}>
                            {['minutes', 'hours', 'days'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.delivery_method', 'Delivery')}>
                        <select className="shop-input" value={form.service?.delivery_method || 'online'} onChange={(e) => set('service', { ...form.service, delivery_method: e.target.value })}>
                            {['online', 'onsite', 'both'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.travel_fee', 'Travel fee')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.service?.travel_fee ?? ''} onChange={(e) => set('service', { ...form.service, travel_fee: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.meeting_instructions', 'Meeting instructions')}>
                        <textarea className="shop-input" rows={2} value={form.service?.meeting_instructions ?? ''} onChange={(e) => set('service', { ...form.service, meeting_instructions: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.online_service_url', 'Online service URL')}>
                        <input type="url" className="shop-input" value={form.service?.online_service_url ?? ''} onChange={(e) => set('service', { ...form.service, online_service_url: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.service_area', 'Service area')}>
                        <input className="shop-input" value={form.service?.service_area ?? ''} onChange={(e) => set('service', { ...form.service, service_area: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.location_info', 'Location info')}>
                        <textarea className="shop-input" rows={2} value={form.service?.location_info ?? ''} onChange={(e) => set('service', { ...form.service, location_info: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.delivery_instructions', 'Delivery instructions')}>
                        <textarea className="shop-input" rows={2} value={form.service?.delivery_instructions ?? ''} onChange={(e) => set('service', { ...form.service, delivery_instructions: e.target.value })} />
                    </FormField>
                    {(form.service?.service_type || 'one_time') === 'appointment' ? (
                        <>
                            <FormField label={t('storefront.seller.available_days', 'Available days (comma: mon,tue,...)')}>
                                <input
                                    className="shop-input"
                                    value={(form.service?.availability_config?.available_days || []).join(',')}
                                    onChange={(e) => set('service', {
                                        ...form.service,
                                        availability_config: {
                                            ...(form.service?.availability_config || {}),
                                            available_days: e.target.value.split(',').map((d) => d.trim()).filter(Boolean),
                                        },
                                    })}
                                />
                            </FormField>
                            <FormField label={t('storefront.seller.start_time', 'Start time')}>
                                <input type="time" className="shop-input" value={form.service?.availability_config?.start_time || '09:00'} onChange={(e) => set('service', { ...form.service, availability_config: { ...(form.service?.availability_config || {}), start_time: e.target.value } })} />
                            </FormField>
                            <FormField label={t('storefront.seller.end_time', 'End time')}>
                                <input type="time" className="shop-input" value={form.service?.availability_config?.end_time || '18:00'} onChange={(e) => set('service', { ...form.service, availability_config: { ...(form.service?.availability_config || {}), end_time: e.target.value } })} />
                            </FormField>
                            <FormField label={t('storefront.seller.min_advance_hours', 'Min advance (hours)')}>
                                <input type="number" min="0" className="shop-input" value={form.service?.availability_config?.min_advance_hours ?? 24} onChange={(e) => set('service', { ...form.service, availability_config: { ...(form.service?.availability_config || {}), min_advance_hours: e.target.value } })} />
                            </FormField>
                            <FormField label={t('storefront.seller.max_advance_days', 'Max advance (days)')}>
                                <input type="number" min="0" className="shop-input" value={form.service?.availability_config?.max_advance_days ?? 30} onChange={(e) => set('service', { ...form.service, availability_config: { ...(form.service?.availability_config || {}), max_advance_days: e.target.value } })} />
                            </FormField>
                            <FormField label={t('storefront.seller.max_bookings', 'Max bookings / day')}>
                                <input type="number" min="1" className="shop-input" value={form.service?.availability_config?.max_bookings ?? ''} onChange={(e) => set('service', { ...form.service, availability_config: { ...(form.service?.availability_config || {}), max_bookings: e.target.value } })} />
                            </FormField>
                        </>
                    ) : null}
                </FormSection>
            ) : null}

            {tab === 'rental' ? (
                <FormSection title={t('storefront.seller.section_rental', 'Rental')}>
                    <FormField label={t('storefront.seller.rental_unit', 'Rental unit')}>
                        <select className="shop-input" value={form.rental?.rental_unit || 'day'} onChange={(e) => set('rental', { ...form.rental, rental_unit: e.target.value })}>
                            {['hour', 'day', 'week', 'month'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.min_duration', 'Min duration')}>
                        <input type="number" min="1" className="shop-input" value={form.rental?.min_duration ?? ''} onChange={(e) => set('rental', { ...form.rental, min_duration: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.max_duration', 'Max duration')}>
                        <input type="number" min="1" className="shop-input" value={form.rental?.max_duration ?? ''} onChange={(e) => set('rental', { ...form.rental, max_duration: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.default_duration', 'Default duration')}>
                        <input type="number" min="1" className="shop-input" value={form.rental?.default_duration ?? ''} onChange={(e) => set('rental', { ...form.rental, default_duration: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.pricing_model', 'Pricing model')}>
                        <select className="shop-input" value={form.rental?.pricing_model || 'per_day'} onChange={(e) => set('rental', { ...form.rental, pricing_model: e.target.value })}>
                            {['per_hour', 'per_day', 'per_week', 'per_month'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    <FormField label={t('storefront.seller.deposit_type', 'Security deposit')}>
                        <select className="shop-input" value={form.rental?.security_deposit_type || 'none'} onChange={(e) => set('rental', { ...form.rental, security_deposit_type: e.target.value })}>
                            {['none', 'fixed', 'percent'].map((row) => <option key={row} value={row}>{row}</option>)}
                        </select>
                    </FormField>
                    {form.rental?.security_deposit_type && form.rental.security_deposit_type !== 'none' ? (
                        <FormField label={t('storefront.seller.deposit_value', 'Deposit value')}>
                            <input type="number" min="0" step="0.01" className="shop-input" value={form.rental?.security_deposit_value ?? ''} onChange={(e) => set('rental', { ...form.rental, security_deposit_value: e.target.value })} />
                        </FormField>
                    ) : null}
                    <FormField label={t('storefront.seller.cleaning_fee', 'Cleaning fee')}>
                        <input type="number" min="0" step="0.01" className="shop-input" value={form.rental?.cleaning_fee ?? ''} onChange={(e) => set('rental', { ...form.rental, cleaning_fee: e.target.value })} />
                    </FormField>
                    <FormField label={t('storefront.seller.inventory_mode', 'Inventory mode')}>
                        <select className="shop-input" value={form.rental?.inventory_mode || 'quantity'} onChange={(e) => set('rental', { ...form.rental, inventory_mode: e.target.value })}>
                            <option value="quantity">quantity</option>
                            <option value="asset">asset</option>
                        </select>
                    </FormField>
                    <button
                        type="button"
                        className="shop-btn shop-btn-ghost"
                        onClick={() => set('rental_pricing_tiers', [...(form.rental_pricing_tiers || []), { min_duration: 1, max_duration: '', unit: form.rental?.rental_unit || 'day', price: '', position: form.rental_pricing_tiers?.length || 0 }])}
                    >
                        {t('storefront.seller.add_tier', 'Add pricing tier')}
                    </button>
                    {(form.rental_pricing_tiers || []).map((row, index) => (
                        <div key={index} className="sm:col-span-2 grid gap-3 sm:grid-cols-3">
                            <FormField label="Min">
                                <input type="number" min="1" className="shop-input" value={row.min_duration ?? ''} onChange={(e) => {
                                    const min_duration = e.target.value;
                                    setForm((current) => {
                                        const rental_pricing_tiers = [...current.rental_pricing_tiers];
                                        rental_pricing_tiers[index] = { ...rental_pricing_tiers[index], min_duration };
                                        return { ...current, rental_pricing_tiers };
                                    });
                                }} />
                            </FormField>
                            <FormField label="Max">
                                <input type="number" min="1" className="shop-input" value={row.max_duration ?? ''} onChange={(e) => {
                                    const max_duration = e.target.value;
                                    setForm((current) => {
                                        const rental_pricing_tiers = [...current.rental_pricing_tiers];
                                        rental_pricing_tiers[index] = { ...rental_pricing_tiers[index], max_duration };
                                        return { ...current, rental_pricing_tiers };
                                    });
                                }} />
                            </FormField>
                            <FormField label={t('storefront.seller.price', 'Price')}>
                                <input type="number" min="0" step="0.01" className="shop-input" value={row.price ?? ''} onChange={(e) => {
                                    const price = e.target.value;
                                    setForm((current) => {
                                        const rental_pricing_tiers = [...current.rental_pricing_tiers];
                                        rental_pricing_tiers[index] = { ...rental_pricing_tiers[index], price };
                                        return { ...current, rental_pricing_tiers };
                                    });
                                }} />
                            </FormField>
                        </div>
                    ))}
                </FormSection>
            ) : null}

            {tab === 'preorder' && typeSupports(productType, 'preorder') ? (
                <FormSection title={t('storefront.seller.section_preorder', 'Pre-order')}>
                    <label className="shop-check">
                        <input
                            type="checkbox"
                            checked={!!form.preorder_enabled}
                            onChange={(e) => set('preorder_enabled', e.target.checked)}
                        />
                        {t('storefront.seller.preorder_enable', 'Enable pre-order')}
                    </label>
                    {form.preorder_enabled ? (
                        <>
                            <FormField label={t('storefront.seller.preorder_start', 'Start')}>
                                <input type="datetime-local" className="shop-input" value={form.preorder?.start_at || ''} onChange={(e) => set('preorder', { ...form.preorder, start_at: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_end', 'End')}>
                                <input type="datetime-local" className="shop-input" value={form.preorder?.end_at || ''} onChange={(e) => set('preorder', { ...form.preorder, end_at: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_release', 'Release date')}>
                                <input type="datetime-local" className="shop-input" value={form.preorder?.release_at || ''} onChange={(e) => set('preorder', { ...form.preorder, release_at: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_price', 'Pre-order price')}>
                                <input type="number" min="0" step="0.01" className="shop-input" value={form.preorder?.preorder_price ?? ''} onChange={(e) => set('preorder', { ...form.preorder, preorder_price: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_payment', 'Payment')}>
                                <select className="shop-input" value={form.preorder?.payment_type || 'full'} onChange={(e) => set('preorder', { ...form.preorder, payment_type: e.target.value })}>
                                    <option value="full">{t('storefront.seller.preorder_full', 'Full payment')}</option>
                                    <option value="deposit">{t('storefront.seller.preorder_deposit', 'Deposit')}</option>
                                </select>
                            </FormField>
                            {form.preorder?.payment_type === 'deposit' ? (
                                <>
                                    <FormField label={t('storefront.seller.preorder_deposit_type', 'Deposit type')}>
                                        <select className="shop-input" value={form.preorder?.deposit_type || 'percent'} onChange={(e) => set('preorder', { ...form.preorder, deposit_type: e.target.value })}>
                                            <option value="percent">Percent</option>
                                            <option value="fixed">Fixed</option>
                                        </select>
                                    </FormField>
                                    <FormField label={t('storefront.seller.preorder_deposit_value', 'Deposit value')}>
                                        <input type="number" min="0" step="0.01" className="shop-input" value={form.preorder?.deposit_value ?? ''} onChange={(e) => set('preorder', { ...form.preorder, deposit_value: e.target.value })} />
                                    </FormField>
                                </>
                            ) : null}
                            <FormField label={t('storefront.seller.preorder_min_qty', 'Min quantity')}>
                                <input type="number" min="1" className="shop-input" value={form.preorder?.min_quantity ?? 1} onChange={(e) => set('preorder', { ...form.preorder, min_quantity: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_max_qty', 'Maximum per customer')}>
                                <input type="number" min="1" className="shop-input" value={form.preorder?.max_quantity ?? ''} onChange={(e) => set('preorder', { ...form.preorder, max_quantity: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_total_limit', 'Total limit')}>
                                <input type="number" min="1" className="shop-input" value={form.preorder?.total_limit ?? ''} onChange={(e) => set('preorder', { ...form.preorder, total_limit: e.target.value })} />
                            </FormField>
                            <FormField label={t('storefront.seller.preorder_message', 'Message')}>
                                <textarea className="shop-input" rows={3} value={form.preorder?.message || ''} onChange={(e) => set('preorder', { ...form.preorder, message: e.target.value })} />
                            </FormField>
                            <label className="shop-check">
                                <input
                                    type="checkbox"
                                    checked={!!form.preorder?.show_countdown_pdp}
                                    onChange={(e) => set('preorder', { ...form.preorder, show_countdown_pdp: e.target.checked })}
                                />
                                {t('storefront.seller.preorder_countdown_pdp', 'Countdown on product page')}
                            </label>
                            <label className="shop-check">
                                <input
                                    type="checkbox"
                                    checked={!!form.preorder?.show_countdown_listing}
                                    onChange={(e) => set('preorder', { ...form.preorder, show_countdown_listing: e.target.checked })}
                                />
                                {t('storefront.seller.preorder_countdown_listing', 'Countdown on product listing')}
                            </label>
                            <label className="shop-check">
                                <input
                                    type="checkbox"
                                    checked={!!form.preorder?.show_image_label}
                                    onChange={(e) => set('preorder', { ...form.preorder, show_image_label: e.target.checked })}
                                />
                                {t('storefront.seller.preorder_image_label', 'Pre-order image label')}
                            </label>
                            <FormField label={t('storefront.seller.preorder_fulfillment', 'Fulfillment')}>
                                <select className="shop-input" value={form.preorder?.fulfillment_rule || 'auto'} onChange={(e) => set('preorder', { ...form.preorder, fulfillment_rule: e.target.value })}>
                                    <option value="auto">Auto when available</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </FormField>
                        </>
                    ) : null}
                </FormSection>
            ) : null}

            <FormNotice tone="error">{error}</FormNotice>
            {Object.keys(fieldErrors).length > 0 ? (
                <ul className="text-sm mv-error">
                    {Object.entries(fieldErrors).slice(0, 12).map(([key, messages]) => (
                        <li key={key}>{key}: {Array.isArray(messages) ? messages[0] : messages}</li>
                    ))}
                </ul>
            ) : null}
        </FormShell>
    );
}
