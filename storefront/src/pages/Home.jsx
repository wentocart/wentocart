import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import ProductImage from '../components/ProductImage.jsx';
import { Icon } from '../components/Icons.jsx';
import { EmptyState, HomeSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import HeroBanner from '../components/HeroBanner.jsx';
import HomeCarousel, {
    productCarouselBreakpoints,
    productCarouselFallback,
} from '../components/HomeCarousel.jsx';
import CmsModal, { MessageModal } from '../components/CmsModal.jsx';
import {
    classicAssetUrl,
    classicDemoBrands,
    classicDemoCategoryImage,
    classicDemoContent,
    classicDemoProduct,
    classicDemoShelfCategory,
    classicDemoShelfProduct,
    classicDemoTestimonialAvatar,
} from '../classicAssets.js';
import { categoryPath, productPath, resolveConfiguredLink, configuredLinkExists, entityState, shopHasCatalog } from '../lib/links.js';
import { applyBootSeo } from '../lib/seoHead.js';
import { isDefaultCatalogImage } from '../lib/format.js';
import { pickLocalizedText } from '../lib/localizedText.js';
import { useShopSession } from '../state/useShopSession.js';

const DEFAULT_ORDER = [
    'hero', 'trust', 'promos', 'featured', 'banner', 'new_arrivals', 'categories',
    'promos_mid', 'category_shelves', 'testimonials', 'bestsellers', 'promos_bottom',
    'brands', 'content', 'newsletter',
];


function shelfImageSrc(category) {
    const src = classicDemoCategoryImage(category);
    if (!src || isDefaultCatalogImage(src)) {
        return '';
    }
    return src;
}

function CategoryShelf({ shelf, showViewAllEnabled = true }) {
    const shelfTitle = shelf.category?.name || t('storefront.home.catalog', 'Catalog');
    const displayedProducts = Array.isArray(shelf.products) ? shelf.products : [];
    const totalProducts = Number(shelf.category?.products_count) || 0;
    const showViewAll = showViewAllEnabled && totalProducts > displayedProducts.length;
    const initialImage = shelfImageSrc(shelf.category);
    const [bannerSrc, setBannerSrc] = useState(initialImage);
    const showBanner = Boolean(bannerSrc);

    useEffect(() => {
        setBannerSrc(shelfImageSrc(shelf.category));
    }, [shelf.category?.id, shelf.category?.image, shelf.category?.image_url, shelf.category?.thumb]);

    return (
        <section>
            <Header
                title={shelfTitle}
                action={showViewAll ? (
                    <Link
                        to={categoryPath(shelf.category)}
                        state={entityState('category')}
                        className="cx-sec-view-all"
                    >
                        {t('storefront.home.view_all', 'View All')}
                    </Link>
                ) : null}
            />
            <div className={`cx-shelf${showBanner ? '' : ' is-products-only'}`}>
                {showBanner ? (
                    <Link
                        to={categoryPath(shelf.category)}
                        state={entityState('category')}
                        className="cx-shelf-banner"
                    >
                        <img
                            src={bannerSrc}
                            alt={shelfTitle}
                            width={280}
                            height={380}
                            className="h-full w-full object-cover"
                            loading="lazy"
                            decoding="async"
                            onError={() => setBannerSrc('')}
                        />
                    </Link>
                ) : null}
                <div className="cx-shelf-products">
                    {displayedProducts.map((product) => (
                        <ProductCard key={product.id || product.sku || product.slug} product={product} compact />
                    ))}
                </div>
            </div>
        </section>
    );
}

export default function Home() {
    useEffect(() => { applyBootSeo(); }, []);

    const store = useStore();
    const { locale } = useShopSession();
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    const theme = store.shop?.shop?.theme?.settings || window.__WENTO__?.theme?.settings || {};
    const home = theme.home || {};
    const sections = home.sections || {};
    const density = home.density || {};
    const hero = home.hero || {};
    const trust = home.trust || [];
    const promos = home.promos || [];
    const testimonials = home.testimonials || [];
    const promosMid = home.promos_mid || [];
    const promosBottom = home.promos_bottom || [];
    const featuredCfg = home.featured || {};
    const newCfg = home.new_arrivals || {};
    const bestCfg = home.bestsellers || {};
    const shelvesCfg = typeof home.category_shelves === 'object' && !Array.isArray(home.category_shelves)
        ? home.category_shelves
        : {};
    const showViewAllEnabled = shelvesCfg.show_view_all !== false;
    const brandCfg = typeof home.brands === 'object' && !Array.isArray(home.brands) ? home.brands : {};
    const banner = home.banner || {};
    const content = home.content || {};
    const newsletter = home.newsletter || {};
    const show = (key) => sections[key] !== false;
    const order = (home.order && home.order.length) ? home.order : DEFAULT_ORDER;

    const productsLimit = density.products_limit || 12;
    const recentLimit = density.recent_limit || 4;
    const productsPerRow = Math.max(2, Math.min(6, Number(density.products_per_row) || 5));
    const productBreakpoints = productCarouselBreakpoints(productsPerRow);
    const productFallback = productCarouselFallback(productsPerRow);
    const productGap = 20;

    useEffect(() => {
        api.get('/catalog/home').then((r) => setData(r.data)).catch(() => setError(t('storefront.home.error', 'Could not load the homepage.')));
    }, []);

    if (error) {
        return <div className="shop-container py-16"><EmptyState title={t('storefront.home.error', 'Could not load the homepage.')} /></div>;
    }

    const categories = (data?.categories || store.shop?.categories || []).map((category) => ({
        ...category,
        image: classicDemoCategoryImage(category),
    }));
    const hasCatalog = shopHasCatalog({
        categories,
        products: [...(data?.featured || []), ...(data?.new_arrivals || []), ...(data?.bestsellers || [])],
    });
    const linkOpts = { categories, hasCatalog };
    const brands = classicDemoBrands(data?.brands || []);
    const featuredItems = (data?.featured || [])
        .slice(0, featuredCfg.limit || productsLimit)
        .map((product, index) => classicDemoProduct(product, index));
    const newItems = (data?.new_arrivals || [])
        .slice(0, newCfg.limit || productsLimit)
        .map((product, index) => classicDemoProduct(product, index + 10));
    const shelves = (data?.category_shelves || [])
        .filter((row) => row?.products?.length)
        .map((row, shelfIndex) => ({
            ...row,
            category: classicDemoShelfCategory(
                { ...row.category, image: classicDemoCategoryImage(row.category) },
                row.products,
            ),
            products: row.products.map((product, index) => classicDemoShelfProduct(product, shelfIndex, index)),
        }));
    // Theme hero can paint before /catalog/home returns — do not wait on the API for the banner.
    const configuredSlides = (hero.slides || []).filter((s) => s && (s.image_url || s.title || s.content));
    let heroSlides = configuredSlides.length
        ? configuredSlides.map((slide, index) => {
            const product = (data?.featured || data?.new_arrivals || [])[index];
            return {
                ...slide,
                product: slide.product || product,
                eyebrow: slide.eyebrow || hero.eyebrow,
                cta_label: slide.cta_label || hero.cta_label,
                cta_url: resolveConfiguredLink(slide.cta_url || hero.cta_url, {
                    ...linkOpts,
                    product,
                }),
                hide_cta: !configuredLinkExists(slide.cta_url || hero.cta_url, categories),
            };
        })
        : (data?.featured || data?.new_arrivals || []).slice(0, 3).map((product) => ({
            image_url: hero.image_url || product.cover_url || '',
            product,
            eyebrow: hero.eyebrow || '',
            title: hero.title || product.name,
            body: hero.body || '',
            cta_label: hero.cta_label || 'Shop Now',
            cta_url: resolveConfiguredLink(hero.cta_url, { ...linkOpts, product }),
        }));
    if (!heroSlides.length && (hero.title || hero.image_url || hero.content)) {
        heroSlides = [{
            image_url: hero.image_url || '',
            eyebrow: hero.eyebrow,
            title: hero.title,
            body: data?.blocks?.[0]?.content || hero.body,
            content: hero.content,
            cta_label: hero.cta_label,
            cta_url: resolveConfiguredLink(hero.cta_url, { ...linkOpts, product: data?.featured?.[0] }),
            hide_cta: !configuredLinkExists(hero.cta_url, categories) && !data?.featured?.[0],
        }];
    }

    const configuredSides = (hero.sides || []).filter((s) => s && (s.title || s.image_url));
    let heroSides = (configuredSides.length ? configuredSides : []).map((side, i) => {
        const product = side.image_url ? undefined : (data?.featured || data?.new_arrivals || [])[i + 1];
        return {
            ...side,
            product,
            cta_url: resolveConfiguredLink(side.cta_url, { ...linkOpts, product }),
            hide_cta: !configuredLinkExists(side.cta_url, categories) && !product,
        };
    });
    if (!heroSides.length && data) {
        heroSides = (data.featured || data.new_arrivals || []).slice(1, 3).map((product) => ({
            title: product.name,
            cta_label: 'Shop Now',
            cta_url: productPath(product),
            product,
        }));
    }

    const heroLayout = [1, 2, 3].includes(Number(hero.layout)) ? Number(hero.layout) : 3;
    const heroColumns = (Array.isArray(hero.columns) ? hero.columns : []).map((column) => ({
        ...column,
        slides: (column.slides || []).map((slide, index) => {
            const product = (data?.featured || data?.new_arrivals || [])[index];
            return {
                ...slide,
                product: slide.product || product,
                cta_url: resolveConfiguredLink(slide.cta_url || hero.cta_url, { ...linkOpts, product }),
                hide_cta: !configuredLinkExists(slide.cta_url || hero.cta_url, categories) && !product,
            };
        }).filter((s) => s && (s.image_url || s.title || s.content || s.product)),
    })).filter((col) => (col.slides || []).length);

    if (!data) {
        const earlyHero = show('hero') && (heroColumns.length || heroSlides.length) ? (
            <HeroBanner
                key="hero"
                layout={heroLayout}
                columns={heroColumns}
                slides={heroSlides}
                sides={heroSides}
                autoplayMs={Number(hero.autoplay_ms) > 0 ? Number(hero.autoplay_ms) : 5000}
                fallbackBody={hero.body || ''}
                hasCatalog={hasCatalog}
                categories={categories}
            />
        ) : null;
        const earlyTrust = show('trust') && trust.length ? (
            <section key="trust" className="shop-container">
                <div className="cx-trust">
                    {trust.slice(0, 8).map((item, index) => (
                        <div key={`${item.icon || 'trust'}-${index}`} className="cx-trust-item">
                            <span>
                                <Icon name={trustIcon(item.icon)} className="h-5 w-5" />
                            </span>
                            <div>
                                <p>{pickLocalizedText(item.title, locale, '')}</p>
                                <small>{pickLocalizedText(item.text, locale, '')}</small>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        ) : null;

        return (
            <div className="cx-home pb-0">
                {earlyHero}
                {earlyTrust}
                <HomeSkeleton productsOnly />
            </div>
        );
    }

    const seoBlock = (data.blocks || []).find((b) => b.content) || data.blocks?.[0];
    const contentHtml = classicDemoContent(pickLocalizedText(content.html, locale, '') || seoBlock?.content || '');
    const contentTitle = pickLocalizedText(content.title, locale, '') || seoBlock?.title || t('storefront.home.seo_title', 'Smart Electronics Designed Modern Lifestyle');

    const blocks = {
        hero: show('hero') && (heroColumns.length || heroSlides.length) ? (
            <HeroBanner
                key="hero"
                layout={heroLayout}
                columns={heroColumns}
                slides={heroSlides}
                sides={heroSides}
                autoplayMs={Number(hero.autoplay_ms) > 0 ? Number(hero.autoplay_ms) : 5000}
                fallbackBody={hero.body || ''}
                hasCatalog={hasCatalog}
                categories={categories}
            />
        ) : null,
        trust: show('trust') && trust.length ? (
            <section key="trust" className="shop-container">
                <div className="cx-trust">
                    {trust.slice(0, 8).map((item, index) => (
                        <div key={`${item.icon || 'trust'}-${index}`} className="cx-trust-item">
                            <span>
                                <Icon name={trustIcon(item.icon)} className="h-5 w-5" />
                            </span>
                            <div>
                                <p>{pickLocalizedText(item.title, locale, '')}</p>
                                <small>{pickLocalizedText(item.text, locale, '')}</small>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        ) : null,
        promos: show('promos') ? <PromoBanners key="promos" items={promos} featured={data.featured || []} categories={store.shop?.categories || categories} hasCatalog={hasCatalog} /> : null,
        featured: show('featured') && featuredItems.length ? (
            <section key="featured" className="shop-container cx-sec cx-product-collection">
                <Header title={pickLocalizedText(featuredCfg.title, locale, '') || t('storefront.home.featured', 'Featured Products')} />
                <HomeCarousel
                    items={featuredItems}
                    breakpoints={productBreakpoints}
                    fallback={productFallback}
                    gap={productGap}
                    speedMs={250}
                    showControls
                    showDots={false}
                    renderItem={(p, i) => <ProductCard product={p} priority={i < 6} compact />}
                />
            </section>
        ) : null,
        banner: show('banner') ? <SingleBanner key="banner" banner={banner} featured={data.featured || []} categories={store.shop?.categories || categories} hasCatalog={hasCatalog} /> : null,
        new_arrivals: show('new_arrivals') && newItems.length ? (
            <section key="new_arrivals" className="shop-container cx-sec cx-product-collection">
                <Header title={pickLocalizedText(newCfg.title, locale, '') || t('storefront.home.new', 'New Products')} />
                <HomeCarousel
                    items={newItems}
                    breakpoints={productBreakpoints}
                    fallback={productFallback}
                    gap={productGap}
                    speedMs={250}
                    showControls
                    showDots={false}
                    renderItem={(p) => <ProductCard product={p} compact />}
                />
            </section>
        ) : null,
        categories: null,
        promos_mid: show('promos_mid') ? <PromoBanners key="promos_mid" items={promosMid} featured={data.featured || []} categories={store.shop?.categories || categories} hasCatalog={hasCatalog} /> : null,
        category_shelves: show('category_shelves') && shelves.length ? (
            <div key="category_shelves" className="shop-container cx-sec cx-category-shelves">
                {shelves.map((shelf) => (
                    <CategoryShelf
                        key={shelf.category.id}
                        shelf={shelf}
                        showViewAllEnabled={showViewAllEnabled}
                    />
                ))}
            </div>
        ) : null,
        testimonials: show('testimonials') && testimonials.length ? (
            <section key="testimonials" className="cx-quotes">
                <div className="shop-container">
                    <div className="cx-sec-title is-center">
                        <h2>{t('storefront.home.testimonials', 'See What Our Customers Say')}</h2>
                    </div>
                    <HomeCarousel
                        items={testimonials}
                        breakpoints={{ 0: 1, 767: 2, 1199: 3 }}
                        fallback={3}
                        loop={testimonials.length > 3}
                        autoplayMs={testimonials.length > 3 ? 4500 : 0}
                        speedMs={320}
                        pauseOnHover
                        showControls={testimonials.length > 3}
                        renderItem={(item) => {
                            const rawRating = Number(item.rating);
                            const rating = Number.isFinite(rawRating) && rawRating > 0
                                ? Math.max(1, Math.min(5, Math.round(rawRating)))
                                : null;
                            const avatar = classicDemoTestimonialAvatar(item.avatar_url);
                            return (
                                <article className="cx-quote">
                                    <span className="cx-quote-badge">
                                        <span className="cx-quote-badge-icon" aria-hidden="true">✓</span>
                                        {t('storefront.home.verified_reviewer', 'Verified reviewer')}
                                    </span>
                                    {rating ? (
                                        <div className="cx-quote-stars" aria-label={`${rating} out of 5 stars`}>
                                            {'★★★★★'.slice(0, rating)}
                                        </div>
                                    ) : null}
                                    <p className="cx-quote-text">“{pickLocalizedText(item.quote, locale, '')}”</p>
                                    <footer className="cx-quote-author">
                                        <img src={avatar} alt="" width="44" height="44" loading="lazy" />
                                        <span>
                                            <strong>{pickLocalizedText(item.name, locale, '')}</strong>
                                            <small>{pickLocalizedText(item.role, locale, '') || t('storefront.home.verified_buyer', 'Verified buyer')}</small>
                                        </span>
                                    </footer>
                                </article>
                            );
                        }}
                    />
                </div>
            </section>
        ) : null,
        bestsellers: show('bestsellers') && (data.bestsellers || []).length ? (
            <section key="bestsellers" className="shop-container cx-sec cx-product-collection">
                <Header title={pickLocalizedText(bestCfg.title, locale, '') || t('storefront.home.bestsellers', 'Bestseller Products')} />
                <HomeCarousel
                    items={(data.bestsellers || [])
                        .slice(0, bestCfg.limit || productsLimit)
                        .map((product, index) => classicDemoProduct(product, index + 4))}
                    breakpoints={productBreakpoints}
                    fallback={productFallback}
                    gap={productGap}
                    speedMs={250}
                    showControls
                    showDots={false}
                    renderItem={(p) => <ProductCard product={p} compact />}
                />
            </section>
        ) : null,
        promos_bottom: show('promos_bottom') ? <PromoBanners key="promos_bottom" items={promosBottom} featured={data.new_arrivals || data.featured || []} categories={store.shop?.categories || categories} hasCatalog={hasCatalog} /> : null,
        brands: show('brands') && brands.length ? (
            <PopularBrands
                key="brands"
                title={pickLocalizedText(brandCfg.title, locale, '') || t('storefront.home.brands', 'Popular Brands')}
                brands={brands.slice(0, Math.min(16, brandCfg.limit || 16))}
            />
        ) : null,
        content: show('content') && (contentHtml || contentTitle) ? (
            <section key="content" className="cx-seo">
                <div className="shop-container">
                    <div className="cx-seo-panel">
                        <p className="cx-seo-eyebrow">{t('storefront.home.content_eyebrow', 'About our store')}</p>
                        <h2>{contentTitle}</h2>
                        {contentHtml ? <SeoExpandBody html={contentHtml} /> : null}
                    </div>
                </div>
            </section>
        ) : null,
        newsletter: show('newsletter') ? (
            <NewsletterBlock key="newsletter" newsletter={newsletter} />
        ) : null,
    };

    const rendered = order.map((code) => blocks[code]).filter(Boolean);
    const empty = !featuredItems.length && !newItems.length && !rendered.length;

    return (
        <div className="cx-home pb-0">
            {rendered}
            {(data.modules?.body || []).length ? (
                <div className="shop-container py-8">
                    <ModuleBlocks slots={data.modules.body} />
                </div>
            ) : null}
            {empty ? (
                <div className="shop-container py-8">
                    <EmptyState title={t('storefront.home.empty', 'No products yet')} body={t('storefront.home.empty_body', 'Add products in admin to fill this grid.')} />
                </div>
            ) : null}
        </div>
    );
}

function seoPlainLength(html) {
    return String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().length;
}

function SeoExpandBody({ html }) {
    const [open, setOpen] = useState(false);
    const [needsToggle, setNeedsToggle] = useState(seoPlainLength(html) > 160);
    const bodyRef = useRef(null);

    useEffect(() => {
        const el = bodyRef.current;
        const longCopy = seoPlainLength(html) > 160;
        if (!el) {
            setNeedsToggle(longCopy);
            return undefined;
        }
        const measure = () => {
            if (open) {
                return;
            }
            setNeedsToggle(longCopy || el.scrollHeight > el.clientHeight + 4);
        };
        measure();
        if (typeof ResizeObserver === 'undefined') {
            return undefined;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, [html, open]);

    return (
        <>
            <div
                ref={bodyRef}
                className={open ? 'cx-seo-body' : 'cx-seo-body cx-seo-clamp'}
                dangerouslySetInnerHTML={{ __html: html }}
            />
            {needsToggle || open ? (
                <button type="button" className="shop-btn shop-btn-primary cx-seo-more" onClick={() => setOpen((current) => !current)}>
                    {open ? t('storefront.home.read_less', 'Read less') : t('storefront.home.read_more', 'Read more')}
                </button>
            ) : null}
        </>
    );
}

function Header({ title, action = null }) {
    return (
        <div className="cx-sec-title">
            <h2>{title}</h2>
            {action}
        </div>
    );
}

const BRAND_ACCENTS = [
    { tone: '#7C3AED', soft: 'rgba(124, 58, 237, 0.16)', glow: 'rgba(124, 58, 237, 0.28)' },
    { tone: '#DB2777', soft: 'rgba(219, 39, 119, 0.14)', glow: 'rgba(244, 114, 182, 0.35)' },
    { tone: '#4F46E5', soft: 'rgba(79, 70, 229, 0.14)', glow: 'rgba(129, 140, 248, 0.32)' },
    { tone: '#0891B2', soft: 'rgba(8, 145, 178, 0.14)', glow: 'rgba(34, 211, 238, 0.28)' },
    { tone: '#059669', soft: 'rgba(5, 150, 105, 0.14)', glow: 'rgba(52, 211, 153, 0.3)' },
    { tone: '#EA580C', soft: 'rgba(234, 88, 12, 0.14)', glow: 'rgba(251, 146, 60, 0.32)' },
    { tone: '#2563EB', soft: 'rgba(37, 99, 235, 0.14)', glow: 'rgba(96, 165, 250, 0.3)' },
    { tone: '#C026D3', soft: 'rgba(192, 38, 211, 0.14)', glow: 'rgba(232, 121, 249, 0.3)' },
];

function brandHasLogo(brand) {
    return Boolean(brand?.logo && !brand.logo.includes('default.png') && !brand.logo.includes('p-md-default'));
}

function PopularBrands({ title, brands = [] }) {
    const items = brands.slice(0, 16);
    const brandBreakpoints = {
        0: 2,
        480: 3,
        768: 4,
        991: 6,
        1199: 7,
        1449: 8,
    };

    return (
        <section className="cx-sec cx-brands">
            <div className="shop-container">
                <div className="cx-brands-intro">
                    <div>
                        <h2>{title}</h2>
                        <p className="cx-brands-eyebrow">{t('storefront.home.brands_eyebrow', 'Discover products from popular brands.')}</p>
                    </div>
                </div>

                <HomeCarousel
                    className="cx-brands-carousel"
                    items={items}
                    breakpoints={brandBreakpoints}
                    fallback={8}
                    gap={20}
                    showControls
                    showDots={false}
                    renderItem={(brand, index) => {
                        const accent = BRAND_ACCENTS[index % BRAND_ACCENTS.length];
                        return (
                            <Link
                                key={`${brand.id}-${brand.name}`}
                                to={brand.url || `/brand/${brand.slug || brand.id}`}
                                state={entityState('brand')}
                                className={`cx-brand${brand.banner ? ' has-cover' : ''}`}
                                title={brand.name}
                                aria-label={brand.name}
                                style={{
                                    '--brand-tone': accent.tone,
                                    '--brand-soft': accent.soft,
                                    '--brand-glow': accent.glow,
                                }}
                            >
                                <span className="cx-brand-tile">
                                    {brand.banner ? <img className="cx-brand-cover" src={brand.banner} alt="" /> : null}
                                    {brandHasLogo(brand) ? (
                                        <img className="cx-brand-logo" src={brand.logo} alt={brand.name || 'Brand'} />
                                    ) : (
                                        <span className="cx-brand-monogram">{(brand.name || 'B').slice(0, 2).toUpperCase()}</span>
                                    )}
                                </span>
                                <span className="cx-brand-name">{brand.name || t('storefront.home.brand', 'Brand')}</span>
                            </Link>
                        );
                    }}
                />
            </div>
        </section>
    );
}

function promoDestination(promo, featuredProduct, categories, hasCatalog = true) {
    const configured = String(promo?.url || promo?.cta_url || '').trim();
    return resolveConfiguredLink(configured, {
        categories,
        product: featuredProduct || promo?.product,
        hasCatalog,
    });
}

function PromoBanners({ items, featured, categories = [], hasCatalog = true }) {
    const { locale } = useShopSession();
    const row = (items || []).filter((item) => {
        if (!item || !(pickLocalizedText(item.title, locale, '') || item.image_url)) {
            return false;
        }
        return configuredLinkExists(item.url || item.cta_url, categories);
    }).slice(0, 4);
    if (!row.length) {
        return null;
    }
    return (
        <section className="shop-container cx-promo-section">
            <div className="cx-promo-row">
                {row.map((promo, i) => (
                    <Link key={`${promo.title}-${i}`} to={promoDestination(promo, featured[i], categories, hasCatalog)} state={entityState('category')} className="cx-promo">
                        <div className="cx-promo-media">
                            {promo.image_url ? (
                                <img src={classicAssetUrl(promo.image_url)} alt={pickLocalizedText(promo.title, locale, '') || 'Promotion'} />
                            ) : (
                                <ProductImage product={promo.product || featured[i] || { name: pickLocalizedText(promo.title, locale, '') }} fit="cover" />
                            )}
                        </div>
                        <div className="cx-promo-copy">
                            {pickLocalizedText(promo.kicker, locale, '') ? <small>{pickLocalizedText(promo.kicker, locale, '')}</small> : null}
                            <h3>{pickLocalizedText(promo.title, locale, '')}</h3>
                            {pickLocalizedText(promo.subtitle, locale, '') ? <p>{pickLocalizedText(promo.subtitle, locale, '')}</p> : null}
                            <span>{pickLocalizedText(promo.cta, locale, '') || t('storefront.home.shop_now', 'Shop Now')}</span>
                        </div>
                    </Link>
                ))}
            </div>
        </section>
    );
}

function SingleBanner({ banner, featured = [], categories = [], hasCatalog = true }) {
    const { locale } = useShopSession();
    const title = pickLocalizedText(banner?.title, locale, '');
    const kicker = pickLocalizedText(banner?.kicker, locale, '');
    const subtitle = pickLocalizedText(banner?.subtitle, locale, '');
    const cta = pickLocalizedText(banner?.cta, locale, '');
    if (!banner || (!banner.image_url && !title)) {
        return null;
    }
    const href = promoDestination(banner, featured[0], categories, hasCatalog);
    const live = configuredLinkExists(banner.url || banner.cta_url, categories) || Boolean(featured[0]);
    if (banner.image_url) {
        const media = (
            <>
                <div className="cx-promo-media">
                    <img src={classicAssetUrl(banner.image_url)} alt={title || 'Banner'} />
                </div>
                <div className="cx-promo-copy">
                    {kicker ? <small>{kicker}</small> : null}
                    <h3>{title}</h3>
                    {subtitle ? <p>{subtitle}</p> : null}
                    {live ? <span>{cta || t('storefront.home.shop_now', 'Shop Now')}</span> : null}
                </div>
            </>
        );
        return (
            <section className="shop-container cx-promo-section">
                {live ? (
                    <Link to={href} state={entityState('category')} className="cx-promo">{media}</Link>
                ) : (
                    <div className="cx-promo">{media}</div>
                )}
            </section>
        );
    }
    return (
        <section className="shop-container">
            <div className="cx-dealstrip">
                <p>
                    {title}
                    {subtitle ? <>{' '}<span>{subtitle}</span></> : null}
                    {kicker ? <>{' '}{kicker}</> : null}
                </p>
            </div>
        </section>
    );
}

function NewsletterBlock({ newsletter }) {
    const { locale } = useShopSession();
    const [email, setEmail] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [consent, setConsent] = useState(false);
    const [cmsSlug, setCmsSlug] = useState('');
    const [successOpen, setSuccessOpen] = useState(false);

    return (
        <section className="cx-footer-news">
            <div className="shop-container cx-newsletter-row">
                <div className="cx-newsletter-copy">
                    <span className="cx-newsletter-icon" aria-hidden="true">
                        <Icon name="mail" className="h-6 w-6" />
                    </span>
                    <div>
                        <h2>{pickLocalizedText(newsletter.title, locale, '') || t('storefront.home.newsletter_title', 'Join Our Newsletter')}</h2>
                        <p>{pickLocalizedText(newsletter.body, locale, '') || t('storefront.home.newsletter_body', 'Get all latest information on events, sales and offers')}</p>
                    </div>
                </div>
                <div className="cx-newsletter-form-wrap">
                    <form
                        onSubmit={async (e) => {
                            e.preventDefault();
                            if (busy) {
                                return;
                            }
                            if (!email.trim()) {
                                setError(t('storefront.home.email_required', 'Enter your email address.'));
                                return;
                            }
                            if (!consent) {
                                setError(t('storefront.home.consent_required', 'Please agree to the terms and privacy policy.'));
                                return;
                            }
                            setBusy(true);
                            setError('');
                            try {
                                await csrf();
                                await api.post('/newsletter', { email: email.trim() });
                                setEmail('');
                                setConsent(false);
                                setSuccessOpen(true);
                            } catch (err) {
                                const msg = err.response?.data?.errors?.email?.[0]
                                    || err.response?.data?.message
                                    || t('storefront.home.subscribe_error', 'Could not subscribe. Try again.');
                                setError(msg);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <div className="cx-newsletter-field">
                            <input
                                type="email"
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder={t('storefront.home.email_placeholder', 'Your email address')}
                            />
                            <button type="submit" disabled={busy} aria-label={t('storefront.home.subscribe', 'Subscribe')}>
                                <Icon name="send" className="h-5 w-5" />
                            </button>
                        </div>
                        <label className="cx-newsletter-consent">
                            <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                            <span>
                                {t('storefront.home.newsletter_consent_prefix', 'I agree to the')}{' '}
                                <button type="button" className="cx-newsletter-legal" onClick={() => setCmsSlug('terms')}>
                                    {t('storefront.shell.terms', 'terms and conditions')}
                                </button>
                                {' '}{t('storefront.home.newsletter_consent_and', 'and the')}{' '}
                                <button type="button" className="cx-newsletter-legal" onClick={() => setCmsSlug('privacy')}>
                                    {t('storefront.shell.privacy', 'privacy policy')}
                                </button>
                            </span>
                        </label>
                    </form>
                    {error ? <p className="cx-newsletter-error">{error}</p> : null}
                </div>
            </div>

            <CmsModal
                slug={cmsSlug}
                open={Boolean(cmsSlug)}
                onClose={() => setCmsSlug('')}
                titleFallback={cmsSlug === 'privacy'
                    ? t('storefront.shell.privacy', 'Privacy Policy')
                    : t('storefront.shell.terms', 'Terms and Conditions')}
            />
            <MessageModal
                open={successOpen}
                onClose={() => setSuccessOpen(false)}
                title={t('storefront.home.subscribed_title', 'Subscribed')}
                message={t('storefront.home.subscribed', 'Thanks — you are on the list.')}
                confirmLabel={t('storefront.common.continue', 'Continue')}
            />
        </section>
    );
}

function trustIcon(name) {
    const map = {
        truck: 'truck',
        return: 'return',
        shield: 'shield',
        support: 'user',
        tag: 'flame',
    };
    return map[name] || 'truck';
}
