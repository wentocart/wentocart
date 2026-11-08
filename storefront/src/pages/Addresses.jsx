import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { FormField } from '../components/Form.jsx';
import { apiAddressErrors, hasFieldErrors, localAddressErrors, postalMeta, stateRequiredFor, countryRow } from '../lib/addressFields.js';
import { Icon } from '../components/Icons.jsx';
import PhoneField from '../components/PhoneField.jsx';

const empty = {
    label: 'Home',
    first_name: '',
    last_name: '',
    phone: '',
    company: '',
    tax_id: '',
    line1: '',
    line2: '',
    city: '',
    state: '',
    postal_code: '',
    country_code: 'US',
    is_default_shipping: false,
    is_default_billing: false,
};

function regionsFor(countries, code) {
    return (countries || []).find((row) => row.code === code)?.regions || [];
}

function citiesFor(countries, code, state) {
    const country = (countries || []).find((row) => row.code === code);
    const all = country?.cities || [];
    if (!all.length) {
        return [];
    }
    if (!state) {
        return all;
    }
    const region = (country.regions || []).find((row) => row.name === state || row.code === state);
    const filtered = all.filter((city) => !city.region_name && !city.region_code
        || city.region_name === state
        || city.region_code === state
        || (region && (city.region_code === region.code || city.region_name === region.name)));
    return filtered.length ? filtered : all;
}

function labelIcon(label) {
    const key = String(label || '').trim().toLowerCase();
    if (key.includes('office') || key.includes('work')) {
        return 'briefcase';
    }
    if (key.includes('other') || key.includes('friend') || key.includes('gift')) {
        return 'building';
    }
    return 'home';
}

function countryName(countries, code) {
    return (countries || []).find((row) => row.code === code)?.name || code || '';
}

