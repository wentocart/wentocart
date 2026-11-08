import { router } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge,
    Button,
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

export default function OrderShow({ order }) {
    const ship = order.shipping_address || {};

    return (
        <Layout>
            <PageHeader
                title={order.number}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/orders', label: t('admin.nav.seller_orders', 'Seller Orders') },
                    { label: order.number },
                ]}
            />
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <div className="space-y-6">
                    <section className={ui.sectionCard}>
                        <h2 className="text-lg font-semibold">{t('admin.multivendor.order_items', 'Items')}</h2>
                        <Table className="mt-4">
                            <THead>
                                <tr>
                                    <Th>{t('admin.common.name', 'Name')}</Th>
                                    <Th>{t('admin.common.sku', 'SKU')}</Th>
                                    <Th>{t('admin.common.qty', 'Qty')}</Th>
                                    <Th>{t('admin.common.total', 'Total')}</Th>
                                </tr>
                            </THead>
                            <tbody>
                                {(order.items || []).map((item) => (
                                    <Tr key={item.id}>
                                        <Td>{item.name}</Td>
                                        <Td>{item.sku || '—'}</Td>
                                        <Td>{item.qty}</Td>
                                        <Td>{Number(item.total).toFixed(2)}</Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className="text-lg font-semibold">{t('admin.multivendor.shipments', 'Shipments')}</h2>
                        {(order.shipments || []).length === 0 ? (
                            <p className="mt-3 text-sm text-neutral-500">{t('admin.multivendor.no_shipments', 'No shipments yet.')}</p>
                        ) : (
                            <Table className="mt-4">
                                <THead>
                                    <tr>
                                        <Th>{t('admin.common.status', 'Status')}</Th>
                                        <Th>{t('admin.orders.carrier', 'Carrier')}</Th>
                                        <Th>{t('admin.orders.tracking_number', 'Tracking')}</Th>
                                        <Th>{t('admin.orders.shipping', 'Shipping')}</Th>
                                        <Th>{t('admin.common.items', 'Items')}</Th>
                                    </tr>
                                </THead>
                                <tbody>
                                    {order.shipments.map((shipment) => (
                                        <Tr key={shipment.id}>
                                            <Td><Badge>{shipment.status}</Badge></Td>
                                            <Td>{shipment.carrier || '—'}</Td>
                                            <Td>
                                                {shipment.tracking_url ? (
                                                    <a href={shipment.tracking_url} className={ui.btnLink} target="_blank" rel="noreferrer">
                                                        {shipment.tracking_number || shipment.tracking_url}
                                                    </a>
                                                ) : (shipment.tracking_number || '—')}
                                            </Td>
                                            <Td>{Number(shipment.shipping_cost || 0).toFixed(2)}</Td>
                                            <Td>{shipment.item_count}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </section>
                </div>
                <aside className={`${ui.sectionCard} h-fit space-y-3`}>
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold">{order.shop_name}</h2>
                        <Badge>{order.status}</Badge>
                    </div>
                    <p className="text-sm text-neutral-600">{order.customer_email || '—'}</p>
                    {order.parent_status ? (
                        <p className="text-xs text-neutral-500 capitalize">
                            {t('admin.multivendor.parent_status', 'Parent order')}: {order.parent_status}
                        </p>
                    ) : null}
                    <div className="rounded-md border border-neutral-200 p-3 text-sm">
                        <p className="font-medium">{t('admin.orders.ship_to', 'Ship to')}</p>
                        <p className="mt-1 text-neutral-600">{ship.first_name} {ship.last_name}</p>
                        <p className="text-neutral-600">{ship.line1}</p>
                        <p className="text-neutral-600">{ship.city} {ship.state} {ship.postal_code}</p>
                        <p className="text-neutral-600">{ship.country_code}</p>
                    </div>
                    <dl className="space-y-2 text-sm">
                        <Row label={t('admin.multivendor.subtotal', 'Subtotal')} value={order.subtotal} />
                        <Row label={t('admin.multivendor.discount', 'Discount')} value={order.discount_total ?? 0} />
                        <Row label={t('admin.multivendor.commissionable_gross', 'Commissionable gross')} value={order.commissionable_gross ?? order.subtotal} />
                        <Row
                            label={t('admin.multivendor.commission_source', 'Commission source')}
                            value={order.commission_source || '—'}
                            raw
                        />
                        <Row
                            label={t('admin.multivendor.commission_type', 'Commission type')}
                            value={order.commission_type || '—'}
                            raw
                        />
                        <Row
                            label={t('admin.multivendor.commission_rate', 'Commission rate')}
                            value={order.commission_rate != null ? `${Number(order.commission_rate).toFixed(2)}%` : '—'}
                            raw
                        />
                        <Row label={t('admin.multivendor.commission', 'Commission')} value={order.commission_total} />
                        <Row label={t('admin.multivendor.earning', 'Seller earning')} value={order.earning_total} />
                        <Row label={t('admin.common.total', 'Total')} value={order.grand_total} />
                    </dl>
                    <Select
                        value={['pending', 'processing', 'cancelled'].includes(order.status) ? order.status : 'processing'}
                        onChange={(e) => router.put(`/admin/multivendor/orders/${order.id}/status`, { status: e.target.value }, { preserveScroll: true })}
                    >
                        {['pending', 'processing', 'cancelled'].map((status) => (
                            <option key={status} value={status}>{status}</option>
                        ))}
                    </Select>
                    <p className="text-xs text-neutral-500">
                        {t('admin.multivendor.ship_via_portal', 'Ship / deliver actions are managed in the seller portal shipment workflow.')}
                    </p>
                    <Button variant="secondary" href="/admin/multivendor/orders">{t('admin.common.back', 'Back')}</Button>
                </aside>
            </div>
        </Layout>
    );
}

function Row({ label, value, raw = false }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-neutral-500">{label}</dt>
            <dd className="font-medium">{raw ? value : Number(value || 0).toFixed(2)}</dd>
        </div>
    );
}
