import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, Navigate, useLocation, useOutletContext } from 'react-router-dom';
import api, { csrf } from '../../api.js';
import { t } from '../../i18n.js';
import { formatMoney } from '../../lib/format.js';
import { useStore } from '../../state/StoreState.jsx';
import { FormActions, FormField, FormNotice, FormSection, FormShell, LocalizedFormField } from '../../components/Form.jsx';
import { PageSkeleton } from '../../components/Feedback.jsx';
import { SELLER_APPLY_PATH } from './SellerLayout.jsx';
import PhoneField from '../../components/PhoneField.jsx';

const emptyForm = {
    first_name: '',
    last_name: '',
    shop_slug: '',
    profile_name: '',
    email: '',
    phone: '',
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    address_line1: '',
    address_line2: '',
    city: '',
    state: '',
    postal_code: '',
    country_code: 'US',
    tax_id: '',
};

function emptyLocale() {
    return { shop_name: '', description: '' };
}

function canAccessSellerPortal(seller) {
    if (!seller) {
        return false;
    }
    if (typeof seller.can_access_portal === 'boolean') {
        return seller.can_access_portal;
    }
    return !!seller.is_approved;
}

function buildLocaleMap(languages, seller, fallbackCode = 'en') {
    const map = {};
    const codes = (languages || []).map((lang) => lang.code).filter(Boolean);
    if (codes.length === 0) {
        codes.push(fallbackCode);
    }
    codes.forEach((code) => {
        map[code] = emptyLocale();
    });
    if (!seller) {
        return map;
    }
    (seller.translations || []).forEach((row) => {
        if (!row?.language_code) {
            return;
        }
        map[row.language_code] = {
            shop_name: row.shop_name || '',
            description: row.description || '',
        };
    });
    const primary = languages?.find((l) => l.is_default)?.code
        || codes[0]
        || fallbackCode;
    if (!map[primary]) {
        map[primary] = emptyLocale();
    }
    if (!map[primary].shop_name && seller.shop_name) {
        map[primary].shop_name = seller.shop_name;
    }
    if (!map[primary].description && seller.description) {
        map[primary].description = seller.description;
    }
    return map;
}

function firstError(errors) {
    if (!errors || typeof errors !== 'object') {
        return '';
    }
    const first = Object.values(errors)[0];
    return Array.isArray(first) ? (first[0] || '') : (first || '');
}

function errorText(errors, key) {
    const value = errors?.[key];
    return Array.isArray(value) ? (value[0] || '') : (value || '');
}

function requiredMessage(attribute) {
    return t('storefront.validation.required', 'The :attribute field is required.').replace(':attribute', attribute);
}

/** Mirror apply required fields so empty submit shows all field errors (not only the first browser tooltip). */
function collectClientApplyErrors({ form, defaultRow, showStateField, stateValue }) {
    const errors = {};
    const need = (key, value, attribute) => {
        if (!String(value ?? '').trim()) {
            errors[key] = [requiredMessage(attribute)];
        }
    };

    need('shop_name', defaultRow.shop_name, 'shop name');
    need('shop_slug', form.shop_slug, 'shop slug');
    need('first_name', form.first_name, 'first name');
    need('last_name', form.last_name, 'last name');
    need('profile_name', form.profile_name, 'profile name');
    need('email', form.email, 'email');
    need('phone', form.phone, 'phone');
    need('contact_name', form.contact_name, 'contact name');
    need('contact_phone', form.contact_phone, 'contact phone');
    need('address_line1', form.address_line1, 'address line1');
    need('city', form.city, 'city');
    need('postal_code', form.postal_code, 'postal code');
    need('country_code', form.country_code, 'country code');
    if (showStateField) {
        need('state', stateValue, 'state');
    }

    if (form.email && String(form.email).trim()) {
        const probe = document.createElement('input');
        probe.type = 'email';
        probe.value = form.email;
        if (!probe.checkValidity()) {
            errors.email = [t('storefront.validation.email', 'The email must be a valid email address.')];
        }
    }

    return errors;
}

