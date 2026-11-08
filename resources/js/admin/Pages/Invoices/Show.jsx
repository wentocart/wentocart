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

function formatDate(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
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

export default function Show({ invoice, source = 'invoices', urls = {}, can_view_order = false }) {
    const [downloadOpen, setDownloadOpen] = useState(false);
    const emailForm = useForm({});
    const shop = invoice.shop || {};
    const code = invoice.currency_code;
    const crumbs = source === 'orders'
        ? [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
            { href: urls.order || `/admin/orders/${invoice.id}`, label: `#${invoice.number}` },
            { label: t('admin.invoices.invoice', 'Invoice') },
        ]
        : [
            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
            { href: '/admin/invoices', label: t('admin.invoices.invoices', 'Invoices') },
            { label: `#${invoice.number}` },
        ];

    const sendEmail = () => {
        emailForm.post(urls.email, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                className="print:hidden"
                title={`${t('admin.invoices.invoice', 'Invoice')} #${invoice.number}`}
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
                        <Button type="button" icon="mail" onClick={sendEmail} busy={emailForm.processing} disabled={!invoice.email}>
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
                                    {shop.email ? `\n${shop.email}` : ''}
                                </div>
                            </div>
                        </div>
                        <div>
                            <div className={ui.invoiceKicker}>{t('admin.invoices.invoice', 'INVOICE')} #{invoice.number}</div>
                            <div className={ui.invoiceMeta}>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.invoices.invoice_date', 'Invoice Date')}:</span> {formatDate(invoice.created_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.invoices.order_date', 'Order Date')}:</span> {formatDate(invoice.created_at)}</div>
                                <div><span className={ui.invoiceMetaLabel}>{t('admin.orders.payment_method', 'Payment Method')}:</span> {invoice.payment_method || '—'}</div>
                                <div>
                                    <span className={ui.invoiceMetaLabel}>{t('admin.orders.payment_status', 'Payment Status')}:</span>{' '}
                                    <span className={ui.invoicePaid}>{t('admin.orders.paid', 'Paid')}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className={ui.invoiceSplit}>
                        <Party title={t('admin.invoices.bill_to', 'Bill To')} party={invoice.billing} />
                        <Party title={t('admin.invoices.ship_to', 'Ship To')} party={invoice.shipping} />
                    </div>

                    <table className={ui.invoiceTable}>
                        <thead>
                            <tr>
                                <th className={ui.invoiceTh}>{t('admin.orders.item', 'Item')}</th>
                                <th className={ui.invoiceTh}>{t('admin.orders.sku', 'SKU')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.price', 'Price')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.qty', 'Qty')}</th>
                                <th className={ui.invoiceThRight}>{t('admin.orders.total', 'Total')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(invoice.items || []).map((item) => (
                                <tr key={item.id}>
                                    <td className={ui.invoiceTd}>{item.name}</td>
                                    <td className={ui.invoiceTd}>{item.sku || '—'}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.unit_price, code)}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{item.qty}</td>
                                    <td className={`${ui.invoiceTd} text-right`}>{money(item.total, code)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className={ui.invoiceTotals}>
                        <div className={ui.invoiceTotalRow}>
                            <span>{t('admin.orders.subtotal', 'Subtotal')}</span>
                            <span>{money(invoice.subtotal, code)}</span>
                        </div>
                        {Number(invoice.discount_total) > 0 ? (
                            <div className={ui.invoiceTotalRow}>
                                <span>{t('admin.orders.discount', 'Discount')}</span>
                                <span>-{money(invoice.discount_total, code)}</span>
                            </div>
                        ) : null}
                        <div className={ui.invoiceTotalRow}>
                            <span>{t('admin.orders.shipping', 'Shipping')}</span>
                            <span>{money(invoice.shipping_total, code)}</span>
                        </div>
                        <div className={ui.invoiceTotalRow}>
                            <span>{invoice.tax_label || t('admin.orders.tax', 'Tax')}</span>
                            <span>{money(invoice.tax_total, code)}</span>
                        </div>
                        <div className={ui.invoiceGrand}>
                            <span>{t('admin.orders.total', 'Total')}</span>
                            <span>{money(invoice.grand_total, code)}</span>
                        </div>
                    </div>

                    <div className={ui.invoiceFoot}>
                        <p className={ui.invoiceThanks}>{t('admin.invoices.thank_you', 'Thank you for shopping with us!')}</p>
                        <div className={ui.invoiceSign}>
                            {t('admin.invoices.authorized_signature', 'Authorized Signature')}
                            <div className={ui.invoiceSignLine} />
                        </div>
                    </div>
                </article>

                {can_view_order && source === 'invoices' ? (
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
