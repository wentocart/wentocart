import { router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    Field,
    Input,
    PageHeader,
    Textarea,
    t,
    ui,
} from '../../ui';

const tone = {
    requested: 'warning',
    under_review: 'info',
    processing: 'info',
    paid: 'success',
    rejected: 'danger',
    failed: 'danger',
};

export default function PayoutShow({ payout }) {
    const [note, setNote] = useState(payout.note || '');
    const [reference, setReference] = useState(payout.payout_reference || '');

    const process = (action) => {
        router.post(`/admin/multivendor/payouts/${payout.id}/process`, {
            action,
            note,
            payout_reference: reference,
            payout_rail: 'offline',
        }, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.multivendor.payout', 'Seller payout')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/payouts', label: t('admin.nav.seller_payouts', 'Seller Payouts') },
                    { label: payout.shop_name },
                ]}
            />
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <section className={`${ui.sectionCard} space-y-3`}>
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">{payout.shop_name}</h2>
                        <Badge tone={tone[payout.status] || 'neutral'}>{String(payout.status || '').replaceAll('_', ' ')}</Badge>
                    </div>
                    <p className="text-2xl font-semibold">{Number(payout.amount).toFixed(2)}</p>
                    <p className="text-sm text-neutral-600">
                        {t('admin.multivendor.available_earnings', 'Available earnings')}: {Number(payout.available || 0).toFixed(2)}
                    </p>
                    <p className="text-sm text-neutral-600">{payout.payment_name || t('admin.multivendor.no_payout_method', 'No payout method selected.')}</p>
                    {(payout.payout_bank_account || payout.payout_upi_id || payout.payout_paypal_email) ? (
                        <p className="text-sm text-neutral-600">
                            {payout.payout_bank_account ? `Bank ${payout.payout_bank_account}${payout.payout_bank_ifsc ? ` / ${payout.payout_bank_ifsc}` : ''}` : ''}
                            {payout.payout_upi_id ? ` · UPI ${payout.payout_upi_id}` : ''}
                            {payout.payout_paypal_email ? ` · PayPal ${payout.payout_paypal_email}` : ''}
                        </p>
                    ) : null}
                    <p className="text-xs text-neutral-500">{payout.created_at}</p>
                    {payout.processed_at ? (
                        <p className="text-xs text-neutral-500">{t('admin.multivendor.processed_at', 'Processed')}: {payout.processed_at}</p>
                    ) : null}
                    <p className="text-xs text-neutral-500">
                        {t('admin.multivendor.payout_flow_hint', 'Flow: requested → under review → processing → paid. Reject releases the hold; failed restores available balance.')}
                    </p>
                </section>
                <aside className={`${ui.sectionCard} h-fit space-y-3`}>
                    <Field label={t('admin.sellers.notes', 'Notes')}>
                        <Textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} />
                    </Field>
                    {['requested', 'under_review', 'processing'].includes(payout.status) ? (
                        <Field label={t('admin.multivendor.payout_reference', 'Payout reference')}>
                            <Input wide value={reference} onChange={(e) => setReference(e.target.value)} placeholder={t('admin.multivendor.payout_reference_hint', 'Bank transfer / UPI / cheque id')} />
                        </Field>
                    ) : payout.payout_reference ? (
                        <p className="text-sm text-neutral-600">{t('admin.multivendor.payout_reference', 'Payout reference')}: {payout.payout_reference}</p>
                    ) : null}
                    {payout.status === 'requested' ? (
                        <>
                            <Button onClick={() => process('review')}>{t('admin.multivendor.start_review', 'Start review')}</Button>
                            <Button variant="danger" onClick={() => process('reject')}>{t('admin.sellers.reject', 'Reject')}</Button>
                            <Button variant="secondary" onClick={() => process('pay')}>{t('admin.multivendor.mark_paid', 'Mark paid')}</Button>
                        </>
                    ) : null}
                    {payout.status === 'under_review' ? (
                        <>
                            <Button onClick={() => process('process')}>{t('admin.multivendor.start_processing', 'Start processing')}</Button>
                            <Button variant="danger" onClick={() => process('reject')}>{t('admin.sellers.reject', 'Reject')}</Button>
                            <Button variant="secondary" onClick={() => process('pay')}>{t('admin.multivendor.mark_paid', 'Mark paid')}</Button>
                        </>
                    ) : null}
                    {payout.status === 'processing' ? (
                        <>
                            <Button onClick={() => process('pay')}>{t('admin.multivendor.mark_paid', 'Mark paid')}</Button>
                            <Button variant="danger" onClick={() => process('fail')}>{t('admin.multivendor.mark_failed', 'Mark failed')}</Button>
                        </>
                    ) : null}
                    {payout.status === 'failed' ? (
                        <Button onClick={() => process('retry')}>{t('admin.multivendor.retry_payout', 'Retry (under review)')}</Button>
                    ) : null}
                    <Button variant="secondary" href="/admin/multivendor/payouts">{t('admin.common.back', 'Back')}</Button>
                </aside>
            </div>
        </Layout>
    );
}
