import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import Stars from '../components/Stars.jsx';
import { ProductReviewModal } from '../components/ProductReviewForm.jsx';
import ReviewMediaRow from '../components/ReviewMediaRow.jsx';
import ReviewSocial from '../components/ReviewSocial.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { reviewsEnabled } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';

export default function Reviews() {
    const store = useStore();
    const [params, setParams] = useSearchParams();
    const productId = Number(params.get('product_id') || 0) || null;
    const [rows, setRows] = useState(null);
    const [eligibility, setEligibility] = useState(null);
    const [modalProductId, setModalProductId] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);

    const reload = () => {
        api.get('/account/reviews').then((r) => setRows(r.data.reviews || [])).catch(() => setRows([]));
    };

    useEffect(() => {
        if (!reviewsEnabled(store.shop)) {
            setRows([]);
            return;
        }
        reload();
    }, [store.shop]);

    useEffect(() => {
        if (!modalProductId) {
            setEligibility(null);
            return;
        }
        api.get('/account/reviews/eligibility', { params: { product_id: modalProductId } })
            .then((r) => setEligibility(r.data.eligibility || null))
            .catch(() => setEligibility(null));
    }, [modalProductId]);

    // Deep link: /account/reviews?product_id=34 opens the update modal.
    useEffect(() => {
        if (!productId || rows === null) {
            return;
        }
        setModalProductId(productId);
        setModalOpen(true);
    }, [productId, rows]);

    const modalReview = useMemo(
        () => (rows || []).find((row) => Number(row.product?.id) === Number(modalProductId)) || null,
        [rows, modalProductId],
    );

    const closeModal = () => {
        setModalOpen(false);
        setModalProductId(null);
        if (params.has('product_id')) {
            const next = new URLSearchParams(params);
            next.delete('product_id');
            setParams(next, { replace: true });
        }
    };

    const openUpdateModal = (reviewProductId) => {
        setModalProductId(Number(reviewProductId) || null);
        setModalOpen(true);
        if (reviewProductId) {
            const next = new URLSearchParams(params);
            next.set('product_id', String(reviewProductId));
            setParams(next, { replace: true });
        }
    };

    if (!reviewsEnabled(store.shop)) {
        return <EmptyState title={t('storefront.pdp.reviews_disabled', 'Reviews are currently disabled.')} />;
    }

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <h1 className="text-3xl font-extrabold">{t('storefront.account.reviews', 'Reviews')}</h1>
            <p className="mt-3 text-sm text-neutral-600">
                {t('storefront.account.reviews_hint', 'Open a product page and use Write a review, or pass ?product_id= on this URL.')}
            </p>

            {!rows.length ? (
                <div className="mt-8">
                    <EmptyState title={t('storefront.account.no_reviews', 'You have not reviewed a product yet.')} action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} />
                </div>
            ) : (
                <div className="mt-6 space-y-3">
                    {rows.map((review) => {
                        const lastUpdated = review.updated_at || review.created_at;
                        const statusLabel = review.status === 'approved' || review.is_approved
                            ? t('storefront.account.published', 'Published')
                            : review.status === 'rejected'
                                ? t('storefront.account.rejected_review', 'Not published')
                                : t('storefront.account.pending_review', 'Pending approval');
                        const imageUrls = Array.isArray(review.image_urls) && review.image_urls.length
                            ? review.image_urls
                            : (review.photo_url ? [review.photo_url] : []);
                        const videoUrl = review.video_url || null;
                        const hasMedia = imageUrls.length > 0 || Boolean(videoUrl);
                        return (
                            <article
                                key={review.id}
                                className={`shop-card shop-account-review p-5${hasMedia ? ' has-media' : ''}`}
                            >
                                <div className="shop-account-review__layout">
                                    <div className="shop-account-review__content">
                                        <Link to={review.product?.url || '/catalog'} className="shop-account-review__product">
                                            {review.product?.name || t('storefront.account.product', 'Product')}
                                        </Link>
                                        <div className="shop-account-review__rating">
                                            <Stars value={review.rating} compact />
                                        </div>
                                        {review.title ? <h2 className="shop-account-review__title">{review.title}</h2> : null}
                                        {review.body ? <p className="shop-account-review__body">{review.body}</p> : null}
                                        {review.rejected_reason ? (
                                            <p className="shop-account-review__rejected">{review.rejected_reason}</p>
                                        ) : null}
                                        {review.admin_reply ? (
                                            <p className="shop-account-review__reply">
                                                {t('storefront.product.shop_reply', 'Reply from the shop')}: {review.admin_reply}
                                            </p>
                                        ) : null}
                                        {hasMedia ? (
                                            <ReviewMediaRow imageUrls={imageUrls} videoUrl={videoUrl} />
                                        ) : null}
                                        <ReviewSocial review={review} allowVote={false} />
                                    </div>
                                    <div className="shop-account-review__aside">
                                        <div className="shop-account-review__meta">
                                            <div>{statusLabel}</div>
                                            {lastUpdated ? (
                                                <div>
                                                    {t('storefront.account.review_last_updated', 'Last updated')}: {lastUpdated}
                                                </div>
                                            ) : null}
                                        </div>
                                        {review.product?.id ? (
                                            <button
                                                type="button"
                                                className="shop-btn shop-btn-secondary shop-account-review__update"
                                                onClick={() => openUpdateModal(review.product.id)}
                                            >
                                                {t('storefront.account.update_review', 'Update review')}
                                            </button>
                                        ) : null}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}

            <ProductReviewModal
                open={modalOpen && Boolean(modalProductId)}
                onClose={closeModal}
                productId={modalProductId}
                shop={store.shop}
                eligibility={eligibility || (modalReview ? { allowed: true, has_review: true, review: modalReview } : null)}
                initialReview={modalReview || eligibility?.review || null}
                onSubmitted={() => {
                    reload();
                }}
            />
        </div>
    );
}
