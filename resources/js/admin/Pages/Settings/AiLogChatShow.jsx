import { router } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    PageHeader,
    ui,
    cx,
    t,
} from '../../ui';

function MessageBody({ msg }) {
    return (
        <div>
            {msg.text ? <p className="whitespace-pre-wrap">{msg.text}</p> : null}
            {msg.kind === 'chart' && msg.chart ? (
                <p className={cx(ui.muted, 'mt-2')}>{t('admin.ai.chart_in_session', 'Chart was shown in this session.')}</p>
            ) : null}
            {(msg.kind === 'table' || msg.kind === 'chart') && msg.table?.length ? (
                <div className={ui.aiChatTableWrap}>
                    <table className="min-w-full text-left text-xs">
                        <thead className="bg-neutral-100">
                            <tr>
                                {(msg.columns || Object.keys(msg.table[0] || {})).map((col) => (
                                    <th key={col} className="whitespace-nowrap px-2 py-1 font-medium">{col}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {msg.table.map((row, idx) => (
                                <tr key={idx} className="border-t border-neutral-100">
                                    {(msg.columns || Object.keys(row)).map((col) => (
                                        <td key={col} className="whitespace-nowrap px-2 py-1">{String(row[col] ?? '')}</td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
            {msg.kind === 'confirm_mutation' && msg.plan ? (
                <p className={cx(ui.muted, 'mt-2')}>
                    {t('admin.ai.mutation_plan', 'Action')}: {String(msg.plan.action || '')} {String(msg.plan.entity || '')}
                </p>
            ) : null}
        </div>
    );
}

export default function AiLogChatShow({ chat }) {
    const messages = chat?.messages || [];

    return (
        <Layout>
            <PageHeader
                title={chat?.title || t('admin.ai.admin_chat', 'Admin chat')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/ai', label: t('admin.nav.ai', 'AI Assistant') },
                    { href: '/admin/ai/logs', label: t('admin.nav.ai_admin_chats', 'Admin chats') },
                    { label: chat?.title || `#${chat?.id}` },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" native href="/admin/ai/logs">
                            {t('admin.common.back', 'Back')}
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.ai.delete_chat', 'Delete this chat?'))) {
                                    router.delete(`/admin/ai/logs/${chat.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    </div>
                )}
            />
            <div className="mx-auto max-w-2xl space-y-4">
                <div className={ui.sectionCard}>
                    <p className="text-sm font-medium text-neutral-900">{chat.admin_name}</p>
                    <p className={ui.muted}>{chat.admin_email}</p>
                    <p className={cx(ui.muted, 'mt-1')}>
                        {t('admin.ai.started', 'Started')}: {chat.created_at}
                        {chat.updated_at ? ` · ${t('admin.ai.updated', 'Updated')}: ${chat.updated_at}` : ''}
                    </p>
                </div>
                <div className={cx(ui.aiChatPanel, '!h-auto !w-full max-w-none shadow-none')}>
                    <div className={ui.aiChatHeader}>
                        <span>{t('admin.ai.assistant_title', 'Shop AI')}</span>
                    </div>
                    <div className={cx(ui.aiChatBody, '!max-h-none')}>
                        {messages.length === 0 ? (
                            <p className={ui.muted}>{t('admin.ai.no_messages', 'No messages in this session.')}</p>
                        ) : (
                            messages.map((msg, idx) => (
                                <div
                                    key={idx}
                                    className={msg.role === 'user' ? ui.aiChatBubbleUser : ui.aiChatBubbleAi}
                                >
                                    <MessageBody msg={msg} />
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </Layout>
    );
}
