import { useEffect, useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { formatMoney } from '../../lib/format.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice, FormSwitch } from '../../components/Form.jsx';
import { Field } from './SellerHome.jsx';
import { SellerDeleteButton, SellerEditButton, SaveBtnLabel } from './sellerUi.jsx';

const blankShip = { name: '', description: '', type: 'flat', price: '0', is_enabled: true };

export default function SellerShipping() {
    const [rows, setRows] = useState(null);
    const [form, setForm] = useState(blankShip);
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState('');
    const load = () => api.get('/account/seller/shipping').then((r) => setRows(r.data.methods || [])).catch(() => setRows([]));
    useEffect(() => { load(); }, []);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="mv-split">
            <div>
                <h2 className="mv-page-title">{t('storefront.seller.shipping', 'Seller Shipping')}</h2>
                {rows.length === 0 ? (
                    <div className="mv-body"><EmptyState title={t('storefront.seller.no_shipping', 'No shipping methods yet.')} /></div>
                ) : (
                    <ul className="mv-list">
                        {rows.map((row) => (
                            <li key={row.id} className="shop-card mv-row p-4">
                                <div>
                                    <p className="font-semibold">{row.name}</p>
                                    <p className="mv-muted-sm">{row.type} · {formatMoney(row.price)}</p>
                                </div>
                                <div className="mv-row-actions">
                                    <SellerEditButton onClick={() => { setEditing(row.id); setForm({ ...row, price: String(row.price) }); }} />
                                    <SellerDeleteButton onClick={async () => { await csrf(); await api.delete(`/account/seller/shipping/${row.id}`); load(); }} />
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
                            await api.patch(`/account/seller/shipping/${editing}`, form);
                        } else {
                            await api.post('/account/seller/shipping', form);
                        }
                        setForm(blankShip);
                        setEditing(null);
                        load();
                    } catch (err) {
                        setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                    }
                }}
            >
                <h3 className="font-semibold">{editing ? t('storefront.seller.edit', 'Edit') : t('storefront.seller.add_shipping', 'Add shipping method')}</h3>
                <Field label={t('storefront.seller.name', 'Name')}><input className="shop-input" value={form.name} onChange={(e) => set('name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.price', 'Price')}><input type="number" min="0" step="0.01" className="shop-input" value={form.price} onChange={(e) => set('price', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.type', 'Type')}>
                    <select className="shop-input" value={form.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="flat">flat</option>
                        <option value="free">free</option>
                        <option value="local_pickup">local_pickup</option>
                    </select>
                </Field>
                <FormSwitch checked={!!form.is_enabled} onChange={(on) => set('is_enabled', on)} label={t('storefront.seller.enabled', 'Enabled')} />
                <FormNotice tone="error">{error}</FormNotice>
                <button className="shop-btn shop-btn-primary">
                    <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                </button>
            </form>
        </div>
    );
}
