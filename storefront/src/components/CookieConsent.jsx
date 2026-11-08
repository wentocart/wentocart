import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import CmsModal from './CmsModal.jsx';
import { t } from '../i18n.js';

const KEY = 'wento_cookie_consent';
const PREF_KEY = 'wento_gdpr_cookies';

export function cookieChoice() {
    try {
        return localStorage.getItem(KEY) || '';
    } catch {
        return '';
    }
}

export function gdprPrefs() {
    try {
        const raw = localStorage.getItem(PREF_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

export function saveGdprPrefs(prefs) {
    const next = {
        strictly_necessary: true,
        basic: !!prefs.basic,
        experience: !!prefs.experience,
        measurements: !!prefs.measurements,
        targeting: !!prefs.targeting,
    };
    try {
        localStorage.setItem(PREF_KEY, JSON.stringify(next));
        localStorage.setItem(KEY, next.measurements || next.targeting ? 'all' : 'necessary');
    } catch {
        // ignore
    }
    window.dispatchEvent(new Event('wento-consent'));
    return next;
}

const positions = {
    bottom_left: 'bottom-4 left-4 max-w-md',
    bottom_right: 'bottom-4 right-4 max-w-md',
    top_left: 'top-4 left-4 max-w-md',
    top_right: 'top-4 right-4 max-w-md',
    center: 'top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 max-w-lg',
};

export default function CookieConsent({ shop }) {
    const privacy = shop?.commerce?.privacy || {};
    const gdpr = shop?.commerce?.gdpr || {};
    const [choice, setChoice] = useState(() => cookieChoice());
    const [prefs, setPrefs] = useState(() => gdprPrefs());
    const [cookieOpen, setCookieOpen] = useState(false);

    if (gdpr.cookie_notice) {
        if (prefs) {
            return null;
        }
        const place = positions[gdpr.cookie_position] || positions.bottom_left;
        const choose = (next) => setPrefs(saveGdprPrefs(next));
        return (
            <div className={`fixed z-50 rounded-xl border border-neutral-200 bg-white p-4 shadow-lg ${place}`}>
                <h2 className="text-sm font-semibold text-neutral-900 mb-2">{gdpr.cookie_title || t('storefront.gdpr.cookie_block', 'Cookie Block')}</h2>
                {gdpr.cookie_page?.slug ? (
                    <p className="text-sm text-neutral-700 mb-4">
                        <button type="button" className="underline cursor-pointer" onClick={() => setCookieOpen(true)}>
                            {t('storefront.gdpr.read_more', 'Read More')}
                        </button>
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2 mt-1">
                    <button type="button" className="shop-btn shop-btn-primary" onClick={() => choose({ basic: true, experience: true, measurements: true, targeting: true })}>
                        {t('storefront.gdpr.accept', 'Accept')}
                    </button>
                    <button type="button" className="shop-btn" onClick={() => choose({ basic: false, experience: false, measurements: false, targeting: false })}>
                        {t('storefront.gdpr.reject', 'Reject')}
                    </button>
                    <Link className="shop-btn" to="/account/gdpr/cookies">{t('storefront.gdpr.learn_more', 'Learn More and Customize')}</Link>
                </div>
                <CmsModal
                    slug={gdpr.cookie_page?.slug || ''}
                    titleFallback={gdpr.cookie_page?.title || ''}
                    open={cookieOpen}
                    onClose={() => setCookieOpen(false)}
                />
            </div>
        );
    }

    if (!privacy.cookie_consent || choice) {
        return null;
    }

    const save = (value) => {
        try {
            localStorage.setItem(KEY, value);
        } catch {
            // ignore
        }
        setChoice(value);
        window.dispatchEvent(new Event('wento-consent'));
    };

    return (
        <div className="fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-3xl rounded-xl border border-neutral-200 bg-white p-4 shadow-lg">
            <p className="text-sm text-neutral-700 mb-3">
                {t('storefront.privacy.cookies', 'We use cookies for the shopping cart and, if you allow, analytics pixels configured by the merchant.')}
            </p>
            <div className="flex flex-wrap gap-2">
                <button type="button" className="shop-btn shop-btn-primary" onClick={() => save('all')}>
                    {t('storefront.privacy.accept', 'Accept analytics')}
                </button>
                <button type="button" className="shop-btn" onClick={() => save('necessary')}>
                    {t('storefront.privacy.necessary', 'Necessary only')}
                </button>
            </div>
        </div>
    );
}

function loadScript(src, id) {
    if (document.getElementById(id)) {
        return;
    }
    const el = document.createElement('script');
    el.id = id;
    el.async = true;
    el.src = src;
    document.head.appendChild(el);
}

function loadInline(id, body) {
    if (document.getElementById(id)) {
        return;
    }
    const el = document.createElement('script');
    el.id = id;
    el.text = body;
    document.head.appendChild(el);
}

function safePixelId(value, pattern) {
    const id = String(value || '').trim();
    return pattern.test(id) ? id : '';
}

export function PixelTags({ shop }) {
    const privacy = shop?.commerce?.privacy || {};
    const gdpr = shop?.commerce?.gdpr || {};
    const gtmId = safePixelId(privacy.gtm_id, /^GTM-[A-Z0-9]+$/i);
    const ga4Id = safePixelId(privacy.ga4_id, /^G-[A-Z0-9]+$/i);
    const metaId = safePixelId(privacy.meta_pixel_id, /^[0-9]{5,20}$/);
    const [allowed, setAllowed] = useState(() => consentFlags(privacy, gdpr));

    useEffect(() => {
        const onChange = () => setAllowed(consentFlags(privacy, gdpr));
        window.addEventListener('wento-consent', onChange);
        return () => window.removeEventListener('wento-consent', onChange);
    }, [privacy.cookie_consent, gdpr.cookie_notice]);

    useEffect(() => {
        if (allowed.measurements) {
            if (gtmId) {
                loadInline('wento-gtm', `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':Date.now(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${gtmId}');`);
            }
            if (ga4Id) {
                loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ga4Id)}`, 'wento-ga4-src');
                loadInline('wento-ga4', `window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','${ga4Id}');`);
            }
        }
        if (allowed.targeting && metaId) {
            loadInline('wento-meta', `!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','${metaId}');fbq('track','PageView');`);
        }
    }, [allowed, gtmId, ga4Id, metaId]);

    return null;
}

function consentFlags(privacy, gdpr) {
    if (gdpr.cookie_notice) {
        const prefs = gdprPrefs() || {};
        return { measurements: !!prefs.measurements, targeting: !!prefs.targeting };
    }
    const allowed = !privacy.cookie_consent || cookieChoice() === 'all';
    return { measurements: allowed, targeting: allowed };
}

export function firePurchase(order) {
    if (!order || typeof window === 'undefined') {
        return;
    }
    const prefs = gdprPrefs();
    const measurements = prefs ? !!prefs.measurements : cookieChoice() !== 'necessary';
    const targeting = prefs ? !!prefs.targeting : cookieChoice() !== 'necessary';
    if (!measurements && !targeting) {
        return;
    }
    const key = `wento_ga4_${order.number}`;
    try {
        if (sessionStorage.getItem(key)) {
            return;
        }
        sessionStorage.setItem(key, '1');
    } catch {
        // ignore
    }
    const payload = {
        transaction_id: order.number,
        value: Number(order.grand_total || 0),
        currency: order.currency_code || 'USD',
        tax: Number(order.tax_total || 0),
        shipping: Number(order.shipping_total || 0),
        items: (order.items || []).map((item) => ({
            item_id: item.sku || String(item.product_id || ''),
            item_name: item.name,
            quantity: Number(item.qty || 1),
            price: Number(item.unit_price || 0),
        })),
    };
    if (measurements && typeof window.gtag === 'function') {
        window.gtag('event', 'purchase', payload);
    }
    if (measurements) {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ event: 'purchase', ecommerce: payload });
    }
    if (targeting && typeof window.fbq === 'function') {
        window.fbq('track', 'Purchase', { value: payload.value, currency: payload.currency });
    }
}
