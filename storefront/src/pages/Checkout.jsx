import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { translatedName } from '../i18nName.js';
import { useStore } from '../state/StoreState.jsx';
import { formatMoney } from '../lib/format.js';
import { productPath } from '../lib/links.js';
import { CheckoutPageSkeleton, EmptyState } from '../components/Feedback.jsx';
import { FormField, PasswordHint, PasswordInput } from '../components/Form.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import ProductImage from '../components/ProductImage.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { Icon } from '../components/Icons.jsx';
import PhoneField from '../components/PhoneField.jsx';
import { apiAddressErrors, hasFieldErrors, localAddressErrors, postalMeta, stateRequiredFor } from '../lib/addressFields.js';
import PaymentUiHost from '../components/PaymentUiHost.jsx';
import PaymentNotice from '../components/PaymentNotice.jsx';
import {
    collectPaymentContext,
    confirmBeforeCheckout,
    confirmClientAction,
    matchesPaymentNoticeBlock,
    matchesPaymentUiBlock,
    supportsPaymentFirst,
} from '../lib/paymentUiRegistry.js';
import { couponsEnabled, isCatalogMode, pricesHidden, returnsEnabled, termsRequired } from '../lib/commerce.js';
import { passwordReady } from '../lib/passwordStrength.js';
import { goSafeRedirect, safeRedirectUrl } from '../lib/safeRedirect.js';
import { shopBasePath } from '../webPath.js';

function checkoutDraftKey() {
    return `wento-checkout-draft:${shopBasePath() || '/'}`;
}

