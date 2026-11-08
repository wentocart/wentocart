import { useCallback, useEffect, useState } from 'react';
import api from '../../api.js';
import { t } from '../../i18n.js';
import { PageSkeleton } from '../../components/Feedback.jsx';
import Stars from '../../components/Stars.jsx';
import SellerListSettings from '../../components/SellerListSettings.jsx';
import { ListColHeader, ListPagination } from './sellerListShared.jsx';
import { formatRating } from '../../lib/commerce.js';

function reviewStatusLabel(status) {
    const labels = {
        pending: t('storefront.seller.review_pending', 'Pending'),
        approved: t('storefront.seller.review_approved', 'Approved'),
        rejected: t('storefront.seller.review_rejected', 'Rejected'),
    };
    return labels[status] || String(status || '—').replace(/_/g, ' ');
}

function reviewStatusClass(status) {
    const map = {
        pending: 'is-pending',
        approved: 'is-success',
        rejected: 'is-rejected',
    };
    return map[status] || 'is-inactive';
}

function customerInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
        return `${parts[0].charAt(0)}${parts[1].charAt(0)}`.toUpperCase();
    }
    return String(name || '?').trim().charAt(0).toUpperCase() || '?';
}

export default function SellerReviews() {
    const [paginator, setPaginator] = useState(null);
    const [summary, setSummary] = useState(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        return api.get('/account/seller/reviews', { params: { page } })
            .then((r) => {
                setPaginator(r.data.reviews || null);
                setSummary(r.data.summary || null);
            })
            .catch(() => {
                setPaginator({ data: [], total: 0, current_page: 1, last_page: 1 });
                setSummary(null);
            })
            .finally(() => setLoading(false));
    }, [page]);

    useEffect(() => { load(); }, [load]);

    if (!paginator && loading) {
        return <PageSkeleton />;
    }

    const rows = paginator?.data || [];
    const colSpan = 7;

    return (
        <div>
            <div className="mv-toolbar">
                <h2 className="mv-page-title">
                    {t('storefront.seller.reviews', 'Shop reviews')}
                    {paginator?.total != null ? (
                        <span className="mv-page-title-count">{` (${Number(paginator.total).toLocaleString()})`}</span>
                    ) : null}
                </h2>
                <div className="mv-toolbar-actions">
                    <SellerListSettings onRefresh={load} />
                </div>
            </div>

            {summary ? (
                <div className="mv-review-summary mt-5">
                    <div className="shop-card mv-review-summary-card">
                        <div className="mv-review-summary-score">
                            <span className="mv-review-summary-value">
                                {summary.average != null ? (formatRating(summary.average) ?? '—') : '—'}
                            </span>
                            <Stars value={summary.average > 0 ? summary.average : null} compact />
                        </div>
                        <div className="mv-review-summary-meta">
                            <p className="mv-review-summary-count">
                                {summary.count} {t('storefront.shop.reviews', 'reviews')}
                            </p>
                            <p className="mv-muted-sm">
                                {t('storefront.seller.approved_only_public', 'approved reviews are public')}
                            </p>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="mv-list-panel mt-5">
                <div className="mv-list-table-wrap">
                    <table className="mv-list-table">
                        <thead>
                            <tr>
                                <th className="mv-list-th-id">
                                    <ListColHeader label={t('storefront.seller.col_id', 'ID')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.col_customer', 'Customer')} />
                                </th>
                                <th className="mv-list-th-center">
                                    <ListColHeader label={t('storefront.seller.col_rating', 'Rating')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.col_review', 'Review')} />
                                </th>
                                <th className="mv-list-th-status">
                                    <ListColHeader label={t('storefront.seller.col_status', 'Status')} />
                                </th>
                                <th className="mv-list-th-center">
                                    <ListColHeader label={t('storefront.seller.col_verified', 'Verified')} />
                                </th>
                                <th>
                                    <ListColHeader label={t('storefront.seller.col_date', 'Date')} />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={colSpan}>
                                        <div className="mv-list-empty">
                                            <p>{t('storefront.seller.no_reviews', 'No reviews yet.')}</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : rows.map((row) => {
                                const created = row.created_at ? new Date(row.created_at) : null;

                                return (
                                    <tr key={row.id}>
                                        <td className="mv-list-td-id">{row.id}</td>
                                        <td>
                                            <div className="mv-list-row">
                                                <span className="mv-list-avatar" aria-hidden="true">
                                                    {customerInitials(row.customer)}
                                                </span>
                                                <div className="mv-list-cell-stack">
                                                    <div className="mv-list-cell-strong">{row.customer || '—'}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="mv-list-td-center">
                                            <div className="mv-list-stars">
                                                <Stars value={row.rating} compact />
                                                <span className="mv-list-stars-value">{row.rating}/5</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="mv-list-cell-stack mv-list-review-cell">
                                                {row.title ? (
                                                    <div className="mv-list-cell-strong">{row.title}</div>
                                                ) : null}
                                                {row.body ? (
                                                    <div className="mv-list-cell-sub">{row.body}</div>
                                                ) : (
                                                    !row.title ? <span className="mv-muted">—</span> : null
                                                )}
                                            </div>
                                        </td>
                                        <td className="mv-list-td-status">
                                            <span className={`mv-list-status ${reviewStatusClass(row.status)}`}>
                                                {reviewStatusLabel(row.status)}
                                            </span>
                                        </td>
                                        <td className="mv-list-td-center">
                                            {row.verified_purchase ? (
                                                <span className="mv-list-badge is-success">
                                                    {t('storefront.seller.verified', 'Yes')}
                                                </span>
                                            ) : (
                                                <span className="mv-list-badge is-inactive">
                                                    {t('storefront.seller.not_verified', 'No')}
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            {created ? (
                                                <div className="mv-list-cell-stack">
                                                    <div className="mv-list-cell-strong">
                                                        {created.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                                                    </div>
                                                    <div className="mv-list-cell-sub">
                                                        {created.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}
                                                    </div>
                                                </div>
                                            ) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="mv-list-footer">
                    <span>
                        {paginator?.total != null
                            ? `${Number(paginator.total).toLocaleString()} ${t('storefront.shop.reviews', 'reviews')}`
                            : null}
                    </span>
                    <ListPagination paginator={paginator} onPage={setPage} />
                </div>
            </div>
        </div>
    );
}
