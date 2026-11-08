import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import SimplePdp from '../pdp/SimplePdp.jsx';
import CombinationPdp from '../pdp/CombinationPdp.jsx';
import GiftCardPdp from '../pdp/GiftCardPdp.jsx';
import VirtualPdp from '../pdp/VirtualPdp.jsx';
import BundlePdp from '../pdp/BundlePdp.jsx';
import GroupPdp from '../pdp/GroupPdp.jsx';
import ServicePdp from '../pdp/ServicePdp.jsx';
import RentalPdp from '../pdp/RentalPdp.jsx';
import ProductDocuments from '../components/ProductDocuments.jsx';
import { ProductPageSkeleton, EmptyState } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import { rememberProduct } from '../lib/storage.js';
import { jumpToTop, scrollToUnderHeader } from '../lib/scroll.js';
import { applySeoHead } from '../lib/seoHead.js';
import { brandPath, categoryPath, entityState } from '../lib/links.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { formatRating, recentlyViewedEnabled, reviewsEnabled, returnsEnabled, canWriteReview, reviewLoginRequired, returnWindowDays } from '../lib/commerce.js';
import RecentlyViewed from '../components/RecentlyViewed.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import Stars from '../components/Stars.jsx';
import { ProductReviewModal } from '../components/ProductReviewForm.jsx';
import ReviewMediaRow from '../components/ReviewMediaRow.jsx';
import ReviewSocial from '../components/ReviewSocial.jsx';
import HomeCarousel, {
    productCarouselBreakpoints,
    productCarouselFallback,
} from '../components/HomeCarousel.jsx';
import SafeHtml from '../components/SafeHtml.jsx';
import {
    classicDemoProduct,
    classicPdpEnhance,
    classicPdpRelatedProduct,
} from '../classicAssets.js';

const registry = {
    simple: SimplePdp,
    combination: CombinationPdp,
    gift_card: GiftCardPdp,
    virtual: VirtualPdp,
    service: ServicePdp,
    rental: RentalPdp,
    bundle: BundlePdp,
    group: GroupPdp,
};

const HIGHLIGHT_ICONS = ['shield', 'package', 'return', 'help'];

const DEFAULT_HIGHLIGHTS = [
    {
        title: t('storefront.pdp.hl_soft', 'Soft & Breathable'),
        text: t('storefront.pdp.hl_soft_text', 'Comfortable everyday materials designed to feel great.'),
    },
    {
        title: t('storefront.pdp.hl_durable', 'Durable & Long Lasting'),
        text: t('storefront.pdp.hl_durable_text', 'Built to hold up through regular use and washing.'),
    },
    {
        title: t('storefront.pdp.hl_care', 'Easy Care'),
        text: t('storefront.pdp.hl_care_text', 'Simple care instructions so it stays looking fresh.'),
    },
    {
        title: t('storefront.pdp.hl_quality', 'Quality Checked'),
        text: t('storefront.pdp.hl_quality_text', 'Selected for finish, fit, and everyday performance.'),
    },
];

const SERVICE_HIGHLIGHTS = [
    {
        title: t('storefront.pdp.hl_service_book', 'Book with confidence'),
        text: t('storefront.pdp.hl_service_book_text', 'Pick a date that works and confirm your booking at checkout.'),
    },
    {
        title: t('storefront.pdp.hl_service_expert', 'Delivered by experts'),
        text: t('storefront.pdp.hl_service_expert_text', 'Clear scope, professional delivery, and support when you need it.'),
    },
    {
        title: t('storefront.pdp.hl_service_flex', 'Flexible delivery'),
        text: t('storefront.pdp.hl_service_flex_text', 'Online, on-site, or both — depending on how this service is offered.'),
    },
    {
        title: t('storefront.pdp.hl_service_support', 'Help when you need it'),
        text: t('storefront.pdp.hl_service_support_text', 'Questions before or after your booking? Reach out to the shop.'),
    },
];

const RENTAL_HIGHLIGHTS = [
    {
        title: t('storefront.pdp.hl_rental_dates', 'Pick your rental dates'),
        text: t('storefront.pdp.hl_rental_dates_text', 'Choose a start date and duration that fits your schedule.'),
    },
    {
        title: t('storefront.pdp.hl_rental_deposit', 'Transparent deposits'),
        text: t('storefront.pdp.hl_rental_deposit_text', 'Any security deposit or cleaning fee is shown before you pay.'),
    },
    {
        title: t('storefront.pdp.hl_rental_ship', 'Delivery & return'),
        text: t('storefront.pdp.hl_rental_ship_text', 'Shipping may apply for outbound delivery and return of the item.'),
    },
    {
        title: t('storefront.pdp.hl_rental_care', 'Look after the item'),
        text: t('storefront.pdp.hl_rental_care_text', 'Return on time in good condition to avoid late or damage fees.'),
    },
];

