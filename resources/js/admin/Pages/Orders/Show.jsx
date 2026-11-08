import { useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Avatar,
    Badge,
    Button,
    Icon,
    Input,
    PageHeader,
    PortalMenu,
    Select,
    money,
    ui,
    cx,
    t,
    ModuleSlots,
    ModuleActionButtons,
    hexFromTone,
    TONE_HEX,
} from '../../ui';
import { catalogImageUrl } from '../../lib/catalogMedia';

function statusLabel(status) {
    if (status === 'completed') {
        return t('admin.orders.delivered', 'Delivered');
    }
    if (status === 'partial') {
        return t('admin.orders.partially_paid', 'Partially Paid');
    }
    if (status === 'partially_refunded') {
        return t('admin.orders.partially_refunded', 'Partially Refunded');
    }

    return (status || 'pending').replace(/^\w/, (c) => c.toUpperCase());
}

function stageFor(stages, status, stageId = null) {
    if (stageId != null && stageId !== '') {
        const byId = (stages || []).find((stage) => String(stage.id) === String(stageId));
        if (byId) {
            return byId;
        }
    }

    return (stages || []).find((stage) => stage.code === status) || null;
}

function statusTone(status) {
    const map = {
        pending: 'warning',
        processing: 'primary',
        shipped: 'info',
        completed: 'success',
        delivered: 'success',
        cancelled: 'danger',
        refunded: 'danger',
        paid: 'success',
        failed: 'danger',
        partial: 'warning',
        partially_refunded: 'warning',
    };

    return map[status] || 'warning';
}

function personName(order) {
    if (order.customer?.name) {
        return order.customer.name;
    }
    const billing = order.billing_address || {};
    const name = [billing.first_name, billing.last_name].filter(Boolean).join(' ');

    return name || order.email || t('admin.orders.guest', 'Guest');
}

function formatAddress(address) {
    if (!address) {
        return '—';
    }
    const name = [address.first_name, address.last_name].filter(Boolean).join(' ');
    const lines = [
        name,
        address.company,
        address.line1,
        address.line2,
        [address.city, address.state, address.postal_code].filter(Boolean).join(', '),
        address.country_code,
        address.phone ? `${t('admin.orders.phone', 'Phone')}: ${address.phone}` : null,
    ].filter(Boolean);

    return lines.length ? lines.join('\n') : '—';
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} ${date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
}

function formatCreatedOn(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const day = date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    const time = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });

    return `${day} at ${time}`;
}

function Row({ label, children }) {
    return (
        <div className={ui.detailRow}>
            <span className={ui.detailLabel}>{label}</span>
            <span className={ui.detailValue}>{children}</span>
        </div>
    );
}

function Totals({ order }) {
    const paid = Number(order.amount_paid != null ? order.amount_paid : (order.payment_status === 'paid' ? order.grand_total : 0));
    const due = Number(order.amount_due != null ? order.amount_due : Math.max(0, Number(order.grand_total || 0) - paid));

    return (
        <div className="ml-auto w-full max-w-xs space-y-0">
            <div className={ui.summaryRow}><span>{t('admin.orders.subtotal', 'Subtotal')}</span><span>{money(order.subtotal, order.currency_code)}</span></div>
            <div className={ui.summaryRow}><span>{t('admin.orders.discount', 'Discount')}</span><span>-{money(order.discount_total, order.currency_code)}</span></div>
            <div className={ui.summaryRow}><span>{t('admin.orders.shipping', 'Shipping')}</span><span>{money(order.shipping_total, order.currency_code)}</span></div>
            <div className={ui.summaryRow}><span>{t('admin.orders.tax', 'Tax')}</span><span>{money(order.tax_total, order.currency_code)}</span></div>
            {(order.tax_details || []).length > 0 ? (
                <div className={`${ui.muted} text-xs space-y-0.5 mb-2`}>
                    {order.tax_details.map((row, i) => (
                        <div key={i}>{row.label} ({row.rate}%): {money(row.amount, order.currency_code)}</div>
                    ))}
                </div>
            ) : null}
            {order.currency_rate && Number(order.currency_rate) !== 1 ? (
                <div className={`${ui.muted} text-xs mb-2`}>FX rate at place: {order.currency_rate}</div>
            ) : null}
            <div className={`${ui.summaryGrand} border-0 pt-2`}>
                <span className={ui.cellStrong}>{t('admin.orders.order_total', 'Order Total')}</span>
                <span className={ui.orderTotalChip}>{money(order.grand_total, order.currency_code)}</span>
            </div>
            <div className={`${ui.summaryRow} pt-2 border-t border-neutral-100`}>
                <span>{t('admin.orders.paid_amount', 'Amount Paid')}</span>
                <span className="font-medium text-emerald-600">{money(paid, order.currency_code)}</span>
            </div>
            {due > 0.009 ? (
                <div className={ui.summaryRow}>
                    <span className="font-medium text-amber-700">{t('admin.orders.balance_due', 'Balance Due')}</span>
                    <span className="font-semibold text-amber-600">{money(due, order.currency_code)}</span>
                </div>
            ) : null}
        </div>
    );
}

