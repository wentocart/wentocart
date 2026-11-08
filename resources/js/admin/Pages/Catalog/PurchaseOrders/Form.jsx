import { useEffect, useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Field,
    FieldError,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Select,
    Textarea,
    cx,
    ui,
    t,
} from '../../../ui';
import './purchase-order-form.css';

function emptyLine() {
    return {
        product_id: null,
        sku: '',
        name: '',
        qty_ordered: '1',
        unit_cost: '',
        qty_received: 0,
        receive_qty: '',
        supplier_reference: null,
        wholesale_price: null,
        last_purchase_cost: null,
    };
}

function statusLabel(status) {
    if (status === 'draft') return t('admin.purchase_orders.status_draft', 'Draft');
    if (status === 'ordered') return t('admin.purchase_orders.status_ordered', 'Ordered');
    if (status === 'received') return t('admin.purchase_orders.status_received', 'Received');
    if (status === 'cancelled') return t('admin.purchase_orders.status_cancelled', 'Cancelled');
    return status || t('admin.purchase_orders.status_draft', 'Draft');
}

function money(value) {
    const n = Number(value || 0);
    return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}

function ProductSearchPicker({ disabled, selectedIds, onSelect }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (disabled) {
            setResults([]);
            setOpen(false);
            return undefined;
        }

        const q = query.trim();
        if (q.length < 1) {
            setResults([]);
            setOpen(false);
            return undefined;
        }

        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q });
            fetch(`/admin/products/search?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            })
                .then((res) => (res.ok ? res.json() : []))
                .then((rows) => {
                    const filtered = Array.isArray(rows)
                        ? rows.filter((row) => !selectedIds.includes(row.id))
                        : [];
                    setResults(filtered);
                    setOpen(filtered.length > 0);
                })
                .catch(() => {
                    setResults([]);
                    setOpen(false);
                });
        }, 250);

        return () => clearTimeout(timer);
    }, [query, disabled, selectedIds.join(',')]);

    if (disabled) {
        return null;
    }

    return (
        <div className="po-picker">
            <div className={ui.filterSearch}>
                <Icon name="search" className={ui.filterSearchIcon} />
                <input
                    className={ui.filterSearchInput}
                    type="text"
                    value={query}
                    placeholder={t('admin.purchase_orders.search_product', 'Search product by name or SKU')}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={() => results.length > 0 && setOpen(true)}
                    onBlur={() => setTimeout(() => setOpen(false), 150)}
                    autoComplete="off"
                />
            </div>
            {open && results.length > 0 ? (
                <div className="po-picker__menu" role="listbox">
                    {results.map((row) => (
                        <button
                            key={row.id}
                            type="button"
                            className="po-picker__item"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                                onSelect(row);
                                setQuery('');
                                setResults([]);
                                setOpen(false);
                            }}
                        >
                            <span className="po-picker__name">{row.name}</span>
                            <span className="po-picker__meta">
                                <span className="po-picker__sku">{row.sku || '—'}</span>
                                <span>{t('admin.purchase_orders.stock', 'Stock')} {row.stock_qty ?? 0}</span>
                                <span>{money(row.selling_price ?? row.price)}</span>
                            </span>
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default function Form({ order, suppliers = [], warehouses = [], totals = null }) {
    const isNew = !order?.id;
    const locked = ['received', 'cancelled'].includes(order?.status);
    const canReceive = !isNew && (order.status === 'draft' || order.status === 'ordered');
    const receipts = order?.receipts || [];

    const form = useForm({
        supplier_id: order?.supplier_id || '',
        warehouse_id: order?.warehouse_id
            || warehouses.find((row) => row.is_default)?.id
            || warehouses.find((row) => row.code === 'default')?.id
            || warehouses[0]?.id
            || '',
        expected_at: order?.expected_at || '',
        notes: order?.notes || '',
        discount_type: order?.discount_type || '',
        discount_value: String(order?.discount_value ?? 0),
        shipping_cost: String(order?.shipping_cost ?? 0),
        items: (order?.items || []).length
            ? order.items.map((row) => ({
                id: row.id,
                product_id: row.product_id || null,
                sku: row.sku || '',
                name: row.name || '',
                qty_ordered: String(row.qty_ordered ?? 1),
                unit_cost: String(row.unit_cost ?? 0),
                qty_received: Number(row.qty_received || 0),
                receive_qty: '',
                supplier_reference: row.supplier_reference ?? null,
                wholesale_price: row.wholesale_price ?? null,
                last_purchase_cost: row.last_purchase_cost ?? null,
            }))
            : [],
    });

    const [receiving, setReceiving] = useState(false);
    const [reversingId, setReversingId] = useState(null);

    const selectedProductIds = useMemo(
        () => form.data.items.map((row) => row.product_id).filter(Boolean),
        [form.data.items],
    );

    const openLines = useMemo(
        () => form.data.items.filter(
            (row) => row.id && Number(row.qty_ordered) - Number(row.qty_received || 0) > 1e-4,
        ),
        [form.data.items],
    );

    const computedTotals = useMemo(() => {
        const lines = form.data.items.filter((row) => row.product_id || row.sku);
        const lineSubtotal = lines.reduce(
            (sum, row) => sum + Number(row.qty_ordered || 0) * Number(row.unit_cost || 0),
            0,
        );
        const qty = lines.reduce((sum, row) => sum + Number(row.qty_ordered || 0), 0);
        const received = lines.reduce((sum, row) => sum + Number(row.qty_received || 0), 0);
        const discountValue = Number(form.data.discount_value || 0);
        let discountAmount = 0;
        if (form.data.discount_type === 'percent') {
            discountAmount = (Math.min(100, Math.max(0, discountValue)) / 100) * lineSubtotal;
        } else if (form.data.discount_type === 'fixed') {
            discountAmount = Math.min(lineSubtotal, Math.max(0, discountValue));
        }
        const shippingCost = Math.max(0, Number(form.data.shipping_cost || 0));
        return {
            lines: lines.length,
            qty,
            received,
            remaining: Math.max(0, qty - received),
            line_subtotal: lineSubtotal,
            discount_amount: discountAmount,
            shipping_cost: shippingCost,
            grand_total: Math.max(0, lineSubtotal - discountAmount + shippingCost),
        };
    }, [form.data.items, form.data.discount_type, form.data.discount_value, form.data.shipping_cost]);

    const updateLine = (index, patch) => {
        form.setData(
            'items',
            form.data.items.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    };

    const selectProduct = (product) => {
        if (selectedProductIds.includes(product.id)) {
            window.alert(t('admin.purchase_orders.duplicate_product', 'This product is already on the purchase order.'));
            return;
        }

        const blankIndex = form.data.items.findIndex((row) => !row.product_id && !row.sku);
        const nextLine = {
            ...emptyLine(),
            product_id: product.id,
            sku: product.sku || '',
            name: product.name || '',
            unit_cost: String(product.cost_price ?? product.wholesale_price ?? product.price ?? ''),
        };

        if (blankIndex >= 0) {
            form.setData(
                'items',
                form.data.items.map((row, i) => (i === blankIndex ? { ...row, ...nextLine } : row)),
            );
        } else {
            form.setData('items', [...form.data.items, nextLine]);
        }
    };

    const submit = (e) => {
        e.preventDefault();
        const ids = form.data.items.map((row) => row.product_id).filter(Boolean);
        if (new Set(ids).size !== ids.length) {
            window.alert(t('admin.purchase_orders.duplicate_product', 'This product is already on the purchase order.'));
            return;
        }
        if (isNew) {
            form.post('/admin/purchase-orders');
            return;
        }
        form.put(`/admin/purchase-orders/${order.id}`, { preserveScroll: true });
    };

    const receive = (mode) => {
        if (!canReceive || receiving) {
            return;
        }

        const payload = { items: [] };
        if (mode === 'partial') {
            payload.items = openLines.map((row) => {
                const remaining = Math.max(0, Number(row.qty_ordered) - Number(row.qty_received || 0));
                const qty = Math.min(remaining, Math.max(0, Number(row.receive_qty || 0)));
                return { id: row.id, qty };
            }).filter((row) => row.qty > 0);

            if (!payload.items.length) {
                window.alert(t('admin.purchase_orders.receive_qty_required', 'Enter a receive quantity on at least one open line.'));
                return;
            }
            if (!window.confirm(t('admin.purchase_orders.receive_selected_confirm', 'Receive the entered quantities into the warehouse?'))) {
                return;
            }
        } else if (!window.confirm(t('admin.purchase_orders.receive_all_confirm', 'Receive all remaining quantities into the warehouse?'))) {
            return;
        }

        setReceiving(true);
        router.post(`/admin/purchase-orders/${order.id}/receive`, payload, {
            preserveScroll: true,
            onFinish: () => setReceiving(false),
        });
    };

    const reverseReceipt = (receiptId) => {
        if (reversingId) {
            return;
        }
        if (!window.confirm(t('admin.purchase_orders.reverse_confirm', 'Reverse this receipt? Stock will be deducted and received qty reduced.'))) {
            return;
        }
        setReversingId(receiptId);
        router.post(`/admin/purchase-orders/${order.id}/receipts/${receiptId}/reverse`, {}, {
            preserveScroll: true,
            onFinish: () => setReversingId(null),
        });
    };

    const crumbs = [
        { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
        { href: '/admin/purchase-orders', label: t('admin.nav.purchase_orders', 'Purchase orders') },
        { label: isNew ? t('admin.common.create', 'Create') : (order.number || t('admin.common.edit', 'Edit')) },
    ];

    const statusTone = order?.status === 'received'
        ? 'success'
        : order?.status === 'ordered'
            ? 'warning'
            : order?.status === 'cancelled'
                ? 'danger'
                : 'neutral';

    const receiveProgress = computedTotals.qty > 0
        ? Math.min(100, Math.round((computedTotals.received / computedTotals.qty) * 100))
        : 0;

    return (
        <Layout>
            <PageHeader
                title={isNew ? t('admin.purchase_orders.new', 'New purchase order') : order.number}
                crumbs={crumbs}
                className="mb-6"
                actions={(
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={statusTone}>{statusLabel(order?.status || 'draft')}</Badge>
                        {order?.is_overdue ? (
                            <Badge tone="danger">
                                {t('admin.purchase_orders.overdue_days', 'Overdue :days d').replace(':days', String(order.overdue_days || 0))}
                            </Badge>
                        ) : null}
                        {!isNew ? (
                            <>
                                <Button
                                    href={`/admin/purchase-orders/${order.id}/print`}
                                    variant="secondary"
                                    native
                                    target="_blank"
                                    icon="printer"
                                >
                                    {t('admin.purchase_orders.print', 'Print')}
                                </Button>
                                <Button href={`/admin/purchase-orders/${order.id}/pdf`} variant="secondary" native icon="download">
                                    {t('admin.purchase_orders.pdf', 'PDF')}
                                </Button>
                            </>
                        ) : null}
                    </div>
                )}
            />

            <FormErrors className="mb-4" errors={form.errors} />

            <form className="po-form" onSubmit={submit}>
                <div className={ui.formLayout}>
                    <div className={ui.formMain}>
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.purchase_orders.details', 'Order details')}</h2>
                            </div>
                            <div className={ui.fieldGrid}>
                                <Field label={t('admin.purchase_orders.supplier', 'Supplier')}>
                                    <Select
                                        wide
                                        disabled={locked}
                                        value={form.data.supplier_id}
                                        onChange={(e) => form.setData('supplier_id', e.target.value)}
                                    >
                                        <option value="">{t('admin.common.none', 'None')}</option>
                                        {suppliers.map((row) => (
                                            <option key={row.id} value={row.id}>{row.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('admin.nav.warehouses', 'Warehouse')} error={form.errors.warehouse_id} required>
                                    <Select
                                        wide
                                        disabled={locked}
                                        value={form.data.warehouse_id}
                                        onChange={(e) => form.setData('warehouse_id', e.target.value)}
                                    >
                                        <option value="">{t('admin.common.select', 'Select')}</option>
                                        {warehouses.map((row) => (
                                            <option key={row.id} value={row.id}>{row.name} ({row.code})</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label={t('admin.purchase_orders.expected', 'Expected delivery')}>
                                    <Input
                                        wide
                                        type="date"
                                        disabled={locked}
                                        value={form.data.expected_at}
                                        onChange={(e) => form.setData('expected_at', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('admin.common.notes', 'Notes')}>
                                    <Textarea
                                        disabled={locked}
                                        rows={2}
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                        placeholder={t('admin.purchase_orders.notes_placeholder', 'Internal receiving notes')}
                                    />
                                </Field>
                            </div>
                        </section>

                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.purchase_orders.lines', 'Order lines')}</h2>
                                {!locked ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        icon="plus"
                                        onClick={() => form.setData('items', [...form.data.items, emptyLine()])}
                                    >
                                        {t('admin.purchase_orders.add_line', 'Add blank line')}
                                    </Button>
                                ) : null}
                            </div>

                            <ProductSearchPicker
                                disabled={locked}
                                selectedIds={selectedProductIds}
                                onSelect={selectProduct}
                            />

                            <div className={ui.tableWrap}>
                                <table className={ui.table}>
                                    <thead className={ui.thead}>
                                        <tr>
                                            <th className={ui.th}>{t('admin.common.product', 'Product')}</th>
                                            <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.ordered', 'Qty')}</th>
                                            {!isNew ? (
                                                <>
                                                    <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.received_qty', 'Received')}</th>
                                                    <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.remaining', 'Left')}</th>
                                                </>
                                            ) : null}
                                            <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.cost', 'Unit cost')}</th>
                                            {canReceive ? (
                                                <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.receive_qty', 'Receive')}</th>
                                            ) : null}
                                            <th className={cx(ui.th, 'po-col-num')}>{t('admin.purchase_orders.line_total', 'Total')}</th>
                                            {!locked ? <th className={ui.th} /> : null}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {form.data.items.length === 0 ? (
                                            <tr>
                                                <td className={ui.td} colSpan={isNew ? 4 : (canReceive ? 8 : 7)}>
                                                    <div className="po-empty">
                                                        <div className="po-empty__icon">
                                                            <Icon name="package" />
                                                        </div>
                                                        <div>
                                                            <p className="po-empty__title">
                                                                {t('admin.purchase_orders.empty_lines', 'No products yet')}
                                                            </p>
                                                            <p className={ui.muted}>
                                                                {t(
                                                                    'admin.purchase_orders.empty_lines_hint',
                                                                    'Search by name or SKU above to add products to this order.',
                                                                )}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        ) : null}

                                        {form.data.items.map((line, index) => {
                                            const ordered = Number(line.qty_ordered || 0);
                                            const received = Number(line.qty_received || 0);
                                            const remaining = Math.max(0, ordered - received);
                                            const lineTotal = ordered * Number(line.unit_cost || 0);
                                            const hintParts = [
                                                line.supplier_reference
                                                    ? `${t('admin.purchase_orders.supplier_sku', 'Supplier SKU')}: ${line.supplier_reference}`
                                                    : null,
                                                line.wholesale_price != null
                                                    ? `${t('admin.purchase_orders.wholesale', 'Wholesale')}: ${money(line.wholesale_price)}`
                                                    : null,
                                                line.last_purchase_cost != null
                                                    ? `${t('admin.purchase_orders.last_cost', 'Last cost')}: ${money(line.last_purchase_cost)}`
                                                    : null,
                                            ].filter(Boolean);

                                            return (
                                                <tr key={line.id || `new-${index}`} className={ui.tr}>
                                                    <td className={ui.td}>
                                                        <div className={ui.cellStack}>
                                                            <div className={ui.cellStrong}>
                                                                {line.name || line.sku || t('admin.purchase_orders.unnamed', 'Unnamed product')}
                                                            </div>
                                                            <div className={ui.cellSub}>SKU {line.sku || '—'}</div>
                                                            {hintParts.length ? (
                                                                <div className={cx(ui.cellSub, 'po-line-hint')}>{hintParts.join(' · ')}</div>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td className={cx(ui.td, 'po-col-num')}>
                                                        <Input
                                                            narrow
                                                            disabled={locked}
                                                            value={line.qty_ordered}
                                                            onChange={(e) => updateLine(index, { qty_ordered: e.target.value })}
                                                        />
                                                    </td>
                                                    {!isNew ? (
                                                        <>
                                                            <td className={cx(ui.td, 'po-col-num')}>
                                                                <span className="po-metric">{received}</span>
                                                            </td>
                                                            <td className={cx(ui.td, 'po-col-num')}>
                                                                <span className={cx('po-metric', remaining > 0 ? 'po-metric--open' : 'po-metric--done')}>
                                                                    {remaining}
                                                                </span>
                                                            </td>
                                                        </>
                                                    ) : null}
                                                    <td className={cx(ui.td, 'po-col-num')}>
                                                        <Input
                                                            narrow
                                                            disabled={locked}
                                                            value={line.unit_cost}
                                                            onChange={(e) => updateLine(index, { unit_cost: e.target.value })}
                                                        />
                                                    </td>
                                                    {canReceive ? (
                                                        <td className={cx(ui.td, 'po-col-num')}>
                                                            {remaining > 0 ? (
                                                                <Input
                                                                    narrow
                                                                    value={line.receive_qty}
                                                                    placeholder="0"
                                                                    onChange={(e) => {
                                                                        const next = e.target.value;
                                                                        const parsed = Number(next);
                                                                        if (next !== '' && Number.isFinite(parsed) && parsed > remaining) {
                                                                            updateLine(index, { receive_qty: String(remaining) });
                                                                            return;
                                                                        }
                                                                        updateLine(index, { receive_qty: next });
                                                                    }}
                                                                />
                                                            ) : (
                                                                <span className={ui.muted}>—</span>
                                                            )}
                                                        </td>
                                                    ) : null}
                                                    <td className={cx(ui.td, 'po-col-num')}>
                                                        <span className="po-line-total">{money(lineTotal)}</span>
                                                    </td>
                                                    {!locked ? (
                                                        <td className={ui.td}>
                                                            {received <= 0 ? (
                                                                <button
                                                                    type="button"
                                                                    className="po-remove"
                                                                    aria-label={t('admin.common.remove', 'Remove')}
                                                                    onClick={() => form.setData(
                                                                        'items',
                                                                        form.data.items.filter((_, i) => i !== index),
                                                                    )}
                                                                >
                                                                    <Icon name="trash" />
                                                                </button>
                                                            ) : null}
                                                        </td>
                                                    ) : null}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            <FieldError>{form.errors.items}</FieldError>
                        </section>

                        {receipts.length > 0 ? (
                            <section className={ui.sectionCard}>
                                <div className={ui.sectionHead}>
                                    <h2 className={ui.sectionTitle}>{t('admin.purchase_orders.receipts', 'Receiving history')}</h2>
                                </div>
                                <div className="po-receipts">
                                    {receipts.map((receipt) => (
                                        <div key={receipt.id} className="po-receipt">
                                            <div>
                                                <div className="po-receipt__title">
                                                    {receipt.created_at || '—'} · {receipt.sku || '—'} · qty {receipt.qty}
                                                </div>
                                                <p className={ui.muted}>
                                                    {[receipt.warehouse, receipt.admin, receipt.inventory_reference].filter(Boolean).join(' · ') || '—'}
                                                </p>
                                            </div>
                                            <div className="po-receipt__actions">
                                                {receipt.is_final ? (
                                                    <Badge tone="success">{t('admin.purchase_orders.final', 'Final')}</Badge>
                                                ) : null}
                                                {receipt.reversed_at ? (
                                                    <Badge tone="danger">{t('admin.purchase_orders.reversed', 'Reversed')}</Badge>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        busy={reversingId === receipt.id}
                                                        onClick={() => reverseReceipt(receipt.id)}
                                                    >
                                                        {t('admin.purchase_orders.reverse', 'Reverse')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ) : null}
                    </div>

                    <aside className={ui.formSide}>
                        <section className={cx(ui.sectionCard, 'po-summary')}>
                            <h2 className={cx(ui.sectionTitle, 'mb-3')}>
                                {t('admin.purchase_orders.summary', 'Order summary')}
                            </h2>

                            {!isNew && computedTotals.qty > 0 ? (
                                <div className="po-progress" aria-hidden="true">
                                    <div className="po-progress__bar" style={{ width: `${receiveProgress}%` }} />
                                </div>
                            ) : null}

                            <div className={ui.summaryRow}>
                                <span>{t('admin.purchase_orders.line_count', 'Lines')}</span>
                                <span>{computedTotals.lines}</span>
                            </div>
                            <div className={ui.summaryRow}>
                                <span>{t('admin.purchase_orders.ordered_qty', 'Ordered qty')}</span>
                                <span>{computedTotals.qty}</span>
                            </div>
                            {!isNew ? (
                                <>
                                    <div className={ui.summaryRow}>
                                        <span>{t('admin.purchase_orders.received_qty', 'Received')}</span>
                                        <span>{computedTotals.received}</span>
                                    </div>
                                    <div className={ui.summaryRow}>
                                        <span>{t('admin.purchase_orders.remaining', 'Remaining')}</span>
                                        <span>{computedTotals.remaining}</span>
                                    </div>
                                </>
                            ) : null}

                            <div className="po-totals-edit">
                                <Field label={t('admin.purchase_orders.discount_type', 'Discount')}>
                                    <Select
                                        wide
                                        disabled={locked}
                                        value={form.data.discount_type}
                                        onChange={(e) => form.setData('discount_type', e.target.value)}
                                    >
                                        <option value="">{t('admin.common.none', 'None')}</option>
                                        <option value="percent">{t('admin.purchase_orders.percent', 'Percent')}</option>
                                        <option value="fixed">{t('admin.purchase_orders.fixed', 'Fixed')}</option>
                                    </Select>
                                </Field>
                                {form.data.discount_type ? (
                                    <Field label={t('admin.purchase_orders.discount_value', 'Discount value')}>
                                        <Input
                                            wide
                                            disabled={locked}
                                            value={form.data.discount_value}
                                            onChange={(e) => form.setData('discount_value', e.target.value)}
                                        />
                                    </Field>
                                ) : null}
                                <Field label={t('admin.purchase_orders.shipping', 'Shipping')}>
                                    <Input
                                        wide
                                        disabled={locked}
                                        value={form.data.shipping_cost}
                                        onChange={(e) => form.setData('shipping_cost', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <div className={ui.summaryRow}>
                                <span>{t('admin.purchase_orders.subtotal', 'Subtotal')}</span>
                                <span>{money(computedTotals.line_subtotal)}</span>
                            </div>
                            {computedTotals.discount_amount > 0 ? (
                                <div className={ui.summaryRow}>
                                    <span>{t('admin.purchase_orders.discount_amount', 'Discount')}</span>
                                    <span>-{money(computedTotals.discount_amount)}</span>
                                </div>
                            ) : null}
                            {computedTotals.shipping_cost > 0 ? (
                                <div className={ui.summaryRow}>
                                    <span>{t('admin.purchase_orders.shipping', 'Shipping')}</span>
                                    <span>{money(computedTotals.shipping_cost)}</span>
                                </div>
                            ) : null}
                            <div className={ui.summaryGrand}>
                                <span className="font-medium">{t('admin.purchase_orders.grand_total', 'Grand total')}</span>
                                <span className={ui.summaryGrandAmt}>{money(computedTotals.grand_total)}</span>
                            </div>
                            {totals?.grand_total != null && !isNew ? (
                                <p className={cx(ui.muted, 'mt-2 text-xs')}>
                                    {t('admin.purchase_orders.server_total', 'Saved total')}: {money(totals.grand_total)}
                                </p>
                            ) : null}
                        </section>

                        {locked ? (
                            <section className={ui.softAlert}>
                                <Icon name="info" className="mt-0.5 shrink-0" />
                                <span>
                                    {t(
                                        'admin.purchase_orders.locked_hint',
                                        'This purchase order is locked. Create a new order if you need more stock.',
                                    )}
                                </span>
                            </section>
                        ) : null}
                    </aside>
                </div>

                {!locked ? (
                    <div className="po-sticky-bar">
                        <div className="po-sticky-bar__inner">
                            {!isNew && !(order.items || []).some((row) => Number(row.qty_received || 0) > 0) ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        if (window.confirm(t('admin.purchase_orders.cancel_confirm', 'Cancel this purchase order?'))) {
                                            router.post(`/admin/purchase-orders/${order.id}/cancel`);
                                        }
                                    }}
                                >
                                    {t('admin.common.cancel', 'Cancel order')}
                                </Button>
                            ) : (
                                <span />
                            )}
                            <div className="po-sticky-bar__actions">
                                {!isNew && order.status === 'draft' ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => router.post(`/admin/purchase-orders/${order.id}/ordered`)}
                                    >
                                        {t('admin.purchase_orders.mark_ordered', 'Mark ordered')}
                                    </Button>
                                ) : null}
                                {canReceive ? (
                                    <>
                                        <Button type="button" variant="secondary" busy={receiving} onClick={() => receive('partial')}>
                                            {t('admin.purchase_orders.receive_partial', 'Receive selected')}
                                        </Button>
                                        <Button type="button" variant="secondary" busy={receiving} onClick={() => receive('all')}>
                                            {t('admin.purchase_orders.receive_all', 'Receive all remaining')}
                                        </Button>
                                    </>
                                ) : null}
                                <Button icon="check" busy={form.processing} type="submit">
                                    {t('admin.common.save', 'Save')}
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : null}
            </form>
        </Layout>
    );
}
