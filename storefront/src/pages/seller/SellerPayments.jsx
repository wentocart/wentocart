import { useEffect, useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch } from '../../components/Form.jsx';
import { Field } from './SellerHome.jsx';
import { SellerDeleteButton, SellerEditButton, SaveBtnLabel } from './sellerUi.jsx';

const blank = {
    type: 'bank',
    name: '',
    account_name: '',
    account_number: '',
    bank_name: '',
    routing_number: '',
    paypal_email: '',
    notes: '',
    is_default: false,
    is_enabled: true,
};

export default function SellerPayments() {
    const [rows, setRows] = useState(null);
    const [form, setForm] = useState(blank);
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState('');
    const load = () => api.get('/account/seller/payments').then((r) => setRows(r.data.methods || [])).catch(() => setRows([]));
    useEffect(() => { load(); }, []);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="mv-split">
            <div>
                <h2 className="mv-page-title">{t('storefront.seller.payments', 'Seller Payment Method')}</h2>
                <p className="mv-page-help">{t('storefront.seller.payments_help', 'How the store should pay you. These are payout details, not customer checkout methods.')}</p>
                {rows.length === 0 ? (
                    <div className="mv-body"><EmptyState title={t('storefront.seller.no_payments', 'No payout methods yet.')} /></div>
                ) : (
                    <ul className="mv-list">
                        {rows.map((row) => (
                            <li key={row.id} className="shop-card mv-row p-4">
                                <div>
                                    <p className="font-semibold">{row.name} {row.is_default ? `· ${t('storefront.seller.default', 'Default')}` : ''}</p>
                                    <p className="mv-muted-sm">{row.type} · {row.bank_name || row.paypal_email || row.account_name || '—'}</p>
                                </div>
                                <div className="mv-row-actions">
                                    <SellerEditButton onClick={() => { setEditing(row.id); setForm({ ...blank, ...row }); }} />
                                    <SellerDeleteButton onClick={async () => { await csrf(); await api.delete(`/account/seller/payments/${row.id}`); load(); }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <form
                className="shop-card mv-panel-form p-5"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    try {
                        await csrf();
                        if (editing) {
                            await api.patch(`/account/seller/payments/${editing}`, form);
                        } else {
                            await api.post('/account/seller/payments', form);
                        }
                        setForm(blank);
                        setEditing(null);
                        load();
                    } catch (err) {
                        setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                    }
                }}
            >
                <h3 className="font-semibold">{editing ? t('storefront.seller.edit', 'Edit') : t('storefront.seller.add_payment', 'Add payout method')}</h3>
                <Field label={t('storefront.seller.type', 'Type')}>
                    <select className="shop-input" value={form.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="bank">bank</option>
                        <option value="paypal">paypal</option>
                        <option value="upi">upi</option>
                        <option value="other">other</option>
                    </select>
                </Field>
                <Field label={t('storefront.seller.name', 'Name')}><input className="shop-input" value={form.name} onChange={(e) => set('name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.account_name', 'Account name')}><input className="shop-input" value={form.account_name} onChange={(e) => set('account_name', e.target.value)} /></Field>
                <Field label={t('storefront.seller.account_number', 'Account number')}><input className="shop-input" value={form.account_number} onChange={(e) => set('account_number', e.target.value)} /></Field>
                <Field label={t('storefront.seller.bank_name', 'Bank name')}><input className="shop-input" value={form.bank_name} onChange={(e) => set('bank_name', e.target.value)} /></Field>
                <Field label={t('storefront.seller.paypal_email', 'PayPal email')}><input className="shop-input" value={form.paypal_email} onChange={(e) => set('paypal_email', e.target.value)} /></Field>
                <FormSwitch checked={!!form.is_default} onChange={(on) => set('is_default', on)} label={t('storefront.seller.default', 'Default')} />
                <FormNotice tone="error">{error}</FormNotice>
                <button className="shop-btn shop-btn-primary">
                    <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                </button>
            </form>
        </div>
    );
}