function focusLocaleForErrors(errors, translationOrder, defaultLocale, setLocaleCode) {
    if (errors.shop_name || errors.description) {
        setLocaleCode(defaultLocale);
        return;
    }
    for (let i = 0; i < translationOrder.length; i += 1) {
        if (errors[`translations.${i}.shop_name`] || errors[`translations.${i}.description`]) {
            setLocaleCode(translationOrder[i]);
            return;
        }
    }
}

function localeFieldError(errors, field, localeCode, defaultLocale, translationOrder) {
    if (localeCode === defaultLocale) {
        return errorText(errors, field) || errorText(errors, `translations.0.${field}`);
    }
    const idx = translationOrder.indexOf(localeCode);
    if (idx >= 0) {
        return errorText(errors, `translations.${idx}.${field}`);
    }
    return '';
}

/** Same source shape as Addresses / Checkout (`Country::optionsForAddresses`). */
function regionsFor(countries, code) {
    return (countries || []).find((row) => row.code === code)?.regions || [];
}

/** Prefer region code; accept legacy saved name so edit forms still select correctly. */
function resolveStateCode(state, regions) {
    if (!state) {
        return '';
    }
    if (regions.some((row) => row.code === state)) {
        return state;
    }
    const byName = regions.find((row) => row.name === state);
    return byName?.code || '';
}

