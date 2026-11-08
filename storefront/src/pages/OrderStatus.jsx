import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { FormNotice } from '../components/Form.jsx';
import { Icon } from '../components/Icons.jsx';
import OrderReceipt from '../components/OrderReceipt.jsx';
import { t } from '../i18n.js';
import { guestLookupEnabled } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';
import NotFound from './NotFound.jsx';

const POINTS = [
    {
        icon: 'bolt',
        tone: 'blue',
        titleKey: 'storefront.track.fast_title',
        title: 'Fast updates',
        bodyKey: 'storefront.track.fast_body',
        body: 'Get the latest status instantly',
    },
    {
        icon: 'package',
        tone: 'violet',
        titleKey: 'storefront.track.easy_title',
        title: 'Easy tracking',
        bodyKey: 'storefront.track.easy_body',
        body: 'Track your order in one place',
    },
    {
        icon: 'shield',
        tone: 'green',
        titleKey: 'storefront.track.secure_title',
        title: 'Secure & reliable',
        bodyKey: 'storefront.track.secure_body',
        body: 'Your information is always safe',
    },
];

export default function OrderStatus({ embedded = false }) {
    const store = useStore();
    const [form, setForm] = useState({ number: '', email: '', postal_code: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [order, setOrder] = useState(null);
    const [invoiceBusy, setInvoiceBusy] = useState(false);
    const detailRef = useRef(null);

    useEffect(() => {
        const node = detailRef.current;
        if (!order || !node) return;
        node.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [order]);

    if (!store.shop && !store.shopError) {
        return embedded ? null : <div className="shop-track" />;
    }
    if (!guestLookupEnabled(store.shop)) {
        return embedded ? null : <NotFound />;
    }

    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    const lookup = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError('');
        setOrder(null);
        const payload = {
            number: form.number.trim(),
            email: form.email.trim(),
            postal_code: form.postal_code.trim(),
        };
        try {
            await csrf();
            const { data } = await api.post('/orders/lookup', payload);
            setOrder(data.order);
        } catch (err) {
            const status = err?.response?.status;
            if (status === 429) {
                setError(t('storefront.track.throttled', 'Too many attempts. Please wait a few minutes and try again.'));
            } else if (status === 422) {
                setError(t('storefront.track.invalid', 'Enter the order number, email, and postal code from your confirmation.'));
            } else {
                setError(t('storefront.track.missing', 'We could not find an order with that number and email.'));
            }
        } finally {
            setBusy(false);
        }
    };

    const downloadInvoice = async () => {
        if (!order?.can_invoice || invoiceBusy) return;
        setInvoiceBusy(true);
        setError('');
        try {
            await csrf();
            const { data } = await api.post('/orders/invoice', {
                number: form.number.trim(),
                email: form.email.trim(),
                postal_code: form.postal_code.trim(),
            }, { responseType: 'blob' });
            const url = URL.createObjectURL(data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `invoice-${order.number}.html`;
            link.click();
            URL.revokeObjectURL(url);
        } catch {
            setError(t('storefront.account.invoice_failed', 'Could not download the invoice.'));
        } finally {
            setInvoiceBusy(false);
        }
    };

    const formCard = (
        <form className="shop-track-card" onSubmit={lookup}>
            <FormNotice tone="error">{error}</FormNotice>
            <label className="shop-track-field">
                <span className="shop-track-field-icon is-blue"><Icon name="file" className="h-5 w-5" /></span>
                <span className="shop-track-field-copy">
                    <span>{t('storefront.track.number', 'Order number')}</span>
                    <input
                        required
                        value={form.number}
                        placeholder={t('storefront.track.number_placeholder', 'Enter your order number')}
                        onChange={(e) => set('number', e.target.value)}
                        autoComplete="off"
                    />
                </span>
            </label>
            <label className="shop-track-field">
                <span className="shop-track-field-icon is-violet"><Icon name="mail" className="h-5 w-5" /></span>
                <span className="shop-track-field-copy">
                    <span>{t('storefront.track.email', 'Email')}</span>
                    <input
                        type="email"
                        required
                        value={form.email}
                        placeholder={t('storefront.track.email_placeholder', 'Enter the email used for this order')}
                        onChange={(e) => set('email', e.target.value)}
                        autoComplete="email"
                    />
                </span>
            </label>
            <label className="shop-track-field">
                <span className="shop-track-field-icon is-amber"><Icon name="pin" className="h-5 w-5" /></span>
                <span className="shop-track-field-copy">
                    <span>{t('storefront.track.postal', 'Postal / ZIP code')}</span>
                    <input
                        required
                        value={form.postal_code}
                        placeholder={t('storefront.track.postal_placeholder', 'Enter your postal or ZIP code')}
                        onChange={(e) => set('postal_code', e.target.value)}
                        autoComplete="postal-code"
                    />
                </span>
            </label>
            <button type="submit" className="shop-track-submit" disabled={busy}>
                <Icon name="search" className="h-4 w-4" />
                {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.track.lookup', 'Find order')}
            </button>
        </form>
    );

    const result = order ? (
        <div ref={detailRef} className="shop-track-point" tabIndex={-1}>
            <OrderReceipt order={order} invoiceBusy={invoiceBusy} onInvoice={downloadInvoice} />
        </div>
    ) : null;

    if (embedded) {
        return (
            <div className="shop-track is-embedded">
                {formCard}
                {result}
            </div>
        );
    }

    return (
        <div className="shop-track">
            <div className="shop-container shop-track-wrap">
                <nav className="shop-track-crumbs" aria-label="Breadcrumb">
                    <Link to="/">
                        <Icon name="home" className="h-4 w-4" />
                        {t('storefront.shell.home', 'Home')}
                    </Link>
                    <span aria-hidden="true">›</span>
                    <span>{t('storefront.header.track', 'Track Your Order')}</span>
                </nav>
                <div className={`shop-track-layout${order ? ' is-found' : ''}`}>
                    <div className="shop-track-main">
                        <header className="shop-track-head">
                            <h1>{t('storefront.header.track', 'Track Your Order')}</h1>
                            <p>{t('storefront.track.help', 'Enter the order number from your confirmation email to get the latest updates on your order.')}</p>
                        </header>
                        {formCard}
                    </div>
                    {order ? null : (
                    <aside className="shop-track-aside">
                        <TrackArt />
                        <h2>{t('storefront.track.realtime', 'Get real-time updates on your order')}</h2>
                        <ul className="shop-track-points">
                            {POINTS.map((point) => (
                                <li key={point.titleKey}>
                                    <span className={`shop-track-point-icon is-${point.tone}`}>
                                        <Icon name={point.icon} className="h-5 w-5" />
                                    </span>
                                    <span>
                                        <strong>{t(point.titleKey, point.title)}</strong>
                                        <em>{t(point.bodyKey, point.body)}</em>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </aside>
                    )}
                </div>
                {result}
            </div>
        </div>
    );
}

function TrackArt() {
    return (
        <svg className="shop-track-art" viewBox="0 0 420 280" role="img" aria-label={t('storefront.track.art', 'Package on the way')}>
            <ellipse cx="210" cy="168" rx="150" ry="78" fill="#e7eefc" />
            <ellipse cx="250" cy="150" rx="92" ry="58" fill="#f3f7ff" />
            <path d="M78 118c48-36 92-18 128 8 28 20 62 18 96-8" fill="none" stroke="#9db4e8" strokeWidth="2" strokeDasharray="5 7" strokeLinecap="round" />
            <g transform="translate(48 96)">
                <circle cx="28" cy="28" r="28" fill="#e8f0ff" />
                <g transform="translate(12 14)" fill="none" stroke="#3b6fe0" strokeWidth="1.8" strokeLinejoin="round">
                    <path d="M2 10h14v8H2zM16 13h5l4 3v2h-9" />
                    <circle cx="7" cy="20" r="1.6" fill="#3b6fe0" stroke="none" />
                    <circle cx="20" cy="20" r="1.6" fill="#3b6fe0" stroke="none" />
                </g>
            </g>
            <g transform="translate(318 78)">
                <circle cx="28" cy="28" r="28" fill="#e7f8ef" />
                <path d="M14 28.5 28 16l14 12.5V40a2 2 0 0 1-2 2H30v-8h-8v8H16a2 2 0 0 1-2-2V28.5Z" fill="none" stroke="#1f9d62" strokeWidth="1.8" strokeLinejoin="round" />
            </g>
            <g transform="translate(118 108)">
                <path d="M92 18 46 40v52l46 22 46-22V40L92 18Z" fill="#e7c39a" />
                <path d="M46 40 92 62l46-22" fill="none" stroke="#c99562" strokeWidth="1.5" />
                <path d="M92 62v52" stroke="#c99562" strokeWidth="1.5" />
                <path d="M58 34h68v16H58z" fill="#3b6fe0" />
                <rect x="70" y="78" width="18" height="14" rx="1" fill="#f4efe8" stroke="#c99562" />
                <rect x="96" y="78" width="18" height="14" rx="1" fill="#f4efe8" stroke="#c99562" />
                <rect x="122" y="78" width="18" height="14" rx="1" fill="#f4efe8" stroke="#c99562" />
            </g>
            <g transform="translate(176 42)">
                <path d="M32 58s18-16 18-30a18 18 0 1 0-36 0c0 14 18 30 18 30Z" fill="#3b6fe0" />
                <circle cx="32" cy="28" r="7" fill="#fff" />
            </g>
        </svg>
    );
}