export default function Addresses() {
    const { id } = useParams();
    const nav = useNavigate();
    const store = useStore();
    const countries = store.shop?.countries || [];
    const addrSettings = store.shop?.customers?.addresses || {};
    const phoneMode = addrSettings.phone_mode || 'optional';
    const companyMode = addrSettings.company_mode || 'optional';
    const taxIdMode = addrSettings.tax_id_mode || 'hidden';
    const line2Mode = addrSettings.line2_mode || 'optional';
    const stateMode = addrSettings.state_mode || 'country_rules';
    const allowMultiple = addrSettings.allow_multiple !== false;
    const maxSaved = addrSettings.max_saved;
    const [rows, setRows] = useState(null);
    const [form, setForm] = useState(empty);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const editing = id && id !== 'new';
    const stateOptions = regionsFor(countries, form.country_code);
    const countryHasRegions = stateOptions.length > 0;
    const showState = stateMode === 'required' || stateMode === 'optional' || (stateMode === 'country_rules' && countryHasRegions);
    const addressCountry = countryRow(countries, form.country_code);
    const stateRequired = stateRequiredFor(addressCountry, addrSettings);
    const postalRequired = postalMeta(addressCountry, addrSettings).required;
    const atAddressLimit = maxSaved != null && rows && rows.length >= maxSaved;
    const canAddAddress = allowMultiple ? !atAddressLimit : !(rows && rows.length >= 1);

    const set = (key, value) => {
        setFieldErrors((current) => ({ ...current, [key]: '' }));
        setForm((prev) => ({ ...prev, [key]: value }));
    };
    const setCountry = (code) => {
        const options = regionsFor(countries, code).map((row) => row.name);
        setForm((prev) => ({
            ...prev,
            country_code: code,
            state: options.includes(prev.state) ? prev.state : '',
        }));
    };

    const load = () => api.get('/account/addresses').then((r) => setRows(r.data.addresses || [])).catch(() => setRows([]));

    useEffect(() => {
        load();
    }, [id]);

    useEffect(() => {
        if (!rows) {
            return;
        }
        if (id === 'new') {
            setForm({
                ...empty,
                first_name: store.customer?.first_name || '',
                last_name: store.customer?.last_name || '',
                country_code: countries[0]?.code || 'US',
                is_default_shipping: rows.length === 0,
                is_default_billing: rows.length === 0,
            });
            return;
        }
        if (editing) {
            const row = rows.find((item) => String(item.id) === String(id));
            if (row) {
                setForm({ ...empty, ...row });
            }
        }
    }, [id, rows]);

    const remove = async (rowId) => {
        if (!window.confirm(t('storefront.account.delete_address_confirm', 'Delete this address?'))) {
            return;
        }
        setBusyId(rowId);
        try {
            await csrf();
            await api.delete(`/account/addresses/${rowId}`);
            await load();
            store.ping?.(t('storefront.account.address_deleted', 'Address deleted'));
        } finally {
            setBusyId(null);
        }
    };

    const makeDefaultShipping = async (row) => {
        if (row.is_default_shipping) {
            return;
        }
        setBusyId(row.id);
        try {
            await csrf();
            await api.patch(`/account/addresses/${row.id}`, { ...row, is_default_shipping: true });
            await load();
            store.ping?.(t('storefront.account.default_shipping_set', 'Default shipping updated'));
        } finally {
            setBusyId(null);
        }
    };

    if (rows === null) {
        return <PageSkeleton />;
    }

    if (id) {
        return (
            <form
                className="shop-addresses-form"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setError('');
                    const problems = localAddressErrors(form, countries, addrSettings, true);
                    if (hasFieldErrors(problems)) {
                        setFieldErrors(problems);
                        return;
                    }
                    setBusy(true);
                    try {
                        await csrf();
                        const payload = { ...form };
                        if (phoneMode === 'hidden') delete payload.phone;
                        if (companyMode === 'hidden') delete payload.company;
                        if (taxIdMode === 'hidden') delete payload.tax_id;
                        if (line2Mode === 'hidden') delete payload.line2;
                        if (editing) {
                            await api.patch(`/account/addresses/${id}`, payload);
                            store.ping?.(t('storefront.account.address_updated', 'Address updated'));
                        } else {
                            await api.post('/account/addresses', payload);
                            store.ping?.(t('storefront.account.address_added', 'Address added'));
                        }
                        await load();
                        nav('/account/addresses');
                    } catch (err) {
                        const mapped = apiAddressErrors(err.response?.data);
                        if (hasFieldErrors(mapped.account)) {
                            setFieldErrors(mapped.account);
                            setError(mapped.rest[0] || '');
                        } else {
                            setError(err.response?.data?.message || t('storefront.account.address_failed', 'Could not save this address.'));
                        }
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <header className="shop-addresses-head">
                    <div>
                        <h1>{editing ? t('storefront.account.edit_address', 'Edit address') : t('storefront.account.add_address', 'Add new address')}</h1>
                        <p>{t('storefront.account.address_form_body', 'Saved addresses speed up checkout next time.')}</p>
                    </div>
                </header>

                <section className="shop-addresses-form-card">
                    <div className="shop-addresses-form-grid">
                        <FormField label={t('storefront.account.label', 'Label')}>
                            <input className="shop-input" value={form.label || ''} onChange={(e) => set('label', e.target.value)} placeholder="Home" />
                        </FormField>
                        <div className="shop-addresses-form-row-2">
                            <FormField label={t('storefront.account.first_name', 'First name')}>
                                <input className="shop-input" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} required />
                            </FormField>
                            <FormField label={t('storefront.account.last_name', 'Last name')}>
                                <input className="shop-input" value={form.last_name || ''} onChange={(e) => set('last_name', e.target.value)} />
                            </FormField>
                        </div>
                        {phoneMode !== 'hidden' ? (
                            <FormField label={t('storefront.account.phone', 'Phone')}>
                                <PhoneField countries={countries} value={form.phone || ''} onChange={(next) => set('phone', next)} required={phoneMode === 'required'} />
                            </FormField>
                        ) : null}
                        {companyMode !== 'hidden' ? (
                            <FormField label={t('storefront.account.company', 'Company')}>
                                <input className="shop-input" value={form.company || ''} onChange={(e) => set('company', e.target.value)} required={companyMode === 'required'} />
                            </FormField>
                        ) : null}
                        {taxIdMode !== 'hidden' ? (
                            <FormField label={t('storefront.account.tax_id', 'Tax / VAT ID')}>
                                <input className="shop-input" value={form.tax_id || ''} onChange={(e) => set('tax_id', e.target.value)} required={taxIdMode === 'required'} />
                            </FormField>
                        ) : null}
                        <FormField label={t('storefront.account.line1', 'Street address')}>
                            <input className="shop-input" value={form.line1} onChange={(e) => set('line1', e.target.value)} required />
                        </FormField>
                        {line2Mode !== 'hidden' ? (
                            <FormField label={line2Mode === 'required'
                                ? t('storefront.account.line2_label', 'Apartment, suite')
                                : t('storefront.account.line2', 'Apartment, suite (optional)')}>
                                <input className="shop-input" value={form.line2 || ''} onChange={(e) => set('line2', e.target.value)} required={line2Mode === 'required'} />
                            </FormField>
                        ) : null}
                        <FormField label={t('storefront.account.country', 'Country')}>
                            <select className="shop-input" value={form.country_code} onChange={(e) => setCountry(e.target.value)}>
                                {(countries.length ? countries : [{ code: 'US', name: 'United States' }]).map((c) => (
                                    <option key={c.code} value={c.code}>{c.name}</option>
                                ))}
                            </select>
                        </FormField>
                        <div className={`shop-addresses-form-row-${showState ? '3' : '2'}`}>
                            <FormField label={t('storefront.account.city', 'City')}>
                                {citiesFor(countries, form.country_code, form.state).length ? (
                                    <select className="shop-input" value={form.city} onChange={(e) => set('city', e.target.value)} required>
                                        <option value="">{t('storefront.account.select_city', 'Select city')}</option>
                                        {citiesFor(countries, form.country_code, form.state).map((city) => (
                                            <option key={city.name} value={city.name}>{city.name}</option>
                                        ))}
                                    </select>
                                ) : (
                                    <input className="shop-input" value={form.city} onChange={(e) => set('city', e.target.value)} required />
                                )}
                            </FormField>
                            {showState ? (
                                <FormField label={t('storefront.account.state', 'State / Province')} required={stateRequired} error={fieldErrors.state}>
                                    {countryHasRegions ? (
                                        <select className="shop-input" value={form.state || ''} onChange={(e) => set('state', e.target.value)}>
                                            <option value="">{t('storefront.account.select_state', 'Select state')}</option>
                                            {stateOptions.map((region) => (
                                                <option key={region.code} value={region.name}>{region.name}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input className="shop-input" value={form.state || ''} onChange={(e) => set('state', e.target.value)} required={stateRequired} />
                                    )}
                                </FormField>
                            ) : null}
                            <FormField
                                label={addressCountry?.postal_code_label || t('storefront.account.postal', 'ZIP / Postal code')}
                                required={postalRequired}
                                error={fieldErrors.postal_code}
                            >
                                <input className="shop-input" value={form.postal_code || ''} onChange={(e) => set('postal_code', e.target.value)} autoComplete="postal-code" />
                            </FormField>
                        </div>
                        <label className="shop-addresses-check">
                            <input type="checkbox" checked={!!form.is_default_shipping} onChange={(e) => set('is_default_shipping', e.target.checked)} />
                            <span>{t('storefront.account.default_shipping', 'Default shipping')}</span>
                        </label>
                        <label className="shop-addresses-check">
                            <input type="checkbox" checked={!!form.is_default_billing} onChange={(e) => set('is_default_billing', e.target.checked)} />
                            <span>{t('storefront.account.default_billing', 'Default billing')}</span>
                        </label>
                    </div>
                </section>

                {error ? <p className="shop-addresses-error">{error}</p> : null}

                <div className="shop-addresses-form-actions">
                    <button type="submit" className="shop-addresses-save" disabled={busy}>
                        {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.account.save', 'Save changes')}
                    </button>
                    <Link to="/account/addresses" className="shop-addresses-cancel">{t('storefront.account.cancel', 'Cancel')}</Link>
                </div>
            </form>
        );
    }

    return (
        <div className="shop-addresses">
                <header className="shop-addresses-head">
                <div>
                    <h1>{t('storefront.account.addresses', 'Addresses')}</h1>
                    <p>{t('storefront.account.addresses_body', 'Manage and organize your saved addresses for faster checkout.')}</p>
                </div>
                {canAddAddress ? (
                    <Link to="/account/addresses/new" className="shop-addresses-add">
                        <Icon name="plus" className="h-4 w-4" />
                        {t('storefront.account.add_address', 'Add new address')}
                    </Link>
                ) : null}
            </header>

            {rows.length === 0 ? (
                <div className="shop-addresses-empty">
                    <EmptyState
                        className="shop-addresses-empty-flat"
                        title={t('storefront.account.no_addresses', 'No saved addresses')}
                        body={t('storefront.account.no_addresses_body', 'Add a shipping address to speed up checkout.')}
                    />
                </div>
            ) : (
                <div className="shop-addresses-grid">
                    {rows.map((row) => {
                        const country = countryName(countries, row.country_code);
                        const street = [row.line1, row.line2].filter(Boolean).join(', ');
                        const locality = [row.city, row.state, row.postal_code].filter(Boolean).join(', ');
                        const addressText = [street, locality, country].filter(Boolean).join(', ');
                        const busy = busyId === row.id;
                        const isShip = !!row.is_default_shipping;
                        const isBill = !!row.is_default_billing;
                        const showBillBadge = isBill && !isShip;

                        return (
                            <article
                                key={row.id}
                                className={`shop-address-card${isShip ? ' is-default' : ''}${showBillBadge ? ' is-billing' : ''}${busy ? ' is-busy' : ''}`}
                            >
                                <div className="shop-address-card-top">
                                    <div className="shop-address-badges">
                                        {isShip ? (
                                            <span className="shop-address-badge is-ship">
                                                <Icon name="truck" className="h-3.5 w-3.5" />
                                                {t('storefront.account.default_shipping', 'Default shipping')}
                                            </span>
                                        ) : null}
                                        {showBillBadge ? (
                                            <span className="shop-address-badge is-bill">
                                                <Icon name="card" className="h-3.5 w-3.5" />
                                                {t('storefront.account.default_billing', 'Default billing')}
                                            </span>
                                        ) : null}
                                    </div>
                                    <button
                                        type="button"
                                        className={`shop-address-star${isShip ? ' is-on' : ''}`}
                                        title={t('storefront.account.set_default_shipping', 'Set as default shipping')}
                                        disabled={busy || isShip}
                                        onClick={() => makeDefaultShipping(row)}
                                    >
                                        <Icon name={isShip ? 'star' : 'starOutline'} className="h-4 w-4" />
                                    </button>
                                </div>

                                <div className="shop-address-card-body">
                                    <span className={`shop-address-type is-${labelIcon(row.label)}`}>
                                        <Icon name={labelIcon(row.label)} className="h-5 w-5" />
                                    </span>
                                    <div>
                                        <h2>{row.label || t('storefront.account.address', 'Address')}</h2>
                                        {addressText ? <p>{addressText}</p> : null}
                                        {row.phone ? <p className="shop-address-phone">{row.phone}</p> : null}
                                    </div>
                                </div>

                                <div className="shop-address-card-actions">
                                    <Link to={`/account/addresses/${row.id}`}>
                                        <Icon name="pencil" className="h-3.5 w-3.5" />
                                        {t('storefront.account.edit', 'Edit')}
                                    </Link>
                                    <button type="button" disabled={busy} onClick={() => remove(row.id)}>
                                        <Icon name="trash" className="h-3.5 w-3.5" />
                                        {t('storefront.account.delete', 'Delete')}
                                    </button>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
