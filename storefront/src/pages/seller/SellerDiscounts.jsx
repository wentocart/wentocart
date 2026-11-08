import { useEffect, useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';
import { Field } from './SellerHome.jsx';
import { SellerDeleteButton, SellerEditButton, SaveBtnLabel } from './sellerUi.jsx';

const blank = {
    name: '',
    code: '',
    apply_as: 'catalog',
    action: 'percent',
    value: '10',
    min_cart: '',
    is_enabled: true,
};

export default function SellerDiscounts() {
    const [rows, setRows] = useState(null);
    const [form, setForm] = useState(blank);
    const [editing, setEditing] = useState(null);
    const [error, setError] = useState('');
    const load = () => api.get('/account/seller/discounts').then((r) => setRows(r.data.discounts || [])).catch(() => setRows([]));
    useEffect(() => { load(); }, []);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="mv-split">
            <div>
                <h2 className="mv-page-title">{t('storefront.seller.discounts', 'Seller Discounts')}</h2>
                <p className="mv-page-help">{t('storefront.seller.discounts_help', 'Catalog price changes your product price. Cart and coupon discounts apply in the cart. A copy is stored on the store discount list too.')}</p>
                {rows.length === 0 ? (
                    <div className="mv-body"><EmptyState title={t('storefront.seller.no_discounts', 'No discounts yet.')} /></div>
                ) : (
                    <ul className="mv-list">
                        {rows.map((row) => (
                            <li key={row.id} className="shop-card mv-row p-4">
                                <div>
                                    <p className="font-semibold">{row.name} {row.code ? `· ${row.code}` : ''}</p>
                                    <p className="mv-muted-sm">{row.action} {row.value} · {row.apply_as}</p>
                                </div>
                                <div className="mv-row-actions">
                                    <SellerEditButton onClick={() => { setEditing(row.id); setForm({ ...blank, ...row, value: String(row.value), min_cart: row.min_cart ?? '' }); }} />
                                    <SellerDeleteButton onClick={async () => { await csrf(); await api.delete(`/account/seller/discounts/${row.id}`); load(); }} />
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
                            await api.patch(`/account/seller/discounts/${editing}`, form);
                        } else {
                            await api.post('/account/seller/discounts', form);
                        }
                        setForm(blank);
                        setEditing(null);
                        load();
                    } catch (err) {
                        setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                    }
                }}
            >
                <h3 className="font-semibold">{editing ? t('storefront.seller.edit', 'Edit') : t('storefront.seller.add_discount', 'Add discount')}</h3>
                <Field label={t('storefront.seller.name', 'Name')}><input className="shop-input" value={form.name} onChange={(e) => set('name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.code', 'Coupon code')}><input className="shop-input" value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase())} /></Field>
                <Field label={t('storefront.seller.apply_as', 'Apply as')}>
                    <select className="shop-input" value={form.apply_as || 'catalog'} onChange={(e) => set('apply_as', e.target.value)}>
                        <option value="catalog">{t('storefront.seller.apply_catalog', 'Catalog price')}</option>
                        <option value="cart">{t('storefront.seller.apply_cart', 'Automatic cart')}</option>
                        <option value="coupon">{t('storefront.seller.apply_coupon', 'Coupon code')}</option>
                    </select>
                </Field>
                <Field label={t('storefront.seller.action', 'Action')}>
                    <select className="shop-input" value={form.action} onChange={(e) => set('action', e.target.value)}>
                        <option value="percent">percent</option>
                        <option value="amount">amount</option>
                    </select>
                </Field>
                <Field label={t('storefront.seller.value', 'Value')}><input type="number" min="0" step="0.01" className="shop-input" value={form.value} onChange={(e) => set('value', e.target.value)} required /></Field>
                <FormNotice tone="error">{error}</FormNotice>
                <button className="shop-btn shop-btn-primary">
                    <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                </button>
            </form>
        </div>
    );
}
