/**
 * Allow same-origin paths and known payment hosts only.
 * Rejects protocol-relative (//evil) and arbitrary https hosts.
 */
const PAYMENT_HOSTS = ['paypal.com', 'paypalobjects.com', 'razorpay.com', 'rzp.io', 'stripe.com'];

function hostAllowed(host) {
    const h = String(host || '').toLowerCase();
    return PAYMENT_HOSTS.some((d) => h === d || h.endsWith(`.${d}`));
}

export function safeRedirectUrl(url) {
    const raw = String(url || '').trim();
    if (!raw || raw.length > 2048) {
        return null;
    }
    if (raw.startsWith('//') || /^[a-z][a-z0-9+.-]*:/i.test(raw) && !/^https?:/i.test(raw)) {
        return null;
    }
    if (raw.startsWith('/') && !raw.startsWith('//')) {
        return raw;
    }
    try {
        const base = typeof window !== 'undefined' ? window.location.origin : 'https://localhost';
        const parsed = new URL(raw, base);
        if (parsed.origin === base || (typeof window !== 'undefined' && parsed.origin === window.location.origin)) {
            return `${parsed.pathname}${parsed.search}${parsed.hash}`;
        }
        if (parsed.protocol === 'https:' && hostAllowed(parsed.hostname)) {
            return parsed.toString();
        }
    } catch {
        return null;
    }
    return null;
}

export function goSafeRedirect(url) {
    const safe = safeRedirectUrl(url);
    if (!safe) {
        return false;
    }
    window.location.href = safe;
    return true;
}
