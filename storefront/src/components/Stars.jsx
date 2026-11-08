import { formatRating } from '../lib/commerce.js';
import { t } from '../i18n.js';

/**
 * Product rating stars from real review averages only.
 * Pass null/undefined value when the product has no reviews — do not invent scores.
 */
export default function Stars({ value = null, count = null, compact = false, emptyLabel = null }) {
    if (value === null || value === undefined || value === '') {
        if (compact) {
            return null;
        }
        return (
            <div className={`cx-rating flex items-center ${compact ? 'is-compact' : ''}`}>
                <span className="text-sm text-[var(--shop-muted)]">
                    {emptyLabel || t('storefront.pdp.no_reviews_yet', 'No reviews yet')}
                </span>
            </div>
        );
    }

    const rating = Number(value);
    if (! Number.isFinite(rating) || rating <= 0) {
        if (compact) {
            return null;
        }
        return (
            <div className={`cx-rating flex items-center ${compact ? 'is-compact' : ''}`}>
                <span className="text-sm text-[var(--shop-muted)]">
                    {emptyLabel || t('storefront.pdp.no_reviews_yet', 'No reviews yet')}
                </span>
            </div>
        );
    }

    const label = formatRating(rating);
    const reviewCount = count != null ? Number(count) : null;

    return (
        <div className={`cx-rating flex items-center text-amber-400 ${compact ? 'is-compact' : ''}`}>
            {Array.from({ length: 5 }).map((_, i) => (
                <span key={i} className={i < Math.round(rating) ? 'text-amber-400' : 'text-[var(--shop-border,#e5e7eb)]'}>
                    ★
                </span>
            ))}
            {compact ? null : (
                <span className="text-sm text-[var(--shop-muted)]">
                    {label || String(rating)}
                    {reviewCount > 0 ? ` (${reviewCount.toLocaleString()} reviews)` : ''}
                </span>
            )}
        </div>
    );
}
