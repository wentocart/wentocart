import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    PageHeader,
    Textarea,
    t,
    ui,
} from '../../ui';

function statusLabel(status) {
    if (status === 'replied') {
        return t('admin.support.replied', 'Replied');
    }
    if (status === 'closed') {
        return t('admin.support.closed', 'Closed');
    }
    return t('admin.support.open', 'Open');
}

const tone = { open: 'warning', replied: 'success', closed: 'neutral' };

export default function SupportShow({ ticket, settings = {} }) {
    const form = useForm({ body: '' });

    return (
        <Layout>
            <PageHeader
                title={ticket.name}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/support', label: t('admin.support.title', 'Support') },
                    { label: ticket.name },
                ]}
                actions={(
                    <div className="flex gap-2">
                        {ticket.status === 'closed' ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.post(`/admin/support/${ticket.id}`, { action: 'reopen' })}
                            >
                                {t('admin.support.reopen', 'Reopen')}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.post(`/admin/support/${ticket.id}`, { action: 'close' })}
                            >
                                {t('admin.support.close', 'Close')}
                            </Button>
                        )}
                    </div>
                )}
            />
            <section className={`${ui.sectionCard} max-w-3xl space-y-5`}>
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className={ui.sectionKicker}>{t('admin.support.message', 'Message')}</p>
                        <p className="mt-1 text-sm text-neutral-500">
                            {ticket.email}
                            {ticket.order_number ? ` · ${ticket.order_number}` : ''}
                            {ticket.created_at ? ` · ${ticket.created_at}` : ''}
                        </p>
                    </div>
                    <Badge tone={tone[ticket.status] || 'neutral'}>{statusLabel(ticket.status)}</Badge>
                </header>
                <p className="whitespace-pre-wrap rounded-xl bg-neutral-50 px-4 py-3 text-sm text-neutral-800">{ticket.body}</p>
                {(ticket.replies || []).map((reply) => (
                    <div key={reply.id} className="rounded-xl border border-neutral-200 px-4 py-3">
                        <p className="text-xs font-medium text-neutral-500">
                            {reply.staff_name || t('admin.support.staff', 'Staff')}
                            {reply.created_at ? ` · ${reply.created_at}` : ''}
                        </p>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-neutral-800">{reply.body}</p>
                    </div>
                ))}
                {ticket.status !== 'closed' ? (
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/support/${ticket.id}/reply`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('body'),
                            });
                        }}
                    >
                        <FormErrors errors={form.errors} />
                        <Field label={t('admin.support.reply', 'Reply')}>
                            <Textarea rows={6} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                        </Field>
                        <p className={ui.muted}>
                            {settings.reply_email !== false
                                ? t('admin.support.reply_will_email', 'This reply is emailed to the customer.')
                                : t('admin.support.reply_saved_only', 'Customer email on reply is off. This stays in the inbox only.')}
                        </p>
                        <Button type="submit" icon="check" busy={form.processing}>{t('admin.support.send_reply', 'Send reply')}</Button>
                    </form>
                ) : null}
            </section>
        </Layout>
    );
}
