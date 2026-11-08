import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Button,
    Card,
    FeatureTile,
    FormErrors,
    Input,
    PageHeader,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    t,
    ui,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.document_settings', 'Invoices & Documents') },
];

function preview(format, start) {
    const safe = String(format || '').includes('{number}') ? format : 'INV-{number}';
    return String(safe).replace('{number}', String(start || 10001));
}

export default function DocumentRules(props) {
    const form = useForm({
        invoices_enabled: props.invoices_enabled !== false,
        invoice_format: props.invoice_format || 'INV-{number}',
        invoice_start: Number(props.invoice_start ?? 10001),
        invoice_create_statuses: Array.isArray(props.invoice_create_statuses) && props.invoice_create_statuses.length
            ? props.invoice_create_statuses
            : ['processing'],
        invoice_auto_send: !!props.invoice_auto_send,
        credit_format: props.credit_format || 'CN-{number}',
        packing_slips: props.packing_slips !== false,
        order_pdf: props.order_pdf !== false,
    });

    useSaveOnChange(form, '/admin/document-settings', {
        textKeys: ['invoice_format', 'invoice_start', 'credit_format'],
    });

    const statuses = Array.isArray(props.status_choices) ? props.status_choices : [];
    const selected = form.data.invoice_create_statuses;
    const allOn = statuses.length > 0 && statuses.every((status) => selected.includes(status.value));
    const fallbackStatus = () => (
        statuses.find((status) => status.value === 'processing')?.value
        || statuses[0]?.value
        || null
    );
    const toggleStatus = (code, on) => {
        const next = on
            ? [...new Set([...selected, code])]
            : selected.filter((item) => item !== code);
        if (next.length === 0) {
            return;
        }
        form.setData('invoice_create_statuses', next);
    };
    const toggleAllStatuses = (on) => {
        if (on) {
            form.setData('invoice_create_statuses', statuses.map((status) => status.value));
            return;
        }
        const fallback = fallbackStatus();
        form.setData('invoice_create_statuses', fallback ? [fallback] : selected);
    };

    const body = (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); }}>
            <FormErrors errors={form.errors} />
            <Card>
                <SettingsSectionHead
                    icon="orders"
                    title={t('admin.document_settings.invoices', 'Invoices')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="file"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.document_settings.enabled', 'Invoices')}
                        hint={t('admin.document_settings.enabled_hint', 'Create invoice numbers for orders.')}
                        value={!!form.data.invoices_enabled}
                        onChange={(on) => form.setData('invoices_enabled', on)}
                    />
                    <FeatureTile
                        icon="pin"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.document_settings.format', 'Invoice prefix / sequence')}
                        hint={form.errors.invoice_format || t('admin.document_settings.format_hint', 'Editable. A valid format is saved. Include {number} once. Next number: {preview}', { preview: preview(form.data.invoice_format, form.data.invoice_start) })}
                        control={(
                            <Input className="w-44 shrink-0 font-mono text-xs" value={form.data.invoice_format} onChange={(e) => form.setData('invoice_format', e.target.value)} aria-label={t('admin.document_settings.format_editable', 'Editable invoice number format')} />
                        )}
                    />
                    <FeatureTile
                        icon="orders"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.document_settings.start', 'Starting invoice number')}
                        hint={t('admin.document_settings.start_hint', 'First number used in the sequence.')}
                        control={(
                            <Input className="w-28 shrink-0" type="number" min="1" value={form.data.invoice_start} onChange={(e) => form.setData('invoice_start', Number(e.target.value))} />
                        )}
                    />
                    <FeatureTile
                        className="md:col-span-2"
                        icon="clock"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.document_settings.create_at', 'Invoice creation point')}
                        hint={t('admin.document_settings.create_at_hint', 'Choose one or more statuses. The invoice is created when the order reaches any of them, and it is kept after that.')}
                        error={form.errors.invoice_create_statuses}
                        extra={(
                            <div className="mt-3 flex flex-col gap-2.5" role="group" aria-label={t('admin.document_settings.create_at', 'Invoice creation point')}>
                                <label className={ui.checkboxRow}>
                                    <input
                                        type="checkbox"
                                        className={ui.checkboxInput}
                                        checked={allOn}
                                        onChange={(e) => toggleAllStatuses(e.target.checked)}
                                    />
                                    {t('admin.common.all', 'All')}
                                </label>
                                {statuses.map((status) => (
                                    <label key={status.value} className={ui.checkboxRow}>
                                        <input
                                            type="checkbox"
                                            className={ui.checkboxInput}
                                            checked={allOn || selected.includes(status.value)}
                                            onChange={(e) => toggleStatus(status.value, e.target.checked)}
                                        />
                                        {status.label}
                                    </label>
                                ))}
                            </div>
                        )}
                    />
                    <FeatureTile
                        icon="mail"
                        tone="bg-blue-100 text-blue-600"
                        title={t('admin.document_settings.auto_send', 'Automatic invoice send')}
                        hint={t('admin.document_settings.auto_send_hint', 'Emails the invoice to the customer when it is created. It is sent once, to the email on the order.')}
                        value={!!form.data.invoice_auto_send}
                        onChange={(on) => form.setData('invoice_auto_send', on)}
                    />
                </div>
            </Card>
            <Card>
                <SettingsSectionHead
                    icon="package"
                    title={t('admin.document_settings.other', 'Other documents')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 3 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="file"
                        tone="bg-rose-100 text-rose-600"
                        title={t('admin.document_settings.credit', 'Credit-note prefix')}
                        hint={form.errors.credit_format || t('admin.document_settings.credit_hint', 'Editable. Include {number} once. Example: CN-{number}.')}
                        control={(
                            <Input className="w-40 shrink-0 font-mono text-xs" value={form.data.credit_format} onChange={(e) => form.setData('credit_format', e.target.value)} />
                        )}
                    />
                    <FeatureTile
                        icon="package"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.document_settings.packing', 'Packing slips')}
                        hint={t('admin.document_settings.packing_hint', 'Allow packing slip PDFs on orders.')}
                        value={!!form.data.packing_slips}
                        onChange={(on) => form.setData('packing_slips', on)}
                    />
                    <FeatureTile
                        icon="file"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.document_settings.order_pdf', 'Order PDF')}
                        hint={t('admin.document_settings.order_pdf_hint', 'Allow an order summary PDF.')}
                        value={!!form.data.order_pdf}
                        onChange={(on) => form.setData('order_pdf', on)}
                    />
                </div>
                {!form.data.invoices_enabled ? (
                    <SettingsNote>
                        {t('admin.document_settings.turned_off', 'Invoices are turned off. Opening an invoice shows this message instead of a 404 until invoices are turned on here.')}
                    </SettingsNote>
                ) : null}
                <SettingsNote>
                    {t('admin.document_settings.designs_note', 'PDF layouts stay under Document designs.')}
                    {' '}
                    <Button type="button" variant="link" href="/admin/documents" className="px-0">
                        {t('admin.nav.document_designs', 'Document designs')}
                    </Button>
                </SettingsNote>
            </Card>
        </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.document_settings.title', 'Invoices & Documents')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.document_settings.subtitle', 'Numbering and when documents are created. PDF layouts stay under Document designs.')}
            />
            {body}
        </Layout>
    );
}
