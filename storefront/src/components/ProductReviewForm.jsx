import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import StarRatingInput from './StarRatingInput.jsx';
import { reviewsMedia, reviewsMinLength, reviewsWho } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import { webPath } from '../webPath.js';

const MAX_IMAGES = 5;

function reviewSeed(initialReview) {
    return {
        rating: Number(initialReview?.rating) || 0,
        title: initialReview?.title || '',
        body: initialReview?.body || '',
        imageUrls: Array.isArray(initialReview?.image_urls)
            ? initialReview.image_urls.filter(Boolean)
            : (initialReview?.photo_url ? [initialReview.photo_url] : []),
        videoUrl: initialReview?.video_url || null,
    };
}

function previewUrl(file) {
    try {
        return URL.createObjectURL(file);
    } catch {
        return '';
    }
}

export default function ProductReviewForm({
    productId,
    shop,
    eligibility,
    initialReview = null,
    onSubmitted,
    compact = false,
    hideTitle = false,
    hideAccountLink = false,
    submitLabel = null,
}) {
    const seed = reviewSeed(initialReview || eligibility?.review);
    const [rating, setRating] = useState(seed.rating);
    const [title, setTitle] = useState(seed.title);
    const [body, setBody] = useState(seed.body);
    const [existingImages, setExistingImages] = useState(seed.imageUrls);
    const [removeImages, setRemoveImages] = useState([]);
    const [newPhotos, setNewPhotos] = useState([]);
    const [existingVideo, setExistingVideo] = useState(seed.videoUrl);
    const [removeVideo, setRemoveVideo] = useState(false);
    const [newVideo, setNewVideo] = useState(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [ratingError, setRatingError] = useState('');
    const imageInputRef = useRef(null);
    const videoInputRef = useRef(null);
    const noticeRef = useRef(null);
    const successLockRef = useRef(false);
    const store = useStore();
    const media = reviewsMedia(shop);
    const textLimit = Math.max(0, reviewsMinLength(shop));
    const writeText = textLimit > 0;
    const guestReview = !store?.customer && reviewsWho(shop) === 'everyone';
    const [guestName, setGuestName] = useState('');
    const [guestEmail, setGuestEmail] = useState('');
    const blocked = eligibility && eligibility.allowed === false && !eligibility.has_review;
    const hasReview = Boolean(eligibility?.has_review || initialReview?.id || eligibility?.review?.id);
    const done = Boolean(notice);
    const allowImages = media === 'images' || media === 'video';
    const allowVideo = media === 'video';

    const newPhotoPreviews = useMemo(
        () => newPhotos.map((file) => ({ file, url: previewUrl(file) })),
        [newPhotos],
    );
    const newVideoPreview = useMemo(() => (newVideo ? previewUrl(newVideo) : ''), [newVideo]);

    useEffect(() => () => {
        newPhotoPreviews.forEach((row) => {
            if (row.url) URL.revokeObjectURL(row.url);
        });
        if (newVideoPreview) URL.revokeObjectURL(newVideoPreview);
    }, [newPhotoPreviews, newVideoPreview]);

    useEffect(() => {
        // Keep the success message visible; parent data refreshes must not wipe it.
        if (successLockRef.current) {
            return;
        }
        const next = reviewSeed(initialReview || eligibility?.review);
        setRating(next.rating);
        setTitle(next.title);
        setBody(next.body);
        setExistingImages(next.imageUrls);
        setRemoveImages([]);
        setNewPhotos([]);
        setExistingVideo(next.videoUrl);
        setRemoveVideo(false);
        setNewVideo(null);
        setError('');
        setNotice('');
        setRatingError('');
    }, [
        initialReview?.id,
        initialReview?.rating,
        initialReview?.title,
        initialReview?.body,
        initialReview?.photo_url,
        initialReview?.video_url,
        JSON.stringify(initialReview?.image_urls || []),
        eligibility?.review?.id,
        eligibility?.review?.rating,
        eligibility?.review?.title,
        eligibility?.review?.body,
        eligibility?.review?.photo_url,
        eligibility?.review?.video_url,
        JSON.stringify(eligibility?.review?.image_urls || []),
    ]);

    const visibleExistingImages = existingImages.filter((url) => !removeImages.includes(url));
    const remainingSlots = Math.max(0, MAX_IMAGES - visibleExistingImages.length - newPhotos.length);

    const onPickImages = (e) => {
        const files = Array.from(e.target.files || []);
        e.target.value = '';
        if (!files.length) return;
        setNewPhotos((current) => {
            const room = Math.max(0, MAX_IMAGES - visibleExistingImages.length - current.length);
            return [...current, ...files.slice(0, room)];
        });
    };

    const submit = async (e) => {
        e.preventDefault();
        if (busy || done) return;
        setError('');
        setNotice('');
        if (!rating || rating < 1) {
            setRatingError(t('storefront.shop.rating_required', 'Please select a rating.'));
            return;
        }
        setRatingError('');
        if (guestReview && (!guestName.trim() || !guestEmail.trim())) {
            setError(t('storefront.pdp.review_guest_required', 'Enter your name and email to review as a guest.'));
            return;
        }
        const written = String(body || '').trim();
        if (writeText && written.length > textLimit) {
            setError(t('storefront.pdp.review_max_length', 'The review cannot be longer than {n} characters.')
                .replace('{n}', String(textLimit)));
            return;
        }
        const resolvedProductId = Number(productId)
            || Number(initialReview?.product_id)
            || Number(initialReview?.product?.id)
            || Number(eligibility?.review?.product_id)
            || Number(eligibility?.product_id)
            || 0;
        if (!resolvedProductId) {
            setError(t('storefront.pdp.review_missing_product', 'Could not save the review. Missing product.'));
            return;
        }
        setBusy(true);
        try {
            await csrf();
            const hasUploads = newPhotos.length > 0 || Boolean(newVideo);
            // Keep text + upload intent on the query string so saves survive multipart/body drops.
            const reviewTitle = writeText ? String(title || '').slice(0, 180) : '';
            const reviewBody = writeText ? String(body || '').slice(0, textLimit) : '';
            const query = {
                product_id: resolvedProductId,
                rating,
                title: reviewTitle,
                body: reviewBody,
                has_photos: newPhotos.length > 0 ? 1 : 0,
                has_video: newVideo ? 1 : 0,
                ...(guestReview ? { guest_name: guestName.trim(), guest_email: guestEmail.trim() } : {}),
            };
            let res;
            if (hasUploads) {
                const form = new FormData();
                form.append('product_id', String(resolvedProductId));
                form.append('rating', String(rating));
                form.append('title', reviewTitle);
                form.append('body', reviewBody);
                if (guestReview) {
                    form.append('guest_name', guestName.trim());
                    form.append('guest_email', guestEmail.trim());
                }
                form.append('_has_photos', newPhotos.length > 0 ? '1' : '0');
                form.append('_has_video', newVideo ? '1' : '0');
                newPhotos.forEach((file) => form.append('photos[]', file));
                removeImages.forEach((url) => form.append('remove_images[]', url));
                if (newVideo) form.append('video', newVideo);
                if (removeVideo) form.append('remove_video', '1');
                res = await api.post(guestReview ? `/catalog/product/${resolvedProductId}/reviews` : '/account/reviews', form, { params: query });
            } else {
                res = await api.post(guestReview ? `/catalog/product/${resolvedProductId}/reviews` : '/account/reviews', {
                    product_id: resolvedProductId,
                    rating,
                    title: reviewTitle,
                    body: reviewBody,
                    remove_images: removeImages,
                    remove_video: removeVideo ? 1 : 0,
                    ...(guestReview ? { guest_name: guestName.trim(), guest_email: guestEmail.trim() } : {}),
                }, { params: query });
            }
            const status = res.data?.review?.status;
            const successMsg = status === 'approved'
                ? t('storefront.pdp.review_published', 'Thanks! Your review is published.')
                : t('storefront.pdp.review_pending', 'Thanks! Your review was submitted and is waiting for approval.');
            successLockRef.current = true;
            setNotice(successMsg);
            const saved = res.data?.review || {};
            setExistingImages(Array.isArray(saved.image_urls) ? saved.image_urls : (saved.photo_url ? [saved.photo_url] : []));
            setExistingVideo(saved.video_url || null);
            setRemoveImages([]);
            setNewPhotos([]);
            setRemoveVideo(false);
            setNewVideo(null);
            // Scroll success into view (forms with media can be taller than the modal).
            window.requestAnimationFrame(() => {
                noticeRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
            });
            onSubmitted?.(saved);
        } catch (err) {
            const status = err.response?.status;
            const retryAfter = Number(err.response?.headers?.['retry-after'] || 0);
            const fieldErrors = err.response?.data?.errors || {};
            const fieldMsgs = Object.values(fieldErrors).flat().filter(Boolean);
            let msg = fieldMsgs.length
                ? fieldMsgs.join(' ')
                : (err.response?.data?.message
                    || t('storefront.shop.review_failed', 'Could not save the review.'));
            if (status === 429) {
                msg = retryAfter > 0
                    ? t('storefront.pdp.review_rate_limited_wait', 'Too many attempts. Please wait {n} seconds and try again.')
                        .replace('{n}', String(retryAfter))
                    : t('storefront.pdp.review_rate_limited', 'Too many attempts. Please wait a moment and try again.');
            }
            setError(Array.isArray(msg) ? msg[0] : msg);
        } finally {
            setBusy(false);
        }
    };

    if (blocked) {
        return (
            <p className="shop-pdp2-muted">
                {eligibility?.reason === 'verified_only'
                    ? t('storefront.pdp.review_verified_only', 'Only verified buyers can review this product.')
                    : t('storefront.pdp.reviews_disabled', 'Reviews are currently disabled.')}
            </p>
        );
    }

    return (
        <form className={`shop-review-form${compact ? ' is-compact' : ''}${hideTitle ? ' is-modal' : ''}`} onSubmit={submit} noValidate>
            {!hideTitle ? (
                <h3 className="shop-review-form__title">
                    {hasReview
                        ? t('storefront.pdp.update_review', 'Update your review')
                        : t('storefront.pdp.write_review', 'Write a review')}
                </h3>
            ) : null}
            <StarRatingInput
                label={t('storefront.shop.rating', 'Rating')}
                required
                value={rating}
                error={ratingError}
                onChange={setRating}
                disabled={done}
            />
            {ratingError ? <p className="shop-field-error">{ratingError}</p> : null}
            {guestReview ? (
                <>
                    <label className="shop-review-form__field">
                        <span>{t('storefront.pdp.review_guest_name', 'Name')}<span className="shop-req-mark" aria-hidden="true"> *</span></span>
                        <input className="shop-input" value={guestName} onChange={(e) => setGuestName(e.target.value)} required />
                    </label>
                    <label className="shop-review-form__field">
                        <span>{t('storefront.pdp.review_guest_email', 'Email')}<span className="shop-req-mark" aria-hidden="true"> *</span></span>
                        <input className="shop-input" type="email" value={guestEmail} onChange={(e) => setGuestEmail(e.target.value)} required />
                    </label>
                </>
            ) : null}
            {writeText ? (
                <>
                    <label className="shop-review-form__field">
                        <span>{t('storefront.pdp.review_title', 'Title')}</span>
                        <input
                            className="shop-input"
                            value={title}
                            maxLength={180}
                            disabled={done}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder={t('storefront.pdp.review_title_ph', 'Sum up your experience')}
                        />
                    </label>
                    <label className="shop-review-form__field">
                        <span>
                            {t('storefront.pdp.review_body', 'Review')}
                            {` (${t('storefront.pdp.max_chars', 'max {n}').replace('{n}', String(textLimit))})`}
                        </span>
                        <textarea
                            className="shop-input"
                            rows={4}
                            value={body}
                            maxLength={textLimit}
                            disabled={done}
                            onChange={(e) => setBody(e.target.value)}
                            placeholder={t('storefront.pdp.review_body_ph', 'What did you like or dislike?')}
                        />
                        <span className="shop-review-form__hint">
                            {t('storefront.pdp.review_char_count', '{count} / {limit}')
                                .replace('{count}', String(String(body || '').length))
                                .replace('{limit}', String(textLimit))}
                        </span>
                    </label>
                </>
            ) : (
                <p className="shop-review-form__hint">
                    {t('storefront.pdp.review_rating_only', 'Written reviews are turned off. Leave a star rating.')}
                </p>
            )}

            {allowImages ? (
                <div className="shop-review-form__field">
                    <span>{t('storefront.pdp.review_images', 'Images')} ({visibleExistingImages.length + newPhotos.length}/{MAX_IMAGES})</span>
                    <div className="shop-review-media-grid">
                        {visibleExistingImages.map((url) => (
                            <div key={url} className="shop-review-media-chip">
                                <img src={webPath(url)} alt="" />
                                {!done ? (
                                    <button
                                        type="button"
                                        className="shop-review-media-chip__remove"
                                        onClick={() => setRemoveImages((list) => [...list, url])}
                                        aria-label={t('storefront.common.remove', 'Remove')}
                                    >
                                        ×
                                    </button>
                                ) : null}
                            </div>
                        ))}
                        {newPhotoPreviews.map((row, index) => (
                            <div key={`new-${index}`} className="shop-review-media-chip">
                                <img src={row.url} alt="" />
                                {!done ? (
                                    <button
                                        type="button"
                                        className="shop-review-media-chip__remove"
                                        onClick={() => setNewPhotos((list) => list.filter((_, i) => i !== index))}
                                        aria-label={t('storefront.common.remove', 'Remove')}
                                    >
                                        ×
                                    </button>
                                ) : null}
                            </div>
                        ))}
                    </div>
                    {!done ? (
                        <>
                            <input
                                ref={imageInputRef}
                                className="shop-input"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/gif"
                                multiple
                                hidden
                                onChange={onPickImages}
                            />
                            <button
                                type="button"
                                className="shop-btn shop-btn-secondary"
                                disabled={remainingSlots <= 0}
                                onClick={() => imageInputRef.current?.click()}
                            >
                                {t('storefront.pdp.add_images', 'Add images')}
                            </button>
                            {remainingSlots <= 0 ? (
                                <p className="shop-review-form__hint">
                                    {t('storefront.pdp.max_images', 'Maximum of {n} images.').replace('{n}', String(MAX_IMAGES))}
                                </p>
                            ) : null}
                        </>
                    ) : null}
                </div>
            ) : null}

            {allowVideo ? (
                <div className="shop-review-form__field">
                    <span>{t('storefront.pdp.review_video', 'Video (optional)')}</span>
                    {(newVideo || (existingVideo && !removeVideo)) ? (
                        <div className="shop-review-media-chip is-video">
                            <video
                                src={newVideoPreview || (existingVideo ? webPath(existingVideo) : '')}
                                controls
                                playsInline
                            />
                            {!done ? (
                                <button
                                    type="button"
                                    className="shop-review-media-chip__remove"
                                    onClick={() => {
                                        if (newVideo) {
                                            setNewVideo(null);
                                        } else {
                                            setRemoveVideo(true);
                                        }
                                    }}
                                    aria-label={t('storefront.common.remove', 'Remove')}
                                >
                                    ×
                                </button>
                            ) : null}
                        </div>
                    ) : null}
                    {!done ? (
                        <>
                            <input
                                ref={videoInputRef}
                                className="shop-input"
                                type="file"
                                accept="video/mp4,video/webm"
                                hidden
                                onChange={(e) => {
                                    setNewVideo(e.target.files?.[0] || null);
                                    setRemoveVideo(false);
                                    e.target.value = '';
                                }}
                            />
                            <button
                                type="button"
                                className="shop-btn shop-btn-secondary"
                                onClick={() => videoInputRef.current?.click()}
                            >
                                {(newVideo || (existingVideo && !removeVideo))
                                    ? t('storefront.pdp.replace_video', 'Replace video')
                                    : t('storefront.pdp.add_video', 'Add video')}
                            </button>
                        </>
                    ) : null}
                </div>
            ) : null}

            {error ? <p className="shop-field-error" role="alert">{error}</p> : null}
            {notice ? (
                <p ref={noticeRef} className="shop-review-form__notice" role="status">
                    {notice}
                </p>
            ) : null}
            <button type="submit" className="shop-btn shop-btn-primary" disabled={busy || done}>
                {busy
                    ? t('storefront.common.saving', 'Saving…')
                    : done
                        ? t('storefront.common.saved', 'Saved')
                        : (submitLabel
                            || (hasReview
                                ? t('storefront.pdp.save_review', 'Save review')
                                : t('storefront.pdp.submit_review', 'Submit review')))}
            </button>
            {!hideAccountLink ? (
                <p className="shop-review-form__hint">
                    <Link to="/account/reviews">{t('storefront.account.reviews', 'My reviews')}</Link>
                </p>
            ) : null}
        </form>
    );
}

export function ProductReviewModal({
    open,
    onClose,
    productId,
    shop,
    eligibility,
    initialReview = null,
    onSubmitted,
    closeAfterMs = 2000,
}) {
    const closeTimerRef = useRef(null);
    const pendingReviewRef = useRef(null);
    const onCloseRef = useRef(onClose);
    const onSubmittedRef = useRef(onSubmitted);
    onCloseRef.current = onClose;
    onSubmittedRef.current = onSubmitted;

    const finishAndClose = () => {
        const saved = pendingReviewRef.current;
        pendingReviewRef.current = null;
        if (closeTimerRef.current) {
            window.clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
        // Refresh parent lists only after the success message has been shown (or user closed).
        onSubmittedRef.current?.(saved);
        onCloseRef.current?.();
    };

    const requestClose = () => {
        if (pendingReviewRef.current) {
            finishAndClose();
            return;
        }
        onCloseRef.current?.();
    };

    useEffect(() => () => {
        if (closeTimerRef.current) {
            window.clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
    }, []);

    useEffect(() => {
        if (!open && closeTimerRef.current) {
            window.clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
        if (!open) {
            pendingReviewRef.current = null;
        }
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                requestClose();
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [open]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    const hasReview = Boolean(eligibility?.has_review || initialReview?.id || eligibility?.review?.id);
    const title = hasReview
        ? t('storefront.pdp.update_review_btn', 'Update Review')
        : t('storefront.pdp.write_review_btn', 'Write a Review');

    const handleSubmitted = (review) => {
        pendingReviewRef.current = review || null;
        if (closeTimerRef.current) {
            window.clearTimeout(closeTimerRef.current);
        }
        const waitMs = Math.max(2000, Number(closeAfterMs) || 2000);
        closeTimerRef.current = window.setTimeout(() => {
            closeTimerRef.current = null;
            finishAndClose();
        }, waitMs);
    };

    return createPortal(
        <div className="cx-modal shop-review-modal" role="dialog" aria-modal="true" aria-label={title}>
            <button type="button" className="cx-modal-backdrop" aria-label={t('storefront.common.close', 'Close')} onClick={requestClose} />
            <div className="cx-modal-panel shop-review-modal__panel">
                <header className="cx-modal-head">
                    <h2>{title}</h2>
                    <button type="button" className="cx-modal-close" onClick={requestClose} aria-label={t('storefront.common.close', 'Close')}>
                        ×
                    </button>
                </header>
                <div className="cx-modal-body">
                    <ProductReviewForm
                        key={`review-form-${productId}-open`}
                        productId={productId}
                        shop={shop}
                        eligibility={eligibility}
                        initialReview={initialReview || eligibility?.review || null}
                        hideTitle
                        hideAccountLink
                        onSubmitted={handleSubmitted}
                    />
                </div>
            </div>
        </div>,
        document.body,
    );
}
