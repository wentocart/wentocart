import { router } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';
import {
    Alert,
    Badge,
    Button,
    Card,
    EmptyState,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    t,
    ui,
} from '../../ui';

function copyText(value) {
    const text = value == null ? '' : String(value);
    if (!text || !navigator?.clipboard?.writeText) {
        return;
    }
    navigator.clipboard.writeText(text).catch(() => {});
}

function prettyJson(value) {
    if (value == null || value === '') {
        return '';
    }
    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function deliveryTone(status) {
    if (status === 'sent') return 'success';
    if (status === 'failed') return 'danger';
    if (status === 'sending') return 'info';
    return 'neutral';
}

function statusLabel(status) {
    if (status === 'sent') return t('admin.ops_tools.log_sent', 'Sent');
    if (status === 'failed') return t('admin.ops_tools.log_failed', 'Failed');
    if (status === 'sending') return t('admin.ops_tools.log_sending', 'Sending');
    if (status === 'pending') return t('admin.ops_tools.log_pending', 'Waiting');
    return status;
}

export default function WebhookLogs({ deliveries = [], eventLabel }) {
    const [filter, setFilter] = useState('all');
    const [openId, setOpenId] = useState(null);
    const [copied, setCopied] = useState('');

    const counts = useMemo(() => ({
        all: deliveries.length,
        sent: deliveries.filter((row) => row.status === 'sent').length,
        failed: deliveries.filter((row) => row.status === 'failed').length,
        pending: deliveries.filter((row) => row.status === 'pending' || row.status === 'sending').length,
    }), [deliveries]);

    const rows = useMemo(() => {
        if (filter === 'all') {
            return deliveries;
        }
        if (filter === 'pending') {
            return deliveries.filter((row) => row.status === 'pending' || row.status === 'sending');
        }
        return deliveries.filter((row) => row.status === filter);
    }, [deliveries, filter]);

    const copy = (value, id) => {
        copyText(value);
        setCopied(id);
    };

    return (
        <Card className="space-y-3">
            <Alert type="info">
                {t('admin.ops_tools.logs_notice', 'Each row is one message we sent (or tried to send) to a saved destination. Open a row to see the JSON payload and their reply. Failed rows can be sent again.')}
            </Alert>
            <StatusTabs
                value={filter}
                onChange={setFilter}
                tabs={[
                    { key: 'all', label: `${t('admin.common.all', 'All')} (${counts.all})` },
                    { key: 'sent', label: `${t('admin.ops_tools.log_sent', 'Sent')} (${counts.sent})` },
                    { key: 'failed', label: `${t('admin.ops_tools.log_failed', 'Failed')} (${counts.failed})` },
                    { key: 'pending', label: `${t('admin.ops_tools.log_pending', 'Waiting')} (${counts.pending})` },
                ]}
            />
            <Table>
                <THead>
                    <Th>{t('admin.ops_tools.event', 'Event')}</Th>
                    <Th>{t('admin.common.status', 'Status')}</Th>
                    <Th>{t('admin.activity.when', 'When')}</Th>
                    <Th></Th>
                </THead>
                <tbody>
                    {rows.length ? rows.map((row) => {
                        const open = openId === row.id;
                        const envelope = {
                            id: row.uuid,
                            event: row.event,
                            created_at: row.sent_at || row.created_at,
                            data: row.payload || {},
                        };
                        const payloadText = prettyJson(envelope);
                        const responseText = prettyJson(row.response_body) || row.response_body || '';

                        return (
                            <Fragment key={row.id}>
                                <Tr>
                                    <Td>
                                        <button
                                            type="button"
                                            className="cursor-pointer text-left"
                                            onClick={() => setOpenId(open ? null : row.id)}
                                        >
                                            <span className={ui.cellStrong}>{eventLabel(row.event)}</span>
                                            <div className={ui.muted}>{row.endpoint || '—'}</div>
                                            {row.uuid ? <div className={ui.muted}>{row.uuid}</div> : null}
                                        </button>
                                    </Td>
                                    <Td>
                                        <Badge tone={deliveryTone(row.status)}>{statusLabel(row.status)}</Badge>
                                        {row.response_code ? (
                                            <div className={ui.muted}>{t('admin.ops_tools.response_code', 'Their reply')}: {row.response_code}</div>
                                        ) : null}
                                        {row.last_error ? <div className={ui.muted}>{row.last_error}</div> : null}
                                    </Td>
                                    <Td>
                                        {row.sent_at || row.created_at}
                                        <div className={ui.muted}>{t('admin.ops_tools.attempts', 'Attempts')}: {row.attempts}</div>
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-2">
                                            <Button type="button" variant="secondary" onClick={() => setOpenId(open ? null : row.id)}>
                                                {open ? t('admin.common.hide', 'Hide') : t('admin.ops_tools.view_log', 'View')}
                                            </Button>
                                            {row.status !== 'sent' ? (
                                                <Button variant="secondary" type="button" onClick={() => router.post(`/admin/ops-tools/deliveries/${row.id}/retry`)}>
                                                    {t('admin.ops_tools.retry', 'Retry')}
                                                </Button>
                                            ) : null}
                                        </div>
                                    </Td>
                                </Tr>
                                {open ? (
                                    <Tr>
                                        <Td colSpan={4}>
                                            <div className="grid gap-4 lg:grid-cols-2">
                                                <div className="space-y-2">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className={ui.cellStrong}>{t('admin.ops_tools.log_payload', 'Payload we sent')}</p>
                                                        <Button type="button" variant="ghost" onClick={() => copy(payloadText, `p-${row.id}`)}>
                                                            {copied === `p-${row.id}` ? t('admin.common.copied', 'Copied') : t('admin.common.copy', 'Copy')}
                                                        </Button>
                                                    </div>
                                                    <pre className={ui.codePreview}>{payloadText || '—'}</pre>
                                                </div>
                                                <div className="space-y-2">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className={ui.cellStrong}>{t('admin.ops_tools.log_response', 'Their response')}</p>
                                                        {responseText ? (
                                                            <Button type="button" variant="ghost" onClick={() => copy(responseText, `r-${row.id}`)}>
                                                                {copied === `r-${row.id}` ? t('admin.common.copied', 'Copied') : t('admin.common.copy', 'Copy')}
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                    <pre className={ui.codePreview}>{responseText || t('admin.ops_tools.log_no_response', 'No response body stored.')}</pre>
                                                </div>
                                            </div>
                                        </Td>
                                    </Tr>
                                ) : null}
                            </Fragment>
                        );
                    }) : (
                        <EmptyState colSpan={4}>{t('admin.ops_tools.no_logs', 'No webhook logs yet. Send a test from Webhook Events to create the first row.')}</EmptyState>
                    )}
                </tbody>
            </Table>
        </Card>
    );
}
