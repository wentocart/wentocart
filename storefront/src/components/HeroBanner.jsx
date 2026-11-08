import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import ProductImage from './ProductImage.jsx';
import { Icon } from './Icons.jsx';
import { classicAssetUrl } from '../classicAssets.js';
import { entityState, rewriteThemeHtmlHrefs } from '../lib/links.js';
import { pricesHidden } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';

/** Theme text may be a legacy string or a locale map { en: '…', fr: '…' }. */
function lines(value) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        const locale = String(window.__WENTO__?.locale || '').toLowerCase();
        const short = locale.slice(0, 2);
        const direct = value[locale] || value[short] || value[locale.replace('-', '_')];
        if (typeof direct === 'string' && direct.trim() !== '') {
            return direct.trim();
        }
        const first = Object.values(value).find((row) => typeof row === 'string' && row.trim() !== '');

        return typeof first === 'string' ? first.trim() : '';
    }

    return String(value || '').trim();
}

function splitPriceLead(text) {
    const raw = String(text || '').replace(/\s+/g, ' ').trim();
    const match = raw.match(/((?:[^\s\d]{1,6}\s*)?\d[\d,]*(?:\.\d+)?(?:\s*[^\s\d]{1,6})?)/);
    if (!match || !/\d/.test(match[1])) {
        return null;
    }
    const price = match[1].trim();
    const label = `${raw.slice(0, match.index)}${raw.slice(match.index + match[0].length)}`.replace(/\s+/g, ' ').trim();

    return { label, price };
}

function looksLikePriceLead(text) {
    const raw = String(text || '').trim();
    if (!raw || raw.length > 80) {
        return false;
    }
    const split = splitPriceLead(raw);

    return Boolean(split && (/from/i.test(raw) || /[^\d\s,.]/.test(split.price)));
}

function StartFrom({ text, product }) {
    const store = useStore();
    if (pricesHidden(store.shop, store.customer) || product?.show_price === false) {
        return null;
    }
    const raw = String(text || '').trim();
    const split = splitPriceLead(raw);
    const price = split?.price || product?.price_formatted || '';
    const label = (split?.label || '').replace(/start from/i, '').trim() || (price ? 'Start From' : '');
    if (!label && !price) {
        return null;
    }
    return (
        <p className="cx-hero-from">
            {label}
            {price ? (
                <>
                    {' '}
                    <strong>{price}</strong>
                </>
            ) : null}
        </p>
    );
}

function richHtml(value) {
    const html = lines(value);
    if (!html || !/<[a-z][\s\S]*>/i.test(html)) {
        return '';
    }
    const text = html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim();
    if (text === '' && !/<a\b/i.test(html)) {
        return '';
    }

    return html.replace(/<br\s*\/?>/gi, ' ');
}

function HeroCopy({ item, fallbackBody, headingLevel = 1, compact = false, hasCatalog = true, categories = [] }) {
    const hideCta = Boolean(item.hide_cta);
    const html = richHtml(item.content);
    if (html) {
        const rewritten = rewriteThemeHtmlHrefs(html, { hasCatalog, categories });
        const safeHtml = hideCta
            ? rewritten.replace(/<a\b[^>]*>([\s\S]*?)<\/a>/gi, '$1')
            : rewritten;
        return (
            <div
                className={`cx-hero-caption cx-hero-rich${compact ? ' is-compact' : ''}`}
                dangerouslySetInnerHTML={{ __html: safeHtml }}
                onClick={(event) => {
                    const link = event.target.closest('a');
                    if (link && (link.getAttribute('href') || '') === '#') {
                        event.preventDefault();
                    }
                }}
            />
        );
    }
    const title = lines(item.title || item.product?.name || (compact ? '' : 'Shop the looks you love'));
    const eyebrow = lines(item.eyebrow || item.kicker || '');
    const body = lines(item.body || item.subtitle || fallbackBody || '');
    const Heading = headingLevel === 1 ? 'h1' : 'h2';
    const ctaTo = item.cta_url || item.product?.url || (hasCatalog ? '/catalog' : '#');
    const ctaLabel = lines(item.cta_label) || 'Shop Now';

    return (
        <div className="cx-hero-caption">
            {eyebrow ? <p className="cx-hero-kicker">{eyebrow}</p> : null}
            {title ? <Heading className="cx-hero-title">{title}</Heading> : null}
            {body ? (
                looksLikePriceLead(body)
                    ? <StartFrom text={body} product={item.product} />
                    : <p className="cx-hero-desc">{body}</p>
            ) : (
                <StartFrom text="" product={item.product} />
            )}
            {hideCta ? null : (
                <Link
                    to={ctaTo}
                    state={entityState(item.entityType || 'category')}
                    className={`cx-hero-cta${compact ? ' is-compact' : ''}`}
                    onClick={(event) => {
                        if (ctaTo === '#') {
                            event.preventDefault();
                        }
                    }}
                >
                    {ctaLabel}
                </Link>
            )}
        </div>
    );
}

