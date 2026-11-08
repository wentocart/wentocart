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

export default function Show({ creditSlip, source = 'credit-slips', urls = {}, can_view_order = false }) {
    const [downloadOpen, setDownloadOpen] = useState(false);
    const emailForm = useForm({});
    const shop = creditSlip.shop || {};
    const code = creditSlip.currency_code;
    const crumbs = source === 'orders'
        ? [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
            { href: urls.order || `/admin/orders/${creditSlip.id}`, label: `#${creditSlip.order_number}` },
            { label: t('admin.nav.credit_slip', 'Credit Slip') },
        ]
        : [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/credit-slips', label: t('admin.nav.credit_slip', 'Credit Slip') },
            { label: `#${creditSlip.number}` },
        ];

    const sendEmail = () => {
        emailForm.post(urls.email, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                className="print:hidden"
                title={`${t('admin.nav.credit_slip', 'Credit Slip')} #${creditSlip.number}`}
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
                        <Button type="button" icon="mail" onClick={sendEmail} busy={emailForm.processing} disabled={!creditSlip.email}>
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
                            <div className={ui.invoiceKicker}>{t('admin.nav.credit_slip', 'CREDIT SLIP')} #{creditSlip.number}</div>
                            <div className={ui.invoiceMeta}>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.nav.credit_slip', 'Credit Slip Date')}:</span> {formatDate(creditSlip.refunded_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.order', 'Order Number')}:</span> #{creditSlip.order_number}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.invoices.order_date', 'Order Date')}:</span> {formatDate(creditSlip.created_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.reason', 'Reason')}:</span> {creditSlip.refund_reason || '—'}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.payment_method', 'Refund Method')}:</span> {creditSlip.refund_method || '—'}</div>
                                <div>
                                    <span className={ui.invoiceMetaLabel}>{t('admin.orders.status', 'Status')}:</span>{' '}
                                    <span className={ui.invoicePaid}>Refunded</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className={ui.invoiceSplit}>
                        <Party title={t('admin.invoices.bill_to', 'Bill To')} party={creditSlip.billing} />
                        <Party title={t('admin.invoices.ship_to', 'Ship To')} party={creditSlip.shipping} />
                    </div>

                    <table className={ui.invoiceTable}>
                        <thead>
                            <tr>
                                <th className={ui.invoiceTh}>{t('admin.orders.item', 'Item')}</th>
                                <th className={ui.invoiceTh}>{t('admin.orders.sku', 'SKU')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.price', 'Price')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.qty', 'Qty')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.refund', 'Refund')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.total', 'Total')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(creditSlip.items || []).map((item) => (
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
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.unit_price, code)}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{item.qty}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.refund_total, code)}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.total, code)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="mt-6 grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                        <div className={ui.sectionCard}>
                            <div className={ui.sectionTitle}>Credit & Refund Summary</div>
                            <div className="mt-4 space-y-2">
                                <div className={ui.invoiceTotalRow}>
                                    <span>{t('admin.orders.subtotal', 'Subtotal')} ({(creditSlip.items || []).length} {(creditSlip.items || []).length === 1 ? 'item' : 'items'})</span>
                                    <span>{money(creditSlip.subtotal, code)}</span>
                                </div>
                                {Number(creditSlip.discount_total) > 0 ? (
                                    <div className={ui.invoiceTotalRow}>
                                        <span>{t('admin.orders.discount', 'Discount')}</span>
                                        <span>-{money(creditSlip.discount_total, code)}</span>
                                    </div>
                                ) : null}
                                <div className={ui.invoiceTotalRow}>
                                    <span>{t('admin.orders.shipping', 'Shipping')}</span>
                                    <span>{money(creditSlip.shipping_total, code)}</span>
                                </div>
                                <div className={ui.invoiceTotalRow}>
                                    <span>{creditSlip.tax_label || t('admin.orders.tax', 'Tax')}</span>
                                    <span>{money(creditSlip.tax_total, code)}</span>
                                </div>
                                <div className={ui.invoiceGrand}>
                                    <span>Total Refunded</span>
                                    <span>{money(creditSlip.refunded_total, code)}</span>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-4">
                            <div className={ui.sectionCard}>
                                <div className={ui.sectionTitle}>Refund To</div>
                                <div className="mt-4 space-y-2 text-sm text-neutral-600">
                                    <div className="flex justify-between gap-4"><span>Method</span><span>{creditSlip.refund_method || '—'}</span></div>
                                    <div className="flex justify-between gap-4"><span>Transaction ID</span><span>{creditSlip.transaction_id || '—'}</span></div>
                                    <div className="flex justify-between gap-4"><span>Refunded On</span><span>{formatDateTime(creditSlip.refunded_at)}</span></div>
                                </div>
                            </div>
                            <div className={ui.sectionCard}>
                                <div className={ui.sectionTitle}>Note</div>
                                <p className="mt-4 text-sm text-neutral-600 whitespace-pre-line">{creditSlip.note || '—'}</p>
                            </div>
                        </div>
                    </div>
                </article>

                {can_view_order && source === 'credit-slips' ? (
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
