import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Badge,
    Button,
    Card,
    EmptyState,
    Icon,
    Input,
    ListFooter,
    ListPanel,
    RowActions,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    cx,
} from '../../ui';

function copyText(value) {
    const text = value == null ? '' : String(value);
    if (!text || !navigator?.clipboard?.writeText) {
        return;
    }
    navigator.clipboard.writeText(text).catch(() => {});
}

function emailExportHref(filters = {}) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value && value !== 'all') {
            params.set(key, value);
        }
    });
    const query = params.toString();

    return query ? `/admin/advanced/email-logs/export?${query}` : '/admin/advanced/email-logs/export';
}

export default function EmailLogs({ rows, filters = {}, templates = [], stats = {} }) {
    const [draft, setDraft] = useState({
        q: filters.q || '',
        template: filters.template || 'all',
        status: filters.status || 'all',
        range: filters.range || '7',
    });

    const apply = (next = draft) => {
        router.get('/admin/email', {
            tab: 'logs',
            q: next.q || undefined,
            template: next.template && next.template !== 'all' ? next.template : undefined,
            status: next.status && next.status !== 'all' ? next.status : undefined,
            range: next.range || '7',
            per_page: filters.per_page || 10,
        }, { preserveState: true, replace: true });
    };

    const reset = () => {
        const cleared = { q: '', template: 'all', status: 'all', range: '7' };
        setDraft(cleared);
        router.get('/admin/email', { tab: 'logs', range: '7' }, { preserveState: true, replace: true });
    };

    const data = rows?.data || [];
    const total = rows?.total || 0;
    const statusTone = (status) => {
        if (status === 'sent') return 'success';
        if (status === 'queued') return 'warning';
        return 'danger';
    };
    const statusLabel = (status) => {
        if (status === 'sent') return t('admin.advanced.sent', 'Sent');
        if (status === 'queued') return t('admin.advanced.queued', 'Queued');
        if (status === 'failed') return t('admin.advanced.failed', 'Failed');
        return status;
    };

    return (
        <div className={ui.formLayout}>
            <div className={ui.formMain}>
                <Card className="space-y-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex items-start gap-3">
                            <span className={ui.sectionHeadIcon}>
                                <Icon name="mail" className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className={cx(ui.sectionTitle, 'mb-0')}>{t('admin.advanced.email_logs', 'Email logs')}</h2>
                                <p className={cx(ui.muted, 'mt-1')}>
                                    {t('admin.advanced.email_logs_lead', 'View the history of all emails sent from your store.')}
                                </p>
                            </div>
                        </div>
                        <Button variant="secondary" icon="upload" href={emailExportHref(draft)}>
                            {t('admin.advanced.export_logs', 'Export logs')}
                        </Button>
                    </div>

                    <div className={cx(ui.filterBar, 'flex-wrap')}>
                        <Select
                            value={draft.range}
                            aria-label={t('admin.advanced.date_range', 'Date range')}
                            onChange={(e) => {
                                const next = { ...draft, range: e.target.value };
                                setDraft(next);
                                apply(next);
                            }}
                        >
                            <option value="7">{t('admin.advanced.last_7_days', 'Last 7 days')}</option>
                            <option value="30">{t('admin.advanced.last_30_days', 'Last 30 days')}</option>
                            <option value="90">{t('admin.advanced.last_90_days', 'Last 90 days')}</option>
                            <option value="all">{t('admin.advanced.all_time', 'All time')}</option>
                        </Select>
                        <div className="relative min-w-[14rem] flex-1">
                            <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <Input
                                className="w-full pl-9"
                                value={draft.q}
                                placeholder={t('admin.advanced.search_email_logs', 'Search by email, subject, or template...')}
                                onChange={(e) => setDraft((prev) => ({ ...prev, q: e.target.value }))}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        apply();
                                    }
                                }}
                            />
                        </div>
                        <Select
                            value={draft.template}
                            onChange={(e) => {
                                const next = { ...draft, template: e.target.value };
                                setDraft(next);
                                apply(next);
                            }}
                        >
                            <option value="all">{t('admin.advanced.all_templates', 'All templates')}</option>
                            {templates.map((code) => (
                                <option key={code} value={code}>{code}</option>
                            ))}
                        </Select>
                        <Select
                            value={draft.status}
                            onChange={(e) => {
                                const next = { ...draft, status: e.target.value };
                                setDraft(next);
                                apply(next);
                            }}
                        >
                            <option value="all">{t('admin.advanced.all_statuses', 'All statuses')}</option>
                            <option value="sent">{t('admin.advanced.sent', 'Sent')}</option>
                            <option value="queued">{t('admin.advanced.queued', 'Queued')}</option>
                            <option value="failed">{t('admin.advanced.failed', 'Failed')}</option>
                        </Select>
                        <Button type="button" variant="secondary" icon="refresh" onClick={reset}>
                            {t('admin.common.reset', 'Reset')}
                        </Button>
                    </div>

                    <ListPanel
                        footer={(
                            <ListFooter
                                left={(
                                    <span>
                                        {t('admin.common.showing', 'Showing')} {rows?.from || 0} {t('admin.common.to', 'to')} {rows?.to || 0}{' '}
                                        {t('admin.common.of', 'of')} {total} {t('admin.advanced.logs_count', 'logs')}
                                    </span>
                                )}
                                pagination={rows}
                                query={{
                                    tab: 'logs',
                                    q: filters.q || undefined,
                                    template: filters.template || undefined,
                                    status: filters.status || undefined,
                                    range: filters.range || '7',
                                    per_page: filters.per_page || 10,
                                }}
                            />
                        )}
                    >
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>{t('admin.advanced.to', 'To')}</Th>
                                    <Th>{t('admin.advanced.subject', 'Subject')}</Th>
                                    <Th>{t('admin.advanced.template', 'Template')}</Th>
                                    <Th>{t('admin.advanced.status', 'Status')}</Th>
                                    <Th>{t('admin.advanced.when', 'When')}</Th>
                                    <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                                </Tr>
                            </THead>
                            <tbody>
                                {!data.length ? (
                                    <EmptyState colSpan={6}>
                                        {t('admin.advanced.no_email_logs_hint', 'Sends from templates, events, and SMTP tests appear here.')}
                                    </EmptyState>
                                ) : (
                                    data.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>{row.to}</Td>
                                            <Td>{row.subject}</Td>
                                            <Td><code className="text-xs">{row.template || '—'}</code></Td>
                                            <Td>
                                                <Badge tone={statusTone(row.status)}>{statusLabel(row.status)}</Badge>
                                                {row.error ? <div className={ui.muted}>{row.error}</div> : null}
                                            </Td>
                                            <Td>{row.created_at || '—'}</Td>
                                            <Td actions>
                                                <RowActions
                                                    canEdit={false}
                                                    canDelete={false}
                                                    items={[
                                                        {
                                                            key: 'copy',
                                                            icon: 'copy',
                                                            label: t('admin.advanced.copy_recipient', 'Copy recipient'),
                                                            onClick: () => copyText(row.to),
                                                        },
                                                    ]}
                                                />
                                            </Td>
                                        </Tr>
                                    ))
                                )}
                            </tbody>
                        </Table>
                    </ListPanel>
                </Card>
            </div>

            <aside className={ui.formSide}>
                <Card className="space-y-2 border-violet-100 bg-violet-50/40">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="info" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.about_email_logs', 'About email logs')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.about_email_logs_hint', 'This page records outgoing store emails so you can troubleshoot delivery and template issues.')}
                            </p>
                        </div>
                    </div>
                </Card>
                <Card className="space-y-3">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="lightbulb" className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className={cx(ui.sectionTitle, 'mb-0 text-base text-violet-800')}>
                                {t('admin.advanced.need_help', 'Need help?')}
                            </h3>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.advanced.need_help_email_hint', 'Check the documentation for SMTP setup and email templates.')}
                            </p>
                        </div>
                    </div>
                    <Button variant="secondary" icon="link" href="/admin/email?tab=smtp">
                        {t('admin.email.open_smtp', 'Open SMTP')}
                    </Button>
                </Card>
                <Card className="space-y-3">
                    <div className="flex items-center gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="gauge" className="h-5 w-5" />
                        </span>
                        <h3 className={cx(ui.sectionTitle, 'mb-0 text-base')}>
                            {t('admin.advanced.email_status', 'Email status')}
                        </h3>
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <Badge tone="success">{t('admin.advanced.sent', 'Sent')}</Badge>
                            <span className="font-semibold tabular-nums">{stats.sent ?? 0}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <Badge tone="warning">{t('admin.advanced.queued', 'Queued')}</Badge>
                            <span className="font-semibold tabular-nums">{stats.queued ?? 0}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <Badge tone="danger">{t('admin.advanced.failed', 'Failed')}</Badge>
                            <span className="font-semibold tabular-nums">{stats.failed ?? 0}</span>
                        </div>
                    </div>
                </Card>
            </aside>
        </div>
    );
}
