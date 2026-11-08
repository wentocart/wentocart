import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api.js';
import ProductCard from '../components/ProductCard.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { SocialBrandIcon } from '../components/SocialBrandIcon.jsx';
import { Icon } from '../components/Icons.jsx';
import { EmptyState, BrandPageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { DEFAULT_IMAGES } from '../lib/format.js';
import { applyNotFoundSeo, applySeoHead, hasVariantQuery, barePathCanonical } from '../lib/seoHead.js';

/** Description is TinyMCE HTML — only treat it as present when it has real text. */
function hasRichText(html) {
    return String(html || '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .length > 0;
}

/** Prefer the uploaded banner, then the logo; ignore API placeholder defaults. */
function brandHeroImage(brand) {
    const placeholders = Object.values(DEFAULT_IMAGES);
    const candidates = [brand?.banner, brand?.logo];
    const real = candidates.find((url) => {
        const src = String(url || '').trim();
        return src !== '' && !placeholders.includes(src);
    });
    return real || '';
}

/** Single-line address for display; joins only the parts that exist. */
function formatAddress(address) {
    if (!address) {
        return '';
    }
    return [
        address.line1,
        address.line2,
        address.city,
        address.state,
        address.postcode,
        address.country,
    ].filter(Boolean).join(', ');
}

export default function Brand({ slug, initial = null }) {
    // Mounted by SmartPage it receives `slug` as a prop; mounted directly by the
    // `/brand/:slug` route it must read the param instead, or the API is called
    // with `undefined`.
    const params = useParams();
    const raw = slug || params.slug || '';
    const resolved = raw.replace(/^brand\//, '');
    const [data, setData] = useState(initial);
    useEffect(() => {
        if (initial?.brand) {
            setData(initial);
            const brand = initial.brand;
            applySeoHead({
                title: brand?.seo?.title || brand?.name,
                description: brand?.seo?.description || brand?.short_description || '',
                canonical: brand?.seo?.canonical || brand?.url || barePathCanonical(),
                robots: hasVariantQuery() ? 'noindex,follow' : (brand?.seo?.robots || 'index,follow'),
                og_type: brand?.seo?.og_type || 'website',
                og_image: brand?.seo?.og_image || brand?.banner || brand?.logo || null,
                clear_og_image: !(brand?.seo?.og_image || brand?.banner || brand?.logo),
                json_ld: brand?.seo?.json_ld ?? null,
                hreflang: brand?.seo?.hreflang || [],
            });
            return undefined;
        }
        if (!resolved) {
            setData(false);
            applyNotFoundSeo();
            return;
        }
        api.get(`/catalog/brand/${resolved}`).then((r) => {
            setData(r.data);
            const brand = r.data?.brand;
            applySeoHead({
                title: brand?.seo?.title || brand?.name,
                description: brand?.seo?.description || brand?.short_description || '',
                canonical: brand?.seo?.canonical || brand?.url || barePathCanonical(),
                robots: hasVariantQuery() ? 'noindex,follow' : (brand?.seo?.robots || 'index,follow'),
                og_type: brand?.seo?.og_type || 'website',
                og_image: brand?.seo?.og_image || brand?.banner || brand?.logo || null,
                clear_og_image: !(brand?.seo?.og_image || brand?.banner || brand?.logo),
                json_ld: brand?.seo?.json_ld ?? null,
                hreflang: brand?.seo?.hreflang || [],
            });
        }).catch(() => {
            setData(false);
            applyNotFoundSeo();
        });
    }, [resolved]);

    if (data === null) {
        return <BrandPageSkeleton />;
    }
    if (!data) {
        return <div className="shop-container py-16"><EmptyState title={t('storefront.brands.missing', 'Brand not found')} action={<Link to="/brands" className="shop-btn shop-btn-primary">{t('storefront.brands.title', 'Brands')}</Link>} /></div>;
    }

    const brand = data.brand;
    const products = data.products?.data || [];
    const heroImage = brandHeroImage(brand);
    const hasDescription = hasRichText(brand?.description);
    const social = brand.social || [];
    const addressLine = formatAddress(brand.address);
    const hasContact = !!(brand.email || brand.phone || addressLine || brand.website);

    return (
        <div className="shop-container py-8">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: t('storefront.brands.title', 'Brands'), url: '/brands' }, { name: brand.name }]} />

            {/* Brand hero — copy left, square image right. */}
            <header className={`shop-brand-hero${heroImage ? '' : ' is-text-only'}`}>
                <div className="shop-brand-hero-copy">
                    <h1>{brand.name}</h1>
                    {hasDescription ? (
                        <div
                            className="shop-brand-hero-desc shop-prose shop-pdp-rich"
                            dangerouslySetInnerHTML={{ __html: brand.description }}
                        />
                    ) : null}

                    {/* Address & contact inline below description */}
                    {hasContact ? (
                        <div className="shop-brand-hero-contact">
                            {addressLine ? (
                                <p className="shop-brand-hero-contact-row">
                                    <Icon name="pin" className="h-4 w-4" />
                                    <span>{addressLine}</span>
                                </p>
                            ) : null}
                            {brand.email ? (
                                <p className="shop-brand-hero-contact-row">
                                    <Icon name="mail" className="h-4 w-4" />
                                    <a href={`mailto:${brand.email}`}>{brand.email}</a>
                                </p>
                            ) : null}
                            {brand.phone ? (
                                <p className="shop-brand-hero-contact-row">
                                    <Icon name="phone" className="h-4 w-4" />
                                    <a href={`tel:${String(brand.phone).replace(/[^\d+]/g, '')}`}>{brand.phone}</a>
                                </p>
                            ) : null}
                            {brand.website ? (
                                <p className="shop-brand-hero-contact-row">
                                    <Icon name="send" className="h-4 w-4" />
                                    <a href={brand.website} target="_blank" rel="noopener noreferrer">{brand.website}</a>
                                </p>
                            ) : null}
                            {social.length > 0 ? (
                                <div className="shop-brand-hero-social">
                                    {social.map((item) => (
                                        <a
                                            key={item.key}
                                            href={item.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={`shop-brand-social-link shop-brand-social-link--${item.key}`}
                                            aria-label={item.label}
                                            title={item.label}
                                        >
                                            <SocialBrandIcon brand={item.key} />
                                        </a>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                </div>
                {heroImage ? (
                    <div className="shop-brand-hero-media">
                        <img
                            src={heroImage}
                            alt={brand.name || ''}
                            loading="eager"
                            fetchPriority="high"
                        />
                    </div>
                ) : null}
            </header>

            {products.length > 0 ? (
                <section className="shop-brand-products" aria-label={t('storefront.brands.products', 'Brand products')}>
                    <h2 className="shop-brand-products-title">
                        {t('storefront.brands.products', 'Brand products')}
                        <span className="shop-brand-products-count">{data.products?.total ?? products.length}</span>
                    </h2>
                    <div className="shop-brand-grid">
                        {products.map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                </section>
            ) : (
                <div className="mt-8"><EmptyState title={t('storefront.category.empty', 'No products match these filters')} action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} /></div>
            )}
        </div>
    );
}
