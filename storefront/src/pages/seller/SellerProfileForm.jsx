import { useState } from 'react';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { FormActions, FormNotice, FormSection, FormShell } from '../../components/Form.jsx';
import { Field } from './SellerHome.jsx';
import { SaveBtnLabel } from './sellerUi.jsx';
import PhoneField from '../../components/PhoneField.jsx';
import { useStore } from '../../state/StoreState.jsx';

function profileFormFromSeller(seller) {
    return {
        first_name: seller?.first_name || '',
        last_name: seller?.last_name || '',
        profile_name: seller?.profile_name || '',
        email: seller?.email || '',
        phone: seller?.phone || '',
        contact_name: seller?.contact_name || '',
        contact_phone: seller?.contact_phone || '',
        contact_email: seller?.contact_email || '',
        tax_id: seller?.tax_id || '',
    };
}

export default function SellerProfileForm({ seller, setSeller }) {
    const store = useStore();
    const [form, setForm] = useState(() => profileFormFromSeller(seller));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [saved, setSaved] = useState(false);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    return (
        <FormShell
            size="wide"
            onSubmit={async (e) => {
                e.preventDefault();
                setError('');
                setFieldErrors({});
                setSaved(false);
                setBusy(true);
                try {
                    await csrf();
                    const { data } = await api.patch('/account/seller/profile', form);
                    setSeller(data.seller);
                    setForm(profileFormFromSeller(data.seller));
                    setSaved(true);
                } catch (err) {
                    setFieldErrors(err.response?.data?.errors || {});
                    setError(err.response?.data?.message || t('storefront.seller.save_failed', 'Could not save.'));
                } finally {
                    setBusy(false);
                }
            }}
        >
            <FormSection title={t('storefront.seller.section_identity', 'Seller identity')}>
                <Field label={t('storefront.seller.first_name', 'Seller first name')}><input className="shop-input" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.last_name', 'Seller last name')}><input className="shop-input" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.profile_name', 'Profile name')}><input className="shop-input" value={form.profile_name} onChange={(e) => set('profile_name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.tax_id', 'Tax ID')}><input className="shop-input" value={form.tax_id} onChange={(e) => set('tax_id', e.target.value)} /></Field>
            </FormSection>
            <FormSection title={t('storefront.seller.section_contact', 'Contact')}>
                <Field label={t('storefront.seller.email', 'Email')}><input type="email" className="shop-input" value={form.email} onChange={(e) => set('email', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.phone', 'Phone')}><PhoneField countries={store.shop?.countries || []} value={form.phone} onChange={(value) => set('phone', value)} required /></Field>
                <Field label={t('storefront.seller.contact_name', 'Shop contact name')}><input className="shop-input" value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} required /></Field>
                <Field label={t('storefront.seller.contact_phone', 'Shop contact phone')}><PhoneField countries={store.shop?.countries || []} value={form.contact_phone} onChange={(value) => set('contact_phone', value)} required /></Field>
                <Field label={t('storefront.seller.contact_email', 'Shop contact email')} span><input type="email" className="shop-input" value={form.contact_email} onChange={(e) => set('contact_email', e.target.value)} /></Field>
            </FormSection>
            <FormNotice tone="error">{error}</FormNotice>
            {Object.keys(fieldErrors).length > 0 ? (
                <ul className="text-sm mv-error">
                    {Object.entries(fieldErrors).map(([key, messages]) => (
                        <li key={key}>{Array.isArray(messages) ? messages[0] : messages}</li>
                    ))}
                </ul>
            ) : null}
            <FormNotice tone="ok">{saved ? t('storefront.account.saved', 'Saved.') : null}</FormNotice>
            <FormActions>
                <button className="shop-btn shop-btn-primary" disabled={busy} aria-busy={busy || undefined}>
                    {busy ? t('storefront.account.saving', 'Saving…') : (
                        <SaveBtnLabel>{t('storefront.account.save', 'Save changes')}</SaveBtnLabel>
                    )}
                </button>
            </FormActions>
        </FormShell>
    );
}
