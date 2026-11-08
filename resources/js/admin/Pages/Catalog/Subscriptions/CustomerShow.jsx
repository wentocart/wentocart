import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import Layout from '../../Layout';
import {
    Alert,
    Badge,
    Button,
    Field,
    Icon,
    Input,
    PageHeader,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Thumb,
    formatMoney,
    ui,
    t,
} from '../../../ui';
import {
    orderStatusLabel,
    orderStatusTone,
    paymentLabel,
    paymentTone,
    statusLabel,
    statusTone,
} from './status';

function formatDate(value) {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleString(undefined, {
            day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
        });
}

function Fact({ label, children }) {
    if (children == null || children === '') {
        return null;
    }

    return (
        <div className={ui.detailRow}>
            <span className={ui.detailLabel}>{label}</span>
            <span className={ui.detailValue}>{children}</span>
        </div>
    );
}

function FactsCard({ title, children }) {
    return (
        <section className={ui.sectionCardCompact}>
            <div className={ui.sectionHead}>
                <h2 className={ui.sectionTitle}>{title}</h2>
            </div>
            {children}
        </section>
    );
}

export default function CustomerSubscriptionShow() {
    const { subscription, renewals = [], available_plans = [], can = {} } = usePage().props;
    const [busy, setBusy] = useState(null);
    const [planId, setPlanId] = useState(subscription.plan_id);
    const [trialEnds, setTrialEnds] = useState(
        subscription.trial_ends_at ? subscription.trial_ends_at.slice(0, 16) : '',
    );

    const act = useCallback((action, data = {}) => {
        setBusy(action);
        router.post(`/admin/customer-subscriptions/${subscription.id}/${action}`, data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    }, [subscription.id]);

    const productName = subscription.product_name || t('admin.subscriptions.detail_title', 'Subscription');
    const customerName = subscription.customer_name || subscription.customer_email || '—';
    const cancellation = subscription.cancel_at_period_end
        ? t('admin.subscriptions.ends_after_period', 'Ends after this period')
        : formatDate(subscription.cancelled_at);
    const priceLabel = subscription.price != null
        ? formatMoney(subscription.price, subscription.currency_code || 'USD')
        : null;
    const locked = !!busy;
    const canPause = can.manage && (subscription.status === 'active' || subscription.status === 'trialing');
    const canResume = can.manage && subscription.status === 'paused';
    const canSkip = can.manage && subscription.can_skip;
    const canCancelPeriod = can.manage && subscription.is_manageable && !subscription.cancel_at_period_end;
    const canKeep = can.manage && subscription.cancel_at_period_end && subscription.is_manageable;
    const canCancelNow = can.manage && subscription.is_manageable;
    const canRetry = can.manage && subscription.status === 'past_due';
    const canReactivate = can.manage && subscription.can_reactivate;
    const hasActions = canPause || canResume || canSkip || canCancelPeriod || canKeep || canCancelNow || canRetry || canReactivate;
    const canManagePlan = can.manage && subscription.is_manageable;

    return (
        <Layout>
            <PageHeader
                crumbsFirst
                title={t('admin.subscriptions.detail_title', 'Subscription details')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customer-subscriptions', label: t('admin.subscriptions.customers_title', 'Customer subscriptions') },
                    { label: productName },
                ]}
                actions={(
                    <Button href="/admin/customer-subscriptions" variant="neutral" icon="arrow-left">
                        {t('admin.common.back', 'Back')}
                    </Button>
                )}
            />

            <div className={ui.formMain}>
                <section className={ui.sectionCardCompact}>
                    <div className={ui.infoRowPlain}>
                        <div className={ui.row}>
                            <Thumb src={subscription.product_cover} alt={productName} />
                            <div>
                                <p className={ui.sectionKicker}>{t('admin.subscriptions.product', 'Product')}</p>
                                {subscription.product_url ? (
                                    <Link href={subscription.product_url} className={ui.infoRowLabel}>{productName}</Link>
                                ) : (
                                    <p className={ui.infoRowLabel}>{productName}</p>
                                )}
                                <p className={ui.infoRowHint}>
                                    {t('admin.subscriptions.plan', 'Subscription Plan')}: {subscription.plan_name || '—'}
                                </p>
                                <p className={ui.infoRowHint}>
                                    {t('admin.common.customer', 'Customer')}:{' '}
                                    {subscription.customer_url ? (
                                        <Link href={subscription.customer_url} className={ui.btnLink}>{customerName}</Link>
                                    ) : customerName}
                                </p>
                            </div>
                        </div>
                        <div>
                            <Badge tone={statusTone(subscription.status)}>{statusLabel(subscription.status)}</Badge>
                            {priceLabel ? <p className={ui.summaryGrandAmt}>{priceLabel}</p> : null}
                            {subscription.billing_summary ? (
                                <p className={ui.infoRowHint}>{subscription.billing_summary}</p>
                            ) : null}
                        </div>
                    </div>

                    {hasActions ? (
                        <div className={ui.sectionSplitTight}>
                            <p className={ui.metaK}>{t('admin.common.actions', 'Actions')}</p>
                            <div className={ui.toolbar}>
                                {canRetry ? (
                                    <Button variant="success" icon="refresh" disabled={locked} busy={busy === 'retry'} onClick={() => act('retry')}>
                                        {t('admin.subscriptions.retry_now', 'Retry charge')}
                                    </Button>
                                ) : null}
                                {canPause ? (
                                    <Button variant="warning" icon="pause" disabled={locked} busy={busy === 'pause'} onClick={() => act('pause')}>
                                        {t('admin.subscriptions.pause', 'Pause')}
                                    </Button>
                                ) : null}
                                {canResume ? (
                                    <Button variant="success" icon="play" disabled={locked} busy={busy === 'resume'} onClick={() => act('resume')}>
                                        {t('admin.subscriptions.resume', 'Resume')}
                                    </Button>
                                ) : null}
                                {canSkip ? (
                                    <Button variant="neutral" icon="skip-forward" disabled={locked} busy={busy === 'skip'} onClick={() => act('skip')}>
                                        {t('admin.subscriptions.skip_cycle', 'Skip cycle')}
                                    </Button>
                                ) : null}
                                {canKeep ? (
                                    <Button variant="success" icon="check" disabled={locked} busy={busy === 'resume-period-end'} onClick={() => act('resume-period-end')}>
                                        {t('admin.subscriptions.keep', 'Keep subscription')}
                                    </Button>
                                ) : null}
                                {canCancelPeriod ? (
                                    <Button variant="warning" icon="clock" disabled={locked} busy={busy === 'cancel-at-period-end'} onClick={() => act('cancel-at-period-end')}>
                                        {t('admin.subscriptions.cancel_period_end', 'Cancel at period end')}
                                    </Button>
                                ) : null}
                                {canCancelNow ? (
                                    <Button variant="dangerSoft" icon="close" disabled={locked} busy={busy === 'cancel'} onClick={() => act('cancel')}>
                                        {t('admin.subscriptions.cancel_now', 'Cancel now')}
                                    </Button>
                                ) : null}
                                {canReactivate ? (
                                    <Button variant="success" icon="refresh" disabled={locked} busy={busy === 'reactivate'} onClick={() => act('reactivate')}>
                                        {t('admin.subscriptions.reactivate', 'Reactivate')}
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </section>

                <div className={ui.formLayoutEqual}>
                    <FactsCard title={t('admin.subscriptions.subscription_information', 'Subscription information')}>
                        <Fact label={t('admin.subscriptions.plan', 'Subscription Plan')}>{subscription.plan_name}</Fact>
                        <Fact label={t('admin.subscriptions.status', 'Status')}>{statusLabel(subscription.status)}</Fact>
                        <Fact label={t('admin.subscriptions.started', 'Start date')}>{formatDate(subscription.started_at)}</Fact>
                        <Fact label={t('admin.subscriptions.duration', 'Duration')}>{subscription.duration_summary}</Fact>
                        <Fact label={t('admin.subscriptions.quantity', 'Quantity')}>
                            {subscription.qty != null ? String(subscription.qty) : null}
                        </Fact>
                        <Fact label={t('admin.subscriptions.cancellation', 'Cancellation')}>{cancellation}</Fact>
                        <Fact label={t('admin.subscriptions.ended_at', 'Ended')}>{formatDate(subscription.ended_at)}</Fact>
                    </FactsCard>

                    <FactsCard title={t('admin.subscriptions.billing_information', 'Billing information')}>
                        <Fact label={t('admin.subscriptions.price', 'Price')}>{priceLabel}</Fact>
                        <Fact label={t('admin.subscriptions.billing_interval', 'Billing Interval')}>{subscription.billing_summary}</Fact>
                        <Fact label={t('admin.subscriptions.trial_period', 'Trial period')}>{subscription.trial_summary}</Fact>
                        <Fact label={t('admin.subscriptions.trial_ends', 'Trial end date')}>{formatDate(subscription.trial_ends_at)}</Fact>
                        <Fact label={t('admin.subscriptions.next_renewal', 'Next Billing')}>{formatDate(subscription.next_renewal_at)}</Fact>
                        <Fact label={t('admin.subscriptions.cycles', 'Completed cycles')}>
                            {subscription.cycles_completed != null ? String(subscription.cycles_completed) : null}
                        </Fact>
                        <Fact label={t('admin.subscriptions.payment_status', 'Payment Status')}>
                            <Badge tone={paymentTone(subscription.payment_status)}>
                                {paymentLabel(subscription.payment_status)}
                            </Badge>
                        </Fact>
                        <Fact label={t('admin.subscriptions.value', 'Monthly value')}>
                            {formatMoney(subscription.monthly_value, subscription.currency_code || 'USD')}
                        </Fact>
                    </FactsCard>
                </div>

                <FactsCard title={t('admin.subscriptions.billing_health', 'Billing health')}>
                    {subscription.last_renewal_error ? (
                        <Alert type="error">
                            <strong>{t('admin.subscriptions.last_error', 'Last renewal failed')}</strong>
                            <p>{subscription.last_renewal_error}</p>
                        </Alert>
                    ) : (
                        <p className={ui.muted}>{t('admin.subscriptions.no_errors', 'No renewal errors recorded.')}</p>
                    )}
                    <Fact label={t('admin.subscriptions.dunning', 'Dunning attempts')}>
                        {`${subscription.dunning_attempts}${subscription.dunning_exhausted ? ` · ${t('admin.subscriptions.dunning_exhausted', 'exhausted')}` : ''}`}
                    </Fact>
                    <Fact label={t('admin.subscriptions.attempts', 'Attempts this period')}>{String(subscription.renewal_attempts)}</Fact>
                    {subscription.last_pending_action_url ? (
                        <Button
                            native
                            href={subscription.last_pending_action_url}
                            variant="neutral"
                            icon="lock"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('admin.subscriptions.complete_auth', 'Customer authentication pending')}
                        </Button>
                    ) : null}
                </FactsCard>

                {canManagePlan ? (
                    <section className={ui.sectionCardCompact}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.subscriptions.manage_plan', 'Plan and trial')}</h2>
                        </div>
                        <div className={ui.formLayoutEqual}>
                            {available_plans.length > 1 ? (
                                <Field
                                    label={t('admin.subscriptions.change_plan', 'Change plan')}
                                    hint={t('admin.subscriptions.change_plan_hint', 'New price applies on the next renewal (no mid-cycle proration).')}
                                >
                                    <div className={ui.row}>
                                        <Select
                                            value={planId}
                                            onChange={(e) => setPlanId(Number(e.target.value))}
                                        >
                                            {available_plans.map((plan) => (
                                                <option key={plan.id} value={plan.id}>{plan.name} ({plan.price})</option>
                                            ))}
                                        </Select>
                                        <Button
                                            variant="neutral"
                                            disabled={locked || Number(planId) === Number(subscription.plan_id)}
                                            busy={busy === 'change-plan'}
                                            onClick={() => act('change-plan', { subscription_plan_id: Number(planId) })}
                                        >
                                            {t('admin.subscriptions.apply_plan', 'Apply plan')}
                                        </Button>
                                    </div>
                                </Field>
                            ) : (
                                <p className={ui.muted}>{t('admin.subscriptions.one_plan', 'This product has a single plan.')}</p>
                            )}
                            <Field label={t('admin.subscriptions.trial', 'Trial')}>
                                <div className={ui.row}>
                                    <Input
                                        type="datetime-local"
                                        value={trialEnds}
                                        onChange={(e) => setTrialEnds(e.target.value)}
                                    />
                                    <Button
                                        variant="neutral"
                                        disabled={locked}
                                        busy={busy === 'trial'}
                                        onClick={() => act('trial', { trial_ends_at: trialEnds || null })}
                                    >
                                        {t('admin.subscriptions.save_trial', 'Save trial')}
                                    </Button>
                                </div>
                            </Field>
                        </div>
                    </section>
                ) : null}

                <section className={ui.sectionCardCompact}>
                    <div className={ui.sectionHead}>
                        <h2 className={ui.sectionTitle}>{t('admin.subscriptions.renewals', 'Renewal invoices')}</h2>
                    </div>
                    {renewals.length === 0 ? (
                        <div className={ui.emptyPanel}>
                            <div className={ui.emptyPanelIcon}>
                                <Icon name="file" />
                            </div>
                            <div>
                                <p className={ui.infoRowLabel}>{t('admin.subscriptions.no_renewals', 'No renewal invoices yet.')}</p>
                                <p className={ui.infoRowHint}>
                                    {t('admin.subscriptions.no_renewals_hint', 'Renewal orders will appear here after the next billing cycle.')}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <Table>
                            <THead>
                                <tr>
                                    <Th>{t('admin.orders.number', 'Order')}</Th>
                                    <Th>{t('admin.subscriptions.invoice_type', 'Type')}</Th>
                                    <Th col="center">{t('admin.orders.status', 'Status')}</Th>
                                    <Th col="center">{t('admin.orders.payment', 'Payment')}</Th>
                                    <Th col="center">{t('admin.orders.total', 'Total')}</Th>
                                    <Th col="center">{t('admin.common.date', 'Date')}</Th>
                                    <Th actions>{t('admin.common.action', 'Action')}</Th>
                                </tr>
                            </THead>
                            <tbody>
                                {renewals.map((order) => (
                                    <Tr key={`${order.kind || 'order'}-${order.id}`}>
                                        <Td>
                                            <Link href={`/admin/orders/${order.id}`} className={ui.cellStrong}>
                                                #{order.number}
                                            </Link>
                                        </Td>
                                        <Td>
                                            {order.kind === 'original'
                                                ? t('admin.subscriptions.original_order', 'Original order')
                                                : t('admin.subscriptions.renewal_order', 'Renewal order')}
                                        </Td>
                                        <Td col="center">
                                            <Badge tone={orderStatusTone(order.status)}>
                                                {orderStatusLabel(order.status)}
                                            </Badge>
                                        </Td>
                                        <Td col="center">
                                            <Badge tone={paymentTone(order.payment_status)}>
                                                {paymentLabel(order.payment_status)}
                                            </Badge>
                                        </Td>
                                        <Td col="center">{order.grand_total} {order.currency_code}</Td>
                                        <Td col="center">{formatDate(order.created_at)}</Td>
                                        <Td actions>
                                            <Button href={`/admin/orders/${order.id}`} variant="ghost" icon="eye">
                                                {t('admin.common.view', 'View')}
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </section>
            </div>
        </Layout>
    );
}
