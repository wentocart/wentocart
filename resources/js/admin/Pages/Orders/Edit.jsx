import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    Field,
    FieldError,
    FormErrors,
    Icon,
    Input,
    ModuleActionButtons,
    PageHeader,
    PortalMenu,
    RowActions,
    Select,
    Textarea,
    money,
    ui,
    cx,
    t,
} from '../../ui';
import { catalogImageUrl } from '../../lib/catalogMedia';

function initials(name) {
    return (name || 'C')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

function localDateTime() {
    const date = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function lineMath(item) {
    const line = Number(item.qty || 0) * Number(item.unit_price || 0);
    const discount = Number(item.discount || 0);
    const discountAmount = item.discount_type === 'amount'
        ? Math.min(discount, line)
        : line * (discount / 100);
    const taxable = Math.max(0, line - discountAmount);
    const tax = taxable * (Number(item.tax_rate || 0) / 100);

    return { line, discountAmount, tax, total: taxable + tax };
}

function addressName(address) {
    return [address?.first_name, address?.last_name].filter(Boolean).join(' ').trim();
}

function addressOptionLabel(address, choices) {
    const name = addressName(address)
        || (address?.label && address.label !== 'Address' ? address.label : '')
        || address?.city
        || address?.line1
        || t('admin.orders.address', 'Address');
    const sameName = (choices || []).filter((item) => {
        const other = addressName(item)
            || (item?.label && item.label !== 'Address' ? item.label : '')
            || item?.city
            || item?.line1
            || t('admin.orders.address', 'Address');

        return other === name;
    });
    if (sameName.length < 2) {
        return name;
    }
    const extra = [address?.city, address?.postal_code].filter(Boolean).join(' ');

    return extra ? `${name} · ${extra}` : name;
}

function formatAddress(address) {
    if (!address) {
        return '';
    }
    const lines = [
        addressName(address),
        address.line1,
        address.line2,
        [address.city, address.state, address.postal_code].filter(Boolean).join(', '),
        address.country_code,
        address.phone ? `${t('admin.orders.phone', 'Phone')}: ${address.phone}` : null,
    ].filter(Boolean);

    return lines.join('\n');
}

function addressKey(address) {
    if (!address) {
        return '';
    }
    if (address.id) {
        return String(address.id);
    }

    return [address.line1, address.city, address.postal_code].filter(Boolean).join('|');
}

function toAddress(source = {}) {
    return {
        id: source.id || null,
        first_name: source.first_name || '',
        last_name: source.last_name || '',
        phone: source.phone || '',
        line1: source.line1 || '',
        line2: source.line2 || '',
        city: source.city || '',
        state: source.state || '',
        postal_code: source.postal_code || '',
        country_code: source.country_code || 'IN',
    };
}

function statusLabel(status) {
    if (status === 'completed') {
        return t('admin.orders.delivered', 'Delivered');
    }
    if (status === 'partial') {
        return t('admin.orders.partial_payment', 'Partial');
    }
    if (status === 'partially_refunded') {
        return t('admin.orders.partially_refunded', 'Partially refunded');
    }

    return (status || 'pending').replace(/^\w/, (c) => c.toUpperCase());
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
        partial: 'warning',
        partially_refunded: 'warning',
        failed: 'danger',
    };

    return map[status] || 'warning';
}

function splitDateTime(value) {
    if (!value) {
        return { date: '', time: '' };
    }
    const [date, time] = String(value).split('T');

    return { date: date || '', time: (time || '').slice(0, 5) };
}

function formatStamp(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} ${date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`;
}

let rowSeed = 1;

export default function Edit({
    order,
    customers = [],
    paymentMethods = [],
    shippingMethods = [],
    taxClasses = [],
    currencies = [],
    stages = [],
    paymentStatuses = [],
    salesChannels = [],
    orderActionSlots = [],
    order_pdf = true,
    invoices_enabled = true,
}) {
    const { flash } = usePage().props;
    const appliedCustomer = useRef(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [productQuery, setProductQuery] = useState('');
    const [productResults, setProductResults] = useState([]);
    const [productLoading, setProductLoading] = useState(false);
    const [showNewCustomer, setShowNewCustomer] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const moreActionsRef = useRef(null);
    const [refundOpen, setRefundOpen] = useState(false);
    const [issueVoucher, setIssueVoucher] = useState(false);
    const [issueCredit, setIssueCredit] = useState(false);
    const [restock, setRestock] = useState(false);
    const [refundBusy, setRefundBusy] = useState(false);
    const [refundQtys, setRefundQtys] = useState({});
    const [newCustomer, setNewCustomer] = useState({
        name: '',
        email: '',
        phone: '',
        line1: '',
        city: '',
        state: '',
        postal_code: '',
        country_code: 'IN',
    });
    const refunds = order.refunds || [];
    const hasCreditSlip = !!(order.has_credit_slip || Number(order.refunded_total || 0) > 0);

    const taxOptions = useMemo(() => {
        const options = [{ name: 'No Tax', rate: 0 }, { name: 'GST (18%)', rate: 18 }];
        taxClasses.forEach((tax) => {
            if (!options.some((option) => option.rate === Number(tax.rate))) {
                options.push({ name: `${tax.name} (${Number(tax.rate)}%)`, rate: Number(tax.rate) });
            }
        });

        return options;
    }, [taxClasses]);

    const defaultCurrency = currencies.find((item) => item.code === 'INR')?.code
        || currencies.find((item) => item.is_default)?.code
        || currencies[0]?.code
        || 'INR';

    const form = useForm({
        customer_id: order.customer_id || '',
        email: order.email || '',
        status: order.status || 'pending',
        billing_address: toAddress(order.billing_address),
        shipping_address: toAddress(order.shipping_address || order.billing_address),
        items: (order.items || []).map((item) => ({
            ...item,
            key: item.key || rowSeed++,
            discount: item.discount || 0,
            discount_type: item.discount_type || 'percent',
        })),
        payment_method_id: order.payment_method_id || '',
        shipping_method_id: order.shipping_method_id || '',
        currency_code: order.currency_code || defaultCurrency,
        customer_note: order.customer_note || '',
        order_stage_id: order.order_stage_id || stages.find((stage) => stage.code === order.status)?.id || '',
        payment_status: order.payment_status || 'pending',
        payment_status_id: order.payment_status_id
            || paymentStatuses.find((row) => row.code === (order.payment_status || 'pending'))?.id
            || '',
        order_discount: order.order_discount || 0,
        order_discount_type: order.order_discount_type || 'amount',
        shipping_total: order.shipping_total ?? 0,
        created_at: order.created_at || localDateTime(),
        sales_channel_id: order.sales_channel_id
            || salesChannels.find((row) => row.code === (order.channel || 'web'))?.id
            || '',
    });

    const selectedPaymentStatus = paymentStatuses.find((row) => String(row.id) === String(form.data.payment_status_id));
    const paymentStatusCode = selectedPaymentStatus?.code || form.data.payment_status || 'pending';

    const selectedCustomer = customers.find((customer) => String(customer.id) === String(form.data.customer_id));
    const shipping = shippingMethods.find((method) => String(method.id) === String(form.data.shipping_method_id));
    const paymentMethod = paymentMethods.find((method) => String(method.id) === String(form.data.payment_method_id));
    const createdParts = splitDateTime(form.data.created_at);
    const noteLength = (form.data.customer_note || '').length;

    const addressChoices = (current) => {
        const list = [...(selectedCustomer?.addresses || [])];
        const currentKey = addressKey(current);
        if (current?.line1 && !list.some((item) => addressKey(item) === currentKey)) {
            list.unshift({ ...current, id: current.id || 'current' });
        }

        return list;
    };

    const billingChoices = addressChoices(form.data.billing_address);
    const shippingChoices = addressChoices(form.data.shipping_address);

    const totals = useMemo(() => {
        const next = form.data.items.reduce((acc, item) => {
            const math = lineMath(item);
            acc.subtotal += math.line;
            acc.discount += math.discountAmount;
            acc.tax += math.tax;
            acc.qty += Number(item.qty || 0);
            if (item.tax_rate) {
                acc.rates.add(Number(item.tax_rate));
            }

            return acc;
        }, { subtotal: 0, discount: 0, tax: 0, qty: 0, rates: new Set() });

        const afterLine = Math.max(0, next.subtotal - next.discount);
        const extra = Number(form.data.order_discount || 0);
        next.discount += form.data.order_discount_type === 'percent'
            ? afterLine * (extra / 100)
            : Math.min(extra, afterLine);

        const shippingTotal = form.data.shipping_total === '' || form.data.shipping_total === null
            ? Number(shipping?.price || 0)
            : Number(form.data.shipping_total || 0);
        next.shipping = shippingTotal;
        next.grand = next.subtotal - next.discount + next.tax + shippingTotal;
        next.taxRate = next.rates.size === 1 ? [...next.rates][0] : null;

        return next;
    }, [form.data.items, form.data.order_discount, form.data.order_discount_type, form.data.shipping_total, shipping]);

    const refundedTotal = Number(order.refunded_total || 0);
    const amountPaid = Number(order.amount_paid ?? 0);
    const amountDue = Number(order.amount_due ?? Math.max(0, Number(order.grand_total || 0) - amountPaid));
    const paidTotal = amountPaid > 0
        ? amountPaid
        : (['paid', 'partially_refunded', 'refunded'].includes(form.data.payment_status)
            ? Number(order.paid_total || order.grand_total || totals.grand)
            : 0);
    const balance = amountDue > 0 ? amountDue : Math.max(0, Number(order.grand_total || totals.grand) - paidTotal);
    const hasBalanceDue = amountDue > 0.009 || form.data.payment_status === 'partial' || form.data.payment_status === 'pending' || form.data.payment_status === 'failed';

    const applyCustomer = (customer) => {
        if (!customer) {
            form.setData((current) => ({
                ...current,
                customer_id: '',
                email: '',
                billing_address: toAddress(),
                shipping_address: toAddress(),
            }));

            return;
        }

        const address = customer.addresses?.[0] || {};
        const parts = (customer.name || '').split(' ');
        const nextAddress = toAddress({
            ...address,
            first_name: address.first_name || parts[0] || customer.name,
            last_name: address.last_name || parts.slice(1).join(' '),
            phone: address.phone || customer.phone || '',
        });
        form.setData((current) => ({
            ...current,
            customer_id: customer.id,
            email: customer.email,
            billing_address: nextAddress,
            shipping_address: nextAddress,
        }));
    };

    useEffect(() => {
        if (!flash?.customer_id || appliedCustomer.current === flash.customer_id) {
            return;
        }
        const customer = customers.find((item) => String(item.id) === String(flash.customer_id));
        if (customer) {
            appliedCustomer.current = flash.customer_id;
            applyCustomer(customer);
            setShowNewCustomer(false);
        }
    }, [flash?.customer_id, customers]);

    const addProduct = (product) => {
        const existing = form.data.items.find((item) => String(item.product_id) === String(product.id));
        if (existing) {
            form.setData('items', form.data.items.map((item) => (
                item.key === existing.key ? { ...item, qty: Number(item.qty) + 1 } : item
            )));
            return;
        }

        const taxRate = Number(product.tax_rate || taxOptions.find((option) => option.rate === 18)?.rate || 0);
        form.setData('items', [
            ...form.data.items,
            {
                key: rowSeed++,
                product_id: product.id,
                name: product.name,
                sku: product.sku,
                unit_price: Number(product.price),
                qty: 1,
                tax_rate: taxRate,
                discount: 0,
                discount_type: 'percent',
                cover_path: product.cover_path,
                stock_status: product.stock_status,
            },
        ]);
    };

    useEffect(() => {
        if (!pickerOpen) {
            return undefined;
        }
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setPickerOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [pickerOpen]);

    useEffect(() => {
        if (!pickerOpen) {
            return undefined;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setProductLoading(true);
            try {
                const query = productQuery.trim();
                const response = await fetch(`/admin/orders/products?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await response.json();
                setProductResults(payload.products || []);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setProductResults([]);
                }
            } finally {
                setProductLoading(false);
            }
        }, productQuery ? 250 : 0);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [pickerOpen, productQuery]);

    const addCustomItem = () => {
        form.setData('items', [
            ...form.data.items,
            {
                key: rowSeed++,
                product_id: null,
                name: 'Custom item',
                sku: 'CUSTOM',
                unit_price: 0,
                qty: 1,
                tax_rate: 0,
                discount: 0,
                discount_type: 'percent',
                custom: true,
            },
        ]);
    };

    const updateItem = (key, patch) => {
        form.setData('items', form.data.items.map((item) => (
            item.key === key ? { ...item, ...patch } : item
        )));
    };

    const removeItem = (key) => {
        form.setData('items', form.data.items.filter((item) => item.key !== key));
        setMenuId(null);
    };

    const chooseAddress = (id, field) => {
        const address = addressChoices(form.data[field]).find((item) => String(item.id) === String(id) || addressKey(item) === String(id));
        if (!address) {
            return;
        }
        form.setData(field, toAddress(address));
    };

    const setCreatedPart = (part, value) => {
        const next = { ...createdParts, [part]: value };
        form.setData('created_at', next.date && next.time ? `${next.date}T${next.time}` : next.date);
    };

    const save = () => {
        form.transform((data) => ({
            ...data,
            order_stage_id: data.order_stage_id ? Number(data.order_stage_id) : null,
            payment_status_id: data.payment_status_id ? Number(data.payment_status_id) : null,
            sales_channel_id: data.sales_channel_id ? Number(data.sales_channel_id) : null,
            payment_method_id: data.payment_method_id || null,
            shipping_method_id: data.shipping_method_id || null,
            shipping_address: data.shipping_address?.line1 ? data.shipping_address : data.billing_address,
        }));
        form.put(`/admin/orders/${order.id}`, {
            preserveScroll: true,
            onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
        });
    };

    const refundableItems = useMemo(() => (
        (order.items || [])
            .filter((item) => item.id && Number(item.returnable_qty ?? item.qty ?? 0) > 0)
            .map((item) => ({
                id: item.id,
                name: item.name,
                sku: item.sku,
                qty: Number(item.qty || 0),
                returnable: Number(item.returnable_qty ?? item.qty ?? 0),
                lineTotal: Number(item.line_total ?? ((Number(item.unit_price) || 0) * (Number(item.qty) || 0))),
            }))
    ), [order.items]);

    const selectedRefundLines = useMemo(() => (
        refundableItems
            .map((item) => {
                const qty = Math.min(Number(refundQtys[item.id] || 0), item.returnable);
                if (qty <= 0) {
                    return null;
                }
                const share = item.qty > 0 ? (item.lineTotal * (qty / item.qty)) : 0;

                return { order_item_id: item.id, qty, share };
            })
            .filter(Boolean)
    ), [refundableItems, refundQtys]);

    const selectedRefundTotal = useMemo(
        () => Math.round(selectedRefundLines.reduce((sum, line) => sum + line.share, 0) * 100) / 100,
        [selectedRefundLines],
    );

    const openRefund = () => {
        if (! ['paid', 'partial', 'partially_refunded'].includes(form.data.payment_status)) {
            return;
        }
        setIssueVoucher(false);
        setIssueCredit(false);
        setRestock(false);
        setRefundQtys({});
        setRefundOpen(true);
    };

    const setItemRefundQty = (itemId, qty, returnable) => {
        const nextQty = Math.max(0, Math.min(returnable, Number(qty) || 0));
        setRefundQtys((prev) => ({ ...prev, [itemId]: nextQty }));
    };

    const confirmRefund = () => {
        if (! ['paid', 'partial', 'partially_refunded'].includes(form.data.payment_status) || refundBusy) {
            return;
        }
        if (selectedRefundLines.length === 0) {
            return;
        }
        setRefundBusy(true);
        router.post(`/admin/orders/${order.id}/refund`, {
            issue_voucher: issueVoucher && !!form.data.customer_id,
            issue_credit: issueCredit && !!form.data.customer_id,
            restock,
            items: selectedRefundLines.map(({ order_item_id, qty }) => ({ order_item_id, qty })),
        }, {
            preserveScroll: false,
            preserveState: false,
            onFinish: () => {
                setRefundBusy(false);
                setRefundOpen(false);
                setIssueVoucher(false);
                setIssueCredit(false);
                setRestock(false);
                setRefundQtys({});
            },
        });
    };

    const submitCustomer = (event) => {
        event.preventDefault();
        router.post('/admin/orders/customers', newCustomer, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setNewCustomer({
                    name: '',
                    email: '',
                    phone: '',
                    line1: '',
                    city: '',
                    state: '',
                    postal_code: '',
                    country_code: 'IN',
                });
            },
        });
    };

    return (
        <Layout>
            <PageHeader
                title={(
                    <span className="inline-flex items-center gap-3">
                        {t('admin.orders.edit_order', 'Edit Order')} #{order.number}
                        <Badge tone={statusTone(form.data.payment_status)}>{statusLabel(form.data.payment_status)}</Badge>
                    </span>
                )}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { href: `/admin/orders/${order.id}`, label: `#${order.number}` },
                    { label: t('admin.orders.edit', 'Edit') },
                ]}
                actions={(
                    <>
                        <ModuleActionButtons slots={orderActionSlots} />
                        <div className="relative" ref={moreActionsRef}>
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => setMoreOpen((open) => !open)}
                            >
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
                                {form.data.payment_status === 'paid' && invoices_enabled ? (
                                    <>
                                        <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/invoice`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.orders.view_invoice', 'View Invoice')}
                                        </Link>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/invoice/download`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.orders.download_invoice', 'Download Invoice')}
                                        </a>
                                    </>
                                ) : null}
                                {form.data.payment_status === 'paid' && !invoices_enabled ? (
                                    <Link className={ui.popItem} role="menuitem" href="/admin/order-settings?tab=invoices" onClick={() => setMoreOpen(false)}>
                                        {t('admin.orders.invoices_off', 'Invoices are turned off')}
                                    </Link>
                                ) : null}
                                {hasCreditSlip ? (
                                    <>
                                        <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/credit-slip`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.credit_slip', 'View Credit Slip')}
                                        </Link>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/credit-slip/download`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.credit_slip', 'Download Credit Slip')}
                                        </a>
                                    </>
                                ) : null}
                                {['shipped', 'completed'].includes(form.data.status) ? (
                                    <>
                                        <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/delivery-slip`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.delivery_slip', 'View Delivery Slip')}
                                        </Link>
                                        <a className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}/delivery-slip/download`} onClick={() => setMoreOpen(false)}>
                                            {t('admin.nav.delivery_slip', 'Download Delivery Slip')}
                                        </a>
                                    </>
                                ) : null}
                                <Link className={ui.popItem} role="menuitem" href={`/admin/orders/${order.id}`} onClick={() => setMoreOpen(false)}>
                                    {t('admin.orders.view_order', 'View Order')}
                                </Link>
                            </PortalMenu>
                        </div>
                        <Button variant="secondary" href={`/admin/orders/${order.id}`}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="button" icon="check" onClick={save} busy={form.processing}>
                            {t('admin.orders.save_changes', 'Save Changes')}
                        </Button>
                    </>
                )}
            />

            <FormErrors className="mb-4" errors={form.errors} />

            <div className={ui.formLayout}>
                <div className={ui.formMain}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.orders.customer', 'Customer')}</h2>
                        </div>
                        <label className={ui.label}>{t('admin.orders.select_customer', 'Select Customer')}<span className={ui.req}>*</span></label>
                        <div className={cx(ui.row, 'mt-1 mb-3')}>
                            <Select
                                className="flex-1"
                                value={form.data.customer_id}
                                onChange={(event) => applyCustomer(customers.find((item) => String(item.id) === event.target.value))}
                            >
                                <option value="">{t('admin.orders.search_or_select_a_customer', 'Search or select a customer')}</option>
                                {customers.map((customer) => (
                                    <option key={customer.id} value={customer.id}>{customer.name} ({customer.email})</option>
                                ))}
                            </Select>
                            <Button type="button" variant="secondary" icon="plus" onClick={() => setShowNewCustomer(true)} aria-label={t('admin.orders.add_customer', 'Add customer')} />
                        </div>
                        <FieldError>{form.errors.customer_id}</FieldError>

                        <div className={cx(ui.fieldGrid, 'mt-3')}>
                            <Field label={t('admin.orders.email', 'Email')} error={form.errors.email}>
                                <Input
                                    wide
                                    type="email"
                                    invalid={!!form.errors.email}
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
            </Field>
                            <Field label={t('admin.orders.phone', 'Phone')} error={form.errors['billing_address.phone']}>
                                <Input
                                    wide
                                    invalid={!!form.errors['billing_address.phone']}
                                    value={form.data.billing_address.phone || ''}
                                    onChange={(e) => form.setData('billing_address', {
                                        ...form.data.billing_address,
                                        phone: e.target.value,
                                    })}
                                />
                            </Field>
                        </div>

                        <button type="button" className={cx(ui.btnLink, 'mt-3 text-sm')} onClick={() => setShowNewCustomer((open) => !open)}>
                            + Add New Customer
                        </button>

                        {showNewCustomer ? (
                            <form className={cx(ui.fieldGrid, 'mt-4')} onSubmit={submitCustomer}>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.name', 'Name')}<span className={ui.req}>*</span></label>
                                    <Input wide className="mt-1" value={newCustomer.name} onChange={(e) => setNewCustomer({ ...newCustomer, name: e.target.value })} required />
                                </div>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.email', 'Email')}<span className={ui.req}>*</span></label>
                                    <Input wide className="mt-1" type="email" value={newCustomer.email} onChange={(e) => setNewCustomer({ ...newCustomer, email: e.target.value })} required />
                                </div>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.phone', 'Phone')}</label>
                                    <Input wide className="mt-1" value={newCustomer.phone} onChange={(e) => setNewCustomer({ ...newCustomer, phone: e.target.value })} />
                                </div>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.address', 'Address')}<span className={ui.req}>*</span></label>
                                    <Input wide className="mt-1" value={newCustomer.line1} onChange={(e) => setNewCustomer({ ...newCustomer, line1: e.target.value })} required />
                                </div>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.city', 'City')}<span className={ui.req}>*</span></label>
                                    <Input wide className="mt-1" value={newCustomer.city} onChange={(e) => setNewCustomer({ ...newCustomer, city: e.target.value })} required />
                                </div>
                                <div>
                                    <label className={ui.label}>{t('admin.orders.postal_code', 'Postal code')}</label>
                                    <Input wide className="mt-1" value={newCustomer.postal_code} onChange={(e) => setNewCustomer({ ...newCustomer, postal_code: e.target.value })} />
                                </div>
                                <div className="sm:col-span-2">
                                    <Button type="submit" variant="sm" icon="check">{t('admin.orders.save_customer', 'Save Customer')}</Button>
                                </div>
                            </form>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.orders.addresses', 'Addresses')}</h2>
                        <div className={ui.fieldGrid}>
                            <div>
                                <label className={ui.label}>{t('admin.orders.billing_address', 'Billing Address')}</label>
                                <Select
                                    wide
                                    className="mt-1"
                                    value={addressKey(form.data.billing_address)}
                                    onChange={(event) => chooseAddress(event.target.value, 'billing_address')}
                                >
                                    {billingChoices.map((address) => (
                                        <option key={addressKey(address)} value={address.id || addressKey(address)}>
                                            {addressOptionLabel(address, billingChoices)}
                                        </option>
                                    ))}
                                </Select>
                                {formatAddress(form.data.billing_address) ? (
                                    <div className={ui.addressPreview}>{formatAddress(form.data.billing_address)}</div>
                                ) : null}
                                <FieldError>{form.errors['billing_address.line1']}</FieldError>
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.shipping_address', 'Shipping Address')}</label>
                                <Select
                                    wide
                                    className="mt-1"
                                    value={addressKey(form.data.shipping_address)}
                                    onChange={(event) => chooseAddress(event.target.value, 'shipping_address')}
                                >
                                    {shippingChoices.map((address) => (
                                        <option key={addressKey(address)} value={address.id || addressKey(address)}>
                                            {addressOptionLabel(address, shippingChoices)}
                                        </option>
                                    ))}
                                </Select>
                                {formatAddress(form.data.shipping_address) ? (
                                    <div className={ui.addressPreview}>{formatAddress(form.data.shipping_address)}</div>
                                ) : null}
                            </div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.orders.order_items', 'Order Items')}</h2>
                            <div className={ui.toolbar}>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setPickerOpen(true);
                                        setProductQuery('');
                                    }}
                                >
                                    {t('admin.orders.add_product', 'Add Product')}
                                </Button>
                                <Button type="button" variant="secondary" onClick={addCustomItem}>{t('admin.orders.add_custom_item', 'Add Custom Item')}</Button>
                            </div>
                        </div>

                        <div className={ui.tableWrap}>
                            <table className={ui.table}>
                                <thead className={ui.thead}>
                                    <tr>
                                        <th className={ui.th}>{t('admin.orders.product', 'Product')}</th>
                                        <th className={ui.th}>{t('admin.orders.price', 'Price')}</th>
                                        <th className={ui.th}>{t('admin.orders.qty', 'Qty')}</th>
                                        <th className={ui.th}>{t('admin.orders.tax', 'Tax')}</th>
                                        <th className={ui.th}>{t('admin.orders.discount', 'Discount')}</th>
                                        <th className={ui.th}>{t('admin.orders.total', 'Total')}</th>
                                        <th className={ui.th} />
                                    </tr>
                                </thead>
                                <tbody>
                                    {form.data.items.length === 0 ? (
                                        <tr>
                                            <td className={ui.td} colSpan={7}>
                                                <div className={ui.empty}>{t('admin.orders.add_a_product_or_custom_item_to_this_order', 'Add a product or custom item to this order.')}</div>
                                            </td>
                                        </tr>
                                    ) : null}
                                    {form.data.items.map((item) => {
                                        const math = lineMath(item);

                                        return (
                                            <tr key={item.key} className={ui.tr}>
                                                <td className={ui.td}>
                                                    <div className={ui.row}>
                                                        {item.cover_path ? (
                                                            <img className={ui.thumb} src={catalogImageUrl(item.cover_path, 'sm')} alt="" />
                                                        ) : (
                                                            <Avatar name={item.name} />
                                                        )}
                                                        <div className={ui.cellStack}>
                                                            {item.custom ? (
                                                                <Input compact value={item.name} onChange={(e) => updateItem(item.key, { name: e.target.value })} />
                                                            ) : (
                                                                <div className={ui.cellStrong}>{item.name}</div>
                                                            )}
                                                            <div className={ui.cellSub}>{item.sku}</div>
                                                            {item.stock_status !== 'out_of_stock' && !item.custom ? <div className={ui.cellSub}>{t('admin.orders.in_stock', 'In Stock')}</div> : null}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className={ui.td}>
                                                    <Input
                                                        narrow
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={item.unit_price}
                                                        onChange={(e) => updateItem(item.key, { unit_price: e.target.value })}
                                                    />
                                                </td>
                                                <td className={ui.td}>
                                                    <div className={ui.qtyControl}>
                                                        <button type="button" className={ui.qtyBtn} onClick={() => updateItem(item.key, { qty: Math.max(1, Number(item.qty) - 1) })}>−</button>
                                                        <span className={ui.qtyValue}>{item.qty}</span>
                                                        <button type="button" className={ui.qtyBtn} onClick={() => updateItem(item.key, { qty: Number(item.qty) + 1 })}>+</button>
                                                    </div>
                                                </td>
                                                <td className={ui.td}>
                                                    <Select
                                                        value={item.tax_rate}
                                                        onChange={(e) => updateItem(item.key, { tax_rate: Number(e.target.value) })}
                                                    >
                                                        {taxOptions.map((tax) => (
                                                            <option key={tax.name} value={tax.rate}>{tax.name}</option>
                                                        ))}
                                                    </Select>
                                                </td>
                                                <td className={ui.td}>
                                                    <div className={ui.row}>
                                                        <Input
                                                            narrow
                                                            type="number"
                                                            min="0"
                                                            value={item.discount}
                                                            onChange={(e) => updateItem(item.key, { discount: e.target.value })}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            onClick={() => updateItem(item.key, { discount_type: item.discount_type === 'percent' ? 'amount' : 'percent' })}
                                                        >
                                                            {item.discount_type === 'percent' ? '%' : form.data.currency_code}
                                                        </Button>
                                                    </div>
                                                </td>
                                                <td className={ui.td}>{money(math.total, form.data.currency_code)}</td>
                                                <td className={ui.td}>
                                                    <RowActions
                                                        canEdit={false}
                                                        canView={false}
                                                        onDelete={() => removeItem(item.key)}
                                                        deleteLabel={t('admin.common.remove', 'Remove')}
                                                        confirmMessage={t('admin.orders.remove_item_confirm', 'Remove this item from the order?')}
                                                    />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <FieldError>{form.errors.items}</FieldError>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.orders.add_order_note', 'Add Order Note')}</h2>
                        <div className={ui.textareaCountWrap}>
                            <Textarea
                                className="min-h-28 pb-7"
                                maxLength={500}
                                value={form.data.customer_note}
                                onChange={(e) => form.setData('customer_note', e.target.value)}
                                placeholder={t('admin.orders.add_a_note_for_internal_reference', 'Add a note for internal reference...')}
                            />
                            <span className={ui.textareaCount}>{noteLength} / 500</span>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.orders.payments', 'Payments')}</h2>
                        <div className={ui.payStats}>
                            <div>
                                <div className={ui.metaK}>{t('admin.orders.total_paid', 'Total Paid')}</div>
                                <div className={ui.payStatPaid}>{money(paidTotal, form.data.currency_code)}</div>
                            </div>
                            <div>
                                <div className={ui.metaK}>{t('admin.orders.total_refunded', 'Total Refunded')}</div>
                                <div className={ui.payStatRefund}>{money(refundedTotal, form.data.currency_code)}</div>
                            </div>
                            <div>
                                <div className={ui.metaK}>{t('admin.orders.balance_due', 'Balance Due')}</div>
                                <div className={ui.payStatBalance}>{money(balance, form.data.currency_code)}</div>
                            </div>
                        </div>
                        {hasBalanceDue && balance > 0.009 ? (
                            <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                {t(
                                    'admin.orders.balance_due_hint',
                                    'This order has an unpaid balance (for example after adding products). Send a pay link or mark the balance as paid.',
                                )}
                            </div>
                        ) : null}
                        <div className={ui.tableWrap}>
                            <table className={ui.table}>
                                <thead className={ui.thead}>
                                    <tr>
                                        <th className={ui.th}>{t('admin.orders.date', 'Date')}</th>
                                        <th className={ui.th}>{t('admin.orders.method', 'Method')}</th>
                                        <th className={ui.th}>{t('admin.orders.transaction_id', 'Transaction ID')}</th>
                                        <th className={ui.th}>{t('admin.orders.amount', 'Amount')}</th>
                                        <th className={ui.th}>{t('admin.orders.status', 'Status')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className={ui.tr}>
                                        <td className={ui.td}>{formatStamp(form.data.created_at)}</td>
                                        <td className={ui.td}>{paymentMethod?.name || '—'}</td>
                                        <td className={ui.td}>{`TXN${String(order.number).replace(/\D/g, '') || order.id}`}</td>
                                        <td className={ui.td}>{money(paidTotal || totals.grand, form.data.currency_code)}</td>
                                        <td className={ui.td}>
                                            <Badge tone={statusTone(form.data.payment_status)}>{statusLabel(form.data.payment_status)}</Badge>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            {balance > 0.009 && form.data.payment_status !== 'refunded' ? (
                                <>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => router.post(`/admin/orders/${order.id}/pay-link`)}
                                    >
                                        {t('admin.orders.send_pay_link', 'Email pay link')}
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={() => router.post(`/admin/orders/${order.id}/record-payment`, { mark_paid: true })}
                                    >
                                        {t('admin.orders.mark_balance_paid', 'Mark balance paid')}
                                    </Button>
                                </>
                            ) : null}
                            <Button type="button" variant="secondary" onClick={openRefund} disabled={!['paid', 'partial', 'partially_refunded'].includes(form.data.payment_status) || refundableItems.length === 0}>
                                {t('admin.orders.refund_payment', 'Refund Payment')}
                            </Button>
                        </div>
                    </section>

                    {(hasCreditSlip || refunds.length > 0) ? (
                        <section className={ui.sectionCard}>
                            <h2 className={ui.sectionTitle}>{t('admin.orders.refund_documents', 'Refund documents')}</h2>
                            <div className="space-y-3">
                                {hasCreditSlip ? (
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 px-4 py-3">
                                        <div>
                                            <div className="font-medium text-neutral-900">{t('admin.nav.credit_slip', 'Credit Slip')}</div>
                                            <div className="text-sm text-neutral-500">
                                                {t('admin.orders.total_refunded', 'Total Refunded')}: {money(refundedTotal, form.data.currency_code)}
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
                                                {t('admin.orders.refund', 'Refund')} · {money(row.amount, form.data.currency_code)}
                                            </div>
                                            <div className="text-sm text-neutral-500">{formatStamp(row.at)}</div>
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
                </div>

                <aside className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-3')}>{t('admin.orders.order_summary', 'Order Summary')}</h2>
                        <div className={ui.summaryRow}>
                            <span>{t('admin.orders.subtotal', 'Subtotal')} ({totals.qty} {totals.qty === 1 ? 'item' : 'items'})</span>
                            <span>{money(totals.subtotal, form.data.currency_code)}</span>
                        </div>
                        <div className={cx(ui.summaryRow, 'items-center')}>
                            <span>{t('admin.orders.discount', 'Discount')}</span>
                            <div className={ui.row}>
                                <Input
                                    narrow
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.order_discount}
                                    onChange={(e) => form.setData('order_discount', e.target.value)}
                                />
                                <Select
                                    value={form.data.order_discount_type}
                                    onChange={(e) => form.setData('order_discount_type', e.target.value)}
                                >
                                    <option value="percent">%</option>
                                    <option value="amount">{form.data.currency_code}</option>
                                </Select>
                            </div>
                        </div>
                        <div className={cx(ui.summaryRow, 'items-center')}>
                            <span>{t('admin.orders.shipping', 'Shipping')}</span>
                            <Input
                                narrow
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.shipping_total}
                                onChange={(e) => form.setData('shipping_total', e.target.value)}
                            />
                        </div>
                        <div className={ui.summaryRow}>
                            <span>{totals.taxRate != null ? `${t('admin.orders.tax', 'Tax')} (${totals.taxRate}%)` : t('admin.orders.tax', 'Tax')}</span>
                            <span>{money(totals.tax, form.data.currency_code)}</span>
                        </div>
                        <div className={ui.summaryGrand}>
                            <span className="font-medium">{t('admin.orders.order_total', 'Order Total')}</span>
                            <span className={ui.summaryGrandAmt}>{money(totals.grand, form.data.currency_code)}</span>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-3')}>{t('admin.orders.order_settings', 'Order Settings')}</h2>
                        <label className={ui.label}>{t('admin.orders.order_status', 'Order Status')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.order_stage_id}
                            onChange={(e) => form.setData('order_stage_id', e.target.value)}
                        >
                            {stages.map((stage) => (
                                <option key={stage.id} value={stage.id}>{stage.name || statusLabel(stage.code)}</option>
                            ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.payment_status', 'Payment Status')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.payment_status_id}
                            onChange={(e) => {
                                const nextId = e.target.value;
                                const next = paymentStatuses.find((row) => String(row.id) === String(nextId));
                                form.setData({
                                    ...form.data,
                                    payment_status_id: nextId,
                                    payment_status: next?.code || form.data.payment_status,
                                });
                            }}
                        >
                            {paymentStatuses
                                .filter((row) => !['partially_refunded'].includes(row.code) || row.code === paymentStatusCode)
                                .map((row) => (
                                    <option key={row.id} value={row.id}>{row.name}</option>
                                ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.fulfillment_status', 'Fulfillment Status')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.order_stage_id}
                            onChange={(e) => form.setData('order_stage_id', e.target.value)}
                        >
                            {stages.map((stage) => (
                                <option key={`fulfill-${stage.id}`} value={stage.id}>{stage.name || statusLabel(stage.code)}</option>
                            ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.sales_channel', 'Sales Channel')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.sales_channel_id}
                            onChange={(e) => form.setData('sales_channel_id', e.target.value)}
                        >
                            {salesChannels.map((row) => (
                                <option key={row.id} value={row.id}>{row.name}</option>
                            ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.payment_method', 'Payment Method')}</label>
                        <Select wide className="mt-1" value={form.data.payment_method_id} onChange={(e) => form.setData('payment_method_id', e.target.value)}>
                            <option value="">{t('admin.orders.select_a_method', 'Select a method')}</option>
                            {paymentMethods.map((method) => (
                                <option key={method.id} value={method.id}>{method.name}</option>
                            ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.shipping_method', 'Shipping Method')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.shipping_method_id}
                            onChange={(e) => {
                                const id = e.target.value;
                                const method = shippingMethods.find((item) => String(item.id) === String(id));
                                form.setData((current) => ({
                                    ...current,
                                    shipping_method_id: id,
                                    shipping_total: method ? Number(method.price) : current.shipping_total,
                                }));
                            }}
                        >
                            <option value="">{t('admin.orders.select_a_method', 'Select a method')}</option>
                            {shippingMethods.map((method) => (
                                <option key={method.id} value={method.id}>{method.name}</option>
                            ))}
                        </Select>

                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.order_date', 'Order Date')}</label>
                        <div className={cx(ui.fieldGrid, 'mt-1')}>
                            <Input type="date" wide value={createdParts.date} onChange={(e) => setCreatedPart('date', e.target.value)} />
                            <Input type="time" wide value={createdParts.time} onChange={(e) => setCreatedPart('time', e.target.value)} />
                        </div>
                    </section>
                </aside>
            </div>

            {pickerOpen ? (
                <div className={ui.modalBackdrop} onClick={() => setPickerOpen(false)}>
                    <div className={ui.modal} role="dialog" aria-labelledby="product-picker-title" onClick={(event) => event.stopPropagation()}>
                        <div className={ui.modalHead}>
                            <div>
                                <h2 id="product-picker-title" className={ui.modalTitle}>{t('admin.orders.add_product', 'Add Product')}</h2>
                                <p className={ui.modalSub}>{t('admin.orders.search_by_product_name_or_sku_then_add_it_to_the', 'Search by product name or SKU, then add it to the order.')}</p>
                            </div>
                            <button type="button" className={ui.menuBtn} onClick={() => setPickerOpen(false)} aria-label={t('admin.orders.close', 'Close')}>
                                <Icon name="x" />
                            </button>
                        </div>
                        <div className={ui.modalSearch}>
                            <label className={ui.filterSearch}>
                                <span className={ui.filterSearchIcon}><Icon name="search" /></span>
                                <input
                                    autoFocus
                                    className={ui.filterSearchInput}
                                    value={productQuery}
                                    onChange={(event) => setProductQuery(event.target.value)}
                                    placeholder={t('admin.orders.search_products_by_name_or_sku', 'Search products by name or SKU...')}
                                />
                            </label>
                        </div>
                        <div className={ui.modalList}>
                            {productLoading ? <div className={ui.empty}>{t('admin.orders.searching_products', 'Searching products...')}</div> : null}
                            {!productLoading && productResults.length === 0 ? (
                                <div className={ui.empty}>
                                    {productQuery.trim() ? t('admin.orders.no_products_match_that_search', 'No products match that search.') : t('admin.orders.no_products_available', 'No products available.')}
                                </div>
                            ) : null}
                            {!productLoading ? productResults.map((product) => {
                                const inOrder = form.data.items.find((item) => String(item.product_id) === String(product.id));

                                return (
                                    <div className={ui.modalRow} key={product.id}>
                                        {product.cover_path ? (
                                            <img className={ui.thumb} src={catalogImageUrl(product.cover_path, 'sm')} alt="" />
                                        ) : (
                                            <Avatar name={product.name} />
                                        )}
                                        <div className={cx(ui.cellStack, 'flex-1')}>
                                            <div className={ui.cellStrong}>{product.name}</div>
                                            <div className={ui.cellSub}>{product.sku} · {money(product.price, form.data.currency_code)}</div>
                                            <div className={ui.cellSub}>
                                                {product.stock_status === 'out_of_stock'
                                                    ? 'Out of stock'
                                                    : `In Stock${product.stock_qty ? ` · ${product.stock_qty}` : ''}`}
                                            </div>
                                        </div>
                                        <Button type="button" variant="sm" onClick={() => addProduct(product)}>
                                            {inOrder ? `Add again (${inOrder.qty})` : 'Add'}
                                        </Button>
                                    </div>
                                );
                            }) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            {refundOpen ? (
                <div className={ui.modalBackdrop} onClick={() => (!refundBusy ? setRefundOpen(false) : null)}>
                    <div
                        className={cx(ui.modal, 'max-w-xl')}
                        role="dialog"
                        aria-labelledby="refund-confirm-title"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className={ui.modalHead}>
                            <div>
                                <h2 id="refund-confirm-title" className={ui.modalTitle}>
                                    {t('admin.orders.confirm_refund_title', 'Confirm refund')}
                                </h2>
                                <p className={ui.modalSub}>
                                    {t(
                                        'admin.orders.confirm_refund_sub',
                                        'This marks the order as refunded and opens a credit slip. Confirm before continuing.',
                                    )}
                                </p>
                            </div>
                            <button
                                type="button"
                                className={ui.menuBtn}
                                disabled={refundBusy}
                                onClick={() => setRefundOpen(false)}
                                aria-label={t('admin.orders.close', 'Close')}
                            >
                                <Icon name="x" />
                            </button>
                        </div>
                        <div className={ui.modalBody}>
                            <div className="rounded-xl border border-neutral-200 bg-white px-4 py-3 text-sm space-y-3">
                                <div>
                                    <div className="font-medium text-neutral-900">
                                        {t('admin.orders.refund_items', 'Select products to refund')}
                                    </div>
                                    <p className="text-neutral-500 mt-0.5">
                                        {t(
                                            'admin.orders.refund_items_hint',
                                            'Choose qty for each product. Selected products are removed from the order and the refund amount is calculated automatically.',
                                        )}
                                    </p>
                                </div>
                                <div className="space-y-2 max-h-52 overflow-y-auto">
                                    {refundableItems.map((item) => {
                                        const qty = Number(refundQtys[item.id] || 0);

                                        return (
                                            <div key={item.id} className="flex items-center gap-3 border-b border-neutral-100 pb-2 last:border-0 last:pb-0">
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-medium text-neutral-900 truncate">{item.name}</div>
                                                    <div className="text-xs text-neutral-500">
                                                        {item.sku ? `${item.sku} · ` : ''}
                                                        {t('admin.orders.qty', 'Qty')}: {item.returnable}
                                                        {' · '}
                                                        {money(item.lineTotal, form.data.currency_code)}
                                                    </div>
                                                </div>
                                                <div className={ui.qtyControl}>
                                                    <button
                                                        type="button"
                                                        className={ui.qtyBtn}
                                                        disabled={refundBusy || qty <= 0}
                                                        onClick={() => setItemRefundQty(item.id, qty - 1, item.returnable)}
                                                    >
                                                        −
                                                    </button>
                                                    <span className={ui.qtyValue}>{qty}</span>
                                                    <button
                                                        type="button"
                                                        className={ui.qtyBtn}
                                                        disabled={refundBusy || qty >= item.returnable}
                                                        onClick={() => setItemRefundQty(item.id, qty + 1, item.returnable)}
                                                    >
                                                        +
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="rounded-lg bg-neutral-50 px-3 py-2 text-neutral-800">
                                    <span className="font-medium">{t('admin.orders.refund_amount', 'Refund amount')}: </span>
                                    {money(selectedRefundTotal, form.data.currency_code)}
                                </div>
                                <p className="text-neutral-500">
                                    {t(
                                        'admin.orders.refund_bank_note',
                                        'Refund goes to the customer’s bank / original payment method (offline shops: process the payout yourself).',
                                    )}
                                </p>
                            </div>
                            {form.data.customer_id ? (
                                <>
                                    <Checkbox
                                        checked={issueVoucher}
                                        onChange={(event) => setIssueVoucher(event.target.checked)}
                                        label={t(
                                            'admin.orders.issue_refund_voucher',
                                            'Also issue a store voucher for this amount (optional — appears under My vouchers)',
                                        )}
                                    />
                                    <Checkbox
                                        checked={issueCredit}
                                        onChange={(event) => setIssueCredit(event.target.checked)}
                                        label={t(
                                            'admin.orders.issue_store_credit',
                                            'Issue store credit instead of (or as well as) a payment refund',
                                        )}
                                    />
                                </>
                            ) : (
                                <p className="text-sm text-neutral-500">
                                    {t(
                                        'admin.orders.refund_voucher_guest',
                                        'Store voucher is only available when the order belongs to a customer account.',
                                    )}
                                </p>
                            )}
                            <Checkbox
                                checked={restock}
                                onChange={(event) => setRestock(event.target.checked)}
                                label={t('admin.orders.restock_selected_on_refund', 'Restock selected items')}
                            />
                        </div>
                        <div className={ui.modalFoot}>
                            <Button type="button" variant="ghost" disabled={refundBusy} onClick={() => setRefundOpen(false)}>
                                {t('admin.orders.cancel', 'Cancel')}
                            </Button>
                            <Button type="button" busy={refundBusy} disabled={selectedRefundLines.length === 0} onClick={confirmRefund}>
                                {t('admin.orders.confirm_refund', 'Confirm refund')}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </Layout>
    );
}