function readCheckoutDraft() {
    try {
        const raw = sessionStorage.getItem(checkoutDraftKey());
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

function rememberCheckoutDraft(draft) {
    try {
        sessionStorage.setItem(checkoutDraftKey(), JSON.stringify(draft));
    } catch {
        // Private browsing can refuse storage. The form still works for this page view.
    }
}

function forgetCheckoutDraft() {
    try {
        sessionStorage.removeItem(checkoutDraftKey());
    } catch {
        // Ignore.
    }
}

const emptyAddress = {
    first_name: '',
    last_name: '',
    phone: '',
    line1: '',
    line2: '',
    city: '',
    postal_code: '',
    state: '',
    country_code: 'US',
};

const STEPS = [
    { key: 'personal', label: 'Personal Information' },
    { key: 'shipping', label: 'Shipping & Invoice' },
    { key: 'payment', label: 'Payment' },
    { key: 'review', label: 'Review & Confirm' },
];

export default function Checkout() {
    const nav = useNavigate();
    const store = useStore();
    const hidePrices = pricesHidden(store.shop, store.customer);
    const money = (amount, currency) => (hidePrices ? '' : formatMoney(amount, currency));
    const authReady = store.customer !== undefined;
    const loggedIn = !!store.customer;
    const orderSettings = store.shop?.orders || {};
    const customerSettings = store.shop?.customers || {};
    const guestCheckout = (customerSettings.guest_checkout ?? orderSettings.guest_checkout) !== false;
    const registrationOpen = customerSettings.registration_enabled !== false;
    const mustCreateAccount = !loggedIn && !guestCheckout && registrationOpen;
    const registrationBlocked = !loggedIn && !guestCheckout && !registrationOpen;
    const accountClosedMessage = t(
        'storefront.checkout.account_required_closed',
        'An account is required to place an order. Registration is closed, so sign in if you already have an account.',
    );
    const notesEnabled = orderSettings.customer_notes_enabled !== false;
    const catalogOnly = isCatalogMode(store.shop);
    const needTerms = termsRequired(store.shop);
    const termsSlug = store.shop?.commerce?.cart?.terms_page_slug || 'terms';
    const termsLabel = store.shop?.commerce?.cart?.terms_page_title || t('storefront.checkout.terms', 'Terms of Service');
    const couponsOn = couponsEnabled(store.shop);
    useEffect(() => {
        if (catalogOnly) {
            nav('/catalog', { replace: true });
        }
    }, [catalogOnly, nav]);
    useEffect(() => {
        store.reloadShop?.();
    }, []);
    const [options, setOptions] = useState({ payment_methods: [], shipping_methods: [], modules: {} });
    const [optionsLoading, setOptionsLoading] = useState(true);
    const [addresses, setAddresses] = useState([]);
    const [addressesReady, setAddressesReady] = useState(false);
    const [shipId, setShipId] = useState(null);
    const [billId, setBillId] = useState(null);
    const [composer, setComposer] = useState(null); // null | 'new' | addressId
    const [draft, setDraft] = useState(() => readCheckoutDraft() || { ...emptyAddress, label: 'Home' });
    const [createAccount, setCreateAccount] = useState(false);
    const dobMode = customerSettings.dob_mode || 'hidden';
    const [form, setForm] = useState({
        email: '',
        first_name: '',
        last_name: '',
        date_of_birth: '',
        gender: '',
        password: '',
        password_confirmation: '',
        payment_method_id: '',
        shipping_method_id: '',
        billing_address: { ...emptyAddress },
        shipping_address: { ...emptyAddress },
        customer_note: '',
        same: customerSettings.addresses?.billing_same_default !== false,
        coupon: '',
        gift_card: '',
        accept_terms: false,
    });
    const [sameTouched, setSameTouched] = useState(false);
    useEffect(() => {
        if (sameTouched || !store.shop) return;
        const on = store.shop?.customers?.addresses?.billing_same_default !== false;
        setForm((current) => (current.same === on ? current : { ...current, same: on }));
    }, [store.shop, sameTouched]);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({ shipping: {}, billing: {}, draft: {} });
    const [busy, setBusy] = useState(false);
    const [giftBusy, setGiftBusy] = useState(false);
    const [pendingPaymentOrder, setPendingPaymentOrder] = useState('');
    const [savingAddress, setSavingAddress] = useState(false);
    const [wizard, setWizard] = useState(0);
    const stepped = store.shop?.commerce?.cart?.layout === 'stepped';
    const giftWrapEnabled = !!store.shop?.commerce?.cart?.gift_wrap_enabled;
    const loyaltyOn = !!store.shop?.commerce?.loyalty?.enabled;
    const countries = store.shop?.countries || [];
    const items = store.cart?.cart?.items || [];

    const shipping = useMemo(
        () => options.shipping_methods.find((m) => String(m.id) === String(form.shipping_method_id)),
        [options.shipping_methods, form.shipping_method_id]
    );
    const payment = useMemo(
        () => options.payment_methods.find((m) => String(m.id) === String(form.payment_method_id)),
        [options.payment_methods, form.payment_method_id]
    );
    const paymentUiBlock = useMemo(() => {
        const slots = options.modules?.payment || [];
        return slots.find((block) => matchesPaymentUiBlock(block, payment)) || null;
    }, [options.modules?.payment, payment]);
    const hasInlinePaymentUi = !!paymentUiBlock;
    const paymentUiContext = useMemo(() => ({
        email: form.email || store.customer?.email || '',
        payment_method_id: Number(form.payment_method_id),
        shipping_method_id: Number(form.shipping_method_id),
        billing_address: form.same ? form.shipping_address : form.billing_address,
        shipping_address: form.shipping_address,
    }), [
        form.email,
        form.payment_method_id,
        form.shipping_method_id,
        form.shipping_address,
        form.billing_address,
        form.same,
        store.customer?.email,
        store.cart?.totals?.grand_total,
        shipping?.price,
    ]);
    const isPickup = !!shipping?.is_pickup;

    const loadAddresses = () => api.get('/account/addresses').then((r) => {
        const rows = r.data.addresses || [];
        setAddresses(rows);
        setAddressesReady(true);
        const ship = rows.find((a) => a.is_default_shipping) || rows[0];
        const bill = rows.find((a) => a.is_default_billing) || ship;
        if (ship) {
            forgetCheckoutDraft();
            setShipId(ship.id);
            setBillId(bill?.id || ship.id);
            setComposer(null);
            setForm((current) => ({
                ...current,
                email: current.email || store.customer?.email || '',
                shipping_address: pickAddress(ship, current.shipping_address),
                billing_address: pickAddress(bill || ship, current.billing_address),
            }));
        }
        return rows;
    }).catch(() => {
        setAddresses([]);
        setAddressesReady(true);
        return [];
    });

    const fetchCarrierRates = useCallback((addressOverride) => {
        const addr = addressOverride || form.shipping_address;
        const country = addr?.country_code || countries[0]?.code || 'US';
        const postal = addr?.postal_code || '';
        const state = addr?.state || '';
        const city = addr?.city || '';

        api.get('/checkout/carrier-rates', {
            params: {
                country_code: country,
                postal_code: postal,
                state: state,
                city: city,
            },
        }).then((res) => {
            const rates = res.data?.rates || [];
            if (!rates.length) {
                setOptions((prev) => ({
                    ...prev,
                    shipping_methods: (prev.shipping_methods || []).map((m) =>
                        m.calculating ? { ...m, calculating: false } : m
                    ),
                }));
                return;
            }
            setOptions((prev) => ({
                ...prev,
                shipping_methods: (prev.shipping_methods || []).map((m) => {
                    const found = rates.find((r) => String(r.id) === String(m.id));
                    if (found) {
                        return {
                            ...m,
                            price: found.price,
                            eta: found.eta || m.eta,
                            calculating: false,
                        };
                    }
                    return m.calculating ? { ...m, calculating: false } : m;
                }),
            }));
        }).catch(() => {
            setOptions((prev) => ({
                ...prev,
                shipping_methods: (prev.shipping_methods || []).map((m) =>
                    m.calculating ? { ...m, calculating: false } : m
                ),
            }));
        });
    }, [form.shipping_address, countries]);

    const prevCarrierAddrRef = useRef('');
    useEffect(() => {
        const addrKey = `${form.shipping_address.country_code || ''}|${form.shipping_address.postal_code || ''}|${form.shipping_address.state || ''}`;
        if (!prevCarrierAddrRef.current) {
            prevCarrierAddrRef.current = addrKey;
            return;
        }
        if (prevCarrierAddrRef.current === addrKey) {
            return;
        }
        prevCarrierAddrRef.current = addrKey;

        const isAsyncCarrier = (m) => {
            const driver = String(m.driver || '').toLowerCase();
            return Boolean(m.is_carrier) && driver !== '' && driver !== 'offline' && driver !== 'sandbox';
        };
        const hasCarriers = (options.shipping_methods || []).some(isAsyncCarrier);
        if (!hasCarriers) {
            return;
        }

        setOptions((prev) => ({
            ...prev,
            shipping_methods: (prev.shipping_methods || []).map((m) =>
                isAsyncCarrier(m) ? { ...m, calculating: true } : m
            ),
        }));

        const timer = setTimeout(() => {
            fetchCarrierRates(form.shipping_address);
        }, 350);
        return () => clearTimeout(timer);
    }, [form.shipping_address.country_code, form.shipping_address.postal_code, form.shipping_address.state, options.shipping_methods, fetchCarrierRates]);

    useEffect(() => {
        if (!authReady) {
            return;
        }
        setOptionsLoading(true);
        api.get('/checkout/options').then((r) => {
            setOptions(r.data);
            setForm((current) => ({
                ...current,
                payment_method_id: current.payment_method_id || String(r.data.payment_methods[0]?.id || ''),
                shipping_method_id: current.shipping_method_id || String(r.data.shipping_methods[0]?.id || ''),
            }));
            if ((r.data.shipping_methods || []).some((m) => m.calculating)) {
                fetchCarrierRates(form.shipping_address);
            }
        }).finally(() => {
            setOptionsLoading(false);
        });
        store.reloadCart();
        if (store.customer) {
            setAddressesReady(false);
            setComposer(null);
            setForm((current) => ({
                ...current,
                email: store.customer.email || current.email || '',
            }));
            loadAddresses();
        } else {
            setAddresses([]);
            setAddressesReady(true);
            setComposer(null);
            setForm((current) => ({
                ...current,
                email: current.email || '',
                shipping_address: {
                    ...current.shipping_address,
                    country_code: current.shipping_address.country_code || countries[0]?.code || 'US',
                },
            }));
        }
    }, [authReady, store.customer]);

    useEffect(() => {
        if (loggedIn && addressesReady && addresses.length === 0 && composer == null) {
            const saved = readCheckoutDraft();
            setComposer('new');
            setDraft(saved || {
                ...emptyAddress,
                label: 'Home',
                first_name: store.customer?.first_name || '',
                last_name: store.customer?.last_name || '',
                country_code: countries[0]?.code || 'US',
            });
        }
    }, [loggedIn, addressesReady, addresses.length, composer]);

    useEffect(() => {
        if (composer === 'new') {
            rememberCheckoutDraft(draft);
        }
    }, [composer, draft]);

    useEffect(() => {
        const country = form.shipping_address.country_code;
        store.reloadCart({ shipping_method_id: shipping?.id || '', country_code: country });
    }, [form.shipping_method_id, form.shipping_address.country_code, shipping?.price]);

    const totals = store.cart?.totals || {};
    const lineById = useMemo(() => {
        const map = {};
        (totals.lines || []).forEach((line) => {
            map[line.cart_item_id] = line;
        });
        return map;
    }, [totals.lines]);
    const quotedShipping = Number(totals.shipping ?? shipping?.price ?? 0);
    const discount = Number(totals.discount || 0);
    const couponCode = totals.coupon_code || form.coupon || '';
    const grand = Number(totals.grand_total ?? (Number(totals.subtotal || 0) - discount + Number(totals.tax || 0) + quotedShipping));

    const setShipField = (key, value) => {
        setFieldErrors((current) => ({ ...current, shipping: { ...current.shipping, [key]: '' } }));
        setForm((current) => ({
            ...current,
            shipping_address: { ...current.shipping_address, [key]: value },
            billing_address: current.same
                ? { ...current.shipping_address, [key]: value }
                : current.billing_address,
        }));
    };

    const setBillField = (key, value) => {
        setFieldErrors((current) => ({ ...current, billing: { ...current.billing, [key]: '' } }));
        setForm((current) => ({
            ...current,
            billing_address: { ...current.billing_address, [key]: value },
        }));
    };

    const selectSavedShip = (row) => {
        setShipId(row.id);
        setComposer(null);
        setForm((current) => ({
            ...current,
            shipping_address: pickAddress(row, current.shipping_address),
            billing_address: current.same ? pickAddress(row, current.billing_address) : current.billing_address,
            same: current.same,
        }));
        if (form.same) {
            setBillId(row.id);
        }
    };

    const selectSavedBill = (row) => {
        setBillId(row.id);
        setForm((current) => ({
            ...current,
            billing_address: pickAddress(row, current.billing_address),
        }));
    };

    const openNewAddress = () => {
        setComposer('new');
        setDraft({
            ...emptyAddress,
            label: 'Home',
            first_name: store.customer?.first_name || '',
            last_name: store.customer?.last_name || '',
            country_code: countries[0]?.code || 'US',
        });
    };

    const openEditAddress = (row) => {
        setComposer(row.id);
        setDraft({ ...emptyAddress, ...row });
    };

    const saveComposer = async (e) => {
        e.preventDefault();
        setSavingAddress(true);
        setError('');
        const draftErrors = localAddressErrors(addressForCheckout(draft), countries, customerSettings.addresses || {}, true);
        if (hasFieldErrors(draftErrors)) {
            setFieldErrors((current) => ({ ...current, draft: draftErrors }));
            setSavingAddress(false);
            return;
        }
        try {
            await csrf();
            let row;
            if (composer === 'new') {
                const { data } = await api.post('/account/addresses', {
                    ...draft,
                    is_default_shipping: addresses.length === 0,
                    is_default_billing: addresses.length === 0,
                });
                row = data.address;
            } else {
                const { data } = await api.patch(`/account/addresses/${composer}`, draft);
                row = data.address;
            }
            const rows = await loadAddresses();
            const saved = rows.find((a) => a.id === row.id) || row;
            forgetCheckoutDraft();
            selectSavedShip(saved);
            setComposer(null);
        } catch (err) {
            const mapped = apiAddressErrors(err.response?.data);
            if (hasFieldErrors(mapped.account) || hasFieldErrors(mapped.shipping) || hasFieldErrors(mapped.billing)) {
                setFieldErrors((current) => ({
                    ...current,
                    draft: { ...mapped.account, ...mapped.shipping, ...mapped.billing },
                }));
                setError(mapped.rest[0] || '');
            } else {
                setError(err.response?.data?.message || t('storefront.account.address_failed', 'Could not save this address.'));
            }
        } finally {
            setSavingAddress(false);
        }
    };

    const place = async (e) => {
        e.preventDefault();
        setError('');
        const maxItems = Number(store.shop?.commerce?.cart?.max_cart_items || 0);
        const cartQty = items.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        if (maxItems > 0 && cartQty - maxItems > 0.00001) {
            setError(t('storefront.checkout.cart_full', 'This cart can hold at most {max} items.').replace('{max}', String(maxItems)));
            return;
        }
        const limitError = orderTotalLimitError(grand, totals, orderSettings);
        if (limitError) {
            setError(limitError);
            return;
        }
        if (registrationBlocked) {
            return;
        }
        const creatingAccount = mustCreateAccount || (createAccount && !loggedIn);
        if (creatingAccount) {
            if (!(form.first_name || '').trim() || !(form.last_name || '').trim()) {
                setError(t('storefront.register.name_required', 'Enter your first name and last name.'));
                return;
            }
            if (dobMode === 'required' && !(form.date_of_birth || '').trim()) {
                setError(t('storefront.register.dob_required_msg', 'Enter your date of birth.'));
                return;
            }
            if (!passwordReady(form.password)) {
                setError(t('storefront.auth.password_unmet', 'Use 8–72 characters, a capital letter, a number, and no spaces.'));
                return;
            }
            if (form.password !== form.password_confirmation) {
                setError(t('storefront.checkout.password_mismatch', 'Password and re-password do not match.'));
                return;
            }
        }
        setBusy(true);
        try {
            await csrf();
            let savedAddressRow = null;
            const addressSettings = customerSettings.addresses || {};
            const requireShipping = addressSettings.require_shipping !== false;
            const requireBilling = addressSettings.require_billing !== false;

            if (loggedIn && composer) {
                const draftAddress = addressForCheckout(draft);
                const draftErrors = localAddressErrors(draftAddress, countries, addressSettings, true);
                if (hasFieldErrors(draftErrors)) {
                    setFieldErrors((current) => ({ ...current, draft: draftErrors }));
                    return;
                }
                if (composer === 'new') {
                    const { data } = await api.post('/account/addresses', {
                        ...draft,
                        is_default_shipping: addresses.length === 0,
                        is_default_billing: addresses.length === 0,
                    });
                    savedAddressRow = data.address;
                } else {
                    const { data } = await api.patch(`/account/addresses/${composer}`, draft);
                    savedAddressRow = data.address;
                }
                const rows = await loadAddresses();
                savedAddressRow = rows.find((a) => a.id === savedAddressRow.id) || savedAddressRow;
                forgetCheckoutDraft();
                setShipId(savedAddressRow.id);
                if (form.same) {
                    setBillId(savedAddressRow.id);
                }
                setComposer(null);
            }

            const shippingAddress = savedAddressRow
                ? addressForCheckout(savedAddressRow)
                : resolveShippingAddress({ loggedIn, composer, draft, shipId, addresses, form });
            const billingAddress = form.same
                ? shippingAddress
                : resolveBillingAddress({ loggedIn, billId, addresses, form });

            const billingSource = form.same ? shippingAddress : billingAddress;
            const shipProblems = (!isPickup && requireShipping)
                ? localAddressErrors(shippingAddress, countries, addressSettings, true)
                : {};
            const billProblems = requireBilling
                ? localAddressErrors(billingSource, countries, addressSettings, true)
                : {};
            const visibleShip = form.same ? { ...shipProblems, ...billProblems } : shipProblems;
            if (hasFieldErrors(visibleShip) || (!form.same && hasFieldErrors(billProblems))) {
                setFieldErrors({
                    shipping: visibleShip,
                    billing: form.same ? {} : billProblems,
                    draft: {},
                });
                return;
            }
            setFieldErrors({ shipping: {}, billing: {}, draft: {} });

            if (createAccount && !mustCreateAccount && !loggedIn) {
                const { data: registered } = await api.post('/auth/register', {
                    first_name: form.first_name || shippingAddress.first_name,
                    last_name: form.last_name || shippingAddress.last_name || '',
                    email: form.email,
                    password: form.password,
                    password_confirmation: form.password_confirmation,
                    phone: shippingAddress.phone || '',
                    date_of_birth: form.date_of_birth || '',
                    gender: form.gender || '',
                });
                if (registered.customer) {
                    store.setCustomer?.(registered.customer);
                } else if (registered.pending && (registered.reason === 'approval' || /admin approval/i.test(registered.message || ''))) {
                    try {
                        sessionStorage.setItem('wento.register.pendingEmail', form.email || '');
                    } catch {
                        /* private mode */
                    }
                    nav('/register/pending', { replace: true, state: { email: form.email || '' } });
                    return;
                } else if (registered.pending) {
                    store.ping?.(registered.message || t('storefront.register.pending', 'Your account is waiting for approval.'));
                    setError(registered.message || t('storefront.register.pending', 'Your account is waiting for approval.'));
                    return;
                }
            }
            const paymentContext = hasInlinePaymentUi
                ? collectPaymentContext(paymentUiBlock.extension)
                : undefined;

            const checkoutPayload = {
                email: form.email || store.customer?.email,
                payment_method_id: Number(form.payment_method_id),
                shipping_method_id: Number(form.shipping_method_id),
                billing_address: (requireBilling || isAddressComplete(billingSource, addressSettings)) ? billingSource : null,
                shipping_address: isPickup
                    ? null
                    : ((requireShipping || isAddressComplete(shippingAddress, addressSettings)) ? shippingAddress : null),
                customer_note: form.customer_note,
                accept_terms: needTerms ? form.accept_terms : true,
                payment_context: paymentContext && Object.keys(paymentContext).length > 0 ? paymentContext : undefined,
                ...(mustCreateAccount ? {
                    create_account: true,
                    first_name: form.first_name,
                    last_name: form.last_name,
                    date_of_birth: form.date_of_birth,
                    gender: form.gender,
                    password: form.password,
                    password_confirmation: form.password_confirmation,
                } : {}),
            };

            let paymentProof = null;
            if (hasInlinePaymentUi && supportsPaymentFirst(paymentUiBlock.extension)) {
                paymentProof = await confirmBeforeCheckout(paymentUiBlock.extension, checkoutPayload);
            }

            const { data } = await api.post('/checkout', {
                ...checkoutPayload,
                payment_proof: paymentProof && Object.keys(paymentProof).length > 0 ? paymentProof : undefined,
            });
            if (data.customer) {
                store.setCustomer?.(data.customer);
            }
            if (data.payment?.redirect_url) {
                if (!goSafeRedirect(data.payment.redirect_url)) {
                    setError(t('storefront.checkout.failed', 'Checkout could not be completed.'));
                }
                return;
            }
            if (data.order?.number) {
                nav(`/order/${data.order.number}`, { state: { order: data.order } });
                return;
            }
            const clientAction = data.payment?.meta?.client_action;
            const clientExtension = data.payment?.meta?.extension;
            if (clientAction === 'inline_confirm' && clientExtension && data.payment?.status === 'pending') {
                setPendingPaymentOrder(data.order?.number || '');
                const result = await confirmClientAction(clientExtension, data.order, data.payment?.meta || {});
                const redirect = safeRedirectUrl(result.redirect) || (data.order?.number ? `/order/${data.order.number}` : null);
                if (redirect) {
                    window.location.href = redirect;
                } else {
                    setError(t('storefront.checkout.failed', 'Checkout could not be completed.'));
                }
                return;
            }
            if (clientAction === 'redirect' && data.payment?.meta?.redirect_url) {
                if (!goSafeRedirect(data.payment.meta.redirect_url)) {
                    setError(t('storefront.checkout.failed', 'Checkout could not be completed.'));
                }
                return;
            }
            if (data.order?.number) {
                nav(`/order/${data.order.number}`, { state: { order: data.order } });
                return;
            }
            setError(t('storefront.checkout.failed', 'Checkout could not be completed.'));
        } catch (err) {
            setPendingPaymentOrder('');
            if (!err?.inlinePayment) {
                const mapped = apiAddressErrors(err.response?.data);
                const billing = { ...mapped.billing, ...mapped.account };
                const fieldHit = hasFieldErrors(mapped.shipping) || hasFieldErrors(billing);
                if (fieldHit) {
                    setFieldErrors({
                        shipping: form.same ? { ...mapped.shipping, ...billing } : mapped.shipping,
                        billing: form.same ? {} : billing,
                        draft: {},
                    });
                    setError(mapped.rest[0] || '');
                } else {
                    setError(checkoutErrorMessage(err) || t('storefront.checkout.failed', 'Checkout failed'));
                }
            }
        } finally {
            setBusy(false);
        }
    };

    const applyCoupon = async (e) => {
        e.preventDefault();
        await csrf();
        try {
            await api.post('/cart/coupon', { code: form.coupon });
            store.reloadCart({ shipping_method_id: shipping?.id || '', country_code: form.shipping_address.country_code });
            store.ping?.(t('storefront.cart.coupon_ok', 'Coupon applied'));
        } catch {
            store.ping?.(t('storefront.cart.coupon_bad', 'This coupon cannot be applied.'), 'error');
        }
    };

    const applyGiftCard = async (e) => {
        e.preventDefault();
        if (!form.gift_card.trim() || giftBusy) {
            return;
        }
        setGiftBusy(true);
        await csrf();
        try {
            await api.post('/cart/gift-card', { code: form.gift_card.trim() });
            await store.reloadCart({
                shipping_method_id: shipping?.id || '',
                country_code: form.shipping_address.country_code,
            });
            store.ping?.(t('storefront.cart.gift_applied', 'Gift card applied'));
            setForm((cur) => ({ ...cur, gift_card: '' }));
        } catch (err) {
            const message = err?.response?.data?.message
                || err?.response?.data?.errors?.gift_card?.[0]
                || err?.response?.data?.errors?.gift_card_code?.[0]
                || t('storefront.cart.gift_invalid', 'This gift card cannot be applied.');
            store.ping?.(message, 'error');
        } finally {
            setGiftBusy(false);
        }
    };

    const removeGiftCard = async () => {
        if (giftBusy) {
            return;
        }
        setGiftBusy(true);
        await csrf();
        try {
            await api.delete('/cart/gift-card');
            await store.reloadCart({
                shipping_method_id: shipping?.id || '',
                country_code: form.shipping_address.country_code,
            });
            store.ping?.(t('storefront.cart.gift_removed', 'Gift card removed'));
        } catch (err) {
            store.ping?.(
                err?.response?.data?.message || t('storefront.cart.gift_remove_failed', 'Could not remove the gift card.'),
                'error',
            );
        } finally {
            setGiftBusy(false);
        }
    };

    if (!authReady || !store.cart || (loggedIn && !addressesReady)) {
        return <CheckoutPageSkeleton />;
    }

    if (items.length === 0 && !pendingPaymentOrder) {
        return (
            <section className="shop-checkout-stage">
                <div className="shop-container py-16">
                    <EmptyState
                        title={t('storefront.cart.empty', 'Your cart is empty')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>}
                    />
                </div>
            </section>
        );
    }

    const customerName = [store.customer?.first_name, store.customer?.last_name].filter(Boolean).join(' ')
        || store.customer?.name
        || store.customer?.email
        || '';
    const couponLabel = totals.discount_code || totals.discount_name || couponCode || null;
    const activeShippingSource = composer
        ? draft
        : (loggedIn && shipId
            ? (addresses.find((a) => String(a.id) === String(shipId)) || form.shipping_address)
            : form.shipping_address);
    const billingSource = form.same
        ? activeShippingSource
        : (loggedIn && billId
            ? (addresses.find((a) => String(a.id) === String(billId)) || form.billing_address)
            : form.billing_address);
    const creatingAccount = mustCreateAccount || (!loggedIn && createAccount);
    const hasPersonal = !registrationBlocked && (loggedIn || (!!(form.email || '').trim() && (
        !creatingAccount
        || (
            !!(form.first_name || '').trim()
            && !!(form.last_name || '').trim()
            && (dobMode !== 'required' || !!(form.date_of_birth || '').trim())
            && passwordReady(form.password)
            && form.password === form.password_confirmation
        )
    )));
    const addressSettings = customerSettings.addresses || {};
    const requireShipping = addressSettings.require_shipping !== false;
    const requireBilling = addressSettings.require_billing !== false;
    const hasShip = isPickup || !requireShipping || isAddressComplete(addressForCheckout(activeShippingSource), addressSettings, countries);
    const hasInvoice = !requireBilling || isAddressComplete(addressForCheckout(billingSource), addressSettings, countries);
    const hasDelivery = !!form.shipping_method_id;
    const hasPayment = !!form.payment_method_id;
    const hasShippingStep = hasShip && hasInvoice && hasDelivery;
    const stepClass = (index) => {
        if (index === 0) {
            return hasPersonal ? 'is-done' : 'is-active';
        }
        if (index === 1) {
            if (!hasPersonal) {
                return '';
            }
            return hasShippingStep ? 'is-done' : 'is-active';
        }
        if (index === 2) {
            if (!hasPersonal || !hasShippingStep) {
                return '';
            }
            return hasPayment ? 'is-done' : 'is-active';
        }
        return hasPersonal && hasShippingStep && hasPayment ? 'is-active' : '';
    };
    const goNext = (from) => {
        setError('');
        if (from === 0 && !hasPersonal) {
            if (registrationBlocked) {
                return;
            }
            if (!loggedIn && !(form.email || '').trim()) {
                setError(t('storefront.checkout.email_required', 'Please enter your email address.'));
            } else if (creatingAccount && !(form.first_name || '').trim()) {
                setError(t('storefront.register.first_name_required', 'Enter your first name.'));
            } else if (creatingAccount && !(form.last_name || '').trim()) {
                setError(t('storefront.register.last_name_required', 'Enter your last name.'));
            } else if (creatingAccount && dobMode === 'required' && !(form.date_of_birth || '').trim()) {
                setError(t('storefront.register.dob_required_msg', 'Enter your date of birth.'));
            } else if (creatingAccount && form.password !== form.password_confirmation) {
                setError(t('storefront.checkout.password_mismatch', 'Password and re-password do not match.'));
            } else {
                setError(t('storefront.auth.password_unmet', 'Use 8–72 characters, a capital letter, a number, and no spaces.'));
            }
            return;
        }
        if (from === 1 && !hasShippingStep) {
            const shipProblems = isPickup || !requireShipping
                ? {}
                : localAddressErrors(addressForCheckout(activeShippingSource), countries, addressSettings, true);
            const billProblems = !requireBilling
                ? {}
                : localAddressErrors(addressForCheckout(billingSource), countries, addressSettings, true);
            setFieldErrors({
                shipping: form.same ? { ...shipProblems, ...billProblems } : shipProblems,
                billing: form.same ? {} : billProblems,
                draft: composer ? localAddressErrors(addressForCheckout(draft), countries, addressSettings, true) : {},
            });
            setError(!hasDelivery && hasShip && hasInvoice
                ? t('storefront.checkout.delivery_required', 'Please choose a delivery method.')
                : '');
            return;
        }
        if (from === 2 && !hasPayment) {
            setError(t('storefront.checkout.payment_required', 'Please choose a payment method.'));
            return;
        }
        setWizard(from + 1);
    };

    const jumpToStep = (targetStep, panelId) => {
        if (stepped) {
            setWizard(targetStep);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else if (panelId) {
            const el = document.getElementById(panelId);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    };

    const reviewShippingObj = addressForCheckout(activeShippingSource);
    const reviewBillingObj = addressForCheckout(billingSource);
    const reviewShippingCountry = countries.find((c) => c.code === reviewShippingObj?.country_code)?.name || reviewShippingObj?.country_code || '';
    const reviewBillingCountry = countries.find((c) => c.code === reviewBillingObj?.country_code)?.name || reviewBillingObj?.country_code || '';

    const reviewShippingRecipient = [reviewShippingObj?.first_name, reviewShippingObj?.last_name].filter(Boolean).join(' ');
    const reviewShippingStreet = [reviewShippingObj?.line1, reviewShippingObj?.line2].filter(Boolean).join(', ');
    const reviewShippingCityStateZip = [reviewShippingObj?.city, reviewShippingObj?.state, reviewShippingObj?.postal_code].filter(Boolean).join(', ');

    const reviewBillingRecipient = [reviewBillingObj?.first_name, reviewBillingObj?.last_name].filter(Boolean).join(' ');
    const reviewBillingStreet = [reviewBillingObj?.line1, reviewBillingObj?.line2].filter(Boolean).join(', ');
    const reviewBillingCityStateZip = [reviewBillingObj?.city, reviewBillingObj?.state, reviewBillingObj?.postal_code].filter(Boolean).join(', ');

    const reviewCustomerName = customerName || reviewShippingRecipient || (loggedIn ? store.customer?.name : '') || t('storefront.checkout.guest', 'Guest');
    const reviewCustomerEmail = (loggedIn ? store.customer?.email : form.email) || form.email || '';
    const reviewCustomerPhone = reviewShippingObj?.phone || store.customer?.phone || '';

    const reviewDeliveryPrice = shipping?.price != null
        ? (Number(shipping.price) === 0 ? t('storefront.cart.free', 'Free') : money(shipping.price))
        : null;
    const reviewDeliveryEta = shipping?.eta
        ? `${t('storefront.checkout.eta', 'Estimated Delivery')}: ${shipping.eta}`
        : (shipping?.description || '');

    const reviewContact = loggedIn
        ? [customerName, store.customer?.email].filter(Boolean).join(' · ')
        : (form.email || '');
    const reviewShipping = formatAddressLine(reviewShippingObj);
    const reviewBilling = form.same
        ? t('storefront.checkout.billing_same', 'Billing address same as shipping address')
        : formatAddressLine(reviewBillingObj);
    const reviewDelivery = shipping?.name || t('storefront.checkout.not_selected', 'Not selected');
    const reviewPayment = payment?.name || t('storefront.checkout.not_selected', 'Not selected');

    return (
        <form className="shop-checkout-stage" onSubmit={place}>
            <div className="shop-container shop-checkout-shell">
                <Breadcrumbs items={[
                    { name: t('storefront.shell.home', 'Home'), url: '/' },
                    { name: t('storefront.shell.cart', 'Cart'), url: '/cart' },
                    { name: t('storefront.checkout.title', 'Checkout'), url: null },
                ]} />

                <header className="shop-checkout-head">
                    <div>
                        <h1>{t('storefront.checkout.title', 'Checkout')}</h1>
                        {!loggedIn && guestCheckout ? (
                            <p>
                                {t('storefront.checkout.guest', 'Guest checkout is allowed. Create an account anytime.')}
                                {' '}
                                <Link to="/login" className="shop-checkout-signin">{t('storefront.login.submit', 'Sign in')}</Link>
                            </p>
                        ) : null}
                        {mustCreateAccount ? (
                            <p>
                                {t('storefront.checkout.account_required', 'An account is required to place this order. Enter your email and password below, or sign in.')}
                                {' '}
                                <Link to="/login" className="shop-checkout-signin" state={{ from: '/checkout' }}>{t('storefront.login.submit', 'Sign in')}</Link>
                            </p>
                        ) : null}
                    </div>
                    <p className="shop-checkout-secure">
                        <Icon name="shield" className="h-4 w-4" />
                        {t('storefront.checkout.secure_badge', 'Secure checkout')}
                    </p>
                </header>

                {registrationBlocked ? (
                    <div className="shop-checkout-banner" role="status">
                        <Icon name="alertTriangle" className="h-5 w-5" />
                        <p>{accountClosedMessage}</p>
                    </div>
                ) : null}

                <ol className="shop-checkout-steps" aria-label={t('storefront.checkout.progress', 'Checkout progress')}>
                    {STEPS.map((step, index) => (
                        <li key={step.key} className={stepClass(index)}>
                            <span>{index + 1}</span>
                            <em>{t(`storefront.checkout.step_${step.key}`, step.label)}</em>
                        </li>
                    ))}
                </ol>

                <div className="shop-checkout-grid">
                    <div className="shop-checkout-main">
                        <section className="shop-checkout-panel" id="checkout-panel-personal" hidden={stepped && wizard !== 0}>
                            <h2 className="shop-checkout-panel-title">
                                <span className="shop-checkout-step-num">1</span>
                                <span>{t('storefront.checkout.step_personal', 'Personal Information')}</span>
                            </h2>

                            {loggedIn ? (
                                <div className="shop-checkout-fields-2 shop-checkout-contact-readonly">
                                    <FormField label={t('storefront.checkout.full_name', 'Full Name')}>
                                        <input className="shop-input" value={customerName} readOnly />
                                    </FormField>
                                    <FormField label={t('storefront.checkout.email', 'Email Address')}>
                                        <input className="shop-input" value={store.customer?.email || ''} readOnly />
                                    </FormField>
                                </div>
                            ) : registrationBlocked ? null : (
                                <>
                                    <FormField label={t('storefront.checkout.email', 'Email Address')} required>
                                        <input
                                            required
                                            type="email"
                                            className="shop-input"
                                            placeholder={t('storefront.checkout.email_ph', 'Enter your email address')}
                                            value={form.email}
                                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                                        />
                                    </FormField>

                                    {mustCreateAccount ? null : (
                                        <label className="shop-checkout-check">
                                            <input
                                                type="checkbox"
                                                checked={createAccount}
                                                onChange={(e) => {
                                                    const on = e.target.checked;
                                                    setCreateAccount(on);
                                                    if (!on) {
                                                        setForm((current) => ({ ...current, password: '', password_confirmation: '' }));
                                                    }
                                                }}
                                            />
                                            <span>{t('storefront.checkout.create_account', 'Create an account for faster checkout next time')}</span>
                                        </label>
                                    )}

                                    {creatingAccount ? (
                                        <div className="shop-checkout-passwords">
                                            <div className="shop-checkout-fields-2">
                                                <FormField label={t('storefront.register.first_name', 'First name')} required>
                                                    <input
                                                        className="shop-input"
                                                        autoComplete="given-name"
                                                        value={form.first_name}
                                                        onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                                                    />
                                                </FormField>
                                                <FormField label={t('storefront.register.last_name', 'Last name')} required>
                                                    <input
                                                        className="shop-input"
                                                        autoComplete="family-name"
                                                        value={form.last_name}
                                                        onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                                                    />
                                                </FormField>
                                            </div>
                                            {dobMode !== 'hidden' ? (
                                                <FormField
                                                    label={dobMode === 'required'
                                                        ? t('storefront.register.dob_required', 'Date of birth')
                                                        : t('storefront.register.dob', 'Date of birth (optional)')}
                                                    required={dobMode === 'required'}
                                                >
                                                    <input
                                                        className="shop-input"
                                                        type="date"
                                                        value={form.date_of_birth}
                                                        onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })}
                                                    />
                                                </FormField>
                                            ) : null}
                                            <FormField label={t('storefront.register.gender', 'Gender (optional)')}>
                                                <select
                                                    className="shop-input"
                                                    value={form.gender}
                                                    onChange={(e) => setForm({ ...form, gender: e.target.value })}
                                                >
                                                    <option value="">{t('storefront.common.select', 'Prefer not to say')}</option>
                                                    <option value="female">{t('storefront.register.female', 'Female')}</option>
                                                    <option value="male">{t('storefront.register.male', 'Male')}</option>
                                                    <option value="other">{t('storefront.register.other', 'Other')}</option>
                                                </select>
                                            </FormField>
                                            <div className="shop-checkout-fields-2">
                                                <FormField label={t('storefront.register.password', 'Password')} required>
                                                    <PasswordInput
                                                        required
                                                        autoComplete="new-password"
                                                        minLength={8}
                                                        maxLength={72}
                                                        value={form.password}
                                                        onChange={(e) => setForm({ ...form, password: e.target.value })}
                                                    />
                                                </FormField>
                                                <FormField label={t('storefront.checkout.repassword', 'Re-password')} required>
                                                    <PasswordInput
                                                        required
                                                        autoComplete="new-password"
                                                        minLength={8}
                                                        maxLength={72}
                                                        value={form.password_confirmation}
                                                        onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
                                                    />
                                                </FormField>
                                            </div>
                                            <PasswordHint value={form.password} confirm={form.password_confirmation} />
                                        </div>
                                    ) : null}
                                </>
                            )}
                            {stepped && wizard === 0 ? (
                                <button type="button" className="shop-btn shop-btn-primary mt-4" onClick={() => goNext(0)}>
                                    {t('storefront.checkout.continue', 'Continue')}
                                </button>
                            ) : null}
                        </section>

                        <section className="shop-checkout-panel" id="checkout-panel-shipping" hidden={stepped && wizard !== 1}>
                            <h2 className="shop-checkout-panel-title">
                                <span className="shop-checkout-step-num">2</span>
                                <span>{t('storefront.checkout.step_shipping', 'Shipping & Invoice')}</span>
                            </h2>

                            {loggedIn ? (
                                <>
                                    <p className="shop-checkout-subhead">{isPickup ? t('storefront.checkout.pickup_location', 'Collect in store') : t('storefront.checkout.select_address', 'Shipping address')}</p>
                                    {isPickup ? (
                                        <p className="shop-checkout-muted">{t('storefront.checkout.pickup_note', 'No delivery address needed. We will use your billing details.')}</p>
                                    ) : !addressesReady ? (
                                        <div className="space-y-2 py-2">
                                            <div className="skeleton h-20 w-full rounded-xl" />
                                        </div>
                                    ) : addresses.length > 0 ? (
                                        <div className="shop-checkout-address-list">
                                            {addresses.map((row) => (
                                                <AddressCard
                                                    key={row.id}
                                                    row={row}
                                                    selected={String(shipId) === String(row.id)}
                                                    onSelect={() => selectSavedShip(row)}
                                                    onEdit={() => openEditAddress(row)}
                                                />
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="shop-checkout-muted">{t('storefront.checkout.no_addresses', 'No saved addresses yet. Add one below.')}</p>
                                    )}

                                    {composer ? (
                                        <div className="shop-checkout-composer">
                                            <h3>{composer === 'new'
                                                ? t('storefront.account.add_address', 'Add address')
                                                : t('storefront.account.edit_address', 'Edit address')}</h3>
                                            <AddressFields
                                                value={draft}
                                                onChange={(k, v) => {
                                                    setFieldErrors((current) => ({ ...current, draft: { ...current.draft, [k]: '' } }));
                                                    setDraft((d) => ({ ...d, [k]: v }));
                                                }}
                                                countries={countries}
                                                showLabel
                                                addressSettings={addressSettings}
                                                errors={fieldErrors.draft}
                                            />
                                            <div className="shop-checkout-composer-actions">
                                                {addresses.length > 0 ? (
                                                    <button type="button" className="shop-btn shop-btn-ghost" onClick={() => setComposer(null)}>
                                                        {t('storefront.common.cancel', 'Cancel')}
                                                    </button>
                                                ) : null}
                                                <button type="button" className="shop-btn shop-btn-primary" disabled={savingAddress} onClick={saveComposer}>
                                                    {savingAddress
                                                        ? t('storefront.common.saving', 'Saving…')
                                                        : t('storefront.common.save', 'Save')}
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <button type="button" className="shop-checkout-add-address" onClick={openNewAddress}>
                                            <Icon name="plus" className="h-4 w-4" />
                                            {t('storefront.checkout.add_address', 'Add New Address')}
                                        </button>
                                    )}

                                    <label className="shop-checkout-check">
                                        <input
                                            type="checkbox"
                                            checked={form.same}
                                            onChange={(e) => {
                                                const on = e.target.checked;
                                                setSameTouched(true);
                                                setForm((current) => ({
                                                    ...current,
                                                    same: on,
                                                    billing_address: on ? current.shipping_address : current.billing_address,
                                                }));
                                                if (on) {
                                                    setBillId(shipId);
                                                }
                                            }}
                                        />
                                        <span>{t('storefront.checkout.billing_same', 'Billing address same as shipping address')}</span>
                                    </label>

                                    {!form.same ? (
                                        <div className="shop-checkout-billing-block">
                                            <p className="shop-checkout-subhead">{t('storefront.checkout.billing', 'Billing address')}</p>
                                            {addresses.length ? (
                                                <div className="shop-checkout-address-list">
                                                    {addresses.map((row) => (
                                                        <AddressCard
                                                            key={`b-${row.id}`}
                                                            row={row}
                                                            name="bill-address"
                                                            selected={String(billId) === String(row.id)}
                                                            onSelect={() => selectSavedBill(row)}
                                                        />
                                                    ))}
                                                </div>
                                            ) : (
                                                <AddressFields value={form.billing_address} onChange={setBillField} countries={countries} addressSettings={addressSettings} requiredAddress={requireBilling} errors={fieldErrors.billing} />
                                            )}
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            className="shop-checkout-text-btn"
                                            onClick={() => setForm((current) => ({ ...current, same: false }))}
                                        >
                                            {t('storefront.checkout.change_billing', 'Use a different billing address')}
                                        </button>
                                    )}
                                </>
                            ) : (
                                <>
                                    <h3 className="shop-checkout-block-title">{t('storefront.checkout.shipping_address', 'Shipping address')}</h3>
                                    <AddressFields value={form.shipping_address} onChange={setShipField} countries={countries} addressSettings={addressSettings} requiredAddress={requireShipping && !isPickup} errors={fieldErrors.shipping} />

                                    <label className="shop-checkout-check">
                                        <input
                                            type="checkbox"
                                            checked={form.same}
                                            onChange={(e) => {
                                                const on = e.target.checked;
                                                setSameTouched(true);
                                                setForm((current) => ({
                                                    ...current,
                                                    same: on,
                                                    billing_address: on ? current.shipping_address : current.billing_address,
                                                }));
                                            }}
                                        />
                                        <span>{t('storefront.checkout.billing_same', 'Billing address same as shipping address')}</span>
                                    </label>

                                    {!form.same ? (
                                        <div className="shop-checkout-billing-block">
                                            <h3 className="shop-checkout-block-title">{t('storefront.checkout.billing', 'Billing address')}</h3>
                                            <AddressFields value={form.billing_address} onChange={setBillField} countries={countries} addressSettings={addressSettings} requiredAddress={requireBilling} errors={fieldErrors.billing} />
                                        </div>
                                    ) : null}
                                </>
                            )}

                            <p className="shop-checkout-subhead">{t('storefront.checkout.delivery_method', 'Delivery Method')}</p>
                            {optionsLoading && (options.shipping_methods || []).length === 0 ? (
                                <div className="space-y-2.5 my-3">
                                    <div className="skeleton h-14 w-full rounded-xl" />
                                    <div className="skeleton h-14 w-full rounded-xl" />
                                </div>
                            ) : (
                                <div className="shop-checkout-options">
                                    {options.shipping_methods.map((method) => {
                                        const selected = String(form.shipping_method_id) === String(method.id);
                                        const free = !Number(method.price);
                                        const isCalculating = Boolean(method.calculating);
                                        return (
                                            <label key={method.id} className={`shop-checkout-option${selected ? ' is-selected' : ''}`}>
                                                <input
                                                    type="radio"
                                                    name="shipping"
                                                    checked={selected}
                                                    onChange={() => setForm({ ...form, shipping_method_id: String(method.id) })}
                                                />
                                                <span className="shop-checkout-option-body">
                                                    <strong>{method.name}</strong>
                                                    {method.description ? <em>{method.description}</em> : null}
                                                    {method.eta ? <em>{method.eta}</em> : null}
                                                </span>
                                                <span className={`shop-checkout-option-price${free && !isCalculating ? ' is-free' : ''}`}>
                                                    {isCalculating ? (
                                                        <span className="shop-carrier-calc">
                                                            <span className="shop-carrier-calc-spinner" />
                                                            {t('storefront.checkout.calculating', 'Calculating...')}
                                                        </span>
                                                    ) : free ? (
                                                        t('storefront.checkout.free', 'FREE')
                                                    ) : (
                                                        money(method.price)
                                                    )}
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                            <ModuleBlocks slots={options.modules?.shipping || []} />
                            {stepped && wizard === 1 ? (
                                <button type="button" className="shop-btn shop-btn-primary mt-4" onClick={() => goNext(1)}>
                                    {t('storefront.checkout.continue', 'Continue')}
                                </button>
                            ) : null}
                            {(options.modules?.delivery || []).length ? (
                                <div className="shop-checkout-modules">
                                    <ModuleBlocks slots={options.modules.delivery} />
                                </div>
                            ) : null}
                        </section>

                        <section className="shop-checkout-panel" id="checkout-panel-payment" hidden={stepped && wizard !== 2}>
                            <h2 className="shop-checkout-panel-title">
                                <span className="shop-checkout-step-num">3</span>
                                <span>{t('storefront.checkout.payment_method', 'Payment Method')}</span>
                            </h2>
                            {optionsLoading && (options.payment_methods || []).length === 0 ? (
                                <div className="space-y-2.5 my-3">
                                    <div className="skeleton h-12 w-full rounded-xl" />
                                    <div className="skeleton h-12 w-full rounded-xl" />
                                </div>
                            ) : (
                                <div className="shop-checkout-options shop-checkout-options--pay">
                                    {options.payment_methods.map((method) => {
                                    const selected = String(form.payment_method_id) === String(method.id);
                                    const uiBlock = (options.modules?.payment || []).find((block) => matchesPaymentUiBlock(block, method));
                                    const noticeBlock = (options.modules?.payment || []).find((block) => matchesPaymentNoticeBlock(block, method));
                                    const expanded = selected && !!(uiBlock || noticeBlock);
                                    return (
                                        <div
                                            key={method.id}
                                            className={`shop-checkout-pay-item${expanded ? ' is-expanded' : ''}${selected && !uiBlock && !noticeBlock ? ' is-selected' : ''}`}
                                        >
                                            <label className={`shop-checkout-option${selected ? ' is-selected' : ''}${expanded ? ' is-embedded' : ''}`}>
                                                <input
                                                    type="radio"
                                                    name="payment"
                                                    checked={selected}
                                                    onChange={() => setForm({ ...form, payment_method_id: String(method.id) })}
                                                />
                                                <span className="shop-checkout-option-body">
                                                    <strong>{method.name}</strong>
                                                    {method.description ? <em>{method.description}</em> : null}
                                                </span>
                                            </label>
                                            {expanded ? (
                                                <div className="shop-checkout-pay-ui">
                                                    {noticeBlock ? <PaymentNotice block={noticeBlock} /> : null}
                                                    {uiBlock ? (
                                                        <PaymentUiHost
                                                            block={uiBlock}
                                                            enabled={selected}
                                                            checkoutContext={paymentUiContext}
                                                            paymentMethod={method}
                                                        />
                                                    ) : null}
                                                </div>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                            <ModuleBlocks slots={(options.modules?.payment || []).filter((slot) => slot.type !== 'payment_ui' && slot.type !== 'payment_notice')} />
                            {giftWrapEnabled ? (
                                <label className="shop-checkout-check mt-3">
                                    <input
                                        type="checkbox"
                                        checked={!!store.cart?.cart?.gift_wrap}
                                        onChange={async (e) => {
                                            try {
                                                await csrf();
                                                await api.post('/cart/gift-wrap', { enabled: e.target.checked });
                                                store.reloadCart();
                                            } catch (err) {
                                                setError(err?.response?.data?.message || t('storefront.checkout.gift_wrap_off', 'Gift wrap is turned off.'));
                                            }
                                        }}
                                    />
                                    <span>
                                        {t('storefront.checkout.gift_wrap', 'Gift wrap')}
                                        {Number(store.shop?.commerce?.cart?.gift_wrap_fee) > 0
                                            ? ` (${money(store.shop.commerce.cart.gift_wrap_fee)})`
                                            : ''}
                                    </span>
                                </label>
                            ) : null}
                            {loggedIn && Number(totals.store_credit_balance || store.cart?.totals?.store_credit_balance || 0) > 0 ? (
                                <label className="shop-checkout-check mt-3">
                                    <input
                                        type="checkbox"
                                        checked={store.cart?.cart?.apply_store_credit !== false}
                                        onChange={async (e) => {
                                            await csrf();
                                            await api.post('/cart/store-credit', { enabled: e.target.checked });
                                            store.reloadCart();
                                        }}
                                    />
                                    <span>{t('storefront.checkout.apply_store_credit', 'Apply store credit')}</span>
                                </label>
                            ) : null}
                            {loggedIn && loyaltyOn ? (
                                <label className="shop-checkout-check mt-3">
                                    <input
                                        type="checkbox"
                                        checked={store.cart?.cart?.apply_loyalty !== false}
                                        onChange={async (e) => {
                                            await csrf();
                                            await api.post('/cart/loyalty', { enabled: e.target.checked });
                                            store.reloadCart();
                                        }}
                                    />
                                    <span>{t('storefront.checkout.apply_loyalty', 'Redeem loyalty points')}</span>
                                </label>
                            ) : null}
                            {loggedIn ? (
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-ghost mt-3"
                                    onClick={async () => {
                                        await csrf();
                                        await api.post('/account/quotes', {});
                                        store.ping?.(t('storefront.checkout.quote_sent', 'Quote requested.'));
                                    }}
                                >
                                    {t('storefront.checkout.request_quote', 'Request a quote')}
                                </button>
                            ) : null}
                            {stepped && wizard === 2 ? (
                                <button type="button" className="shop-btn shop-btn-primary mt-4" onClick={() => goNext(2)}>
                                    {t('storefront.checkout.continue', 'Continue')}
                                </button>
                            ) : null}
                        </section>

                        <section className="shop-checkout-panel" id="checkout-panel-review" hidden={stepped && wizard !== 3}>
                            <h2 className="shop-checkout-panel-title">
                                <span className="shop-checkout-step-num">4</span>
                                <span>{t('storefront.checkout.step_review', 'Review & Confirm')}</span>
                            </h2>

                            <div className="shop-checkout-review-wrap">
                                <div className="shop-checkout-review-grid">
                                    {/* Card 1: Personal / Contact */}
                                    <div className="shop-checkout-review-card">
                                        <div className="shop-checkout-review-card-head">
                                            <div className="shop-checkout-review-card-title">
                                                <span className="shop-checkout-review-icon-bubble">
                                                    <Icon name="user" className="h-4 w-4" />
                                                </span>
                                                <span>{t('storefront.checkout.step_personal', 'Personal Information')}</span>
                                            </div>
                                            <button
                                                type="button"
                                                className="shop-checkout-review-edit-btn"
                                                onClick={() => jumpToStep(0, 'checkout-panel-personal')}
                                                title={t('storefront.cart.edit', 'Edit')}
                                            >
                                                <Icon name="pencil" className="h-3 w-3" />
                                                <span>{t('storefront.cart.edit', 'Edit')}</span>
                                            </button>
                                        </div>
                                        <div className="shop-checkout-review-card-body">
                                            <div className="shop-checkout-review-val-primary">{reviewCustomerName}</div>
                                            {reviewCustomerEmail ? (
                                                <div className="shop-checkout-review-val-secondary">{reviewCustomerEmail}</div>
                                            ) : null}
                                            {reviewCustomerPhone ? (
                                                <div className="shop-checkout-review-val-muted">{reviewCustomerPhone}</div>
                                            ) : null}
                                        </div>
                                    </div>

                                    {/* Card 2: Payment Method */}
                                    <div className="shop-checkout-review-card">
                                        <div className="shop-checkout-review-card-head">
                                            <div className="shop-checkout-review-card-title">
                                                <span className="shop-checkout-review-icon-bubble">
                                                    <Icon name="card" className="h-4 w-4" />
                                                </span>
                                                <span>{t('storefront.checkout.payment_method', 'Payment Method')}</span>
                                            </div>
                                            <button
                                                type="button"
                                                className="shop-checkout-review-edit-btn"
                                                onClick={() => jumpToStep(2, 'checkout-panel-payment')}
                                                title={t('storefront.cart.edit', 'Edit')}
                                            >
                                                <Icon name="pencil" className="h-3 w-3" />
                                                <span>{t('storefront.cart.edit', 'Edit')}</span>
                                            </button>
                                        </div>
                                        <div className="shop-checkout-review-card-body">
                                            <div className="shop-checkout-review-val-primary">{payment?.name || t('storefront.checkout.not_selected', 'Not selected')}</div>
                                            <div className="shop-checkout-review-val-secondary">
                                                <span className="shop-checkout-review-pill is-brand">
                                                    <Icon name="shield" className="h-3 w-3" />
                                                    <span>{payment?.driver === 'offline' ? t('storefront.checkout.offline_pay', 'Manual Payment') : t('storefront.checkout.secure_pay', 'Encrypted Payment')}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 3: Shipping Destination */}
                                    <div className="shop-checkout-review-card">
                                        <div className="shop-checkout-review-card-head">
                                            <div className="shop-checkout-review-card-title">
                                                <span className="shop-checkout-review-icon-bubble">
                                                    <Icon name="pin" className="h-4 w-4" />
                                                </span>
                                                <span>{t('storefront.checkout.shipping_address', 'Shipping Address')}</span>
                                            </div>
                                            <button
                                                type="button"
                                                className="shop-checkout-review-edit-btn"
                                                onClick={() => jumpToStep(1, 'checkout-panel-shipping')}
                                                title={t('storefront.cart.edit', 'Edit')}
                                            >
                                                <Icon name="pencil" className="h-3 w-3" />
                                                <span>{t('storefront.cart.edit', 'Edit')}</span>
                                            </button>
                                        </div>
                                        <div className="shop-checkout-review-card-body">
                                            {isPickup ? (
                                                <>
                                                    <div className="shop-checkout-review-val-primary">{t('storefront.checkout.pickup_location', 'Collect in store')}</div>
                                                    <div className="shop-checkout-review-val-secondary">{t('storefront.checkout.pickup_note', 'No delivery address needed.')}</div>
                                                </>
                                            ) : (
                                                <>
                                                    <div className="shop-checkout-review-val-primary">{reviewShippingRecipient || t('storefront.checkout.not_selected', 'Not selected')}</div>
                                                    {reviewShippingStreet ? <div className="shop-checkout-review-val-secondary">{reviewShippingStreet}</div> : null}
                                                    {reviewShippingCityStateZip ? <div className="shop-checkout-review-val-secondary">{reviewShippingCityStateZip}</div> : null}
                                                    {reviewShippingCountry ? <div className="shop-checkout-review-val-muted">{reviewShippingCountry}</div> : null}
                                                </>
                                            )}
                                        </div>
                                    </div>

                                    {/* Card 4: Billing Address */}
                                    <div className="shop-checkout-review-card">
                                        <div className="shop-checkout-review-card-head">
                                            <div className="shop-checkout-review-card-title">
                                                <span className="shop-checkout-review-icon-bubble">
                                                    <Icon name="building" className="h-4 w-4" />
                                                </span>
                                                <span>{t('storefront.checkout.billing', 'Billing Address')}</span>
                                            </div>
                                            <button
                                                type="button"
                                                className="shop-checkout-review-edit-btn"
                                                onClick={() => jumpToStep(1, 'checkout-panel-shipping')}
                                                title={t('storefront.cart.edit', 'Edit')}
                                            >
                                                <Icon name="pencil" className="h-3 w-3" />
                                                <span>{t('storefront.cart.edit', 'Edit')}</span>
                                            </button>
                                        </div>
                                        <div className="shop-checkout-review-card-body">
                                            {form.same ? (
                                                <>
                                                    <div className="shop-checkout-review-pill is-success">
                                                        <Icon name="check" className="h-3 w-3" />
                                                        <span>{t('storefront.checkout.billing_same', 'Same as shipping address')}</span>
                                                    </div>
                                                    <div className="shop-checkout-review-val-muted mt-1">
                                                        {t('storefront.checkout.billing_same_hint', 'Invoice details will match your shipping destination.')}
                                                    </div>
                                                </>
                                            ) : (
                                                <>
                                                    <div className="shop-checkout-review-val-primary">{reviewBillingRecipient || t('storefront.checkout.not_selected', 'Not selected')}</div>
                                                    {reviewBillingStreet ? <div className="shop-checkout-review-val-secondary">{reviewBillingStreet}</div> : null}
                                                    {reviewBillingCityStateZip ? <div className="shop-checkout-review-val-secondary">{reviewBillingCityStateZip}</div> : null}
                                                    {reviewBillingCountry ? <div className="shop-checkout-review-val-muted">{reviewBillingCountry}</div> : null}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Delivery Method Banner Card */}
                                <div className="shop-checkout-review-delivery-card">
                                    <div className="shop-checkout-review-card-head">
                                        <div className="shop-checkout-review-card-title">
                                            <span className="shop-checkout-review-icon-bubble">
                                                <Icon name="truck" className="h-4 w-4" />
                                            </span>
                                            <span>{t('storefront.checkout.delivery_method', 'Delivery Method')}</span>
                                        </div>
                                        <button
                                            type="button"
                                            className="shop-checkout-review-edit-btn"
                                            onClick={() => jumpToStep(1, 'checkout-panel-shipping')}
                                            title={t('storefront.cart.edit', 'Edit')}
                                        >
                                            <Icon name="pencil" className="h-3 w-3" />
                                            <span>{t('storefront.cart.edit', 'Edit')}</span>
                                        </button>
                                    </div>
                                    <div className="shop-checkout-review-delivery-content">
                                        <div className="shop-checkout-review-delivery-info">
                                            <div className="shop-checkout-review-delivery-name">
                                                {shipping?.name || t('storefront.checkout.not_selected', 'Not selected')}
                                            </div>
                                            {reviewDeliveryEta ? (
                                                <div className="shop-checkout-review-delivery-eta">
                                                    {reviewDeliveryEta}
                                                </div>
                                            ) : (
                                                <div className="shop-checkout-review-val-muted">
                                                    {t('storefront.checkout.standard_shipping', 'Standard carrier dispatch')}
                                                </div>
                                            )}
                                        </div>
                                        {reviewDeliveryPrice != null ? (
                                            <div className="shop-checkout-review-delivery-price">
                                                <span className="shop-checkout-review-price-pill">
                                                    {reviewDeliveryPrice}
                                                </span>
                                            </div>
                                        ) : null}
                                    </div>
                                </div>

                                {/* Order Note */}
                                {notesEnabled ? (
                                    <div className="shop-checkout-review-note-box">
                                        <div className="shop-checkout-review-note-header">
                                            <span className="shop-checkout-review-note-title">
                                                <Icon name="file" className="h-4 w-4" />
                                                <span>{t('storefront.checkout.note_label', 'Order note')}</span>
                                            </span>
                                            <span className="shop-checkout-review-note-badge">{t('storefront.checkout.optional', 'Optional')}</span>
                                        </div>
                                        <textarea
                                            className="shop-checkout-review-note-input"
                                            rows="3"
                                            name="customer_note"
                                            value={form.customer_note}
                                            onChange={(e) => setForm({ ...form, customer_note: e.target.value })}
                                            placeholder={t('storefront.checkout.note_ph', 'Add a note for the seller…')}
                                        />
                                        <span className="shop-checkout-review-note-hint">
                                            {t('storefront.checkout.note_helper', 'Add special delivery instructions, gate codes, or notes for the seller.')}
                                        </span>
                                    </div>
                                ) : null}
                            </div>
                        </section>
                    </div>

                    <aside className="shop-checkout-summary">
                        <div className="shop-checkout-summary-head">
                            <h2>{t('storefront.cart.summary', 'Order Summary')}</h2>
                            <Link to="/cart">{t('storefront.checkout.edit_cart', 'Edit Cart')}</Link>
                        </div>

                        <ul className="shop-checkout-items">
                            {items.map((item) => {
                                const product = item.product || {};
                                const name = translatedName(product, store.shop?.locale, `Item ${item.product_id}`);
                                const href = productPath(product);
                                const priced = lineById[item.id];
                                const lineTotal = priced?.display_line_total
                                    ?? priced?.line_total
                                    ?? (Number(item.unit_price) * Number(item.qty || 1));
                                return (
                                    <li key={item.id}>
                                        <Link to={href} className="shop-checkout-item-thumb" state={{ entityType: 'product' }}>
                                            <ProductImage product={{
                                                name,
                                                cover: product.cover_path || product.cover,
                                                cover_url: product.cover_url,
                                            }} />
                                        </Link>
                                        <div className="shop-checkout-item-meta">
                                            <Link to={href} state={{ entityType: 'product' }}>{name}</Link>
                                            <span>{t('storefront.cart.qty', 'Qty')}: {item.qty}</span>
                                            {item.booking_date ? (
                                                <span>
                                                    {item.booking_end_date
                                                        ? `${t('storefront.cart.rental_period', 'Rental')}: ${String(item.booking_date).slice(0, 10)} → ${String(item.booking_end_date).slice(0, 10)}`
                                                        : `${t('storefront.cart.booking_date', 'Booking date')}: ${String(item.booking_date).slice(0, 10)}`}
                                                    {item.rental_data?.deposit > 0
                                                        ? ` · ${t('storefront.cart.rental_deposit', 'Deposit')}: ${money(item.rental_data.deposit)}`
                                                        : ''}
                                                </span>
                                            ) : null}
                                            {item.preorder_data?.final_payment || item.preorder_data?.kind === 'balance_payment' ? (
                                                <span>
                                                    {t('storefront.cart.preorder_final_payment', 'Pre-order final payment')}
                                                    {item.preorder_data.source_order_number ? ` · #${item.preorder_data.source_order_number}` : ''}
                                                    {` · ${t('storefront.cart.preorder_amount_due', 'Amount due')}: ${money(item.preorder_data.remaining_balance || lineTotal)}`}
                                                </span>
                                            ) : item.preorder_data?.enabled ? (
                                                <span>
                                                    {t('storefront.cart.preorder', 'Pre-order')}
                                                    {item.preorder_data.status ? `: ${String(item.preorder_data.status).replace(/_/g, ' ')}` : ''}
                                                    {item.preorder_data.remaining_balance > 0
                                                        ? ` · ${t('storefront.cart.preorder_remaining', 'Remaining')}: ${money(item.preorder_data.remaining_balance)}`
                                                        : ''}
                                                </span>
                                            ) : null}
                                            {(store.cart?.modules?.lines?.[item.id] || store.cart?.modules?.lines?.[String(item.id)] || []).length ? (
                                                <ModuleBlocks slots={store.cart.modules.lines[item.id] || store.cart.modules.lines[String(item.id)]} />
                                            ) : null}
                                        </div>
                                        <strong>{money(lineTotal)}</strong>
                                    </li>
                                );
                            })}
                        </ul>

                        <dl className="shop-checkout-rows">
                            <SummaryRow label={t('storefront.cart.subtotal', 'Subtotal')} value={money(totals.subtotal)} />
                            <SummaryRow
                                label={(
                                    <>
                                        {t('storefront.cart.discount', 'Discount')}
                                        {couponLabel && discount > 0 ? <em className="shop-checkout-coupon-pill">{couponLabel}</em> : null}
                                    </>
                                )}
                                value={discount > 0 ? `-${money(discount)}` : money(0)}
                                tone={discount > 0 ? 'good' : undefined}
                            />
                            <SummaryRow
                                label={t('storefront.cart.shipping', 'Shipping')}
                                value={quotedShipping ? money(quotedShipping) : t('storefront.checkout.free', 'FREE')}
                                tone={!quotedShipping ? 'good' : undefined}
                            />
                            {(totals.gift_wrap_fee > 0) ? (
                                <SummaryRow label={t('storefront.checkout.gift_wrap', 'Gift wrap')} value={money(totals.gift_wrap_fee)} />
                            ) : null}
                            {(totals.store_credit > 0) ? (
                                <SummaryRow label={t('storefront.checkout.store_credit', 'Store credit')} value={`-${money(totals.store_credit)}`} tone="good" />
                            ) : null}
                            {Number(totals.gift_card_credit) > 0 ? (
                                <SummaryRow
                                    label={(
                                        <span className="shop-cart-gift-applied">
                                            {`${t('storefront.cart.gift_card', 'Gift card')} ${totals.gift_card_code || ''}`}
                                            <button
                                                type="button"
                                                className="shop-cart-gift-remove"
                                                onClick={removeGiftCard}
                                                disabled={giftBusy}
                                            >
                                                {t('storefront.cart.remove', 'Remove')}
                                            </button>
                                        </span>
                                    )}
                                    value={`-${money(totals.gift_card_credit)}`}
                                    tone="good"
                                />
                            ) : null}
                            {(totals.adjustments || []).filter((a) => a.type === 'fee').map((a) => (
                                <SummaryRow key={a.code} label={a.label || a.code} value={money(a.amount)} />
                            ))}
                            <SummaryRow label={t('storefront.cart.total', 'Total')} value={money(grand)} strong />
                            {Number(totals.gift_card_credit) > 0 ? (
                                <SummaryRow
                                    label={t('storefront.checkout.payable_after_gift', 'Payable after gift card')}
                                    value={money(grand)}
                                    strong
                                />
                            ) : null}
                            {totals.due_now != null && Number(totals.balance_due) > 0 ? (
                                <>
                                    <SummaryRow label={t('storefront.cart.due_today', 'Due today')} value={money(totals.due_now)} strong />
                                    <SummaryRow label={t('storefront.cart.balance_due', 'Balance due')} value={money(totals.balance_due)} />
                                </>
                            ) : null}
                        </dl>

                        <div className="shop-checkout-modules">
                            <ModuleBlocks slots={options.modules?.sidebar || []} />
                        </div>

                        {couponsOn ? (
                        <div className="shop-checkout-coupon">
                            <input
                                value={form.coupon}
                                onChange={(e) => setForm({ ...form, coupon: e.target.value })}
                                placeholder={t('storefront.cart.coupon_enter', 'Enter coupon code')}
                                aria-label={t('storefront.cart.coupon', 'Coupon')}
                            />
                            <button type="button" onClick={applyCoupon}>{t('storefront.cart.apply', 'Apply')}</button>
                        </div>
                        ) : null}

                        <div className="shop-checkout-coupon">
                            <input
                                value={form.gift_card}
                                onChange={(e) => setForm({ ...form, gift_card: e.target.value })}
                                placeholder={t('storefront.cart.gift_placeholder', 'Gift card code')}
                                aria-label={t('storefront.cart.gift_card', 'Gift card')}
                                disabled={giftBusy || Number(totals.gift_card_credit) > 0}
                            />
                            <button
                                type="button"
                                onClick={applyGiftCard}
                                disabled={giftBusy || !form.gift_card.trim() || Number(totals.gift_card_credit) > 0}
                            >
                                {giftBusy ? t('storefront.cart.applying', 'Applying…') : t('storefront.cart.apply', 'Apply')}
                            </button>
                        </div>

                        <div className="shop-checkout-trust">
                            <Icon name="shield" className="h-4 w-4" />
                            <p>{t('storefront.checkout.ssl', 'Shop with confidence. Your data is protected with 256-bit SSL encryption.')}</p>
                        </div>

                        {error ? <p className="shop-checkout-error">{error}</p> : null}

                        {payment?.driver && payment.driver !== 'offline' && !hasInlinePaymentUi ? (
                            <p className="shop-checkout-hint">{t('storefront.checkout.live_gateway', 'You will be redirected to complete payment securely.')}</p>
                        ) : null}

                        {needTerms ? (
                            <label className="shop-checkout-legal" style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start' }}>
                                <input
                                    type="checkbox"
                                    checked={!!form.accept_terms}
                                    onChange={(e) => setForm({ ...form, accept_terms: e.target.checked })}
                                />
                                <span>
                                    {t('storefront.checkout.accept_terms', 'I agree to the')}
                                    {' '}
                                    <Link to={`/cms/${termsSlug}`}>{termsLabel}</Link>
                                </span>
                            </label>
                        ) : null}

                        <button type="submit" className="shop-checkout-place" disabled={busy || registrationBlocked || (needTerms && !form.accept_terms) || (stepped && wizard < 3)}>
                            <Icon name="lock" className="h-4 w-4" />
                            {busy ? t('storefront.checkout.placing', 'Placing…') : t('storefront.checkout.place', 'Place Order')}
                        </button>

                        <p className="shop-checkout-legal">
                            {t('storefront.checkout.agree_prefix', 'By placing your order, you agree to our')}
                            {' '}
                            <Link to={`/cms/${termsSlug}`}>{termsLabel}</Link>
                            {' '}
                            {t('storefront.checkout.and', 'and')}
                            {' '}
                            <Link to="/cms/privacy">{t('storefront.checkout.privacy', 'Privacy Policy')}</Link>.
                        </p>
                    </aside>
                </div>

                <ul className="shop-checkout-footer-trust">
                    <li>
                        <Icon name="truck" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.auth.point_ship_title', 'Free Shipping')}</strong>
                            <em>{t('storefront.auth.point_ship_text', 'On orders over $75')}</em>
                        </div>
                    </li>
                    {returnsEnabled(store.shop) ? (
                    <li>
                        <Icon name="return" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.auth.point_returns_title', '30-Day Returns')}</strong>
                            <em>{t('storefront.cart.trust_returns', 'Shop with confidence')}</em>
                        </div>
                    </li>
                    ) : null}
                    <li>
                        <Icon name="shield" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.auth.point_secure_title', 'Secure Checkout')}</strong>
                            <em>{t('storefront.cart.trust_secure', 'Protected payments')}</em>
                        </div>
                    </li>
                    <li>
                        <Icon name="help" className="h-5 w-5" />
                        <div>
                            <strong>{t('storefront.pdp.support_247', '24/7 Support')}</strong>
                            <em>{t('storefront.checkout.support_hint', 'We are here to help')}</em>
                        </div>
                    </li>
                </ul>
            </div>
        </form>
    );
}

function SummaryRow({ label, value, strong, tone }) {
    return (
        <div className={`shop-checkout-row${strong ? ' is-strong' : ''}${tone === 'good' ? ' is-good' : ''}`}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

function AddressCard({ row, selected, onSelect, onEdit, name = 'ship-address' }) {
    const person = [row.first_name, row.last_name].filter(Boolean).join(' ');
    const line = [row.line1, row.line2, row.city, row.state, row.postal_code, row.country_code].filter(Boolean).join(', ');
    const title = row.label || t('storefront.account.address', 'Address');
    const isDefault = row.is_default_shipping || row.is_default_billing;

    return (
        <div className={`shop-checkout-address${selected ? ' is-selected' : ''}`}>
            <label className="shop-checkout-address-main">
                <input type="radio" name={name} checked={selected} onChange={onSelect} />
                <span>
                    <strong>
                        {title}
                        {isDefault ? <em> · {t('storefront.checkout.default', 'Default')}</em> : null}
                    </strong>
                    {person ? <span className="shop-checkout-address-person">{person}</span> : null}
                    <span className="shop-checkout-address-line">{line}</span>
                    {row.phone ? <span className="shop-checkout-address-phone">{row.phone}</span> : null}
                </span>
            </label>
            {onEdit ? (
                <button type="button" className="shop-checkout-text-btn" onClick={onEdit}>
                    {t('storefront.common.edit', 'Edit')}
                </button>
            ) : null}
        </div>
    );
}

function pickAddress(row, fallback) {
    return {
        first_name: row.first_name || fallback.first_name,
        last_name: row.last_name || fallback.last_name,
        phone: row.phone || fallback.phone,
        line1: row.line1 || fallback.line1,
        line2: row.line2 || fallback.line2,
        city: row.city || fallback.city,
        state: row.state || fallback.state,
        postal_code: row.postal_code || fallback.postal_code,
        country_code: row.country_code || fallback.country_code,
    };
}

function addressForCheckout(row) {
    return {
        first_name: String(row?.first_name || '').trim(),
        last_name: String(row?.last_name || '').trim(),
        phone: String(row?.phone || '').trim(),
        line1: String(row?.line1 || '').trim(),
        line2: String(row?.line2 || '').trim(),
        city: String(row?.city || '').trim(),
        state: String(row?.state || '').trim(),
        postal_code: String(row?.postal_code || '').trim(),
        country_code: row?.country_code || 'US',
    };
}

function isAddressComplete(address, settings = {}, countries = []) {
    if (!address?.first_name || !address?.line1 || !address?.city || !address?.country_code) {
        return false;
    }
    if (settings.line2_mode === 'required' && !String(address.line2 || '').trim()) {
        return false;
    }
    if (settings.phone_mode === 'required' && !String(address.phone || '').trim()) {
        return false;
    }
    return !hasFieldErrors(localAddressErrors(address, countries, settings, true));
}

function formatAddressLine(address) {
    const name = [address.first_name, address.last_name].filter(Boolean).join(' ');
    const cityLine = [address.city, address.state, address.postal_code].filter(Boolean).join(', ');
    return [name, address.line1, address.line2, cityLine, address.country_code].filter(Boolean).join(', ');
}

function resolveShippingAddress({ loggedIn, composer, draft, shipId, addresses, form }) {
    if (loggedIn && composer) {
        return addressForCheckout(draft);
    }
    if (loggedIn && shipId) {
        const row = addresses.find((a) => String(a.id) === String(shipId));
        return addressForCheckout(row || form.shipping_address);
    }
    return addressForCheckout(form.shipping_address);
}

function resolveBillingAddress({ loggedIn, billId, addresses, form }) {
    if (loggedIn && billId) {
        const row = addresses.find((a) => String(a.id) === String(billId));
        return addressForCheckout(row || form.billing_address);
    }
    return addressForCheckout(form.billing_address);
}

function orderTotalInDefault(grand, totals) {
    const rate = Number(totals?.currency_rate || 1);
    if (!Number.isFinite(rate) || rate <= 0) {
        return grand;
    }

    return grand / rate;
}

function orderTotalLimitError(grand, totals, orderSettings) {
    const base = orderTotalInDefault(grand, totals);
    const minAmount = Number(orderSettings?.min_amount || 0);
    const maxRaw = orderSettings?.max_amount;
    const maxAmount = maxRaw == null || maxRaw === '' ? null : Number(maxRaw);
    const rate = Number(totals?.currency_rate || 1) || 1;
    const displayCode = totals?.currency || orderSettings?.amount_currency;
    if (minAmount > 0 && base + 0.00001 < minAmount) {
        return t('storefront.checkout.min_order', 'Minimum order amount is {amount}.')
            .replace('{amount}', formatMoney(minAmount * rate, displayCode));
    }
    if (maxAmount != null && Number.isFinite(maxAmount) && maxAmount > 0 && base - 0.00001 > maxAmount) {
        return t('storefront.checkout.max_order', 'Maximum order amount is {amount}.')
            .replace('{amount}', formatMoney(maxAmount * rate, displayCode));
    }

    return '';
}

function checkoutErrorMessage(err) {
    const payload = err.response?.data;
    if (!payload) {
        return err?.message || null;
    }
    if (payload.errors && typeof payload.errors === 'object') {
        const first = Object.values(payload.errors).flat().find(Boolean);
        if (first) {
            return first;
        }
    }
    return payload.message || null;
}

function AddressFields({ value, onChange, countries, showLabel = false, addressSettings = {}, requiredAddress = true, errors = {} }) {
    const country = (countries || []).find((row) => row.code === value.country_code) || null;
    const stateOptions = country?.regions || [];
    const stateNeeded = stateRequiredFor(country, addressSettings);
    const postal = postalMeta(country, addressSettings);
    const cityOptions = (() => {
        const country = (countries || []).find((row) => row.code === value.country_code);
        const all = country?.cities || [];
        if (!all.length) {
            return [];
        }
        const state = value.state;
        if (!state) {
            return all;
        }
        const region = stateOptions.find((row) => row.name === state || row.code === state);
        const filtered = all.filter((city) => !city.region_name && !city.region_code
            || city.region_name === state
            || city.region_code === state
            || (region && (city.region_code === region.code || city.region_name === region.name)));
        return filtered.length ? filtered : all;
    })();

    const setCountry = (code) => {
        const names = ((countries || []).find((row) => row.code === code)?.regions || []).map((row) => row.name);
        onChange('country_code', code);
        if (!names.includes(value.state || '')) {
            onChange('state', '');
        }
    };
    const line2Mode = addressSettings.line2_mode || 'optional';
    const phoneMode = addressSettings.phone_mode || 'optional';
    const must = requiredAddress;
    const line2Label = line2Mode === 'required'
        ? t('storefront.account.line2_label', 'Apartment, suite')
        : t('storefront.account.line2', 'Apartment, suite (optional)');

    return (
        <div className="shop-checkout-fields">
            {showLabel ? (
                <FormField label={t('storefront.account.label', 'Label')} span>
                    <input className="shop-input" value={value.label || ''} onChange={(e) => onChange('label', e.target.value)} />
                </FormField>
            ) : null}
            <div className="shop-checkout-fields-2">
                <FormField label={t('storefront.account.first_name', 'First name')} required={must} error={errors.first_name}>
                    <input className="shop-input" value={value.first_name} onChange={(e) => onChange('first_name', e.target.value)} required={must} />
                </FormField>
                <FormField label={t('storefront.account.last_name', 'Last name')}>
                    <input className="shop-input" value={value.last_name || ''} onChange={(e) => onChange('last_name', e.target.value)} />
                </FormField>
            </div>
            {phoneMode !== 'hidden' ? (
                <FormField label={t('storefront.account.phone', 'Phone')}>
                    <PhoneField countries={countries} value={value.phone || ''} onChange={(next) => onChange('phone', next)} required={must && phoneMode === 'required'} />
                </FormField>
            ) : null}
            <FormField label={t('storefront.account.line1', 'Street address')} required={must} error={errors.line1}>
                <input className="shop-input" value={value.line1} onChange={(e) => onChange('line1', e.target.value)} required={must} />
            </FormField>
            {line2Mode !== 'hidden' ? (
                <FormField label={line2Label}>
                    <input className="shop-input" value={value.line2 || ''} onChange={(e) => onChange('line2', e.target.value)} required={must && line2Mode === 'required'} />
                </FormField>
            ) : null}
            <FormField label={t('storefront.account.country', 'Country')} required={must}>
                <select className="shop-input" value={value.country_code} onChange={(e) => setCountry(e.target.value)} required={must}>
                    {(countries.length ? countries : [{ code: 'US', name: 'United States' }]).map((c) => (
                        <option key={c.code} value={c.code}>{c.name}</option>
                    ))}
                </select>
            </FormField>
            <div className={`shop-checkout-fields-${stateOptions.length > 0 ? '3' : '2'}`}>
                <FormField label={t('storefront.account.city', 'City')} required={must} error={errors.city}>
                    {cityOptions.length ? (
                        <select className="shop-input" value={value.city} onChange={(e) => onChange('city', e.target.value)} required={must}>
                            <option value="">{t('storefront.account.select_city', 'Select city')}</option>
                            {cityOptions.map((city) => (
                                <option key={city.name} value={city.name}>{city.name}</option>
                            ))}
                        </select>
                    ) : (
                        <input className="shop-input" value={value.city} onChange={(e) => onChange('city', e.target.value)} required={must} />
                    )}
                </FormField>
                {stateOptions.length > 0 ? (
                    <FormField label={t('storefront.account.state', 'State / Province')} required={must && stateNeeded} error={errors.state}>
                        <select className="shop-input" value={value.state || ''} onChange={(e) => onChange('state', e.target.value)}>
                            <option value="">{t('storefront.account.select_state', 'Select state')}</option>
                            {stateOptions.map((region) => (
                                <option key={region.code} value={region.name}>{region.name}</option>
                            ))}
                        </select>
                    </FormField>
                ) : null}
                <FormField
                    label={country?.postal_code_label || t('storefront.account.postal', 'ZIP / Postal code')}
                    required={must && postal.required}
                    error={errors.postal_code}
                >
                    <input
                        className="shop-input"
                        value={value.postal_code || ''}
                        onChange={(e) => onChange('postal_code', e.target.value)}
                        autoComplete="postal-code"
                        inputMode={postal.pattern && postal.pattern.includes('\\d') && !postal.pattern.includes('[A-Z]') ? 'numeric' : 'text'}
                    />
                </FormField>
            </div>
        </div>
    );
}