function shippingHtml(product) {
    if (product?.type === 'service') {
        return `<p>${t('storefront.pdp.service_shipping_help', 'This is a service item. No physical shipment is required. Any travel fee for on-site visits is shown at checkout.')}</p>`;
    }
    if (product?.type === 'rental') {
        return `<p>${t('storefront.pdp.rental_shipping_help', 'This is a rental. Shipping may be charged for delivery and return. Return the item by the end date to avoid late fees.')}</p>`;
    }
    if (product?.shipping_html) {
        return product.shipping_html;
    }
    return `
<p>${t('storefront.pdp.shipping_help', 'Use collapsible tabs for more detailed information that will help customers make a purchasing decision.')}</p>
<p>${t('storefront.pdp.shipping_help_ex', 'Ex: Shipping and return policies, size guides, and other common questions.')}</p>
<ul>
<li>${t('storefront.pdp.shipping_1', 'Paste the label on a flat surface on the package')}</li>
<li>${t('storefront.pdp.shipping_2', 'Make sure that both 1D and 2D barcodes are clearly visible')}</li>
<li>${t('storefront.pdp.shipping_3', 'Ensure that the label is smooth and isn’t creased or wrinkled')}</li>
<li>${t('storefront.pdp.shipping_4', 'Check for any tears, dents, holes or scratches')}</li>
<li>${t('storefront.pdp.shipping_5', 'Pack your product tightly, with the right size packaging')}</li>
</ul>
`;
}

function sizeHtml(product) {
    if (product?.size_chart_html) {
        return product.size_chart_html;
    }
    return `
<table class="shop-specs">
<thead><tr><th>Size</th><th>S</th><th>M</th><th>L</th><th>XL</th></tr></thead>
<tbody>
<tr><td>Men</td><td>7-10</td><td>10-13</td><td>13-15</td><td>15-17</td></tr>
<tr><td>Women</td><td>7-9</td><td>10-12</td><td>13-14</td><td>15-16</td></tr>
</tbody>
</table>
`;
}

function featureHighlights(features, productType) {
    if (productType === 'service') {
        return SERVICE_HIGHLIGHTS.map((row, i) => ({
            icon: HIGHLIGHT_ICONS[i % HIGHLIGHT_ICONS.length],
            ...row,
        }));
    }
    if (productType === 'rental') {
        return RENTAL_HIGHLIGHTS.map((row, i) => ({
            icon: HIGHLIGHT_ICONS[i % HIGHLIGHT_ICONS.length],
            ...row,
        }));
    }
    const rows = (features || []).filter((f) => f.feature && f.value).slice(0, 4);
    if (rows.length >= 2) {
        return rows.map((row, i) => ({
            icon: HIGHLIGHT_ICONS[i % HIGHLIGHT_ICONS.length],
            title: row.feature,
            text: row.value,
        }));
    }
    return DEFAULT_HIGHLIGHTS.map((row, i) => ({
        icon: HIGHLIGHT_ICONS[i],
        ...row,
    }));
}

function RatingBars({ breakdown, total }) {
    const counts = {};
    if (Array.isArray(breakdown)) {
        breakdown.forEach((row) => {
            counts[Number(row.rating)] = Number(row.count) || 0;
        });
    } else if (breakdown && typeof breakdown === 'object') {
        Object.entries(breakdown).forEach(([k, v]) => {
            counts[Number(k)] = Number(v) || 0;
        });
    }
    const max = Math.max(total || 0, 1);
    return (
        <div className="shop-pdp2-bars">
            {[5, 4, 3, 2, 1].map((star) => {
                const n = Number(counts[star] || 0);
                const pct = Math.round((n / max) * 100);
                return (
                    <div key={star} className="shop-pdp2-bar-row">
                        <span>{star} ★</span>
                        <div className="shop-pdp2-bar-track"><i style={{ width: `${pct}%` }} /></div>
                        <em>{n}</em>
                    </div>
                );
            })}
        </div>
    );
}

