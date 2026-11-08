import { useEffect, useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { formatMoney } from '../../lib/format.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';

export default function SellerReturns() {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const load = () => api.get('/account/seller/returns')
        .then((r) => setRows(r.data.returns?.data || []))
        .catch(() => setRows([]));

    useEffect(() => { load(); }, []);

    const act = async (id, action) => {
        setError('');
        setNotice('');
        try {
            await csrf();
            await api.post(`/account/seller/returns/${id}/${action}`);
            setNotice(t('storefront.seller.return_updated', 'Return updated.'));
            await load();
        } catch (err) {
            setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {})[0]?.[0] || t('storefront.seller.save_failed', 'Could not save.'));
        }
    };

    if (!rows) {
        return <PageSkeleton />;
    }
    if (rows.length === 0) {
        return <EmptyState title={t('storefront.seller.no_returns', 'No returns yet.')} body={t('storefront.seller.no_returns_body', 'Customer return requests for your orders will show here.')} />;
    }

    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.returns', 'Returns')}</h2>
            <FormNotice tone="error">{error}</FormNotice>
            <FormNotice tone="success">{notice}</FormNotice>
            <div className="mv-list">
                {rows.map((row) => (
                    <div key={row.id} className="shop-card space-y-3 p-4">
                        <div className="mv-toolbar">
                            <div>
                                <p className="font-semibold">{row.number}</p>
                                <p className="mv-muted-sm capitalize">{row.status} · {formatMoney(row.refund_total)}</p>
                            </div>
                            <div className="mv-toolbar-actions">
                                {row.status === 'requested' ? (
                                    <>
                                        <button type="button" className="shop-btn shop-btn-primary" onClick={() => act(row.id, 'approve')}>{t('storefront.seller.approve', 'Approve')}</button>
                                        <button type="button" className="shop-btn shop-btn-ghost" onClick={() => act(row.id, 'reject')}>{t('storefront.seller.reject', 'Reject')}</button>
                                    </>
                                ) : null}
                                {row.status === 'approved' ? (
                                    <>
                                        <button type="button" className="shop-btn shop-btn-ghost" onClick={() => act(row.id, 'receive')}>{t('storefront.seller.mark_received', 'Mark received')}</button>
                                        <button type="button" className="shop-btn shop-btn-primary" onClick={() => act(row.id, 'refund')}>{t('storefront.seller.refund', 'Refund')}</button>
                                    </>
                                ) : null}
                                {row.status === 'received' ? (
                                    <button type="button" className="shop-btn shop-btn-primary" onClick={() => act(row.id, 'refund')}>{t('storefront.seller.refund', 'Refund')}</button>
                                ) : null}
                            </div>
                        </div>
                        <ul className="text-sm text-neutral-600">
                            {(row.items || []).map((item) => (
                                <li key={item.id}>#{item.seller_order_item_id} ×{item.qty} · {formatMoney(item.line_total)}</li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
