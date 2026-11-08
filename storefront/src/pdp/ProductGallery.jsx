import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';

const ZOOM_MIN = 1;
const ZOOM_MAX = 3;
const ZOOM_STEP = 0.5;

function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
}

export default function ProductGallery({
    gallery = [],
    productName = '',
    off = 0,
    isNew = false,
    showPreorderLabel = false,
    activeImage = 0,
    onActiveImage,
}) {
    const [lightbox, setLightbox] = useState(false);
    const [lbZoom, setLbZoom] = useState(ZOOM_MIN);
    const [lbPan, setLbPan] = useState({ x: 0, y: 0 });
    const [origin, setOrigin] = useState({ x: 50, y: 50 });
    const [hovering, setHovering] = useState(false);
    const [canHoverZoom, setCanHoverZoom] = useState(false);
    const drag = useRef({ on: false, moved: false, x: 0, y: 0, panX: 0, panY: 0 });
    const frameRef = useRef(null);

    const current = gallery[activeImage] || gallery[0];
    const count = gallery.length;

    useEffect(() => {
        const mq = window.matchMedia('(hover: hover) and (pointer: fine)');
        const apply = () => setCanHoverZoom(mq.matches);
        apply();
        mq.addEventListener?.('change', apply);
        return () => mq.removeEventListener?.('change', apply);
    }, []);

    useEffect(() => {
        if (!lightbox) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                closeLightbox();
            } else if (e.key === 'ArrowRight' && count > 1) {
                step(1);
            } else if (e.key === 'ArrowLeft' && count > 1) {
                step(-1);
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [lightbox, count, activeImage]);

    useEffect(() => {
        const node = frameRef.current;
        if (!lightbox || !node) {
            return undefined;
        }
        const onWheel = (e) => {
            e.preventDefault();
            const dir = e.deltaY < 0 ? 1 : -1;
            setLbZoom((z) => {
                const next = clamp(Number((z + dir * ZOOM_STEP).toFixed(2)), ZOOM_MIN, ZOOM_MAX);
                if (next <= ZOOM_MIN) {
                    setLbPan({ x: 0, y: 0 });
                }
                return next;
            });
        };
        node.addEventListener('wheel', onWheel, { passive: false });
        return () => node.removeEventListener('wheel', onWheel);
    }, [lightbox]);

    const setIndex = (next) => {
        onActiveImage?.(typeof next === 'function' ? next(activeImage) : next);
    };

    const step = (dir) => {
        if (count < 2) {
            return;
        }
        setIndex((i) => (i + dir + count) % count);
        setLbZoom(ZOOM_MIN);
        setLbPan({ x: 0, y: 0 });
    };

    const closeLightbox = () => {
        setLightbox(false);
        setLbZoom(ZOOM_MIN);
        setLbPan({ x: 0, y: 0 });
    };

    const openLightbox = () => {
        if (!current) {
            return;
        }
        setLbZoom(ZOOM_MIN);
        setLbPan({ x: 0, y: 0 });
        setLightbox(true);
    };

    const bumpZoom = (dir) => {
        setLbZoom((z) => {
            const next = clamp(Number((z + dir * ZOOM_STEP).toFixed(2)), ZOOM_MIN, ZOOM_MAX);
            if (next <= ZOOM_MIN) {
                setLbPan({ x: 0, y: 0 });
            }
            return next;
        });
    };

    const onStageMove = (e) => {
        if (!canHoverZoom) {
            return;
        }
        const box = e.currentTarget.getBoundingClientRect();
        const x = ((e.clientX - box.left) / box.width) * 100;
        const y = ((e.clientY - box.top) / box.height) * 100;
        setOrigin({ x, y });
    };

    const onLbPointerDown = (e) => {
        if (e.button !== 0) {
            return;
        }
        drag.current = {
            on: true,
            moved: false,
            x: e.clientX,
            y: e.clientY,
            panX: lbPan.x,
            panY: lbPan.y,
        };
        e.currentTarget.setPointerCapture?.(e.pointerId);
    };

    const onLbPointerMove = (e) => {
        if (!drag.current.on) {
            return;
        }
        const dx = e.clientX - drag.current.x;
        const dy = e.clientY - drag.current.y;
        if (Math.abs(dx) + Math.abs(dy) > 4) {
            drag.current.moved = true;
        }
        if (lbZoom <= ZOOM_MIN) {
            return;
        }
        setLbPan({
            x: drag.current.panX + dx,
            y: drag.current.panY + dy,
        });
    };

    const onLbPointerUp = (e) => {
        if (!drag.current.on) {
            return;
        }
        const moved = drag.current.moved;
        drag.current.on = false;
        e.currentTarget.releasePointerCapture?.(e.pointerId);
        if (moved) {
            return;
        }
        if (lbZoom <= ZOOM_MIN) {
            bumpZoom(1);
        } else {
            setLbZoom(ZOOM_MIN);
            setLbPan({ x: 0, y: 0 });
        }
    };

    const stageHover = canHoverZoom && hovering && !lightbox;

    return (
        <>
            <div className="shop-pdp2-gallery">
                {count > 1 ? (
                    <div className="shop-pdp2-thumbs">
                        {gallery.map((img, index) => (
                            <button
                                key={img.id}
                                type="button"
                                className={index === activeImage ? 'is-on' : ''}
                                onClick={() => setIndex(index)}
                                aria-label={t('storefront.pdp.show_image', 'Show image {n}').replace('{n}', String(index + 1))}
                            >
                                <img src={img.thumb} alt={img.alt || productName || ''} />
                            </button>
                        ))}
                    </div>
                ) : null}
                <div
                    className={`shop-pdp2-stage${current ? ' is-zoomable' : ''}${stageHover ? ' is-hovering' : ''}`}
                    onMouseEnter={() => setHovering(true)}
                    onMouseLeave={() => setHovering(false)}
                    onMouseMove={onStageMove}
                >
                    {off ? <span className="shop-pdp2-badge">-{off}%</span> : null}
                    {isNew ? <span className="shop-product-new-badge">{t('storefront.search.new', 'New')}</span> : null}
                    {showPreorderLabel ? (
                        <span className="shop-product-new-badge is-preorder">{t('storefront.pdp.preorder_badge', 'Pre-order')}</span>
                    ) : null}
                    {count > 1 ? (
                        <>
                            <button
                                type="button"
                                className="shop-pdp2-nav is-prev"
                                aria-label={t('storefront.pdp.prev_image', 'Previous image')}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    step(-1);
                                }}
                            >
                                <Icon name="arrowLeft" className="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                className="shop-pdp2-nav is-next"
                                aria-label={t('storefront.pdp.next_image', 'Next image')}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    step(1);
                                }}
                            >
                                <Icon name="chevron" className="h-4 w-4" />
                            </button>
                        </>
                    ) : null}
                    {current ? (
                        <>
                            <button
                                type="button"
                                className="shop-pdp2-stage-open"
                                onClick={openLightbox}
                                aria-label={t('storefront.pdp.view_large', 'View large image')}
                            >
                                <img
                                    className="shop-pdp2-stage-photo"
                                    src={current.large}
                                    alt={current.alt || productName}
                                    loading="eager"
                                    fetchPriority="high"
                                    decoding="async"
                                    style={stageHover ? { transformOrigin: `${origin.x}% ${origin.y}%` } : undefined}
                                />
                            </button>
                            <button
                                type="button"
                                className="shop-pdp2-stage-zoom"
                                onClick={openLightbox}
                                aria-label={t('storefront.pdp.zoom_image', 'Zoom image')}
                            >
                                <Icon name="zoom" className="h-4 w-4" />
                            </button>
                        </>
                    ) : (
                        <div className="shop-pdp2-empty">{t('storefront.pdp.no_image', 'No image')}</div>
                    )}
                </div>
            </div>

            {lightbox && current && typeof document !== 'undefined'
                ? createPortal(
                    <div
                        className="cx-modal shop-pdp2-lightbox"
                        role="dialog"
                        aria-modal="true"
                        aria-label={t('storefront.pdp.large_image', 'Large product image')}
                    >
                        <button
                            type="button"
                            className="cx-modal-backdrop"
                            aria-label={t('storefront.common.close', 'Close')}
                            onClick={closeLightbox}
                        />
                        <div className="shop-pdp2-lightbox__panel">
                            <div className="shop-pdp2-lightbox__tools">
                                <button
                                    type="button"
                                    onClick={() => bumpZoom(-1)}
                                    disabled={lbZoom <= ZOOM_MIN}
                                    aria-label={t('storefront.pdp.zoom_out', 'Zoom out')}
                                >
                                    <Icon name="zoomOut" className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => bumpZoom(1)}
                                    disabled={lbZoom >= ZOOM_MAX}
                                    aria-label={t('storefront.pdp.zoom_in', 'Zoom in')}
                                >
                                    <Icon name="zoom" className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    className="cx-modal-close"
                                    onClick={closeLightbox}
                                    aria-label={t('storefront.common.close', 'Close')}
                                >
                                    <Icon name="close" className="h-4 w-4" />
                                </button>
                            </div>
                            {count > 1 ? (
                                <button
                                    type="button"
                                    className="shop-pdp2-lightbox__nav is-prev"
                                    onClick={() => step(-1)}
                                    aria-label={t('storefront.pdp.prev_image', 'Previous image')}
                                >
                                    <Icon name="chevron-left" className="h-5 w-5" />
                                </button>
                            ) : null}
                            <div
                                ref={frameRef}
                                className={`shop-pdp2-lightbox__frame${lbZoom > ZOOM_MIN ? ' is-zoomed' : ''}`}
                            >
                                <img
                                    src={current.large}
                                    alt={current.alt || productName}
                                    className="shop-pdp2-lightbox__image"
                                    style={{
                                        transform: `translate(${lbPan.x}px, ${lbPan.y}px) scale(${lbZoom})`,
                                        cursor: lbZoom > ZOOM_MIN ? 'grab' : 'zoom-in',
                                    }}
                                    onPointerDown={onLbPointerDown}
                                    onPointerMove={onLbPointerMove}
                                    onPointerUp={onLbPointerUp}
                                    onPointerCancel={onLbPointerUp}
                                    draggable={false}
                                />
                            </div>
                            {count > 1 ? (
                                <button
                                    type="button"
                                    className="shop-pdp2-lightbox__nav is-next"
                                    onClick={() => step(1)}
                                    aria-label={t('storefront.pdp.next_image', 'Next image')}
                                >
                                    <Icon name="chevron-right" className="h-5 w-5" />
                                </button>
                            ) : null}
                        </div>
                    </div>,
                    document.body,
                )
                : null}
        </>
    );
}