const HERO_PLACEHOLDER = '/themes/classic/assets/merchant/home/hero/placeholder.png';

function heroImageSrc(url) {
    const src = String(url || '');
    if (!src) {
        return classicAssetUrl(HERO_PLACEHOLDER);
    }
    if (!src.includes('/merchant/home/hero')) {
        return src;
    }

    return src + (src.includes('?') ? '&' : '?') + 'v=4';
}

function Slide({ item, fallbackBody, priority, compact, headingLevel = 1, hasCatalog = true, categories = [] }) {
    const src = heroImageSrc(classicAssetUrl(item.image_url) || item.product?.cover_url || HERO_PLACEHOLDER);
    const alt = lines(item.title || item.product?.name) || 'Collection';

    return (
        <div className={`cx-hero-slide${compact ? ' is-compact' : ''}`}>
            <div className="cx-hero-media" aria-hidden={src ? undefined : true}>
                {src ? (
                    <img
                        src={src}
                        alt={alt}
                        width={compact ? 800 : 1920}
                        height={compact ? 600 : 640}
                        decoding="async"
                        loading={priority ? 'eager' : 'lazy'}
                        fetchPriority={priority ? 'high' : 'auto'}
                    />
                ) : (
                    <ProductImage product={item.product || { name: alt }} priority={priority} fit="cover" />
                )}
            </div>
            <HeroCopy item={item} fallbackBody={fallbackBody} headingLevel={headingLevel} compact={compact} hasCatalog={hasCatalog} categories={categories} />
        </div>
    );
}

function SideCard({ item, fallbackBody, hasCatalog = true, categories = [] }) {
    if (!item) {
        return null;
    }
    const src = heroImageSrc(classicAssetUrl(item.image_url) || item.product?.cover_url || HERO_PLACEHOLDER);
    const alt = lines(item.title || item.product?.name) || 'Offer';

    return (
        <div className="cx-hero-side">
            <div className="cx-hero-side-media">
                {src ? (
                    <img src={src} alt={alt} width={800} height={600} loading="lazy" decoding="async" />
                ) : (
                    <ProductImage product={item.product || { name: alt }} fit="cover" />
                )}
            </div>
            <HeroCopy item={item} fallbackBody={fallbackBody} headingLevel={2} compact hasCatalog={hasCatalog} categories={categories} />
        </div>
    );
}

