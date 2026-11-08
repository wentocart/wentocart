import { router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    Field,
    PageHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    t,
    ui,
} from '../../ui';

const tone = {
    requested: 'warning',
    approved: 'info',
    received: 'info',
    refunded: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

export default function ReturnShow({ returnRow, order_number }) {
    const [note, setNote] = useState(returnRow.admin_note || returnRow.seller_note || '');
    const id = returnRow.id;
    const post = (action) => {
        router.post(`/admin/multivendor/returns/${id}/${action}`, { note }, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                title={`${t('admin.multivendor.return', 'Return')} ${returnRow.number}`}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/returns', label: t('admin.nav.seller_returns', 'Seller Returns') },
                    { label: returnRow.number },
                ]}
            />

            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <div className="space-y-6">
                    <section className={ui.sectionCard}>
                        <div className="flex flex-wrap items-center gap-3">
                            <Badge tone={tone[returnRow.status] || 'neutral'}>{returnRow.status}</Badge>
                            <span className="text-sm text-neutral-500">
                                {t('admin.orders.order', 'Order')}: {order_number || '—'}
                            </span>
                            <span className="text-sm text-neutral-500">{returnRow.shop_name}</span>
                        </div>
                        <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                            <div><dt className="text-neutral-500">{t('admin.orders.refund', 'Refund')}</dt><dd className="font-semibold">{Number(returnRow.refund_total || 0).toFixed(2)}</dd></div>
                            <div><dt className="text-neutral-500">{t('admin.multivendor.seller_net', 'Seller net reversed')}</dt><dd>{Number(returnRow.seller_net_reversed || 0).toFixed(2)}</dd></div>
                            <div><dt className="text-neutral-500">{t('admin.multivendor.commission_reversed', 'Commission reversed')}</dt><dd>{Number(returnRow.commission_reversed || 0).toFixed(2)}</dd></div>
                            <div><dt className="text-neutral-500">{t('admin.multivendor.reason', 'Reason')}</dt><dd>{returnRow.reason || '—'}</dd></div>
                        </dl>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className="text-lg font-semibold">{t('admin.common.items', 'Items')}</h2>
                        <Table className="mt-4">
                            <THead>
                                <tr>
                                    <Th>{t('admin.common.item', 'Item')}</Th>
                                    <Th>{t('admin.common.qty', 'Qty')}</Th>
                                    <Th>{t('admin.orders.total', 'Total')}</Th>
                                    <Th>{t('admin.multivendor.net', 'Net')}</Th>
                                </tr>
                            </THead>
                            <tbody>
                                {(returnRow.items || []).map((item) => (
                                    <Tr key={item.id}>
                                        <Td>#{item.seller_order_item_id}</Td>
                                        <Td>{item.qty}</Td>
                                        <Td>{Number(item.line_total || 0).toFixed(2)}</Td>
                                        <Td>{Number(item.net_amount || 0).toFixed(2)}</Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </section>
                </div>

                <aside className={`${ui.sectionCard} space-y-3 h-fit`}>
                    <h2 className="font-semibold">{t('admin.common.actions', 'Actions')}</h2>
                    <Field label={t('admin.common.note', 'Note')}>
                        <Textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} />
                    </Field>
                    {returnRow.status === 'requested' ? (
                        <>
                            <Button onClick={() => post('approve')}>{t('admin.common.approve', 'Approve')}</Button>
                            <Button variant="danger" onClick={() => post('reject')}>{t('admin.common.reject', 'Reject')}</Button>
                            <Button variant="secondary" onClick={() => post('refund')}>{t('admin.orders.approve_and_refund', 'Approve & refund')}</Button>
                        </>
                    ) : null}
                    {returnRow.status === 'approved' ? (
                        <>
                            <Button onClick={() => post('receive')}>{t('admin.multivendor.mark_received', 'Mark received')}</Button>
                            <Button onClick={() => post('refund')}>{t('admin.orders.refund', 'Refund')}</Button>
                        </>
                    ) : null}
                    {returnRow.status === 'received' ? (
                        <Button onClick={() => post('refund')}>{t('admin.orders.refund', 'Refund')}</Button>
                    ) : null}
                </aside>
            </div>
        </Layout>
    );
}
