import { useEffect, useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import { Field } from './SellerHome.jsx';
import { SaveBtnLabel } from './sellerUi.jsx';

export default function SellerMessages() {
    const [rows, setRows] = useState(null);
    const [open, setOpen] = useState(null);
    const [reply, setReply] = useState('');
    const [error, setError] = useState('');
    const load = () => api.get('/account/seller/messages').then((r) => setRows(r.data.messages.data || [])).catch(() => setRows([]));
    useEffect(() => { load(); }, []);

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.messages', 'Seller Messages')}</h2>
            {rows.length === 0 ? (
                <div className="mv-body"><EmptyState title={t('storefront.seller.no_messages', 'No customer messages yet.')} body={t('storefront.seller.no_messages_body', 'Shop visitors can write from your public shop page.')} /></div>
            ) : (
                <ul className="mv-list">
                    {rows.map((row) => (
                        <li key={row.id} className="shop-card p-4">
                            <button type="button" className="w-full text-left" onClick={() => { setOpen(open === row.id ? null : row.id); setReply(row.reply_body || ''); setError(''); }}>
                                <p className="font-semibold">{row.name} · {row.email}</p>
                                <p className="mt-1 line-clamp-2 text-sm text-neutral-600">{row.body}</p>
                                <p className="mt-2 text-xs mv-muted">{row.reply_body ? t('storefront.seller.replied', 'Replied') : t('storefront.seller.open_message', 'Open')}</p>
                            </button>
                            {open === row.id ? (
                                <form
                                    className="mt-4 space-y-3 border-t pt-4"
                                    onSubmit={async (e) => {
                                        e.preventDefault();
                                        setError('');
                                        try {
                                            await csrf();
                                            await api.patch(`/account/seller/messages/${row.id}`, { reply_body: reply });
                                            load();
                                        } catch (err) {
                                            setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                                        }
                                    }}
                                >
                                    <p className="whitespace-pre-wrap text-sm">{row.body}</p>
                                    <Field label={t('storefront.seller.reply', 'Reply')}>
                                        <textarea className="shop-input" rows={4} value={reply} onChange={(e) => setReply(e.target.value)} required />
                                    </Field>
                                    <FormNotice tone="error">{error}</FormNotice>
                                    <button className="shop-btn shop-btn-primary">
                                        <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                                    </button>
                                </form>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
