import { useEffect, useState } from 'react';
import api, { csrf } from '../api.js';
import { Icon } from './Icons.jsx';
import { t } from '../i18n.js';
import { webPath } from '../webPath.js';

function ActionButton({ tone = '', icon, disabled, onClick, children }) {
    const classes = ['shop-od-action', 'is-compact'];
    if (tone) {
        classes.push(`is-${tone}`);
    }

    return (
        <button type="button" className={classes.join(' ')} disabled={disabled} onClick={onClick}>
            <Icon name={icon} className="h-3.5 w-3.5" />
            {children}
        </button>
    );
}

export default function SubscriptionActions({ row, onDone, className = '' }) {
    const [busy, setBusy] = useState(null);
    const [planId, setPlanId] = useState(row.plan_id);

    useEffect(() => {
        setPlanId(row.plan_id);
    }, [row.id, row.plan_id]);

    const act = async (action, body = {}) => {
        setBusy(action);
        try {
            await csrf();
            await api.post(`/account/subscriptions/${row.id}/${action}`, body);
            await onDone?.();
        } finally {
            setBusy(null);
        }
    };

    const payHref = row.pay_url
        ? (/^https?:\/\//i.test(row.pay_url) ? row.pay_url : webPath(row.pay_url))
        : null;
    const locked = !!busy;

    return (
        <div className={`shop-subscriptions-actions ${className}`.trim()}>
            {row.status === 'past_due' && row.last_renewal_error ? (
                <p className="shop-notice shop-notice--error shop-subscriptions-notice" role="alert">
                    {t('storefront.account.renewal_failed', 'Renewal failed')}: {row.last_renewal_error}
                </p>
            ) : null}
            {payHref && (row.status === 'past_due' || row.last_renewal_error) ? (
                <a href={payHref} className="shop-od-action is-compact is-primary">
                    <Icon name="card" className="h-3.5 w-3.5" />
                    {t('storefront.account.pay_now', 'Pay now')}
                </a>
            ) : null}
            {row.can_change_plan ? (
                <div className="shop-subscriptions-plan">
                    <label htmlFor={`plan-${row.id}`}>
                        {t('storefront.account.change_plan', 'Change plan')}
                    </label>
                    <select
                        id={`plan-${row.id}`}
                        className="shop-input"
                        value={planId ?? row.plan_id}
                        onChange={(e) => setPlanId(Number(e.target.value))}
                    >
                        {(row.available_plans || []).map((plan) => (
                            <option key={plan.id} value={plan.id}>{plan.name}</option>
                        ))}
                    </select>
                    <ActionButton
                        tone="primary"
                        icon="layers"
                        disabled={locked || Number(planId ?? row.plan_id) === Number(row.plan_id)}
                        onClick={() => act('change-plan', {
                            subscription_plan_id: Number(planId ?? row.plan_id),
                        })}
                    >
                        {t('storefront.account.change_plan', 'Change plan')}
                    </ActionButton>
                </div>
            ) : null}
            {row.can_pause ? (
                <ActionButton tone="warning" icon="pause" disabled={locked} onClick={() => act('pause')}>
                    {t('storefront.account.pause', 'Pause')}
                </ActionButton>
            ) : null}
            {row.can_resume ? (
                <ActionButton tone="success" icon="play" disabled={locked} onClick={() => act('resume')}>
                    {t('storefront.account.resume', 'Resume')}
                </ActionButton>
            ) : null}
            {row.can_skip ? (
                <ActionButton icon="skipForward" disabled={locked} onClick={() => act('skip')}>
                    {t('storefront.account.skip_cycle', 'Skip cycle')}
                </ActionButton>
            ) : null}
            {row.can_cancel_at_period_end ? (
                <ActionButton tone="warning" icon="calendar" disabled={locked} onClick={() => act('cancel-at-period-end')}>
                    {t('storefront.account.cancel_at_period_end', 'Cancel at period end')}
                </ActionButton>
            ) : null}
            {row.can_resume_period_end ? (
                <ActionButton tone="success" icon="checkCircle" disabled={locked} onClick={() => act('resume-period-end')}>
                    {t('storefront.account.keep_subscription', 'Keep subscription')}
                </ActionButton>
            ) : null}
            {row.can_cancel ? (
                <ActionButton tone="danger" icon="close" disabled={locked} onClick={() => act('cancel')}>
                    {t('storefront.account.cancel_subscription', 'Cancel now')}
                </ActionButton>
            ) : null}
            {row.can_reactivate ? (
                <ActionButton tone="success" icon="refresh" disabled={locked} onClick={() => act('reactivate')}>
                    {t('storefront.account.reactivate', 'Reactivate')}
                </ActionButton>
            ) : null}
        </div>
    );
}
