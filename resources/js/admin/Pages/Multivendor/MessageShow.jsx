import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { Button, Field, PageHeader, Textarea, t, ui } from '../../ui';

export default function MessageShow({ message }) {
    const form = useForm({ reply_body: message.reply_body || '' });

    return (
        <Layout>
            <PageHeader
                title={t('admin.multivendor.message', 'Seller message')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/messages', label: t('admin.nav.seller_messages', 'Seller Messages') },
                    { label: message.name },
                ]}
            />
            <section className={`${ui.sectionCard} max-w-3xl space-y-5`}>
                <header>
                    <p className={ui.sectionKicker}>{t('admin.multivendor.message', 'Seller message')}</p>
                    <p className="mt-2 text-sm text-neutral-500">{message.shop_name} · {message.email} · {message.created_at}</p>
                </header>
                <p className="whitespace-pre-wrap rounded-xl bg-neutral-50 px-4 py-3 text-sm text-neutral-800">{message.body}</p>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/admin/multivendor/messages/${message.id}/reply`);
                    }}
                >
                    <Field label={t('admin.multivendor.reply', 'Reply')}>
                        <Textarea rows={5} value={form.data.reply_body} onChange={(e) => form.setData('reply_body', e.target.value)} />
                    </Field>
                    <Button type="submit">{t('admin.common.save', 'Save')}</Button>
                </form>
            </section>
        </Layout>
    );
}