export default function Product({ slug, initial = null }) {
    const store = useStore();
    const [data, setData] = useState(initial);
    const [error, setError] = useState('');
    const [tab, setTab] = useState('description');
    const [descHtml, setDescHtml] = useState('');
    const [reviewRows, setReviewRows] = useState([]);
    const [reviewMeta, setReviewMeta] = useState(null);
    const [reviewSort, setReviewSort] = useState('newest');
    const [reviewsBusy, setReviewsBusy] = useState(false);
    const [reviewModalOpen, setReviewModalOpen] = useState(false);

    useLayoutEffect(() => {
        setData(initial);
        setError('');
        setTab('description');
        setDescHtml('');
        setReviewRows([]);
        setReviewMeta(null);
        setReviewSort('newest');
        setReviewModalOpen(false);
        jumpToTop();
    }, [slug]);

    useEffect(() => {
        if (initial?.product) {
            const product = classicPdpEnhance(initial.product);
            setData({ ...initial, product });
            setReviewRows(product.reviews || []);
            setReviewMeta({
                current_page: 1,
                last_page: 1,
                total: product.rating?.count || (product.reviews || []).length,
                sort: 'newest',
            });
            if (product?.seo) {
                const noisy = new URLSearchParams(window.location.search);
                const hasVariant = noisy.get('ipa') || noisy.get('combination');
                applySeoHead({
                    ...product.seo,
                    title: product.seo.title || product.name,
                    description: product.seo.description
                        || String(product.short_description || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
                        || '',
                    og_type: product.seo.og_type || 'product',
                    og_image: product.seo.og_image || product.cover_url || null,
                    clear_og_image: !(product.seo.og_image || product.cover_url),
                    canonical: product.seo.canonical || product.url || window.location.pathname,
                    robots: hasVariant ? 'noindex,follow' : (product.seo.robots || 'index,follow'),
                    json_ld: product.seo.json_ld ?? null,
                    hreflang: product.seo.hreflang || [],
                });
            }
            if (recentlyViewedEnabled(store.shop)) {
                rememberProduct(product);
            }
            return undefined;
        }
        let ignore = false;
        api.get(`/catalog/product/${slug}`)
            .then((r) => {
                if (ignore) {
                    return;
                }
                const product = classicPdpEnhance(r.data.product);
                setData({ ...r.data, product });
                setReviewRows(product.reviews || []);
                setReviewMeta({
                    current_page: 1,
                    last_page: 1,
                    total: product.rating?.count || (product.reviews || []).length,
                    sort: 'newest',
                });
                if (product?.seo) {
                    const noisy = new URLSearchParams(window.location.search);
                    const hasVariant = noisy.get('ipa') || noisy.get('combination');
                    applySeoHead({
                        ...product.seo,
                        title: product.seo.title || product.name,
                        description: product.seo.description
                            || String(product.short_description || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
                            || '',
                        og_type: product.seo.og_type || 'product',
                        og_image: product.seo.og_image || product.cover_url || null,
                        clear_og_image: !(product.seo.og_image || product.cover_url),
                        canonical: product.seo.canonical || product.url || window.location.pathname,
                        robots: hasVariant ? 'noindex,follow' : (product.seo.robots || 'index,follow'),
                        json_ld: product.seo.json_ld ?? null,
                        hreflang: product.seo.hreflang || [],
                    });
                }
                if (recentlyViewedEnabled(store.shop)) {
                    rememberProduct(product);
                }
            })
            .catch(() => {
                if (!ignore) {
                    setError(t('storefront.pdp.unavailable', 'This product is not available.'));
                    applySeoHead({
                        title: t('storefront.pdp.unavailable', 'This product is not available.'),
                        description: '',
                        robots: 'noindex,nofollow',
                        canonical: window.location.pathname,
                        clear_og_image: true,
                        json_ld: null,
                    });
                }
            });
        return () => { ignore = true; };
    }, [slug, store.shop]);

    const loadReviews = async (page = 1, sort = reviewSort) => {
        if (!reviewsEnabled(store.shop) || !slug) {
            return;
        }
        setReviewsBusy(true);
        try {
            const r = await api.get(`/catalog/product/${slug}/reviews`, { params: { page, sort, per_page: 5 } });
            const rows = r.data.reviews || [];
            setReviewRows(rows);
            setReviewMeta(r.data.meta || null);
            setReviewSort(sort);
        } catch {
            /* keep embedded reviews */
        } finally {
            setReviewsBusy(false);
        }
    };

    const refreshProductReviews = async () => {
        await loadReviews(1, reviewSort);
        try {
            const r = await api.get(`/catalog/product/${slug}`);
            const next = classicPdpEnhance(r.data.product);
            setData({ ...r.data, product: next });
        } catch {
            /* keep current product payload */
        }
    };

    useEffect(() => {
        if (!data?.product?.id || !reviewsEnabled(store.shop)) {
            return undefined;
        }
        loadReviews(1, 'newest');
        return undefined;
        // Load first page with pagination meta once product is ready.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data?.product?.id, store.shop]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return undefined;
        }
        if (window.location.hash === '#reviews' && reviewsEnabled(store.shop)) {
            setTab('reviews');
            const timer = window.setTimeout(() => {
                const section = document.getElementById('reviews');
                if (section) {
                    scrollToUnderHeader(section, { behavior: 'smooth' });
                    const tablist = section.querySelector('.shop-pdp2-tabs');
                    const tabBtn = section.querySelector('.shop-pdp2-tabs [data-tab="reviews"]');
                    if (tablist && tabBtn) {
                        const left = tabBtn.offsetLeft - Math.max(0, (tablist.clientWidth - tabBtn.offsetWidth) / 2);
                        tablist.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
                    }
                }
            }, 80);
            return () => window.clearTimeout(timer);
        }
        return undefined;
    }, [data?.product?.id, store.shop]);

    useLayoutEffect(() => {
        if (data?.product?.id) {
            jumpToTop();
        }
    }, [data?.product?.id]);

    const openReviews = useCallback(() => {
        if (!reviewsEnabled(store.shop)) {
            return;
        }
        setTab('reviews');
        if (typeof window !== 'undefined') {
            const next = `${window.location.pathname}${window.location.search}#reviews`;
            if (`${window.location.pathname}${window.location.search}${window.location.hash}` !== next) {
                window.history.replaceState(null, '', next);
            }
            window.requestAnimationFrame(() => {
                window.setTimeout(() => {
                    const section = document.getElementById('reviews');
                    if (!section) {
                        return;
                    }
                    scrollToUnderHeader(section, { behavior: 'smooth' });
                    // Keep Reviews tab visible in the horizontal tab strip without nudging page Y.
                    const tablist = section.querySelector('.shop-pdp2-tabs');
                    const tabBtn = section.querySelector('.shop-pdp2-tabs [data-tab="reviews"]');
                    if (tablist && tabBtn) {
                        const left = tabBtn.offsetLeft - Math.max(0, (tablist.clientWidth - tabBtn.offsetWidth) / 2);
                        tablist.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
                    }
                }, 60);
            });
        }
    }, [store.shop]);

    useEffect(() => {
        let cancelled = false;
        const product = data?.product;
        if (!product) {
            return undefined;
        }
        if (product.classic_description_url) {
            let target = '';
            try {
                const parsed = new URL(product.classic_description_url, window.location.origin);
                if (parsed.origin === window.location.origin) {
                    target = parsed.pathname + parsed.search;
                }
            } catch {
                target = '';
            }
            if (target) {
                fetch(target)
                    .then((r) => r.text())
                    .then((html) => { if (!cancelled) setDescHtml(product.description || html); })
                    .catch(() => { if (!cancelled) setDescHtml(product.description || ''); });
            } else {
                setDescHtml(product.description || '');
            }
        } else {
            setDescHtml(product.description || '');
        }
        return () => { cancelled = true; };
    }, [data?.product?.id, data?.product?.classic_description_url, data?.product?.description]);

    const also = useMemo(() => {
        if (!data) {
            return [];
        }
        const rows = data.also_like?.length ? data.also_like : (data.related || []);
        return rows.map((p, index) => (
            data.product?.classic_pdp ? classicPdpRelatedProduct(p, index) : classicDemoProduct(p, index)
        ));
    }, [data]);

    const categoryAlso = useMemo(() => {
        if (!data) {
            return [];
        }
        const rows = data.same_category?.length
            ? data.same_category
            : (data.related?.length ? data.related : (data.also_like || []));
        return rows.map((p, index) => (
            data.product?.classic_pdp ? classicPdpRelatedProduct(p, index) : classicDemoProduct(p, index + 4)
        )).slice(0, data.product?.classic_pdp ? 16 : 8);
    }, [data]);

    if (error) {
        return (
            <div className="shop-container py-16">
                <EmptyState title={error} action={<Link to="/" className="shop-btn shop-btn-primary">{t('storefront.shell.back_home', 'Back to home')}</Link>} />
            </div>
        );
    }
    if (!data) {
        return <ProductPageSkeleton />;
    }

    const product = data.product;
    const Pdp = registry[product.type] || SimplePdp;
    const reviews = reviewRows.length ? reviewRows : (product.reviews || []);
    const features = product.features || [];
    const highlights = featureHighlights(features, product?.type);
    const reviewCount = product.rating?.count || reviewMeta?.total || reviews.length || 0;
    const eligibility = data.review_eligibility || null;
    const showReviewForm = canWriteReview(store.shop, store.customer, eligibility);
    const showReviewLogin = reviewLoginRequired(store.shop, store.customer);
    const conditionLabel = product.condition === 'used' ? 'Used' : product.condition === 'refurbished' ? 'Refurbished' : 'New';
    const attachments = Array.isArray(product.attachments) ? product.attachments.filter((row) => row?.download_url || row?.id) : [];
    const primaryCategory = product.categories?.find((category) => Number(category?.id) === Number(product.default_category_id)) || product.categories?.[0];
    const brandValue = product.brand?.name && (product.brand.url || product.brand.slug || product.brand.id)
        ? <Link to={brandPath(product.brand)} state={entityState('brand')}>{product.brand.name}</Link>
        : product.brand?.name;
    const categoryValue = primaryCategory?.name && (primaryCategory.url || primaryCategory.slug || primaryCategory.id)
        ? <Link to={categoryPath(primaryCategory)} state={entityState('category')}>{primaryCategory.name}</Link>
        : primaryCategory?.name;
    const detailRows = [
        [t('storefront.pdp.reference', 'Reference'), product.sku],
        [t('storefront.pdp.brand', 'Brand'), brandValue],
        [t('storefront.pdp.default_supplier', 'Default Supplier'), product.supplier],
        [t('storefront.pdp.default_category', 'Default Category'), categoryValue],
        [t('storefront.pdp.isbn', 'ISBN'), product.isbn],
        [t('storefront.pdp.upc', 'UPC Barcode'), product.upc],
        [t('storefront.pdp.mpn', 'MPN'), product.mpn],
        [t('storefront.pdp.gtin', 'GTIN'), product.gtin],
        [t('storefront.pdp.condition', 'Condition'), product.display_condition !== false ? conditionLabel : null],
        [t('storefront.pdp.stock', 'Availability'), product.stock_status],
        [t('storefront.pdp.weight', 'Weight'), product.weight ? `${product.weight} ${product.weight_class || ''}`.trim() : null],
        [t('storefront.pdp.dimensions', 'Dimensions'), product.length && product.width && product.height
            ? `${product.length} x ${product.width} x ${product.height} ${product.length_class || ''}`.trim()
            : null],
        [t('storefront.pdp.delivery', 'Delivery'), product.delivery_from && product.delivery_to
            ? `${product.delivery_from}-${product.delivery_to} ${t('storefront.pdp.days', 'business days')}`
            : null],
    ].filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '');

    const tabs = [
        { id: 'description', label: t('storefront.pdp.tab_description', 'Description'), icon: 'file' },
        { id: 'details', label: t('storefront.pdp.tab_details', 'Product Details'), icon: 'layers' },
        ...(attachments.length ? [{ id: 'documents', label: t('storefront.product.documents', 'Documents'), icon: 'file' }] : []),
        { id: 'shipping', label: t('storefront.pdp.tab_shipping_returns', 'Shipping & Returns'), icon: 'truck' },
        { id: 'size', label: t('storefront.pdp.tab_care', 'Care Guide'), icon: 'package' },
        ...(reviewsEnabled(store.shop) ? [{
            id: 'reviews',
            label: `${t('storefront.pdp.tab_reviews', 'Reviews')}${reviewCount ? ` (${reviewCount})` : ''}`,
            icon: 'star-outline',
        }] : []),
    ];

    return (
        <div className={`shop-container shop-pdp2-page py-6 pb-24 lg:pb-10${product.classic_pdp ? ' shop-classic-product-page' : ''}`}>
            <Breadcrumbs items={product.classic_pdp ? [
                { name: 'Home', url: '/' },
                { name: 'Accessories', url: '/catalog' },
                { name: 'Gaming', url: '/search?q=Gaming' },
                { name: product.name, url: null },
            ] : (data.breadcrumbs || [])} />

            <div className="mt-5">
                <Pdp
                    product={product}
                    actionSlots={data.modules?.actions || []}
                    trustSlots={data.modules?.below_trust || []}
                    buybox={data.modules?.buybox || {}}
                    onOpenReviews={openReviews}
                />
            </div>

            {product.seller ? (
                <aside className="shop-pdp2-seller">
                    <div>
                        <p>{t('storefront.shop.sold_by_label', 'Sold by')}</p>
                        <Link to={product.seller.url}>{product.seller.shop_name}</Link>
                    </div>
                    <Link to={product.seller.url} className="shop-btn shop-btn-ghost">{t('storefront.shop.visit', 'Visit shop')}</Link>
                </aside>
            ) : null}
            {product.seller?.vacation_enabled ? (
                <p className="shop-pdp2-seller mt-3 text-sm">
                    {product.seller.vacation_message
                        || t('storefront.shop.vacation', 'This shop is on vacation. Products are listed but cannot be ordered.')}
                </p>
            ) : null}

            <section className="shop-pdp2-highlights" aria-label={t('storefront.pdp.highlights', 'Highlights')}>
                {highlights.map((item) => (
                    <article key={item.title}>
                        <Icon name={item.icon} className="h-6 w-6" />
                        <h3>{item.title}</h3>
                        <p>{item.text}</p>
                    </article>
                ))}
            </section>

            <section className="shop-pdp2-info" id="reviews" aria-label={t('storefront.pdp.product_info', 'Product information')}>
                <div className="shop-pdp2-tabs" role="tablist">
                    {tabs.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            role="tab"
                            data-tab={item.id}
                            aria-selected={tab === item.id}
                            className={tab === item.id ? 'is-on' : ''}
                            onClick={() => setTab(item.id)}
                        >
                            <Icon name={item.icon} className="shop-pdp2-tab-icon" aria-hidden="true" />
                            <span>{item.label}</span>
                        </button>
                    ))}
                </div>

                <div className="shop-pdp2-panel" role="tabpanel">
                    {tab === 'description' ? (
                        <div className="shop-pdp2-copy">
                            <div className="shop-pdp-rich prose max-w-none">
                                {descHtml
                                    ? <div dangerouslySetInnerHTML={{ __html: descHtml }} />
                                    : (product.short_description
                                        ? <SafeHtml html={product.short_description} />
                                        : null)}
                            </div>
                        </div>
                    ) : null}

                    {tab === 'details' ? (
                        <div className="shop-pdp2-details">
                            <dl className="shop-pdp-facts">
                                {detailRows.map(([label, value]) => (
                                    <Fragment key={label}>
                                        <dt>{label}</dt>
                                        <dd>{value}</dd>
                                    </Fragment>
                                ))}
                            </dl>
                            {features.length ? (
                                <>
                                    <h3>{t('storefront.pdp.data_sheet', 'Data sheet')}</h3>
                                    <table className="shop-specs">
                                        <tbody>
                                            {features.map((row) => (
                                                <tr key={row.id || `${row.feature}-${row.value}`}>
                                                    <th>{row.feature}</th>
                                                    <td>{row.value}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </>
                            ) : !detailRows.length ? (
                                <p className="shop-pdp2-muted">{t('storefront.pdp.no_features', 'No extra product details yet.')}</p>
                            ) : null}
                        </div>
                    ) : null}

                    {tab === 'documents' ? <ProductDocuments attachments={attachments} /> : null}

                    {tab === 'shipping' ? (
                        <div className="shop-pdp2-copy">
                            <div className="prose max-w-none" dangerouslySetInnerHTML={{ __html: shippingHtml(product) }} />
                            {returnsEnabled(store.shop) ? (
                                <p className="shop-pdp2-muted mt-4">
                                    {t('storefront.account.return_window', 'Returns accepted within {n} days.')
                                        .replace('{n}', String(returnWindowDays(store.shop)))}
                                </p>
                            ) : (
                                <p className="shop-pdp2-muted mt-4">{t('storefront.pdp.no_returns', 'This shop does not accept returns.')}</p>
                            )}
                        </div>
                    ) : null}

                    {tab === 'size' ? (
                        <div className="shop-pdp2-copy" dangerouslySetInnerHTML={{ __html: sizeHtml(product) }} />
                    ) : null}

                    {tab === 'reviews' ? (
                        <div className="shop-pdp2-reviews-tab">
                            <div className="shop-pdp2-reviews-head">
                                <div className="shop-pdp2-reviews-summary">
                                    <div className="shop-pdp2-score">
                                        <strong>{product.rating?.average != null && reviewCount > 0 ? (formatRating(product.rating.average) ?? '—') : '—'}</strong>
                                        <Stars
                                            value={product.rating?.average != null && reviewCount > 0 ? product.rating.average : null}
                                            count={reviewCount}
                                        />
                                    </div>
                                    <RatingBars breakdown={product.rating_breakdown} total={reviewCount} />
                                </div>
                                <div className="shop-pdp2-reviews-actions">
                                    {showReviewForm ? (
                                        <button
                                            type="button"
                                            className="shop-btn shop-btn-primary"
                                            onClick={() => setReviewModalOpen(true)}
                                        >
                                            {eligibility?.has_review
                                                ? t('storefront.pdp.update_review_btn', 'Update Review')
                                                : t('storefront.pdp.write_review_btn', 'Write a Review')}
                                        </button>
                                    ) : null}
                                    {showReviewLogin ? (
                                        <Link
                                            to={`/login?redirect=${encodeURIComponent(window.location.pathname + '#reviews')}`}
                                            className="shop-btn shop-btn-primary"
                                        >
                                            {t('storefront.pdp.sign_in_to_review', 'Sign in to write a review')}
                                        </Link>
                                    ) : null}
                                    {!showReviewForm && !showReviewLogin && eligibility?.reason === 'verified_only' ? (
                                        <p className="shop-pdp2-muted shop-pdp2-reviews-actions__note">
                                            {t('storefront.pdp.review_verified_only', 'Only verified buyers can review this product.')}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="shop-pdp2-reviews-list-head">
                                <h3 className="shop-pdp2-reviews-list-title">
                                    {t('storefront.pdp.all_reviews', 'All reviews')}
                                </h3>
                                <label className="shop-pdp2-review-sort">
                                    <span>{t('storefront.pdp.review_sort', 'Sort')}</span>
                                    <select
                                        className="shop-input"
                                        value={reviewSort}
                                        disabled={reviewsBusy}
                                        onChange={(e) => loadReviews(1, e.target.value)}
                                    >
                                        <option value="newest">{t('storefront.pdp.sort_newest', 'Newest')}</option>
                                        <option value="oldest">{t('storefront.pdp.sort_oldest', 'Oldest')}</option>
                                        <option value="highest">{t('storefront.pdp.sort_highest', 'Highest rating')}</option>
                                        <option value="lowest">{t('storefront.pdp.sort_lowest', 'Lowest rating')}</option>
                                    </select>
                                </label>
                            </div>

                            <div className="shop-pdp2-review-list">
                                {reviews.length ? reviews.map((review) => {
                                    const imageUrls = Array.isArray(review.image_urls) && review.image_urls.length
                                        ? review.image_urls
                                        : (review.photo_url ? [review.photo_url] : []);
                                    const videoUrl = review.video_url || (
                                        review.photo_url && String(review.photo_url).match(/\.(mp4|webm)(\?|$)/i)
                                            ? review.photo_url
                                            : null
                                    );
                                    const hasMedia = imageUrls.length > 0 || Boolean(videoUrl);
                                    const ratingValue = Number(review.rating) || 0;
                                    return (
                                        <article
                                            key={review.id}
                                            className={`shop-review${hasMedia ? ' has-media' : ''}`}
                                        >
                                            <div className="shop-review__main">
                                                <div className="shop-review__rating">
                                                    <Stars value={ratingValue} compact />
                                                    <span className="shop-review__score">
                                                        {`${Math.round(ratingValue * 10) / 10}/5`}
                                                    </span>
                                                </div>
                                                {review.title ? (
                                                    <h3 className="shop-review__title">{review.title}</h3>
                                                ) : null}
                                                {review.body ? (
                                                    <p className="shop-review__body">{review.body}</p>
                                                ) : null}
                                                {hasMedia ? (
                                                    <ReviewMediaRow imageUrls={imageUrls} videoUrl={videoUrl} />
                                                ) : null}
                                                {review.author ? (
                                                    <div className="shop-review__author">
                                                        <span className="shop-review__name">{review.author}</span>
                                                    </div>
                                                ) : null}
                                                <ReviewSocial
                                                    review={review}
                                                    onVoted={(id, votes) => {
                                                        setReviewRows((rows) => rows.map((row) => (
                                                            Number(row.id) === Number(id) ? { ...row, ...votes } : row
                                                        )));
                                                    }}
                                                />
                                                {review.admin_reply ? (
                                                    <div className="shop-review-reply">
                                                        <strong>{t('storefront.pdp.shop_reply', 'Shop reply')}</strong>
                                                        <p>{review.admin_reply}</p>
                                                    </div>
                                                ) : null}
                                            </div>
                                        </article>
                                    );
                                }) : (
                                    <p className="shop-pdp2-muted">{t('storefront.pdp.no_reviews', 'No reviews yet. Be the first to write one.')}</p>
                                )}
                            </div>

                            {reviewMeta?.last_page > 1 ? (
                                <nav
                                    className="shop-public-products__pagination shop-pdp2-reviews-pagination"
                                    aria-label={t('storefront.pdp.review_pages', 'Review pages')}
                                >
                                    <button
                                        type="button"
                                        className="shop-public-products__page-btn"
                                        disabled={reviewsBusy || reviewMeta.current_page <= 1}
                                        onClick={() => loadReviews(reviewMeta.current_page - 1, reviewSort)}
                                    >
                                        {t('storefront.shop.prev', 'Previous')}
                                    </button>
                                    {Array.from({ length: reviewMeta.last_page }).map((_, i) => {
                                        const page = i + 1;
                                        return (
                                            <button
                                                key={page}
                                                type="button"
                                                className={`shop-public-products__page-btn${page === reviewMeta.current_page ? ' is-active' : ''}`}
                                                disabled={reviewsBusy}
                                                onClick={() => loadReviews(page, reviewSort)}
                                                aria-current={page === reviewMeta.current_page ? 'page' : undefined}
                                            >
                                                {page}
                                            </button>
                                        );
                                    })}
                                    <button
                                        type="button"
                                        className="shop-public-products__page-btn"
                                        disabled={reviewsBusy || reviewMeta.current_page >= reviewMeta.last_page}
                                        onClick={() => loadReviews(reviewMeta.current_page + 1, reviewSort)}
                                    >
                                        {t('storefront.shop.next', 'Next')}
                                    </button>
                                </nav>
                            ) : null}

                            <ProductReviewModal
                                open={reviewModalOpen}
                                onClose={() => setReviewModalOpen(false)}
                                productId={product.id}
                                shop={store.shop}
                                eligibility={eligibility}
                                initialReview={eligibility?.review || null}
                                onSubmitted={() => {
                                    refreshProductReviews();
                                }}
                            />
                        </div>
                    ) : null}
                </div>
            </section>

            {also.length > 0 ? (
                <section className="shop-pdp2-related">
                    <div className="shop-pdp2-related-head">
                        <h2>{t('storefront.pdp.also_like', 'You might also like')}</h2>
                        {!product.classic_pdp ? <Link to="/catalog">{t('storefront.shell.view_all', 'View All')}</Link> : null}
                    </div>
                    <HomeCarousel
                        items={also.slice(0, product.classic_pdp ? 16 : 8)}
                        breakpoints={productCarouselBreakpoints(5)}
                        fallback={productCarouselFallback(5)}
                        gap={20}
                        speedMs={250}
                        showControls
                        showDots={false}
                        className="shop-pdp2-related-carousel"
                        renderItem={(p) => <ProductCard product={p} compact />}
                    />
                </section>
            ) : null}

            {categoryAlso.length > 0 ? (
                <section className="shop-pdp2-related">
                    <div className="shop-pdp2-related-head">
                        <h2>{t('storefront.pdp.customers_bought', 'Customers also bought')}</h2>
                    </div>
                    <HomeCarousel
                        items={categoryAlso}
                        breakpoints={productCarouselBreakpoints(5)}
                        fallback={productCarouselFallback(5)}
                        gap={20}
                        speedMs={250}
                        showControls
                        showDots={false}
                        className="shop-pdp2-related-carousel"
                        renderItem={(p) => <ProductCard product={p} compact />}
                    />
                </section>
            ) : null}

            {(data.modules?.after || []).length ? (
                <section className="mt-10">
                    <ModuleBlocks slots={data.modules.after} />
                </section>
            ) : null}
            {(data.modules?.tabs || []).length ? (
                <section className="mt-6">
                    <ModuleBlocks slots={data.modules.tabs} />
                </section>
            ) : null}
            <RecentlyViewed excludeId={product.id} />
        </div>
    );
}
