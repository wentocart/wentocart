import { Link } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge,
    PageHeader,
    StatCards,
    t,
    ui,
} from '../../ui';

const tone = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    blocked: 'danger',
    processing: 'info',
    shipped: 'info',
    completed: 'success',
    cancelled: 'danger',
};

export default function Dashboard({ stats = [], recent_sellers = [], recent_orders = [], links = [] }) {
    return (
        <Layout>
            <PageHeader
                title={t('admin.multivendor.dashboard', 'Marketplace dashboard')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/dashboard', label: t('admin.nav.multivendor', 'Multivendor Management') },
                    { label: t('admin.multivendor.dashboard', 'Marketplace dashboard') },
                ]}
            />
            <StatCards items={stats.map((row) => ({ key: row.key, label: row.label, value: row.value }))} />
            <div className="mb-6 flex flex-wrap gap-2">
                {links.map((link) => (
                    <Link key={link.href} href={link.href} className={`${ui.btn} ${ui.btnSecondary} text-sm`}>
                        {t(`admin.multivendor.link.${link.label.toLowerCase().replaceAll(' ', '_')}`, link.label)}
                    </Link>
                ))}
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <section className={ui.sectionCard}>
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">{t('admin.multivendor.recent_sellers', 'Recent sellers')}</h2>
                        <Link href="/admin/multivendor/sellers" className="text-sm text-sky-700">{t('admin.common.view_all', 'View all')}</Link>
                    </div>
                    <ul className="mt-4 divide-y text-sm">
                        {recent_sellers.map((row) => (
                            <li key={row.id} className="flex items-center justify-between gap-3 py-3">
                                <div>
                                    <Link href={row.href} className="font-medium text-sky-800 hover:underline">{row.shop_name}</Link>
                                    <p className="text-neutral-500">{row.email}</p>
                                </div>
                                <Badge tone={tone[row.status] || 'neutral'}>{row.status}</Badge>
                            </li>
                        ))}
                        {recent_sellers.length === 0 ? (
                            <li className="py-6 text-neutral-500">{t('admin.multivendor.no_sellers', 'No sellers yet.')}</li>
                        ) : null}
                    </ul>
                </section>
                <section className={ui.sectionCard}>
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">{t('admin.multivendor.recent_orders', 'Recent seller orders')}</h2>
                        <Link href="/admin/multivendor/orders" className="text-sm text-sky-700">{t('admin.common.view_all', 'View all')}</Link>
                    </div>
                    <ul className="mt-4 divide-y text-sm">
                        {recent_orders.map((row) => (
                            <li key={row.id} className="flex items-center justify-between gap-3 py-3">
                                <div>
                                    <Link href={row.href} className="font-medium text-sky-800 hover:underline">{row.number}</Link>
                                    <p className="text-neutral-500">{row.shop_name || '—'}</p>
                                </div>
                                <div className="text-right">
                                    <Badge tone={tone[row.status] || 'neutral'}>{row.status}</Badge>
                                    <p className="mt-1 font-medium">{Number(row.grand_total || 0).toFixed(2)}</p>
                                </div>
                            </li>
                        ))}
                        {recent_orders.length === 0 ? (
                            <li className="py-6 text-neutral-500">{t('admin.multivendor.no_orders', 'No seller orders yet.')}</li>
                        ) : null}
                    </ul>
                </section>
            </div>
        </Layout>
    );
}
