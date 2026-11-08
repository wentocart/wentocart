import { router, useForm } from '@inertiajs/react';
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

const tone = { pending: 'warning', approved: 'success', rejected: 'danger', blocked: 'danger' };

export default function RequestShow({ seller }) {
    const [note, setNote] = useState(seller.review_note || '');
    const commission = useForm({
        commission_rate: seller.commission_rate ?? 10,
        notes: '',
    });

    const review = (action) => {
        router.post(`/admin/multivendor/seller-requests/${seller.id}/review`, {
            action,
            note,
            commission_rate: commission.data.commission_rate,
        }, { preserveScroll: true });
    };

    const pending = seller.status === 'pending';
    const approved = seller.status === 'approved';
    const blocked = seller.status === 'blocked';
    const rejected = seller.status === 'rejected';
    const links = seller.links || [];
    const wallet = seller.wallet || {};

    return (
        <Layout>
            <PageHeader
                title={seller.shop_name}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/dashboard', label: t('admin.nav.multivendor', 'Multivendor Management') },
                    { href: '/admin/multivendor/sellers', label: t('admin.multivendor.sellers', 'Sellers') },
                    { label: seller.shop_name },
                ]}
            />
            <div className="mb-4 flex flex-wrap gap-2">
                {links.map((link) => (
                    <Button key={link.href} href={link.href} variant="secondary">
                        {t(`admin.multivendor.seller_link.${link.label.toLowerCase().replaceAll(' ', '_')}`, link.label)}
                    </Button>
                ))}
            </div>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="space-y-6">
                    <section className={ui.sectionCard}>
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">{t('admin.multivendor.seller_detail', 'Seller detail')}</h2>
                            <Badge tone={tone[seller.status] || 'neutral'}>{seller.status}</Badge>
                        </div>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <Item label={t('admin.sellers.shop_name', 'Shop name')} value={seller.shop_name} />
                            <Item label={t('admin.sellers.shop_slug', 'Unique shop name')} value={seller.shop_slug} />
                            <Item label={t('admin.sellers.profile_name', 'Profile name')} value={seller.profile_name} />
                            <Item label={t('admin.sellers.seller_name', 'Seller name')} value={`${seller.first_name} ${seller.last_name}`} />
                            <Item label={t('admin.sellers.email', 'Email')} value={seller.email} />
                            <Item label={t('admin.sellers.phone', 'Phone')} value={seller.phone} />
                            <Item label={t('admin.sellers.contact', 'Shop contact')} value={`${seller.contact_name} · ${seller.contact_phone}`} />
                            <Item label={t('admin.sellers.tax_id', 'Tax ID')} value={seller.tax_id || '—'} />
                            <Item
                                label={t('admin.sellers.address', 'Shop address')}
                                value={[seller.address_line1, seller.address_line2, seller.city, seller.state, seller.postal_code, seller.country_code].filter(Boolean).join(', ')}
                            />
                            <Item label={t('admin.sellers.customer', 'Customer')} value={seller.customer?.email || '—'} />
                        </dl>
                        {seller.description ? <p className="mt-4 text-sm text-neutral-600">{seller.description}</p> : null}
                    </section>
                    <section className={ui.sectionCard}>
                        <h2 className="text-lg font-semibold">{t('admin.multivendor.wallet', 'Wallet')}</h2>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <Item label={t('admin.multivendor.pending', 'Pending')} value={Number(wallet.pending || 0).toFixed(2)} />
                            <Item label={t('admin.multivendor.available', 'Available')} value={Number(wallet.available || 0).toFixed(2)} />
                            <Item label={t('admin.multivendor.paid', 'Paid')} value={Number(wallet.paid || 0).toFixed(2)} />
                            <Item label={t('admin.multivendor.ledger_balance', 'Ledger balance')} value={Number(wallet.ledger_balance || 0).toFixed(2)} />
                        </dl>
                        <p className="mt-3 text-xs text-neutral-500">
                            {t('admin.sellers.counts', 'Products')}: {seller.product_count ?? 0}
                            {' · '}
                            {t('admin.multivendor.pending_products', 'Pending products')}: {seller.pending_product_count ?? 0}
                            {' · '}
                            {t('admin.sellers.orders', 'Orders')}: {seller.order_count ?? 0}
                            {' · '}
                            {t('admin.multivendor.returns', 'Returns')}: {seller.return_count ?? 0}
                            {' · '}
                            {t('admin.multivendor.reviews', 'Reviews')}: {seller.review_count ?? 0}
                        </p>
                    </section>
                    <section className={ui.sectionCard}>
                        <h2 className="text-lg font-semibold">{t('admin.sellers.commission', 'Commission')}</h2>
                        <form
                            className="mt-4 grid max-w-sm gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                commission.put(`/admin/sellers/${seller.id}/commission`);
                            }}
                        >
                            <Field label={t('admin.sellers.commission_rate', 'Commission rate (%)')}>
                                <Input wide type="number" step="0.01" min="0" max="100" value={commission.data.commission_rate} onChange={(e) => commission.setData('commission_rate', e.target.value)} />
                            </Field>
                            <Field label={t('admin.sellers.notes', 'Notes')}>
                                <Input wide value={commission.data.notes} onChange={(e) => commission.setData('notes', e.target.value)} />
                            </Field>
                            <Button type="submit">{t('admin.sellers.save_commission', 'Save commission')}</Button>
                        </form>
                    </section>
                </div>
                <aside className={`${ui.sectionCard} h-fit space-y-4`}>
                    <h2 className="font-semibold">{t('admin.sellers.review_title', 'Review request')}</h2>
                    <Field label={t('admin.sellers.review_note', 'Note to seller')}>
                        <Textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} />
                    </Field>
                    <div className="grid gap-2">
                        {pending ? (
                            <>
                                <Button onClick={() => review('approve')}>{t('admin.sellers.accept', 'Accept')}</Button>
                                <Button variant="secondary" onClick={() => review('reject')}>{t('admin.sellers.reject', 'Reject')}</Button>
                            </>
                        ) : null}
                        {approved ? (
                            <Button variant="danger" onClick={() => review('suspend')}>{t('admin.sellers.suspend', 'Suspend')}</Button>
                        ) : null}
                        {blocked ? (
                            <Button onClick={() => review('reactivate')}>{t('admin.sellers.reactivate', 'Reactivate')}</Button>
                        ) : null}
                        {rejected ? (
                            <p className="text-xs text-neutral-500">
                                {t('admin.sellers.rejected_hint', 'Rejected sellers must reapply from the storefront. Admin cannot approve a rejected application directly.')}
                            </p>
                        ) : null}
                    </div>
                    <p className="text-xs text-neutral-500">
                        {t('admin.sellers.review_help', 'Accept activates a pending seller. Suspend stops selling without deleting history. Reactivate restores a suspended seller. Reject lets the customer apply again.')}
                    </p>
                </aside>
            </div>
        </Layout>
    );
}

function Item({ label, value }) {
    return (
        <div>
            <dt className="text-neutral-500">{label}</dt>
            <dd className="mt-0.5 font-medium">{value || '—'}</dd>
        </div>
    );
}
