import { Link, router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import Layout from '../../Layout';
import {
    Alert,
    Badge,
    ColumnFilter,
    EmptyState,
    FilterReset,
    Icon,
    KpiCards,
    ListFooter,
    ListPanel,
    PageHeader,
    RowActions,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    cx,
    formatMoney,
    ui,
    t,
} from '../../../ui';
import { statusLabel, statusTone } from './status';

const DEFAULT_RENEWAL_SCHEDULER = {
    cron: '* * * * * cd /path/to/wentocart && php artisan schedule:run >> /dev/null 2>&1',
    verify: [
        'php artisan schedule:list',
        'php artisan wento:subscriptions-tick',
    ],
};

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? String(value)
        : date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function CustomerSubscriptionsIndex() {
    const {
        subscriptions,
        counts = {},
        metrics = {},
        plans = [],
        filters = {},
        renewalScheduler = DEFAULT_RENEWAL_SCHEDULER,
    } = usePage().props;

    const rows = subscriptions?.data || [];
    const currency = metrics.currency || 'USD';
    const cronCommand = renewalScheduler?.cron || DEFAULT_RENEWAL_SCHEDULER.cron;
    const verifyCommands = Array.isArray(renewalScheduler?.verify) && renewalScheduler.verify.length
        ? renewalScheduler.verify
        : DEFAULT_RENEWAL_SCHEDULER.verify;

    const query = useMemo(() => ({
        status: filters.status || 'all',
        q: filters.q || '',
        plan_id: filters.plan_id || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/customer-subscriptions', cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((name, value) => {
        apply({ [name]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const kpis = useMemo(() => [
        { label: t('admin.subscriptions.kpi_active', 'Active'), value: metrics.active ?? 0 },
        { label: t('admin.subscriptions.kpi_trialing', 'On Trial'), value: metrics.trialing ?? 0 },
        { label: t('admin.subscriptions.kpi_past_due', 'Past Due'), value: metrics.past_due ?? 0 },
        { label: t('admin.subscriptions.kpi_mrr', 'Monthly Recurring'), value: formatMoney(metrics.mrr, currency) },
    ], [metrics, currency]);

    const statusOptions = useMemo(() => (
        ['pending', 'active', 'trialing', 'past_due', 'paused', 'cancelled'].map((key) => ({
            value: key,
            label: `${statusLabel(key)} (${counts[key] ?? 0})`,
        }))
    ), [counts]);

    return (
        <Layout>
            <PageHeader
                title={t('admin.subscriptions.customers_title', 'Customer subscriptions')}
                total={subscriptions?.total}
                description={t('admin.subscriptions.customers_subtitle', 'Who is subscribed, what it is worth, and support actions.')}
                crumbs={[
                    { href: '/admin', label: 'Dashboard' },
                    { label: t('admin.subscriptions.customers_title', 'Customer subscriptions') },
                ]}
            />

            <KpiCards items={kpis} />

            <ListPanel footer={<ListFooter pagination={subscriptions} query={query} />}>
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.common.customer', 'Customer')}</Th>
                            <Th>{t('admin.subscriptions.plan', 'Plan')}</Th>
                            <Th col="center">{t('admin.subscriptions.status', 'Status')}</Th>
                            <SortHeader col="center" column="next_renewal_at" label={t('admin.subscriptions.next_renewal', 'Next renewal')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="cycles_completed" label={t('admin.subscriptions.cycles', 'Cycles')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="q"
                                    value={query.q}
                                    placeholder={t('admin.subscriptions.search_placeholder', 'Customer')}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    name="plan_id"
                                    value={query.plan_id}
                                    type="select"
                                    placeholder={t('admin.subscriptions.all_plans', 'All')}
                                    options={plans.map((plan) => ({ value: String(plan.id), label: plan.name }))}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status === 'all' ? '' : query.status}
                                    type="select"
                                    placeholder="All"
                                    options={statusOptions}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilterCenter} />
                            <Th className={ui.thFilterCenter} />
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/customer-subscriptions" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={6}>
                                {t('admin.subscriptions.none_title', 'No subscriptions yet.')}
                            </EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id}>
                                <Td>
                                    <Link href={row.url} className={ui.cellStrong}>
                                        {row.customer_name || row.customer_email || `#${row.customer_id}`}
                                    </Link>
                                    <div className={ui.muted}>{row.customer_email}</div>
                                </Td>
                                <Td>{row.plan_name || '—'}</Td>
                                <Td col="center">
                                    <Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge>
                                    {row.on_trial && row.trial_ends_at ? (
                                        <div className={ui.muted}>
                                            {t('admin.subscriptions.trial_ends', 'Trial ends')} {formatDate(row.trial_ends_at)}
                                        </div>
                                    ) : null}
                                    {row.cancel_at_period_end ? (
                                        <div className={ui.muted}>{t('admin.subscriptions.ends_after_period', 'Ends after this period')}</div>
                                    ) : null}
                                    {row.last_renewal_error ? (
                                        <div className={ui.muted} title={row.last_renewal_error}>{row.last_renewal_error}</div>
                                    ) : null}
                                </Td>
                                <Td col="center">{formatDate(row.next_renewal_at)}</Td>
                                <Td col="center">{row.cycles_completed}</Td>
                                <Td actions>
                                    <RowActions
                                        viewHref={row.url}
                                        viewLabel={t('admin.common.view', 'View')}
                                        canEdit={false}
                                        canDelete={false}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>

            <section
                className={cx(ui.helpCard, 'mt-6')}
                aria-labelledby="subscription-renewal-scheduler-title"
            >
                <div className="flex min-w-0 items-start gap-3">
                    <span className={ui.sectionHeadIcon}>
                        <Icon name="clock" className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1 space-y-3">
                        <div>
                            <p className={ui.sectionKicker}>
                                {t('admin.subscriptions.scheduler_kicker', 'Operations')}
                            </p>
                            <h2
                                id="subscription-renewal-scheduler-title"
                                className={cx(ui.sectionTitle, 'mb-1')}
                            >
                                {t('admin.subscriptions.scheduler_title', 'Subscription Renewal Scheduler')}
                            </h2>
                            <p className={cx(ui.muted, 'text-sm')}>
                                {t(
                                    'admin.subscriptions.scheduler_intro',
                                    'Automatic renewals only run when the Laravel scheduler is active on the server. The recommended production setup is a system cron that runs every minute.',
                                )}
                            </p>
                        </div>

                        <Alert type="info" className="mb-0">
                            {t(
                                'admin.subscriptions.scheduler_no_work',
                                "Production: Use a system cron to run Laravel's scheduler every minute. If you use the cron command below, you do not need to run `php artisan schedule:work` separately.",
                            )}
                        </Alert>

                        <div className="space-y-1.5">
                            <p className="text-sm font-medium text-neutral-800">
                                {t('admin.subscriptions.scheduler_cron_label', 'Recommended production cron (every minute)')}
                            </p>
                            <pre className={cx(ui.codePreview, 'font-mono')}>{cronCommand}</pre>
                            <p className={cx(ui.muted, 'text-xs')}>
                                {t(
                                    'admin.subscriptions.scheduler_path_note',
                                    'Replace /path/to/wentocart with the actual application root on the server.',
                                )}
                            </p>
                        </div>

                        <div className="space-y-1.5">
                            <p className="text-sm font-medium text-neutral-800">
                                {t('admin.subscriptions.scheduler_verify_label', 'Verify the scheduler')}
                            </p>
                            <pre className={cx(ui.codePreview, 'font-mono')}>{verifyCommands.join('\n')}</pre>
                        </div>
                    </div>
                </div>
            </section>
        </Layout>
    );
}