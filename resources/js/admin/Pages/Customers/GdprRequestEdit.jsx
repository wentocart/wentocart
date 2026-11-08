import { Link, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    PageHeader,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    t,
    ui,
} from '../../ui';

const tone = {
    pending: 'warning',
    completed: 'success',
};

export default function GdprRequestEdit({ request }) {
    const form = useForm({ status: request.status || 'pending' });
    const changes = Array.isArray(request.changes) ? request.changes : [];
    const isDelete = request.type === 'delete';

    return (
        <Layout>
            <PageHeader
                title={t('admin.gdpr.edit', 'Edit GDPR Data Request')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/gdpr-requests', label: t('admin.gdpr.requests', 'GDPR Data Requests') },
                    { label: request.reference || `#${request.id}` },
                ]}
                crumbsFirst
            />
            <section className={`${ui.sectionCard} max-w-3xl space-y-5`}>
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className={ui.sectionKicker}>{request.type_label}{request.reference ? ` · ${request.reference}` : ''}</p>
                        <p className="mt-1 text-sm text-neutral-500">
                            {request.customer_id ? (
                                <Link href={`/admin/customers/${request.customer_id}`} className="text-violet-700 hover:underline">
                                    {request.customer_name}
                                </Link>
                            ) : request.customer_name}
                            {request.customer_email ? ` · ${request.customer_email}` : ''}
                        </p>
                    </div>
                    <Badge tone={tone[request.status] || 'neutral'}>{request.status ? request.status.charAt(0).toUpperCase() + request.status.slice(1) : ''}</Badge>
                </header>

                {isDelete ? (
                    <p className="text-sm text-neutral-700">
                        {t('admin.gdpr.delete_explain', 'The customer asked for the account to be erased. Completed erases the account and keeps this request. Declined leaves the account as it is.')}
                    </p>
                ) : changes.length ? (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t('admin.gdpr.col_field', 'Field')}</Th>
                                <Th>{t('admin.gdpr.col_current', 'Current')}</Th>
                                <Th>{t('admin.gdpr.col_requested', 'Requested')}</Th>
                            </Tr>
                        </THead>
                        <tbody>
                            {changes.map((row) => (
                                <Tr key={row.key}>
                                    <Td>{row.label}</Td>
                                    <Td>{row.from || '—'}</Td>
                                    <Td>{row.to || '—'}</Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                ) : (
                    <p className="text-sm text-neutral-700">
                        {t('admin.gdpr.no_fields', 'This older request has no field list. Completing it only emails the customer.')}
                    </p>
                )}

                {request.message ? (
                    <p className="whitespace-pre-wrap rounded-xl bg-neutral-50 px-4 py-3 text-sm text-neutral-800">{request.message}</p>
                ) : null}

                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/admin/gdpr-requests/${request.id}`);
                    }}
                >
                    <FormErrors errors={form.errors} />
                    <Field label={t('admin.gdpr.col_status', 'Status')} hint={isDelete
                        ? t('admin.gdpr.status_delete_hint', 'Completed erases the account. Declined does not.')
                        : t('admin.gdpr.status_edit_hint', 'Completed writes the requested values onto the customer. Declined does not.')}
                    >
                        <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                            <option value="pending">{t('admin.gdpr.pending', 'Pending')}</option>
                            <option value="completed">{t('admin.gdpr.completed', 'Completed')}</option>
                            {!['pending', 'completed'].includes(form.data.status) ? (
                                <option value={form.data.status}>{form.data.status}</option>
                            ) : null}
                        </Select>
                    </Field>
                    <Button type="submit" disabled={form.processing}>{t('admin.common.save', 'Save')}</Button>
                </form>
            </section>
        </Layout>
    );
}
