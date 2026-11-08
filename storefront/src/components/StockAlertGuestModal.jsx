import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import api, { csrf } from '../api.js';
import { Icon } from './Icons.jsx';
import { t } from '../i18n.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const AUTO_CLOSE_MS = 2000;
const FIELD_KEYS = ['first_name', 'last_name', 'email', 'product', 'product_variant_id'];

function validate(form) {
    const errors = {};
    const first = String(form.first_name || '').trim();
    const last = String(form.last_name || '').trim();
    const email = String(form.email || '').trim();
    if (!first) {
        errors.first_name = t('storefront.pdp.stock_alert_first_required', 'First name is required.');
    }
    if (!last) {
        errors.last_name = t('storefront.pdp.stock_alert_last_required', 'Last name is required.');
    }
    if (!email) {
        errors.email = t('storefront.pdp.stock_alert_email_required', 'Email is required.');
    } else if (!EMAIL_RE.test(email)) {
        errors.email = t('storefront.pdp.stock_alert_email_invalid', 'Enter a valid email address.');
    }
    return errors;
}

export default function StockAlertGuestModal({
    open,
    productId,
    productVariantId = null,
    onClose,
    onSubscribed,
}) {
    const [form, setForm] = useState({ first_name: '', last_name: '', email: '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);
    const [success, setSuccess] = useState(null);
    const closeTimerRef = useRef(null);

    const clearCloseTimer = () => {
        if (closeTimerRef.current != null) {
            window.clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
    };

    const scheduleClose = () => {
        clearCloseTimer();
        closeTimerRef.current = window.setTimeout(() => {
            closeTimerRef.current = null;
            onClose?.();
        }, AUTO_CLOSE_MS);
    };

    const updateField = (name, value) => {
        setForm((prev) => ({ ...prev, [name]: value }));
        setErrors((prev) => {
            if (!prev[name]) return prev;
            const next = { ...prev };
            delete next[name];
            return next;
        });
        setFormError('');
    };

    useEffect(() => {
        if (!open) {
            clearCloseTimer();
            return undefined;
        }
        setForm({ first_name: '', last_name: '', email: '' });
        setErrors({});
        setFormError('');
        setBusy(false);
        setSuccess(null);
        const onKey = (e) => {
            if (e.key === 'Escape' && !busy) {
                clearCloseTimer();
                onClose?.();
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
            clearCloseTimer();
        };
    }, [open]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    const title = t('storefront.pdp.notify_when_available', 'Notify me when available');
    const canDismiss = !busy;

    const submit = async (e) => {
        e.preventDefault();
        const nextErrors = validate(form);
        setErrors(nextErrors);
        setFormError('');
        setSuccess(null);
        if (Object.keys(nextErrors).length) {
            return;
        }
        setBusy(true);
        try {
            await csrf();
            const body = {
                first_name: String(form.first_name).trim(),
                last_name: String(form.last_name).trim(),
                email: String(form.email).trim(),
            };
            if (productVariantId) {
                body.product_variant_id = productVariantId;
            }
            await api.post(`/catalog/product/${productId}/customer-stock-alert`, body);
            setSuccess({
                message: t(
                    'storefront.pdp.stock_alert_guest_ok',
                    'We will email you when this product is back in stock.',
                ),
                title: t('storefront.pdp.stock_alert_subscribed', 'Subscribed to stock alert'),
            });
            onSubscribed?.();
            scheduleClose();
        } catch (err) {
            const bag = err.response?.data?.errors || {};
            const mapped = {};
            FIELD_KEYS.forEach((key) => {
                const val = bag[key];
                if (val) {
                    mapped[key] = Array.isArray(val) ? val[0] : val;
                }
            });
            const fieldErrors = {};
            ['first_name', 'last_name', 'email'].forEach((key) => {
                if (mapped[key]) fieldErrors[key] = mapped[key];
            });
            const other = mapped.product || mapped.product_variant_id || '';
            if (Object.keys(fieldErrors).length) {
                setErrors(fieldErrors);
                setFormError(other || '');
            } else {
                setErrors({});
                setFormError(
                    other
                        || err.response?.data?.message
                        || t('storefront.pdp.stock_alert_failed', 'Could not subscribe to stock alert.'),
                );
            }
            // Keep the form open so the guest can fix inputs and retry.
        } finally {
            setBusy(false);
        }
    };

    return createPortal(
        <div className="cx-modal shop-stock-alert-modal" role="dialog" aria-modal="true" aria-label={title}>
            <button
                type="button"
                className="cx-modal-backdrop"
                aria-label={t('storefront.common.close', 'Close')}
                onClick={() => {
                    if (!canDismiss) return;
                    clearCloseTimer();
                    onClose?.();
                }}
            />
            <div className="cx-modal-panel is-compact">
                <header className="cx-modal-head">
                    <h2>{success?.title || title}</h2>
                    <button
                        type="button"
                        className="cx-modal-close"
                        onClick={() => {
                            if (!canDismiss) return;
                            clearCloseTimer();
                            onClose?.();
                        }}
                        aria-label={t('storefront.common.close', 'Close')}
                    >
                        <Icon name="close" className="h-4 w-4" />
                    </button>
                </header>
                <div className="cx-modal-body">
                    {success ? (
                        <div className="shop-stock-alert-modal__result is-success" role="status">
                            <Icon name="checkCircle" className="h-8 w-8" />
                            <p className="shop-pdp2-alert-ok">{success.message}</p>
                        </div>
                    ) : (
                        <form className="shop-stock-alert-modal__form" onSubmit={submit} noValidate>
                            <p className="cx-modal-message">
                                {t(
                                    'storefront.pdp.stock_alert_guest_help',
                                    'Enter your details and we will email you when this item is available again.',
                                )}
                            </p>
                            {formError ? (
                                <p className="shop-stock-alert-modal__error" role="alert">{formError}</p>
                            ) : null}
                            <label className="shop-field">
                                <span className="shop-field-label">
                                    {t('storefront.account.first_name', 'First name')}
                                    <span className="shop-req-mark" aria-hidden="true"> *</span>
                                </span>
                                <input
                                    className="shop-input"
                                    name="first_name"
                                    autoComplete="given-name"
                                    value={form.first_name}
                                    onChange={(e) => updateField('first_name', e.target.value)}
                                    required
                                    disabled={busy}
                                    aria-invalid={errors.first_name ? 'true' : undefined}
                                />
                                {errors.first_name ? <span className="shop-field-error">{errors.first_name}</span> : null}
                            </label>
                            <label className="shop-field">
                                <span className="shop-field-label">
                                    {t('storefront.account.last_name', 'Last name')}
                                    <span className="shop-req-mark" aria-hidden="true"> *</span>
                                </span>
                                <input
                                    className="shop-input"
                                    name="last_name"
                                    autoComplete="family-name"
                                    value={form.last_name}
                                    onChange={(e) => updateField('last_name', e.target.value)}
                                    required
                                    disabled={busy}
                                    aria-invalid={errors.last_name ? 'true' : undefined}
                                />
                                {errors.last_name ? <span className="shop-field-error">{errors.last_name}</span> : null}
                            </label>
                            <label className="shop-field">
                                <span className="shop-field-label">
                                    {t('storefront.contact.email', 'Email')}
                                    <span className="shop-req-mark" aria-hidden="true"> *</span>
                                </span>
                                <input
                                    className="shop-input"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    value={form.email}
                                    onChange={(e) => updateField('email', e.target.value)}
                                    required
                                    disabled={busy}
                                    aria-invalid={errors.email ? 'true' : undefined}
                                />
                                {errors.email ? <span className="shop-field-error">{errors.email}</span> : null}
                            </label>
                            <button type="submit" className="shop-btn shop-btn-primary cx-modal-action" disabled={busy}>
                                {busy
                                    ? t('storefront.common.please_wait', 'Please wait')
                                    : t('storefront.pdp.notify_when_available', 'Notify me when available')}
                            </button>
                        </form>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}
