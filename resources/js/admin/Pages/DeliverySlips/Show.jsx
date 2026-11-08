import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Button,
    Icon,
    PageHeader,
    money,
    ui,
    cx,
    t,
} from '../../ui';
import { catalogImageUrl } from '../../lib/catalogMedia';

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatDateTime(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function Party({ title, party }) {
    const lines = party?.lines || [];

    return (
        <div>
            <div className={ui.invoiceBlockTitle}>{title}</div>
            <div className={ui.invoiceBlockText}>
                <div className={ui.cellStrong}>{party?.name || '—'}</div>
                {lines.map((line) => <div key={line}>{line}</div>)}
                {party?.email ? <div>{party.email}</div> : null}
                {party?.phone ? <div>{party.phone}</div> : null}
            </div>
        </div>
    );
}

export default function Show({ deliverySlip, source = 'delivery-slips', urls = {}, can_view_order = false }) {
    const [downloadOpen, setDownloadOpen] = useState(false);
    const emailForm = useForm({});
    const shop = deliverySlip.shop || {};
    const code = deliverySlip.currency_code;
    const statusLabel = deliverySlip.status || 'Delivered';
    const crumbs = source === 'orders'
        ? [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
            { href: urls.order || `/admin/orders/${deliverySlip.id}`, label: `#${deliverySlip.order_number}` },
            { label: t('admin.nav.delivery_slip', 'Delivery Slip') },
        ]
        : [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/delivery-slips', label: t('admin.nav.delivery_slip', 'Delivery Slip') },
            { label: `#${deliverySlip.number}` },
        ];

    const sendEmail = () => {
        emailForm.post(urls.email, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                className="print:hidden"
                title={`${t('admin.nav.delivery_slip', 'Delivery Slip')} #${deliverySlip.number}`}
                crumbs={crumbs}
                actions={(
                    <>
                        <div className="relative">
                            <Button variant="secondary" type="button" icon="download" onClick={() => setDownloadOpen((open) => !open)}>
                                {t('admin.invoices.download', 'Download')}
                                <Icon name="chevron-down" />
                            </Button>
                            {downloadOpen ? (
                                <div className={cx(ui.popMenu, 'w-48')}>
                                    <a className={ui.popItem} href={urls.download} onClick={() => setDownloadOpen(false)}>
                                        {t('admin.invoices.download_html', 'Download HTML')}
                                    </a>
                                    {urls.pdf ? (
                                        <a className={ui.popItem} href={urls.pdf} onClick={() => setDownloadOpen(false)}>
                                            {t('admin.invoices.download_pdf', 'Download PDF')}
                                        </a>
                                    ) : null}
                                    <button
                                        type="button"
                                        className={ui.popItem}
                                        onClick={() => {
                                            setDownloadOpen(false);
                                            window.print();
                                        }}
                                    >
                                        {t('admin.invoices.print_pdf', 'Print / Save as PDF')}
                                    </button>
                                </div>
                            ) : null}
                        </div>
                        <Button variant="secondary" type="button" icon="printer" onClick={() => window.print()}>
                            {t('admin.invoices.print', 'Print')}
                        </Button>
                        <Button type="button" icon="mail" onClick={sendEmail} busy={emailForm.processing} disabled={!deliverySlip.email}>
                            {t('admin.invoices.send_email', 'Send Email')}
                        </Button>
                    </>
                )}
            />

            <div className={ui.invoiceWrap}>
                <article className={ui.invoiceDoc}>
                    <div className={ui.invoiceTop}>
                        <div className={ui.invoiceBrand}>
                            <span className={ui.invoiceMark}><Icon name="bag" /></span>
                            <div>
                                <div className={ui.invoiceShop}>{shop.name || t('admin.invoices.store', 'Store')}</div>
                                <div className={ui.invoiceShopMeta}>
                                    {shop.address || ''}
                                    {shop.email ? `\nEmail: ${shop.email}` : ''}
                                </div>
                            </div>
                        </div>
                        <div>
                            <div className={ui.invoiceKicker}>{t('admin.nav.delivery_slip', 'DELIVERY SLIP')} #{deliverySlip.number}</div>
                            <div className={ui.invoiceMeta}>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.nav.delivery_slip', 'Delivery Date')}:</span> {formatDate(deliverySlip.delivered_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.order', 'Order Number')}:</span> #{deliverySlip.order_number}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.invoices.order_date', 'Order Date')}:</span> {formatDate(deliverySlip.created_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.shipping_method', 'Shipping Method')}:</span> {deliverySlip.shipping_method || '—'}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.tracking_number', 'Tracking Number')}:</span> {deliverySlip.tracking_number || '—'}</div>
                                <div>
                                    <span className={ui.invoiceMetaLabel}>{t('admin.orders.status', 'Status')}:</span>{' '}
                                    <span className={ui.invoicePaid}>{statusLabel}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className={ui.invoiceSplit}>
                        <Party title="Deliver To" party={deliverySlip.deliver_to} />
                        <Party title="Ship From" party={deliverySlip.ship_from} />
                    </div>

                    <div className="mb-6 grid gap-4 rounded-xl border border-violet-100 bg-violet-50/60 p-4 md:grid-cols-3">
                        <div className="space-y-1">
                            <div className={ui.invoiceBlockTitle}>Carrier</div>
                            <div className={ui.cellStrong}>{deliverySlip.carrier || '—'}</div>
                        </div>
                        <div className="space-y-1">
                            <div className={ui.invoiceBlockTitle}>Tracking Number</div>
                            <div className={ui.cellStrong}>{deliverySlip.tracking_number || '—'}</div>
                        </div>
                        <div className="space-y-1">
                            <div className={ui.invoiceBlockTitle}>Shipped On</div>
                            <div className={ui.cellStrong}>{formatDateTime(deliverySlip.delivered_at)}</div>
                        </div>
                    </div>

                    <table className={ui.invoiceTable}>
                        <thead>
                            <tr>
                                <th className={ui.invoiceTh}>{t('admin.orders.item', 'Item')}</th>
                                <th className={ui.invoiceTh}>{t('admin.orders.sku', 'SKU')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.qty', 'Qty')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.unit', 'Unit')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.total', 'Total')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(deliverySlip.items || []).map((item) => (
                                <tr key={item.id}>
                                    <td className={ui.invoiceTd}>
                                        <div className="flex items-center gap-3">
                                            {item.cover_path ? <img className={ui.thumb} src={catalogImageUrl(item.cover_path, 'sm')} alt="" /> : <span className={ui.cellIcon}><Icon name="package" /></span>}
                                            <div>
                                                <div className={ui.cellStrong}>{item.name}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td className={ui.invoiceTd}>{item.sku || '—'}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{item.qty}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.unit_price, code)}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.total, code)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="mt-4 text-right text-sm font-semibold text-neutral-700">
                        Total Items : {(deliverySlip.items || []).length}
                    </div>

                    <div className="mt-6 grid gap-4 lg:grid-cols-2">
                        <div className={ui.sectionCard}>
                            <div className={ui.sectionTitle}>Package Note</div>
                            <p className="mt-4 text-sm text-neutral-600 whitespace-pre-line">{deliverySlip.note || '—'}</p>
                        </div>
                        <div className={ui.sectionCard}>
                            <div className={ui.sectionTitle}>Received By</div>
                            <div className="mt-4 space-y-4 text-sm text-neutral-700">
                                <div className="flex items-center gap-4"><span className="w-16">Name</span><span className="flex-1 border-b border-neutral-400" /></div>
                                <div className="flex items-center gap-4"><span className="w-16">Signature</span><span className="flex-1 border-b border-neutral-400" /></div>
                                <div className="flex items-center gap-4"><span className="w-16">Date</span><span className="flex-1 border-b border-neutral-400" /></div>
                            </div>
                        </div>
                    </div>
                </article>

                {can_view_order && source === 'delivery-slips' ? (
                    <div className="mt-4 print:hidden">
                        <Button variant="ghost" href={urls.order}>
                            {t('admin.orders.view_order', 'View Order')}
                        </Button>
                    </div>
                ) : null}
            </div>
        </Layout>
    );
}
