import { useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from './Icons.jsx';

const DRAG_THRESHOLD_PX = 8;

/** Build product carousel breakpoints from theme density.products_per_row (desktop max). */
export function productCarouselBreakpoints(perRow = 5) {
    const n = Math.max(2, Math.min(6, Number(perRow) || 5));
    return {
        0: Math.min(2, n),
        576: Math.min(2, n),
        767: Math.min(3, n),
        991: Math.min(4, n),
        1199: n, // laptop/desktop: show full products-per-row (e.g. 5)
        1449: n,
    };
}

export function productCarouselFallback(perRow = 5) {
    return Math.max(2, Math.min(6, Number(perRow) || 5));
}

/** Build category carousel breakpoints from theme density.categories_per_row. */
export function categoryCarouselBreakpoints(perRow = 12) {
    const n = Math.max(3, Math.min(12, Number(perRow) || 12));
    return {
        0: Math.min(3, n),
        480: Math.min(4, n),
        576: Math.min(5, n),
        767: Math.min(6, n),
        991: Math.min(8, n),
        1199: Math.min(10, n),
        1449: n,
    };
}

export const PRODUCT_CAROUSEL_BREAKPOINTS = productCarouselBreakpoints(5);
export const PRODUCT_CAROUSEL_FALLBACK = productCarouselFallback(5);

function perViewForWidth(width, breakpoints, fallback) {
    const keys = Object.keys(breakpoints).map(Number).sort((a, b) => a - b);
    let value = fallback;
    for (const key of keys) {
        if (width >= key) {
            value = breakpoints[key];
        }
    }
    return Math.max(1, value);
}

function valueForWidth(value, width, fallback = 0) {
    if (typeof value === 'number') {
        return value;
    }
    if (!value || typeof value !== 'object') {
        return fallback;
    }

    let resolved = fallback;
    Object.keys(value).map(Number).sort((a, b) => a - b).forEach((breakpoint) => {
        if (width >= breakpoint) {
            resolved = Number(value[breakpoint]) || fallback;
        }
    });

    return resolved;
}

export default function HomeCarousel({
    items = [],
    renderItem,
    breakpoints = { 0: 2, 576: 3, 767: 4, 991: 5, 1199: 6, 1449: 6 },
    fallback = 6,
    loop = false,
    autoplayMs = 0,
    speedMs = 250,
    pauseOnHover = true,
    className = '',
    showControls = false,
    showDots = true,
    gap = 0,
    itemWidth = 0,
    alignStart = false,
}) {
    const viewportRef = useRef(null);
    const [width, setWidth] = useState(0);
    const [vp, setVp] = useState(() => (typeof window === 'undefined' ? 1449 : window.innerWidth));
    const responsivePerView = perViewForWidth(vp, breakpoints, fallback);
    const itemGap = Math.max(0, Number(gap) || 0);
    const fixedItemWidth = Math.max(0, valueForWidth(itemWidth, vp));
    const perView = fixedItemWidth && width
        ? Math.max(1, Math.floor((width + itemGap) / (fixedItemWidth + itemGap)))
        : responsivePerView;
    const rows = useMemo(() => items.filter(Boolean), [items]);
    const canLoop = loop && rows.length > perView;
    const list = useMemo(() => {
        if (!canLoop) {
            return rows;
        }
        return [...rows.slice(-perView), ...rows, ...rows.slice(0, perView)];
    }, [rows, canLoop, perView]);

    const origin = canLoop ? perView : 0;
    const [index, setIndex] = useState(origin);
    const [anim, setAnim] = useState(true);
    const [paused, setPaused] = useState(false);
    const [dragX, setDragX] = useState(0);
    const drag = useRef({
        tracking: false,
        dragging: false,
        startX: 0,
        from: 0,
        pointerId: null,
        suppressClick: false,
    });

    useEffect(() => {
        const node = viewportRef.current;
        if (!node) {
            return undefined;
        }
        const apply = () => {
            setWidth(node.clientWidth);
            setVp(window.innerWidth);
        };
        apply();
        const observer = new ResizeObserver(apply);
        observer.observe(node);
        window.addEventListener('resize', apply);
        return () => {
            observer.disconnect();
            window.removeEventListener('resize', apply);
        };
    }, []);

    useEffect(() => {
        setIndex(canLoop ? perView : 0);
        setDragX(0);
    }, [perView, canLoop, rows.length]);

    useEffect(() => {
        if (!canLoop || !anim) {
            return undefined;
        }
        if (index >= list.length - perView) {
            const id = window.setTimeout(() => {
                setAnim(false);
                setIndex(perView);
            }, speedMs);
            return () => window.clearTimeout(id);
        }
        if (index <= 0) {
            const id = window.setTimeout(() => {
                setAnim(false);
                setIndex(rows.length);
            }, speedMs);
            return () => window.clearTimeout(id);
        }
        return undefined;
    }, [index, canLoop, anim, list.length, perView, speedMs, rows.length]);

    useEffect(() => {
        if (autoplayMs <= 0 || paused || rows.length <= perView) {
            return undefined;
        }
        const id = window.setInterval(() => {
            setAnim(true);
            setIndex((i) => {
                /* Advance one step, then wrap back to the first item once the
                   last item is reached. Without this the index kept growing
                   past maxIndex, so the track translated past the final item
                   and the row sat on empty space — a non-looping carousel with
                   autoplay went blank at the end instead of restarting. */
                const last = Math.max(0, list.length - perView);
                return i >= last ? 0 : i + 1;
            });
        }, autoplayMs);
        return () => window.clearInterval(id);
    }, [autoplayMs, paused, rows.length, perView, list.length]);

    const itemW = fixedItemWidth || (width ? Math.max(0, (width - (itemGap * (perView - 1))) / perView) : 0);
    const maxIndex = Math.max(0, list.length - perView);
    const canNavigate = rows.length > perView;
    const activePage = canLoop
        ? ((index - origin) % rows.length + rows.length) % rows.length
        : Math.min(index, Math.max(0, rows.length - perView));
    const pageCount = canLoop ? rows.length : Math.max(1, rows.length - perView + 1);

    const goTo = (next) => {
        setAnim(true);
        if (canLoop) {
            setIndex(next);
            return;
        }
        setIndex(Math.min(maxIndex, Math.max(0, next)));
    };

    const finishDrag = (clientX, target, fromIndex) => {
        if (!drag.current.tracking) {
            return;
        }
        const wasDragging = drag.current.dragging;
        const startFrom = fromIndex;
        const delta = wasDragging && itemW
            ? Math.round((clientX - drag.current.startX) / (itemW + itemGap))
            : 0;
        if (wasDragging && drag.current.pointerId != null) {
            target?.releasePointerCapture?.(drag.current.pointerId);
        }
        drag.current = {
            tracking: false,
            dragging: false,
            startX: 0,
            from: 0,
            pointerId: null,
            suppressClick: wasDragging,
        };
        setDragX(0);
        if (wasDragging) {
            setAnim(true);
            setIndex(Math.min(maxIndex, Math.max(0, startFrom - delta)));
        }
    };

    if (!list.length) {
        return null;
    }

    const viewport = (
        <div
            className={`cx-owl ${className}`}
            ref={viewportRef}
            style={fixedItemWidth && width ? {
                width: `${(perView * fixedItemWidth) + (Math.max(0, perView - 1) * itemGap)}px`,
                /* Centred when the row is narrower than its column, so leftover space
                   splits evenly rather than pooling on the right. */
                marginInline: 'auto',
            } : undefined}
            onMouseEnter={() => pauseOnHover && setPaused(true)}
            onMouseLeave={() => pauseOnHover && setPaused(false)}
            onPointerDown={(e) => {
                if (e.button != null && e.button !== 0) {
                    return;
                }
                if (e.target?.closest?.('button, input, select, textarea, [data-no-drag]')) {
                    return;
                }
                drag.current = {
                    tracking: true,
                    dragging: false,
                    startX: e.clientX,
                    from: index,
                    pointerId: e.pointerId,
                    suppressClick: false,
                };
            }}
            onPointerMove={(e) => {
                if (!drag.current.tracking) {
                    return;
                }
                const moved = e.clientX - drag.current.startX;
                if (!drag.current.dragging) {
                    if (Math.abs(moved) < DRAG_THRESHOLD_PX) {
                        return;
                    }
                    drag.current.dragging = true;
                    setAnim(false);
                    e.currentTarget.setPointerCapture?.(e.pointerId);
                }
                setDragX(moved);
            }}
            onPointerUp={(e) => finishDrag(e.clientX, e.currentTarget, drag.current.from)}
            onPointerCancel={(e) => finishDrag(e.clientX, e.currentTarget, drag.current.from)}
            onClickCapture={(e) => {
                if (drag.current.suppressClick) {
                    drag.current.suppressClick = false;
                    e.preventDefault();
                    e.stopPropagation();
                }
            }}
        >
            <div
                className="cx-owl-track"
                style={{
                    transform: `translate3d(${-(index * (itemW + itemGap)) + dragX}px, 0, 0)`,
                    transition: anim ? `transform ${speedMs / 1000}s ease` : 'none',
                    gap: itemGap ? `${itemGap}px` : undefined,
                    justifyContent: !canNavigate ? 'center' : undefined,
                }}
            >
                {list.map((item, i) => (
                    <div key={item?.id != null ? `${item.id}-${i}` : i} className="cx-owl-item" style={{ flex: `0 0 ${itemW}px`, width: itemW }}>
                        {renderItem(item, i)}
                    </div>
                ))}
            </div>
        </div>
    );

    if (!showControls || !canNavigate) {
        return viewport;
    }

    const atStart = !canLoop && index <= 0;
    const atEnd = !canLoop && index >= maxIndex;

    return (
        <div className="cx-owl-shell">
            <div className="cx-owl-stage">
                {viewport}
                <button
                    type="button"
                    className="cx-slider-nav is-prev"
                    aria-label="Previous"
                    data-no-drag
                    disabled={atStart}
                    onClick={() => goTo(index - 1)}
                >
                    <Icon name="chevron-left" className="cx-slider-nav-icon" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    className="cx-slider-nav is-next"
                    aria-label="Next"
                    data-no-drag
                    disabled={atEnd}
                    onClick={() => goTo(index + 1)}
                >
                    <Icon name="chevron-right" className="cx-slider-nav-icon" aria-hidden="true" />
                </button>
            </div>
            {showDots ? (
                <div className="cx-owl-dots" role="tablist" aria-label="Slides" data-no-drag>
                    {Array.from({ length: pageCount }, (_, page) => (
                        <button
                            key={page}
                            type="button"
                            className={`cx-owl-dot${page === activePage ? ' is-active' : ''}`}
                            aria-label={`Go to slide ${page + 1}`}
                            aria-current={page === activePage ? 'true' : undefined}
                            onClick={() => goTo(canLoop ? origin + page : page)}
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
