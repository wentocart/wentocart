import { t } from '../../../ui';

export function statusLabel(status) {
    if (status === 'pending') return t('admin.subscriptions.status_pending', 'Pending');
    if (status === 'active') return t('admin.subscriptions.status_active', 'Active');
    if (status === 'trialing') return t('admin.subscriptions.status_trial', 'Trial');
    if (status === 'paused') return t('admin.subscriptions.status_paused', 'Paused');
    if (status === 'past_due') return t('admin.subscriptions.status_past_due', 'Past due');
    if (status === 'cancelled') return t('admin.subscriptions.status_cancelled', 'Cancelled');

    return status || '—';
}

export function statusTone(status) {
    if (status === 'active') return 'success';
    if (status === 'cancelled' || status === 'past_due') return 'danger';
    if (status === 'pending' || status === 'paused' || status === 'trialing') return 'warning';

    return 'neutral';
}

export function paymentLabel(status) {
    if (status === 'paid') return t('admin.subscriptions.payment_paid', 'Paid');
    if (status === 'failed') return t('admin.subscriptions.payment_failed', 'Failed');
    if (status === 'pending') return t('admin.subscriptions.payment_pending', 'Pending');
    if (status === 'partial') return t('admin.orders.partially_paid', 'Partially Paid');
    if (status === 'refunded') return t('admin.orders.refunded', 'Refunded');
    if (status === 'partially_refunded') return t('admin.orders.partially_refunded', 'Partially Refunded');

    return status || '—';
}

export function paymentTone(status) {
    if (status === 'paid') return 'success';
    if (status === 'failed' || status === 'refunded') return 'danger';
    if (status === 'partially_refunded') return 'info';

    return 'warning';
}

/** Order fulfillment status badges (renewal invoice table). */
export function orderStatusLabel(status) {
    if (status === 'completed') return t('admin.orders.delivered', 'Delivered');
    if (!status) return '—';

    return String(status).replace(/^\w/, (c) => c.toUpperCase());
}

export function orderStatusTone(status) {
    if (status === 'completed' || status === 'delivered') return 'success';
    if (status === 'processing') return 'primary';
    if (status === 'shipped') return 'info';
    if (status === 'cancelled' || status === 'refunded') return 'danger';
    if (status === 'pending') return 'warning';

    return 'neutral';
}