function ColumnCarousel({
    slides = [],
    autoplayMs = 5000,
    fallbackBody = '',
    compact = false,
    headingLevel = 1,
    hasCatalog = true,
    categories = [],
}) {
    const items = useMemo(() => (slides || []).filter(Boolean), [slides]);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const count = items.length;

    useEffect(() => {
        setIndex(0);
    }, [count]);

    useEffect(() => {
        if (count < 2 || paused || autoplayMs <= 0) {
            return undefined;
        }
        const id = window.setInterval(() => {
            setIndex((i) => (i + 1) % count);
        }, autoplayMs);
        return () => window.clearInterval(id);
    }, [count, paused, autoplayMs]);

    if (!items[0]) {
        return null;
    }

    const go = (delta) => setIndex((i) => (i + delta + count) % count);

    return (
        <div
            className={`cx-hero-column${compact ? ' is-side' : ' is-main'}`}
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onFocusCapture={() => setPaused(true)}
            onBlurCapture={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget)) {
                    setPaused(false);
                }
            }}
            onKeyDown={(e) => {
                if (count < 2) {
                    return;
                }
                if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    go(-1);
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    go(1);
                }
            }}
        >
            <div
                className="cx-hero-stage"
                role="region"
                aria-roledescription="carousel"
                aria-label={compact ? 'Offer carousel' : 'Hero carousel'}
            >
                <div
                    className="cx-hero-track"
                    style={{
                        width: `${count * 100}%`,
                        transform: `translate3d(${-(index * 100) / count}%, 0, 0)`,
                    }}
                >
                    {items.map((item, i) => (
                        <div
                            key={item.id || item.title || item.product?.id || i}
                            className="cx-hero-slide-wrap"
                            style={{ width: `${100 / count}%` }}
                            aria-hidden={i !== index}
                        >
                            {compact ? (
                                <SideCard item={item} fallbackBody={fallbackBody} hasCatalog={hasCatalog} categories={categories} />
                            ) : (
                                <Slide
                                    item={item}
                                    fallbackBody={fallbackBody}
                                    priority={i === 0}
                                    headingLevel={headingLevel}
                                    hasCatalog={hasCatalog}
                                    categories={categories}
                                />
                            )}
                        </div>
                    ))}
                </div>
            </div>
            {count > 1 ? (
                <div className="cx-hero-dots" role="tablist" aria-label="Slides">
                    {items.map((_, i) => (
                        <button
                            key={i}
                            type="button"
                            role="tab"
                            aria-selected={i === index}
                            className={i === index ? 'is-on' : ''}
                            aria-label={`Go to slide ${i + 1}`}
                            onClick={() => setIndex(i)}
                        />
                    ))}
                </div>
            ) : null}
            {count > 1 ? (
                <div className="cx-hero-arrows">
                    <button type="button" aria-label="Previous slide" onClick={() => go(-1)}>
                        <Icon name="chevron-left" className="h-5 w-5" />
                    </button>
                    <button type="button" aria-label="Next slide" onClick={() => go(1)}>
                        <Icon name="chevron-right" className="h-5 w-5" />
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function migrateColumns(layout, slides, sides) {
    const sideSlides = (sides || []).filter(Boolean).map((side) => ({
        ...side,
        eyebrow: side.eyebrow || side.kicker,
        body: side.body || side.subtitle,
    }));
    if (layout === 1) {
        return [{ width_hint: 'full', slides: slides?.length ? slides : sideSlides }];
    }
    if (layout === 2) {
        return [
            { width_hint: 'main', slides: slides?.length ? slides : sideSlides.slice(0, 1) },
            { width_hint: 'side', slides: sideSlides.length ? sideSlides : (slides || []).slice(0, 1) },
        ];
    }
    return [
        { width_hint: 'main', slides: slides || [] },
        ...sideSlides.slice(0, 2).map((slide) => ({ width_hint: 'side', slides: [slide] })),
    ];
}

export default function HeroBanner({
    layout = 3,
    columns = [],
    slides = [],
    sides = [],
    autoplayMs = 5000,
    fallbackBody = '',
    hasCatalog = true,
    categories = [],
}) {
    const resolvedLayout = [1, 2, 3].includes(Number(layout)) ? Number(layout) : 3;
    const resolvedColumns = useMemo(() => {
        const cleaned = (columns || []).filter((col) => col && (col.slides || []).some(Boolean));
        if (cleaned.length) {
            return cleaned.slice(0, 3);
        }
        return migrateColumns(resolvedLayout, slides, sides).filter((col) => (col.slides || []).length);
    }, [columns, slides, sides, resolvedLayout]);

    if (!resolvedColumns.length) {
        return null;
    }

    return (
        <section
            className={`cx-hero-wrap cx-hero-layout-${resolvedLayout} shop-container`}
            aria-label="Featured promotions"
        >
            <div className={`cx-hero-grid cx-hero-cols-${resolvedColumns.length}`}>
                {resolvedColumns.map((column, index) => {
                    const hint = column.width_hint || (index === 0 ? (resolvedLayout === 1 ? 'full' : 'main') : 'side');
                    const compact = hint === 'side' && resolvedLayout === 3;
                    const headingLevel = index === 0 ? 1 : 2;
                    return (
                        <ColumnCarousel
                            key={`${hint}-${index}`}
                            slides={column.slides}
                            autoplayMs={autoplayMs}
                            fallbackBody={fallbackBody}
                            compact={compact}
                            headingLevel={headingLevel}
                            hasCatalog={hasCatalog}
                            categories={categories}
                        />
                    );
                })}
            </div>
        </section>
    );
}
