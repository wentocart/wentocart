import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { Icon } from './Icons.jsx';
import { useStore } from '../state/StoreState.jsx';

export function formatVoteCount(value) {
    const n = Math.max(0, Number(value) || 0);
    if (n >= 1000000) {
        const compact = n >= 10000000 ? Math.round(n / 1000000) : (Math.round(n / 100000) / 10);
        return `${compact}M`.replace('.0M', 'M');
    }
    if (n >= 1000) {
        const compact = n >= 10000 ? Math.round(n / 1000) : (Math.round(n / 100) / 10);
        return `${compact}K`.replace('.0K', 'K');
    }

    return String(n);
}

export function formatVerifiedDate(value) {
    if (!value) {
        return '';
    }
    const raw = String(value).trim();
    const date = new Date(raw.includes('T') || /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw : raw);
    if (Number.isNaN(date.getTime())) {
        return raw;
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function ReviewVerified({ review }) {
    if (!review?.is_verified_purchase) {
        return null;
    }

    return (
        <span className="shop-review-verified">
            <Icon name="checkCircle" className="shop-review-verified__icon" />
            <span>{t('storefront.pdp.verified_purchase', 'Verified Purchase')}</span>
        </span>
    );
}

export default function ReviewSocial({
    review,
    onVoted,
    allowVote = true,
    showDate = true,
}) {
    const store = useStore();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const helpful = Number(review?.helpful_count) || 0;
    const notHelpful = Number(review?.not_helpful_count) || 0;
    const myVote = review?.my_vote;
    const loggedIn = Boolean(store.customer?.id);
    const canVote = allowVote && review?.can_vote !== false && loggedIn;
    const verified = Boolean(review?.is_verified_purchase);
    const when = showDate ? formatVerifiedDate(review?.created_at) : '';

    const vote = async (helpfulVote) => {
        if (busy || !review?.id) {
            return;
        }
        if (!loggedIn) {
            setError(t('storefront.pdp.review_vote_login', 'Sign in to vote on this review.'));
            return;
        }
        if (!canVote) {
            return;
        }
        setBusy(true);
        setError('');
        try {
            await csrf();
            const res = await api.post(`/account/reviews/${review.id}/vote`, { helpful: helpfulVote });
            onVoted?.(review.id, {
                helpful_count: res.data?.helpful_count ?? helpful,
                not_helpful_count: res.data?.not_helpful_count ?? notHelpful,
                my_vote: res.data?.my_vote ?? helpfulVote,
                can_vote: res.data?.can_vote ?? true,
            });
        } catch (err) {
            const msg = err.response?.data?.errors?.review?.[0]
                || err.response?.data?.message
                || t('storefront.pdp.review_vote_failed', 'Could not save your vote.');
            setError(Array.isArray(msg) ? msg[0] : msg);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="shop-review-social">
            <div className="shop-review-social__votes">
                <button
                    type="button"
                    className={`shop-review-vote${myVote === true ? ' is-on' : ''}`}
                    disabled={busy || (loggedIn && !canVote)}
                    onClick={() => vote(true)}
                >
                    <Icon name="thumbsUp" className="shop-review-vote__icon" />
                    <span>
                        {t('storefront.pdp.helpful', 'Helpful')}
                        {' '}
                        {formatVoteCount(helpful)}
                    </span>
                </button>
                <button
                    type="button"
                    className={`shop-review-vote${myVote === false ? ' is-on' : ''}`}
                    disabled={busy || (loggedIn && !canVote)}
                    onClick={() => vote(false)}
                >
                    <Icon name="thumbsDown" className="shop-review-vote__icon" />
                    <span>
                        {t('storefront.pdp.not_helpful', 'Not Helpful')}
                        {' '}
                        {formatVoteCount(notHelpful)}
                    </span>
                </button>
            </div>
            {(verified || when) ? (
                <div className="shop-review-social__meta">
                    {verified ? <ReviewVerified review={review} /> : null}
                    {verified && when ? (
                        <span className="shop-review-social__sep" aria-hidden="true">·</span>
                    ) : null}
                    {when ? <span className="shop-review__date">{when}</span> : null}
                </div>
            ) : null}
            {error ? (
                <p className="shop-review-social__error" role="alert">
                    {error}
                    {!loggedIn ? (
                        <>
                            {' '}
                            <Link to="/login">{t('storefront.account.sign_in', 'Sign in')}</Link>
                        </>
                    ) : null}
                </p>
            ) : null}
        </div>
    );
}
