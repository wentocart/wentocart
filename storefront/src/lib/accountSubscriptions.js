import { t } from '../i18n.js';

export function subscriptionStatusLabel(status) {
    if (status === 'pending') return t('storefront.account.subscription_status_pending', 'Pending');
    if (status === 'active') return t('storefront.account.subscription_status_active', 'Active');
    if (status === 'trialing') return t('storefront.account.subscription_status_trial', 'Trial');
    if (status === 'paused') return t('storefront.account.subscription_status_paused', 'Paused');
    if (status === 'past_due') return t('storefront.account.subscription_status_past_due', 'Past due');
    if (status === 'cancelled') return t('storefront.account.status_cancelled', 'Cancelled');

    return status || '—';
}

export function subscriptionStatusTone(status) {
    if (status === 'active') return 'delivered';
    if (status === 'pending' || status === 'trialing' || status === 'paused') return 'processing';
    if (status === 'past_due') return 'shipped';
    if (status === 'cancelled') return 'cancelled';

    return 'processing';
}

export function subscriptionPaymentLabel(status) {
    if (status === 'paid') return t('storefront.account.payment_paid', 'Paid');
    if (status === 'failed') return t('storefront.account.payment_failed', 'Failed');
    if (status === 'pending') return t('storefront.account.payment_pending', 'Pending');

    return status || '—';
}

export function subscriptionPaymentTone(status) {
    if (status === 'paid') return 'delivered';
    if (status === 'failed') return 'cancelled';

    return 'processing';
}

export function formatSubscriptionDate(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