export default function SellerHome() {
    const store = useStore();
    const location = useLocation();
    const ctx = useOutletContext() || {};
    const [payload, setPayload] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [locales, setLocales] = useState({});
    const [localeCode, setLocaleCode] = useState('en');
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const onApplyPage = location.pathname === SELLER_APPLY_PATH;
    const translationOrderRef = useRef([]);

    const languages = useMemo(() => {
        const fromApi = payload?.languages;
        if (Array.isArray(fromApi) && fromApi.length) {
            return fromApi;
        }
        return store.shop?.languages || [];
    }, [payload?.languages, store.shop?.languages]);

    const defaultLocale = payload?.default_locale
        || languages.find((l) => l.is_default)?.code
        || languages[0]?.code
        || 'en';
    const activeLocale = locales[localeCode] || emptyLocale();

    const countries = useMemo(() => {
        if (payload?.countries?.length) {
            return payload.countries;
        }
        return store.shop?.countries || [];
    }, [payload?.countries, store.shop?.countries]);

    const stateOptions = regionsFor(countries, form.country_code);
    const countriesReady = countries.length > 0;
    const countryKnown = !!countries.find((row) => row.code === form.country_code);
    const statesLoading = countriesReady && !countryKnown;
    const showStateField = stateOptions.length > 0;
    const stateValue = resolveStateCode(form.state, stateOptions);

    const load = () => api.get('/account/seller').then((r) => {
        setPayload(r.data);
        const langs = (r.data.languages?.length ? r.data.languages : store.shop?.languages) || [];
        const def = r.data.default_locale || langs.find((l) => l.is_default)?.code || langs[0]?.code || 'en';
        setLocaleCode(def);
        setLocales(buildLocaleMap(langs, r.data.seller));
        const seller = r.data.seller;
        if (seller && !canAccessSellerPortal(seller)) {
            setForm((current) => ({
                ...current,
                first_name: seller.first_name || '',
                last_name: seller.last_name || '',
                shop_slug: seller.shop_slug || '',
                profile_name: seller.profile_name || '',
                email: seller.email || '',
                phone: seller.phone || '',
                contact_name: seller.contact_name || '',
                contact_phone: seller.contact_phone || '',
                contact_email: seller.contact_email || '',
                address_line1: seller.address_line1 || '',
                address_line2: seller.address_line2 || '',
                city: seller.city || '',
                state: seller.state || '',
                postal_code: seller.postal_code || '',
                country_code: seller.country_code || 'US',
                tax_id: seller.tax_id || '',
            }));
        } else if (!seller && store.customer) {
            setForm((current) => ({
                ...current,
                first_name: store.customer.first_name || '',
                last_name: store.customer.last_name || '',
                email: store.customer.email || '',
                phone: store.customer.phone || '',
                contact_email: store.customer.email || '',
                profile_name: store.customer.name || '',
            }));
        }
    }).catch(() => setPayload({ seller: null, can_apply: true, countries: [], languages: [] }));

    useEffect(() => { load(); }, [store.customer?.id]);

    if (!payload) {
        return <PageSkeleton />;
    }

    const seller = payload.seller;
    if (canAccessSellerPortal(seller)) {
        // Approved/active sellers belong in the portal, not the apply form.
        if (onApplyPage) {
            return <Navigate to="/account/seller" replace />;
        }
        return <ApprovedHome />;
    }

    const set = (key, value) => {
        setFieldErrors((prev) => {
            if (!prev[key]) {
                return prev;
            }
            const next = { ...prev };
            delete next[key];
            return next;
        });
        setForm((current) => ({ ...current, [key]: value }));
    };
    const setLocaleField = (key, value) => {
        setFieldErrors((prev) => {
            const keys = [key, ...translationOrderRef.current.map((_, i) => `translations.${i}.${key}`)];
            let changed = false;
            const next = { ...prev };
            keys.forEach((k) => {
                if (k in next) {
                    delete next[k];
                    changed = true;
                }
            });
            return changed ? next : prev;
        });
        setLocales((current) => ({
            ...current,
            [localeCode]: { ...(current[localeCode] || emptyLocale()), [key]: value },
        }));
    };
    const setCountry = (code) => {
        setFieldErrors((prev) => {
            const next = { ...prev };
            delete next.country_code;
            delete next.state;
            return next;
        });
        setForm((current) => ({
            ...current,
            country_code: code,
            state: '',
        }));
    };

    const blocked = seller?.status === 'blocked';
    const pending = seller?.status === 'pending';
    const rejected = seller?.status === 'rejected';
    const canSubmit = payload.can_apply && !blocked && !pending;
    const fieldsLocked = !canSubmit || busy;

    const title = pending
        ? t('storefront.seller.application_status', 'Application status')
        : rejected
            ? t('storefront.seller.reapply_title', 'Seller application')
            : blocked
                ? t('storefront.seller.application_status', 'Application status')
                : t('storefront.seller.become', 'Become a seller');

    const brand = store.shop?.name || t('storefront.brand', 'WentoCart');

    const submit = async (e) => {
        e.preventDefault();
        if (!canSubmit || busy) {
            return;
        }
        setError('');
        setFieldErrors({});
        setSaved(false);
        setBusy(true);
        const defaultRow = locales[defaultLocale] || emptyLocale();
        const translations = languages
            .map((lang) => {
                const row = locales[lang.code] || emptyLocale();
                if (!String(row.shop_name || '').trim()) {
                    return null;
                }
                return {
                    language_code: lang.code,
                    shop_name: row.shop_name,
                    description: row.description || null,
                };
            })
            .filter(Boolean);
        translationOrderRef.current = translations.map((row) => row.language_code);

        const clientErrors = collectClientApplyErrors({
            form,
            defaultRow,
            showStateField,
            stateValue,
        });
        if (Object.keys(clientErrors).length > 0) {
            setFieldErrors(clientErrors);
            focusLocaleForErrors(clientErrors, translationOrderRef.current, defaultLocale, setLocaleCode);
            setBusy(false);
            return;
        }

        const body = {
            ...form,
            state: showStateField ? stateValue : '',
            shop_name: defaultRow.shop_name || translations[0]?.shop_name || '',
            description: defaultRow.description || translations[0]?.description || '',
            translations,
        };

        try {
            await csrf();
            const { data } = await api.post('/account/seller/apply', body);
            setPayload((current) => ({ ...current, seller: data.seller, can_apply: false }));
            setLocales(buildLocaleMap(languages, data.seller));
            store.setCustomer({
                ...store.customer,
                seller: {
                    id: data.seller.id,
                    status: data.seller.status,
                    shop_name: data.seller.shop_name,
                    shop_slug: data.seller.shop_slug,
                    is_approved: false,
                    can_access_portal: false,
                },
            });
            ctx.setSeller?.(data.seller);
            setSaved(true);
        } catch (err) {
            const errors = err.response?.data?.errors || {};
            if (Object.keys(errors).length > 0) {
                setFieldErrors(errors);
                focusLocaleForErrors(errors, translationOrderRef.current, defaultLocale, setLocaleCode);
                setError('');
            } else {
                setError(
                    err.response?.data?.message
                    || firstError(errors)
                    || t('storefront.seller.apply_failed', 'Could not send the seller request.'),
                );
            }
        } finally {
            setBusy(false);
        }
    };

    const shopNameError = localeFieldError(fieldErrors, 'shop_name', localeCode, defaultLocale, translationOrderRef.current);
    const descriptionError = localeFieldError(fieldErrors, 'description', localeCode, defaultLocale, translationOrderRef.current);

    return (
        <div className="mv-narrow">
            <h1 className="mv-title">{title}</h1>
            <p className="mv-page-help">
                {pending || blocked || rejected
                    ? t('storefront.seller.become_body', 'Request a shop on this store. After an admin accepts, you can list products and manage orders from your account.')
                    : `${t('storefront.seller.start_selling_prefix', 'Start selling on')} ${brand}.`}
            </p>
            {seller ? (
                <div className="shop-card mt-5 p-4 text-sm" role="status">
                    <p className="font-semibold capitalize">{t('storefront.seller.status', 'Status')}: {seller.status}</p>
                    {seller.review_note ? <p className="mt-2 mv-muted">{seller.review_note}</p> : null}
                    {pending ? <p className="mt-2 mv-muted">{t('storefront.seller.pending_help', 'Your request is waiting for an admin review.')}</p> : null}
                    {blocked ? <p className="mt-2 mv-error">{t('storefront.seller.blocked_help', 'This seller account is blocked. Contact the store.')}</p> : null}
                </div>
            ) : null}
            <FormShell className="mt-6" onSubmit={submit} noValidate>
                <FormSection
                    title={t('storefront.seller.section_shop', 'Shop information')}
                    description={t('storefront.seller.section_shop_help', 'Your public shop name and description. Other sellers use the unique shop name as their URL key.')}
                >
                    <LocalizedFormField
                        label={t('storefront.seller.shop_name', 'Shop name')}
                        languages={languages}
                        currentLanguage={localeCode}
                        onLanguageChange={setLocaleCode}
                        error={shopNameError || null}
                        required
                        span
                    >
                        <input
                            className="shop-input"
                            name="shop_name"
                            value={activeLocale.shop_name}
                            onChange={(e) => setLocaleField('shop_name', e.target.value)}
                            required={localeCode === defaultLocale}
                            disabled={fieldsLocked}
                            aria-required={localeCode === defaultLocale}
                        />
                    </LocalizedFormField>
                    <LocalizedFormField
                        label={t('storefront.seller.description', 'Shop description')}
                        languages={languages}
                        currentLanguage={localeCode}
                        onLanguageChange={setLocaleCode}
                        error={descriptionError || null}
                        span
                    >
                        <textarea
                            className="shop-input"
                            name="description"
                            rows={4}
                            value={activeLocale.description}
                            onChange={(e) => setLocaleField('description', e.target.value)}
                            disabled={fieldsLocked}
                        />
                    </LocalizedFormField>
                    <Field
                        label={t('storefront.seller.shop_slug', 'Unique shop name')}
                        hint={t('storefront.seller.shop_slug_help', 'Lowercase letters, numbers, and hyphens. This becomes your shop URL key.')}
                        error={errorText(fieldErrors, 'shop_slug') || null}
                        required
                        span
                    >
                        <input
                            className="shop-input"
                            name="shop_slug"
                            value={form.shop_slug}
                            onChange={(e) => set('shop_slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-|-$/g, ''))}
                            required
                            disabled={fieldsLocked}
                            autoComplete="off"
                        />
                    </Field>
                </FormSection>

                <FormSection
                    title={t('storefront.seller.section_identity', 'Seller identity')}
                    description={t('storefront.seller.section_identity_help', 'This is how the store identifies you as a seller.')}
                >
                    <Field label={t('storefront.seller.first_name', 'Seller first name')} error={errorText(fieldErrors, 'first_name') || null} required>
                        <input className="shop-input" name="first_name" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.last_name', 'Seller last name')} error={errorText(fieldErrors, 'last_name') || null} required>
                        <input className="shop-input" name="last_name" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.profile_name', 'Profile name')} error={errorText(fieldErrors, 'profile_name') || null} required span>
                        <input className="shop-input" name="profile_name" value={form.profile_name} onChange={(e) => set('profile_name', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.tax_id', 'Tax ID')} error={errorText(fieldErrors, 'tax_id') || null}>
                        <input className="shop-input" name="tax_id" value={form.tax_id} onChange={(e) => set('tax_id', e.target.value)} disabled={fieldsLocked} />
                    </Field>
                </FormSection>

                <FormSection
                    title={t('storefront.seller.section_contact', 'Contact')}
                    description={t('storefront.seller.section_contact_help', 'Customers and the store use these details to reach you.')}
                >
                    <Field label={t('storefront.seller.email', 'Email')} error={errorText(fieldErrors, 'email') || null} required>
                        <input type="email" className="shop-input" name="email" value={form.email} onChange={(e) => set('email', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.phone', 'Phone')} error={errorText(fieldErrors, 'phone') || null} required>
                        <PhoneField countries={countries} value={form.phone} onChange={(value) => set('phone', value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.contact_name', 'Shop contact name')} error={errorText(fieldErrors, 'contact_name') || null} required>
                        <input className="shop-input" name="contact_name" value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.contact_phone', 'Shop contact phone')} error={errorText(fieldErrors, 'contact_phone') || null} required>
                        <PhoneField countries={countries} value={form.contact_phone} onChange={(value) => set('contact_phone', value)} required disabled={fieldsLocked} />
                    </Field>
                </FormSection>

                <FormSection title={t('storefront.seller.section_address', 'Shop address')}>
                    <Field label={t('storefront.seller.address', 'Shop address')} error={errorText(fieldErrors, 'address_line1') || null} required span>
                        <input className="shop-input" name="address_line1" value={form.address_line1} onChange={(e) => set('address_line1', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.address2', 'Address line 2')} error={errorText(fieldErrors, 'address_line2') || null} span>
                        <input className="shop-input" name="address_line2" value={form.address_line2} onChange={(e) => set('address_line2', e.target.value)} disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.city', 'City')} error={errorText(fieldErrors, 'city') || null} required>
                        <input className="shop-input" name="city" value={form.city} onChange={(e) => set('city', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.postal', 'Postal code')} error={errorText(fieldErrors, 'postal_code') || null} required>
                        <input className="shop-input" name="postal_code" value={form.postal_code} onChange={(e) => set('postal_code', e.target.value)} required disabled={fieldsLocked} />
                    </Field>
                    <Field label={t('storefront.seller.country', 'Country')} error={errorText(fieldErrors, 'country_code') || null} required>
                        <select className="shop-input" name="country_code" value={form.country_code} onChange={(e) => setCountry(e.target.value)} disabled={fieldsLocked || !countriesReady} required>
                            {!countriesReady ? (
                                <option value="">{t('storefront.account.loading', 'Loading…')}</option>
                            ) : null}
                            {(countries.length ? countries : [{ code: 'US', name: 'United States' }]).map((country) => (
                                <option key={country.code} value={country.code}>{country.name}</option>
                            ))}
                        </select>
                    </Field>
                    {statesLoading ? (
                        <Field label={t('storefront.seller.state', 'State')}>
                            <select className="shop-input" disabled aria-busy="true">
                                <option value="">{t('storefront.account.loading', 'Loading…')}</option>
                            </select>
                        </Field>
                    ) : null}
                    {showStateField ? (
                        <Field label={t('storefront.seller.state', 'State')} error={errorText(fieldErrors, 'state') || null} required>
                            <select
                                className="shop-input"
                                name="state"
                                value={stateValue}
                                onChange={(e) => set('state', e.target.value)}
                                required
                                disabled={fieldsLocked}
                            >
                                <option value="">{t('storefront.account.select_state', 'Select state')}</option>
                                {stateOptions.map((region) => (
                                    <option key={region.code} value={region.code}>{region.name}</option>
                                ))}
                            </select>
                        </Field>
                    ) : null}
                </FormSection>

                <FormNotice tone="error">{error}</FormNotice>
                <FormNotice tone="ok">{saved ? t('storefront.seller.applied', 'Request sent. An admin will review it.') : null}</FormNotice>
                {canSubmit ? (
                    <FormActions>
                        <button className="shop-btn shop-btn-primary" type="submit" disabled={busy} aria-busy={busy}>
                            {busy
                                ? t('storefront.account.saving', 'Saving…')
                                : (seller
                                    ? t('storefront.seller.reapply', 'Submit again')
                                    : t('storefront.seller.submit', 'Send seller request'))}
                        </button>
                    </FormActions>
                ) : null}
            </FormShell>
        </div>
    );
}

function ApprovedHome() {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    useEffect(() => {
        api.get('/account/seller/dashboard').then((r) => setData(r.data)).catch(() => {
            setData({ stats: {}, recent_orders: [] });
            setError(t('storefront.seller.load_failed', 'Could not load dashboard.'));
        });
    }, []);
    if (!data) {
        return <PageSkeleton />;
    }
    const stats = data.stats || {};
    const orders = data.recent_orders || [];
    return (
        <div>
            <p className="mv-muted">{t('storefront.seller.home_body', 'Catalog, orders, commission, and payouts for your shop. This stays in your customer account. There is no admin login for sellers.')}</p>
            {error ? <p className="mt-3 mv-error-sm">{error}</p> : null}
            <div className="mv-stats">
                <Stat to="/account/seller/products" label={t('storefront.seller.products', 'Products')} value={stats.products ?? 0} />
                <Stat to="/account/seller/orders" label={t('storefront.seller.orders', 'Orders')} value={stats.orders ?? 0} />
                <Stat to="/account/seller/orders" label={t('storefront.seller.pending_orders', 'Open orders')} value={stats.pending_orders ?? 0} />
                <Stat to="/account/seller/returns" label={t('storefront.seller.open_returns', 'Open returns')} value={stats.open_returns ?? 0} />
                <Stat to="/account/seller/earnings" label={t('storefront.seller.available_balance', 'Available')} value={formatMoney(stats.available ?? 0)} />
                <Stat to="/account/seller/earnings" label={t('storefront.seller.earnings', 'Earnings')} value={formatMoney(stats.earnings ?? 0)} />
                <Stat to="/account/seller/reviews" label={t('storefront.seller.reviews', 'Reviews')} value={stats.reviews ?? 0} />
                <Stat to="/account/seller/settings" label={t('storefront.seller.commission', 'Commission')} value={`${stats.commission ?? 0}%`} />
            </div>
            <div className="shop-card mt-6 p-5">
                <div className="mv-toolbar">
                    <h2 className="font-semibold">{t('storefront.seller.recent_orders', 'Recent seller orders')}</h2>
                    <Link to="/account/seller/orders" className="mv-link">{t('storefront.home.view_all', 'View All')}</Link>
                </div>
                <ul className="mt-3 divide-y text-sm">
                    {orders.map((order) => (
                        <li key={order.id} className="mv-row py-3">
                            <Link to={`/account/seller/orders/${order.id}`} className="font-medium">{order.number}</Link>
                            <span className="capitalize mv-muted">{order.status}</span>
                            <span>{formatMoney(order.grand_total, order.currency_code)}</span>
                        </li>
                    ))}
                    {orders.length === 0 ? <li className="py-6 mv-muted">{t('storefront.seller.no_orders', 'No seller orders yet.')}</li> : null}
                </ul>
            </div>
        </div>
    );
}

function Stat({ to, label, value }) {
    return (
        <Link to={to} className="shop-card p-5">
            <p className="mv-stat-label">{label}</p>
            <p className="mv-stat-value">{value}</p>
        </Link>
    );
}

export function Field(props) {
    return <FormField {...props} />;
}
