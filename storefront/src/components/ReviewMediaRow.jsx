import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../i18n.js';
import { webPath } from '../webPath.js';

function ReviewVideoPlayer({ src, className = '' }) {
    const videoRef = useRef(null);
    const [playing, setPlaying] = useState(false);

    const togglePlay = async () => {
        const el = videoRef.current;
        if (!el) return;
        try {
            if (el.paused) {
                await el.play();
                setPlaying(true);
            } else {
                el.pause();
                setPlaying(false);
            }
        } catch {
            setPlaying(false);
        }
    };

    const toggleFullscreen = async () => {
        const el = videoRef.current;
        if (!el) return;
        try {
            if (document.fullscreenElement === el) {
                await document.exitFullscreen?.();
            } else if (el.requestFullscreen) {
                await el.requestFullscreen();
            } else if (el.webkitEnterFullscreen) {
                el.webkitEnterFullscreen();
            }
        } catch {
            // Ignore fullscreen denial.
        }
    };

    return (
        <div className={`shop-review-video${className ? ` ${className}` : ''}`}>
            <video
                ref={videoRef}
                src={src}
                playsInline
                preload="metadata"
                onPlay={() => setPlaying(true)}
                onPause={() => setPlaying(false)}
                onEnded={() => setPlaying(false)}
                onClick={togglePlay}
            />
            <div className="shop-review-video__controls">
                <button
                    type="button"
                    className="shop-review-video__btn"
                    onClick={togglePlay}
                    aria-label={playing
                        ? t('storefront.common.pause', 'Pause')
                        : t('storefront.common.play', 'Play')}
                >
                    {playing ? '❚❚' : '▶'}
                </button>
                <button
                    type="button"
                    className="shop-review-video__btn"
                    onClick={toggleFullscreen}
                    aria-label={t('storefront.common.fullscreen', 'Full screen')}
                >
                    ⛶
                </button>
            </div>
        </div>
    );
}

/**
 * Horizontal review media row + image lightbox.
 * Videos use play + fullscreen only (no native chrome).
 */
export default function ReviewMediaRow({
    imageUrls = [],
    videoUrl = null,
    className = '',
}) {
    const images = (Array.isArray(imageUrls) ? imageUrls : []).filter(Boolean).map((url) => webPath(url));
    const video = videoUrl ? webPath(videoUrl) : null;
    const [lightboxIndex, setLightboxIndex] = useState(null);

    useEffect(() => {
        if (lightboxIndex == null) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                setLightboxIndex(null);
            } else if (e.key === 'ArrowRight' && images.length > 1) {
                setLightboxIndex((i) => ((i ?? 0) + 1) % images.length);
            } else if (e.key === 'ArrowLeft' && images.length > 1) {
                setLightboxIndex((i) => ((i ?? 0) - 1 + images.length) % images.length);
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [lightboxIndex, images.length]);

    if (!images.length && !video) {
        return null;
    }

    const activeUrl = lightboxIndex != null ? images[lightboxIndex] : null;

    return (
        <>
            <div className={`shop-review-media-row${className ? ` ${className}` : ''}`}>
                {images.map((url, index) => (
                    <button
                        key={url}
                        type="button"
                        className="shop-review-media-row__item is-image"
                        onClick={() => setLightboxIndex(index)}
                        aria-label={t('storefront.pdp.view_review_image', 'View image')}
                    >
                        <img src={url} alt="" loading="lazy" />
                    </button>
                ))}
                {video ? (
                    <div className="shop-review-media-row__item is-video">
                        <ReviewVideoPlayer src={video} />
                    </div>
                ) : null}
            </div>

            {activeUrl && typeof document !== 'undefined'
                ? createPortal(
                    <div
                        className="cx-modal shop-review-lightbox"
                        role="dialog"
                        aria-modal="true"
                        aria-label={t('storefront.pdp.review_image', 'Review image')}
                    >
                        <button
                            type="button"
                            className="cx-modal-backdrop"
                            aria-label={t('storefront.common.close', 'Close')}
                            onClick={() => setLightboxIndex(null)}
                        />
                        <div className="shop-review-lightbox__panel">
                            <button
                                type="button"
                                className="cx-modal-close shop-review-lightbox__close"
                                onClick={() => setLightboxIndex(null)}
                                aria-label={t('storefront.common.close', 'Close')}
                            >
                                ×
                            </button>
                            {images.length > 1 ? (
                                <button
                                    type="button"
                                    className="shop-review-lightbox__nav is-prev"
                                    onClick={() => setLightboxIndex((i) => ((i ?? 0) - 1 + images.length) % images.length)}
                                    aria-label={t('storefront.shop.prev', 'Previous')}
                                >
                                    ‹
                                </button>
                            ) : null}
                            <div className="shop-review-lightbox__frame">
                                <img src={activeUrl} alt="" className="shop-review-lightbox__image" />
                            </div>
                            {images.length > 1 ? (
                                <button
                                    type="button"
                                    className="shop-review-lightbox__nav is-next"
                                    onClick={() => setLightboxIndex((i) => ((i ?? 0) + 1) % images.length)}
                                    aria-label={t('storefront.shop.next', 'Next')}
                                >
                                    ›
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