function SectionIcon({ name }) {
    return (
        <span className={ui.sectionHeadIcon}>
            <Icon name={name} className="h-5 w-5" />
        </span>
    );
}

export default function Show({ order, moduleSlots = [], orderActionSlots = [], stages = [], timeline = [], shop_returns = [], eu_withdrawals = [], can_buy_label = false, can_void_label = false, has_credit_slip = false, refunds = [], order_pdf = true, invoices_enabled = true, invoice_ready = false }) {
    const [moreOpen, setMoreOpen] = useState(false);
    const moreActionsRef = useRef(null);
    const [statusOpen, setStatusOpen] = useState(false);
    const form = useForm({
        order_stage_id: order.order_stage_id || (stages || []).find((stage) => stage.code === order.status)?.id || '',
        comment: '',
    });
    const name = personName(order);
    const billing = order.billing_address || {};
    const shipping = order.shipping_address || billing;
    const phone = billing.phone || shipping.phone || order.customer?.phone || '—';
    const paid = Number(order.amount_paid != null ? order.amount_paid : (order.payment_status === 'paid' ? order.grand_total : 0));
    const due = Number(order.amount_due != null ? order.amount_due : Math.max(0, Number(order.grand_total || 0) - paid));
    const created = order.created_at ? new Date(order.created_at) : null;
    const historiesNewest = [...(order.status_histories || order.statusHistories || [])].sort((a, b) => {
        const aTime = new Date(a.created_at || 0).getTime();
        const bTime = new Date(b.created_at || 0).getTime();
        return bTime - aTime;
    });
    const historiesOldest = [...historiesNewest].reverse();
    const latestComment = historiesNewest[0]?.comment || '';
    const itemCount = (order.items || []).length;
    const currentStage = stageFor(stages, order.status, order.order_stage_id);
    const orderTone = currentStage?.tone || statusTone(order.status);
    const orderLabel = currentStage?.name || statusLabel(order.status);
    const canInvoice = invoices_enabled && !!invoice_ready;
    const invoicesOff = !invoices_enabled && !!invoice_ready;
    const canCredit = !!(has_credit_slip || currentStage?.unlock_credit_slip || ['refunded', 'partially_refunded'].includes(order.payment_status) || Number(order.refunded_total || 0) > 0);
    const canDelivery = !!(currentStage?.unlock_delivery_slip || ['shipped', 'completed'].includes(order.status));
    const fallbackStages = [
        { code: 'pending', name: 'Pending' },
        { code: 'processing', name: 'Processing' },
        { code: 'shipped', name: 'Shipped' },
        { code: 'completed', name: 'Delivered' },
        { code: 'cancelled', name: 'Cancelled' },
        { code: 'refunded', name: 'Refunded' },
    ];
    const stageOptions = stages.length ? stages : fallbackStages;

    return (
        <Layout>
            <PageHeader
                crumbsFirst
                title={(
                    <span className="inline-flex flex-wrap items-center gap-2">
                        {t('admin.orders.order', 'Order')} #{order.number}
                        <Badge tone={statusTone(order.payment_status)}>{statusLabel(order.payment_status)}</Badge>
                        <Badge tone={orderTone}>{orderLabel}</Badge>
                    </span>
                )}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { href: '/admin/orders', label: t('admin.orders.order', 'Order') },
                    { label: t('admin.orders.order_details', 'Order Details') },
                ]}
                description={`${t('admin.orders.created_on', 'Created on')} ${created ? formatCreatedOn(order.created_at) : '—'}`}
                actions={(
                    <>
                        {canInvoice ? (
                            <>
                                <Button variant="secondary" native href={`/admin/orders/${order.id}/invoice/download`} icon="download">
                                    {t('admin.orders.download_invoice', 'Download Invoice')}
                                </Button>
                                <Button variant="secondary" href={`/admin/orders/${order.id}/invoice`} icon="eye">
                                    {t('admin.orders.view_invoice', 'View Invoice')}
                                </Button>
                            </>
                        ) : null}
                        <ModuleActionButtons slots={orderActionSlots} />
                        <div className="relative" ref={moreActionsRef}>
                            <Button variant="secondary" type="button" icon="more" onClick={() => setMoreOpen((open) => !open)}>
                                {t('admin.orders.more_actions', 'More Actions')}
                            </Button>
                            <PortalMenu open={moreOpen} onClose={() => setMoreOpen(false)} anchorRef={moreActionsRef}>
                                {order_pdf ? (
                                    <>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/summary/pdf`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.orders.download_order_pdf', 'Download order PDF')}
                                        </a>
                                        <button
                                            type="button"
                                            className={ui.popItem}
                                            role="menuitem"
                                            onClick={() => {
                                                setMoreOpen(false);
                                                window.print();
                                            }}
                                        >
                                            {t('admin.orders.print_order', 'Print Order')}
                                        </button>
                                    </>
                                ) : null}
                                {canCredit ? (
                                    <>
                                        <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/credit-slip`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.credit_slip', 'View Credit Slip')}
                                        </Link>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/credit-slip/download`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.orders.download_credit_slip', 'Download Credit Slip')}
                                        </a>
                                    </>
                                ) : null}
                                {canDelivery ? (
                                    <>
                                        <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/delivery-slip`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.delivery_slip', 'View Delivery Slip')}
                                        </Link>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/delivery-slip/download`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.orders.download_delivery_slip', 'Download Delivery Slip')}
                                        </a>
                                    </>
                                ) : null}
                            </PortalMenu>
                        </div>
                        <Button href={`/admin/orders/${order.id}/edit`} icon="pencil">{t('admin.orders.edit_order', 'Edit Order')}</Button>
                    </>
                )}
            />

            <div className="space-y-4">
                <div className={ui.orderShowTop}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div className="flex min-w-0 items-center gap-3">
                                <SectionIcon name="info" />
                                <h2 className={ui.sectionTitle}>{t('admin.orders.order_information', 'Order Information')}</h2>
                            </div>
                            {can_buy_label && !(order.shipments || []).some((row) => row.tracking_number && row.status !== 'cancelled') ? (
                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/label`)}>
                                    {t('admin.orders.buy_label', 'Buy label')}
                                </Button>
                            ) : null}
                            {can_void_label && (order.shipments || []).some((row) => row.tracking_number && row.status !== 'cancelled') ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                    onClick={() => {
                                        if (window.confirm(t('admin.orders.confirm_void_label', 'Are you sure you want to void this shipping label? The carrier will be requested to refund postage.'))) {
                                            router.post(`/admin/orders/${order.id}/label/void`);
                                        }
                                    }}
                                >
                                    {t('admin.orders.void_label', 'Void label')}
                                </Button>
                            ) : null}
                        </div>
                        <Row label={t('admin.orders.order_id', 'Order ID')}>{order.id}</Row>
                        <Row label={t('admin.orders.order_reference', 'Order reference')}>{order.number}</Row>
                        {order.checkout_reference && order.checkout_reference !== order.number ? (
                            <Row label={t('admin.orders.checkout_reference', 'Checkout reference')}>{order.checkout_reference}</Row>
                        ) : null}
                        {(order.checkout_group?.orders || []).length > 1 ? (
                            <Row label={t('admin.orders.checkout_orders', 'Checkout orders')}>
                                <span className="flex flex-wrap gap-2">
                                    {(order.checkout_group.orders || []).map((row) => (
                                        <Link key={row.id} href={`/admin/orders/${row.id}`} className={ui.btnLink}>
                                            #{row.number}
                                        </Link>
                                    ))}
                                </span>
                            </Row>
                        ) : null}
                        <Row label={t('admin.orders.total_price', 'Total price')}>{money(order.grand_total, order.currency_code)}</Row>
                        <Row label={t('admin.orders.created_on', 'Created on')}>{created ? formatDateTime(order.created_at) : '—'}</Row>
                        <Row label={t('admin.orders.payment_method', 'Payment method')}>{order.payment_method?.name || '—'}</Row>
                        <Row label={t('admin.orders.delivery_method', 'Delivery method')}>{order.shipping_method?.name || '—'}</Row>
                        {(order.shipments || []).length ? (
                            <Row label={t('admin.orders.tracking', 'Tracking')}>
                                <span className="flex flex-wrap items-center gap-2">
                                    {(order.shipments || []).filter((row) => row.tracking_number).map((row) => (
                                        row.tracking_url ? (
                                            <a
                                                key={row.id}
                                                href={row.tracking_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 font-medium text-violet-600 hover:text-violet-800 underline hover:no-underline"
                                                title={t('admin.orders.track_shipment', 'Track shipment')}
                                            >
                                                <span>{row.carrier ? `${row.carrier} ` : ''}{row.tracking_number}</span>
                                                <Icon name="external-link" className="h-3.5 w-3.5 shrink-0" />
                                            </a>
                                        ) : (
                                            <span key={row.id}>{row.carrier ? `${row.carrier} ` : ''}{row.tracking_number}</span>
                                        )
                                    ))}
                                </span>
                            </Row>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div className="flex min-w-0 items-center gap-3">
                                <SectionIcon name="package" />
                                <h2 className={ui.sectionTitle}>{t('admin.orders.current_order_status', 'Current Order Status')}</h2>
                            </div>
                        </div>
                        <div className={ui.orderStatusHeroWrap}>
                            <Badge tone={orderTone} className={ui.orderStatusHero}>{orderLabel}</Badge>
                            {latestComment ? <p className={`${ui.muted} mt-3`}>{latestComment}</p> : null}
                        </div>
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/admin/orders/${order.id}/status`);
                            }}
                        >
                            <Button type="button" variant="outline" className="w-full" onClick={() => setStatusOpen((open) => !open)}>
                                {t('admin.orders.update_status', 'Update Status')}
                            </Button>
                            {statusOpen ? (
                                <>
                                    <Select wide value={form.data.order_stage_id} onChange={(e) => form.setData('order_stage_id', e.target.value)}>
                                        {stageOptions.map((stage) => (
                                            <option key={stage.id} value={stage.id}>{stage.name || statusLabel(stage.code)}</option>
                                        ))}
                                    </Select>
                                    <Input
                                        wide
                                        placeholder={t('admin.orders.comment', 'Comment')}
                                        value={form.data.comment}
                                        onChange={(e) => form.setData('comment', e.target.value)}
                                    />
                                    <Button icon="check" busy={form.processing} className="w-full">
                                        {t('admin.orders.update_status', 'Update Status')}
                                    </Button>
                                </>
                            ) : null}
                        </form>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div className="flex min-w-0 items-center gap-3">
                                <SectionIcon name="clock" />
                                <h2 className={ui.sectionTitle}>{t('admin.orders.order_status_history', 'Order Status History')}</h2>
                            </div>
                        </div>
                        {historiesOldest.length ? (
                            <ol className={ui.orderTimeline}>
                                {historiesOldest.map((row) => {
                                    const tone = stageFor(stages, row.status)?.tone || statusTone(row.status);
                                    const label = stageFor(stages, row.status)?.name || statusLabel(row.status);
                                    const success = hexFromTone(tone) === TONE_HEX.success;

                                    return (
                                        <li key={row.id} className={ui.orderTimelineItem}>
                                            <span className={`${ui.orderTimelineDot} ${success ? 'bg-emerald-100 text-emerald-700' : 'bg-violet-100 text-violet-700'}`}>
                                                <Icon name={success ? 'check' : 'package'} className="h-3.5 w-3.5" />
                                            </span>
                                            <div className={ui.orderTimelineRow}>
                                                <Badge tone={tone} className="shrink-0 mt-0.5">{label}</Badge>
                                                <div className={ui.orderTimelineMeta}>
                                                    <div className={`${ui.muted} text-xs`}>{formatDateTime(row.created_at)}</div>
                                                    {row.comment ? <div className={`${ui.cellSub} mt-0.5`}>{row.comment}</div> : null}
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                        ) : (
                            <p className={ui.muted}>{t('admin.orders.no_status_history', 'No status history yet.')}</p>
                        )}
                    </section>
                </div>

                <div className={ui.orderShowBody}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div className="flex min-w-0 items-center gap-3">
                                <SectionIcon name="user" />
                                <h2 className={ui.sectionTitle}>{t('admin.orders.customer_details', 'Customer Details')}</h2>
                            </div>
                            {order.customer_id ? (
                                <Button variant="secondary" href={`/admin/customers/${order.customer_id}`}>
                                    {t('admin.orders.view_customer', 'View customer')}
                                </Button>
                            ) : null}
                        </div>

                        <div className={`${ui.row} mb-4`}>
                            <Avatar name={name} />
                            <div className={ui.cellStack}>
                                <div className={ui.cellStrong}>{name}</div>
                                <div className={ui.cellSub}>{order.email || order.customer?.email || '—'}</div>
                            </div>
                        </div>

                        <Row label={t('admin.orders.customer_id', 'Customer ID')}>{order.customer_id || '—'}</Row>
                        <Row label={t('admin.orders.phone', 'Phone')}>{phone}</Row>
                        <Row label={t('admin.orders.customer_type', 'Customer type')}>
                            {order.customer_id
                                ? t('admin.orders.registered_customer', 'Registered customer')
                                : t('admin.orders.guest', 'Guest')}
                        </Row>
                        {order.customer?.created_at ? (
                            <Row label={t('admin.orders.account_registration', 'Account registration')}>
                                {formatDateTime(order.customer.created_at)}
                            </Row>
                        ) : null}

                        <div className={ui.sectionSplit}>
                            <div className={ui.orderAddressSplit}>
                                <span className={ui.orderAddressRule} aria-hidden="true" />
                                <div className={ui.orderAddressPane}>
                                    <h3 className={`${ui.sectionTitle} ${ui.orderAddressTitle}`}>
                                        <Icon name="truck" className={ui.orderAddressIcon} />
                                        {t('admin.orders.shipping_address', 'Shipping address')}
                                    </h3>
                                    <p className={`${ui.cellSub} whitespace-pre-line`}>{formatAddress(shipping)}</p>
                                </div>
                                <div className={ui.orderAddressPane}>
                                    <h3 className={`${ui.sectionTitle} ${ui.orderAddressTitle}`}>
                                        <Icon name="file" className={ui.orderAddressIcon} />
                                        {t('admin.orders.invoice_address', 'Invoice address')}
                                    </h3>
                                    <p className={`${ui.cellSub} whitespace-pre-line`}>{formatAddress(billing)}</p>
                                </div>
                            </div>
                        </div>

                        {order.customer_note ? (
                            <div className={ui.sectionSplit}>
                                <h3 className={`${ui.sectionTitle} mb-2`}>{t('admin.orders.customer_note', 'Customer note')}</h3>
                                <p className={ui.cellSub}>{order.customer_note}</p>
                            </div>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>
                                {t('admin.orders.products', 'Products')}
                                {itemCount ? ` (${itemCount})` : ''}
                            </h2>
                        </div>
                        <div className="grid grid-cols-[minmax(0,1fr)_7rem_6rem_7rem] gap-3 text-xs font-medium text-neutral-500 pb-2 border-b border-neutral-100">
                            <span>{t('admin.orders.product', 'Product')}</span>
                            <span className="text-right">{t('admin.orders.unit_price', 'Unit Price')}</span>
                            <span className="text-center">{t('admin.orders.quantity', 'Quantity')}</span>
                            <span className="text-right">{t('admin.orders.total', 'Total')}</span>
                        </div>
                        {(order.items || []).map((item) => (
                            <div key={item.id} className="grid grid-cols-[minmax(0,1fr)_7rem_6rem_7rem] gap-3 items-center py-3 border-b border-neutral-100 last:border-b-0">
                                <div className={ui.row}>
                                    {item.product?.cover_path ? (
                                        <img className={ui.thumb} src={catalogImageUrl(item.product.cover_path, 'sm')} alt="" />
                                    ) : (
                                        <Avatar name={item.name} />
                                    )}
                                    <div className={ui.cellStack}>
                                        <div className={ui.cellStrong}>{item.name}</div>
                                        <div className={ui.cellSub}>{item.sku}</div>
                                        {item.subscription_plan ? (
                                            <div className={ui.cellSub}>
                                                Subscription: {item.subscription_plan.translations?.[0]?.name || 'Plan'}
                                            </div>
                                        ) : null}
                                        {item.booking_date ? (
                                            <div className={ui.cellSub}>
                                                {item.booking_end_date
                                                    ? `${t('admin.orders.rental_period', 'Rental')}: ${String(item.booking_date).slice(0, 10)} → ${String(item.booking_end_date).slice(0, 10)}`
                                                    : `${t('admin.orders.booking_date', 'Booking date')}: ${String(item.booking_date).slice(0, 10)}`}
                                                {item.rental_duration ? ` · ${t('admin.orders.rental_duration', 'Duration')}: ${item.rental_duration}` : ''}
                                                {item.rental_data?.deposit > 0
                                                    ? ` · ${t('admin.orders.rental_deposit', 'Deposit')}: ${money(item.rental_data.deposit, order.currency_code)} (${item.rental_data.deposit_status || 'held'})`
                                                    : ''}
                                            </div>
                                        ) : null}
                                        {item.preorder_data?.enabled ? (
                                            <div className={ui.cellSub}>
                                                {t('admin.orders.preorder', 'Pre-order')}
                                                {item.preorder_data.status ? `: ${item.preorder_data.status}` : ''}
                                                {item.preorder_data.remaining_balance > 0
                                                    ? ` · ${t('admin.orders.preorder_remaining', 'Remaining')}: ${money(item.preorder_data.remaining_balance, order.currency_code)}`
                                                    : ''}
                                                {item.preorder_data.release_at
                                                    ? ` · ${t('admin.orders.preorder_release', 'Release')}: ${String(item.preorder_data.release_at).slice(0, 10)}`
                                                    : ''}
                                            </div>
                                        ) : null}
                                        {(item.customization || []).map((line, index) => (
                                            <div key={line.field_id || index} className={ui.cellSub}>
                                                {line.label}: {line.display || line.value}
                                                {Number(line.price) > 0 ? ` · +${money(line.price, order.currency_code)}` : ''}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className="text-right text-sm">{money(item.unit_price, order.currency_code)}</div>
                                <div className="text-center text-sm">{item.qty}</div>
                                <div className="text-right font-medium">{money(item.total, order.currency_code)}</div>
                            </div>
                        ))}
                        <div className="pt-4 flex justify-end">
                            <Totals order={order} />
                        </div>
                    </section>
                </div>

                <section className={ui.sectionCard}>
                    <div className={ui.sectionHead}>
                        <div className="flex min-w-0 items-center gap-3">
                            <SectionIcon name="bag" />
                            <h2 className={ui.sectionTitle}>{t('admin.orders.payment_information', 'Payment Information')}</h2>
                        </div>
                    </div>
                    <div className={ui.orderPayGrid}>
                        <div className="grid gap-x-8 gap-y-1 sm:grid-cols-2">
                            <Row label={t('admin.orders.method', 'Method')}>{order.payment_method?.name || '—'}</Row>
                            <Row label={t('admin.orders.payment_status', 'Payment Status')}>
                                <Badge tone={statusTone(order.payment_status)}>{statusLabel(order.payment_status)}</Badge>
                            </Row>
                            <Row label={t('admin.orders.transaction_id', 'Transaction ID')}>{order.number}</Row>
                            <Row label={t('admin.orders.paid_on', 'Paid On')}>
                                {order.payment_status === 'paid' && created
                                    ? formatDateTime(order.created_at)
                                    : '—'}
                            </Row>
                            <Row label={t('admin.orders.total_price', 'Total Price')}>{money(order.grand_total, order.currency_code)}</Row>
                            <Row label={t('admin.orders.paid_amount', 'Amount Paid')}>
                                <span className="font-medium text-emerald-600">{money(paid, order.currency_code)}</span>
                            </Row>
                            {due > 0.009 ? (
                                <Row label={t('admin.orders.balance_due', 'Balance Due')}>
                                    <span className="font-semibold text-amber-600">{money(due, order.currency_code)}</span>
                                </Row>
                            ) : null}
                        </div>
                        {canInvoice ? (
                            <div className={ui.invoiceAvail}>
                                <span className={ui.invoiceAvailIcon}>
                                    <Icon name="check" className="h-6 w-6" />
                                </span>
                                <div>
                                    <div className={ui.cellStrong}>{t('admin.orders.invoice_available', 'Invoice Available')}</div>
                                    <p className={`${ui.muted} mt-1`}>
                                        {t('admin.orders.invoice_available_hint', 'You can view or download the invoice for this order.')}
                                    </p>
                                </div>
                                <Button variant="secondary" href={`/admin/orders/${order.id}/invoice`} icon="eye">
                                    {t('admin.orders.view_invoice', 'View Invoice')}
                                </Button>
                            </div>
                        ) : null}
                        {invoicesOff ? (
                            <div className={ui.invoiceAvail}>
                                <span className={ui.invoiceAvailIcon}>
                                    <Icon name="info" className="h-6 w-6" />
                                </span>
                                <div>
                                    <div className={ui.cellStrong}>{t('admin.orders.invoices_off', 'Invoices are turned off')}</div>
                                    <p className={`${ui.muted} mt-1`}>
                                        {t('admin.orders.invoices_off_hint', 'Turn them on under Configure → Orders → Invoices. This order will not open a 404.')}
                                    </p>
                                </div>
                                <Button variant="secondary" href="/admin/order-settings?tab=invoices" icon="settings">
                                    {t('admin.orders.invoice_settings', 'Invoice settings')}
                                </Button>
                            </div>
                        ) : null}
                    </div>
                </section>

                {(canCredit || refunds.length > 0) ? (
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <div className="flex min-w-0 items-center gap-3">
                                <SectionIcon name="bag" />
                                <h2 className={ui.sectionTitle}>{t('admin.orders.refund_documents', 'Refund documents')}</h2>
                            </div>
                        </div>
                        <div className="space-y-3">
                            {canCredit ? (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 px-4 py-3">
                                    <div>
                                        <div className="font-medium text-neutral-900">{t('admin.nav.credit_slip', 'Credit Slip')}</div>
                                        <div className="text-sm text-neutral-500">
                                            {t('admin.orders.total_refunded', 'Total Refunded')}: {money(order.refunded_total || 0, order.currency_code)}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="secondary" href={`/admin/orders/${order.id}/credit-slip`}>
                                            {t('admin.orders.view', 'View')}
                                        </Button>
                                        <Button variant="secondary" native href={`/admin/orders/${order.id}/credit-slip/download`}>
                                            {t('admin.orders.download', 'Download')}
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                            {refunds.map((row, index) => (
                                <div key={`${row.at || 'refund'}-${index}`} className="rounded-xl border border-neutral-200 px-4 py-3 space-y-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="font-medium text-neutral-900">
                                            {t('admin.orders.refund', 'Refund')} · {money(row.amount, order.currency_code)}
                                        </div>
                                        <div className="text-sm text-neutral-500">{row.at ? formatDateTime(row.at) : '—'}</div>
                                    </div>
                                    {(row.items || []).length > 0 ? (
                                        <div className="text-sm text-neutral-600">
                                            {(row.items || []).map((item) => `${item.name} × ${item.qty}`).join(', ')}
                                        </div>
                                    ) : null}
                                    <div className="flex flex-wrap gap-2">
                                        {row.voucher_code ? (
                                            row.voucher_id ? (
                                                <Link className={ui.btnLink} href={`/admin/discounts/${row.voucher_id}/edit`}>
                                                    {t('admin.orders.refund_coupon', 'Coupon')}: {row.voucher_code}
                                                </Link>
                                            ) : (
                                                <span className="text-sm text-neutral-700">
                                                    {t('admin.orders.refund_coupon', 'Coupon')}: {row.voucher_code}
                                                </span>
                                            )
                                        ) : null}
                                        {row.store_credit ? (
                                            <Badge tone="success">{t('admin.orders.store_credit_issued', 'Store credit issued')}</Badge>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                {moduleSlots.length ? <ModuleSlots slots={moduleSlots} /> : null}

                {shop_returns.length ? (
                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.orders.returns', 'Returns')}</h2>
                        <ul className="space-y-3">
                            {shop_returns.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div className={ui.cellStrong}>{row.number}</div>
                                        <div className={ui.cellSub}>{row.status} · {money(row.refund_total, order.currency_code)}</div>
                                    </div>
                                    <div className="flex gap-2">
                                        {row.status === 'requested' ? (
                                            <>
                                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/returns/${row.id}/approve`)}>
                                                    {t('admin.orders.approve', 'Approve')}
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/returns/${row.id}/reject`)}>
                                                    {t('admin.orders.reject', 'Reject')}
                                                </Button>
                                            </>
                                        ) : null}
                                        {row.status === 'approved' ? (
                                            <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/returns/${row.id}/receive`)}>
                                                {t('admin.orders.receive', 'Mark received')}
                                            </Button>
                                        ) : null}
                                        {['approved', 'received'].includes(row.status) ? (
                                            <Button type="button" onClick={() => router.post(`/admin/orders/${order.id}/returns/${row.id}/refund`)}>
                                                {t('admin.orders.refund_return', 'Refund return')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {eu_withdrawals.length ? (
                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.orders.eu_withdrawals', 'EU withdrawals')}</h2>
                        <ul className="space-y-3">
                            {eu_withdrawals.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div className={ui.cellStrong}>{row.reference}</div>
                                        <div className={ui.cellSub}>
                                            {row.status}
                                            {row.reason ? ` · ${row.reason}` : ''}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        {row.status === 'pending' ? (
                                            <>
                                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/withdrawals/${row.id}/approve`)}>
                                                    {t('admin.orders.approve', 'Approve')}
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => router.post(`/admin/orders/${order.id}/withdrawals/${row.id}/reject`)}>
                                                    {t('admin.orders.reject', 'Reject')}
                                                </Button>
                                            </>
                                        ) : null}
                                        {row.status === 'approved' ? (
                                            <Button type="button" onClick={() => router.post(`/admin/orders/${order.id}/withdrawals/${row.id}/complete`)}>
                                                {t('admin.orders.complete_withdrawal', 'Mark completed')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {timeline.length > 0 ? (
                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.activity.timeline', 'Change history')}</h2>
                        <ul className="space-y-2">
                            {timeline.map((row, index) => (
                                <li key={`${row.at}-${index}`} className="text-sm">
                                    <span className={ui.muted}>{row.at} · {row.source}</span>
                                    <div>
                                        {row.href ? <a href={row.href}>{row.title}</a> : row.title}
                                    </div>
                                </li>
                            ))}
            </ul>
                    </section>
                ) : null}
            </div>
        </Layout>
    );
}
