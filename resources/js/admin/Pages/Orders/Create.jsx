import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Avatar,
    Button,
    FieldError,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Select,
    Textarea,
    Table,
    THead,
    Th,
    Tr,
    Td,
    EmptyState,
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

function addressLabel(address) {
    return [address.line1, address.city, address.postal_code].filter(Boolean).join(', ');
}

let rowSeed = 1;

export default function Create({
    customers = [],
    paymentMethods = [],
    shippingMethods = [],
    taxClasses = [],
    currencies = [],
    paymentStatuses = [],
    salesChannels = [],
    order_pdf = true,
}) {
    const { flash } = usePage().props;
    const appliedCustomer = useRef(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [productQuery, setProductQuery] = useState('');
    const [productResults, setProductResults] = useState([]);
    const [productLoading, setProductLoading] = useState(false);
    const [showNewCustomer, setShowNewCustomer] = useState(false);
    const [menuId, setMenuId] = useState(null);
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
        customer_id: '',
        email: '',
        billing_address: {
            first_name: '',
            last_name: '',
            phone: '',
            line1: '',
            city: '',
            state: '',
            postal_code: '',
            country_code: 'IN',
        },
        shipping_address: null,
        items: [],
        payment_method_id: paymentMethods[0]?.id || '',
        shipping_method_id: shippingMethods[0]?.id || '',
        currency_code: defaultCurrency,
        customer_note: '',
        payment_status: 'pending',
        payment_status_id: paymentStatuses.find((row) => row.code === 'pending')?.id || '',
        paid_amount: 0,
        created_at: localDateTime(),
        draft: false,
        sales_channel_id: salesChannels.find((row) => row.code === 'web')?.id || '',
        warehouse: 'Default Warehouse',
    });

    const selectedCustomer = customers.find((customer) => String(customer.id) === String(form.data.customer_id));
    const selectedAddressId = form.data.billing_address?.id || selectedCustomer?.addresses?.[0]?.id || '';
    const shipping = shippingMethods.find((method) => String(method.id) === String(form.data.shipping_method_id));

    const totals = useMemo(() => {
        const next = form.data.items.reduce((acc, item) => {
            const math = lineMath(item);
            acc.subtotal += math.line;
            acc.discount += math.discountAmount;
            acc.tax += math.tax;
            acc.qty += Number(item.qty || 0);

            return acc;
        }, { subtotal: 0, discount: 0, tax: 0, qty: 0 });
        const shippingTotal = Number(shipping?.price || 0);
        next.shipping = shippingTotal;
        next.grand = next.subtotal - next.discount + next.tax + shippingTotal;

        return next;
    }, [form.data.items, shipping]);

    const balance = Math.max(0, totals.grand - Number(form.data.paid_amount || 0));

    const applyCustomer = (customer) => {
        if (!customer) {
            form.setData((current) => ({
                ...current,
                customer_id: '',
                email: '',
                billing_address: {
                    first_name: '',
                    last_name: '',
                    phone: '',
                    line1: '',
                    city: '',
                    state: '',
                    postal_code: '',
                    country_code: 'IN',
                },
            }));

            return;
        }

        const address = customer.addresses?.[0] || {};
        const parts = (customer.name || '').split(' ');
        form.setData((current) => ({
            ...current,
            customer_id: customer.id,
            email: customer.email,
            billing_address: {
                id: address.id,
                first_name: address.first_name || parts[0] || customer.name,
                last_name: address.last_name || parts.slice(1).join(' '),
                phone: address.phone || customer.phone || '',
                line1: address.line1 || '',
                city: address.city || '',
                state: address.state || '',
                postal_code: address.postal_code || '',
                country_code: address.country_code || 'IN',
            },
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

    const chooseAddress = (id) => {
        const address = selectedCustomer?.addresses?.find((item) => String(item.id) === String(id));
        if (!address) {
            return;
        }
        form.setData('billing_address', {
            id: address.id,
            first_name: address.first_name,
            last_name: address.last_name,
            phone: address.phone,
            line1: address.line1,
            city: address.city,
            state: address.state,
            postal_code: address.postal_code,
            country_code: address.country_code,
        });
    };

    const save = (draft = false) => {
        form.transform((data) => ({
            ...data,
            draft,
            payment_status_id: data.payment_status_id ? Number(data.payment_status_id) : null,
            sales_channel_id: data.sales_channel_id ? Number(data.sales_channel_id) : null,
            payment_method_id: data.payment_method_id || null,
            shipping_method_id: data.shipping_method_id || null,
            shipping_address: data.billing_address,
        }));
        form.post('/admin/orders', {
            preserveScroll: true,
            onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
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
                title={t('admin.orders.add_new_order', 'Add New Order')}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { label: t('admin.orders.add_new_order', 'Add New Order') },
                ]}
                actions={(
                    <>
                        <Button variant="secondary" href="/admin/orders">{t('admin.orders.discard', 'Discard')}</Button>
                        <Button variant="secondary" type="button" onClick={() => save(true)} busy={form.processing}>{t('admin.orders.save_as_draft', 'Save as Draft')}</Button>
                        <Button type="button" icon="check" onClick={() => save(false)} busy={form.processing}>{t('admin.orders.save_order', 'Save Order')}</Button>
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

                        <div className="mt-3">
                            <label className={ui.label}>{t('admin.orders.billing_address', 'Billing Address')}</label>
                            <Select
                                wide
                                className="mt-1"
                                value={selectedAddressId}
                                disabled={!selectedCustomer}
                                onChange={(event) => chooseAddress(event.target.value)}
                            >
                                <option value="">{selectedCustomer ? t('admin.orders.select_address', 'Select address') : t('admin.orders.select_a_customer_to_load_address', 'Select a customer to load address')}</option>
                                {(selectedCustomer?.addresses || []).map((address) => (
                                    <option key={address.id} value={address.id}>{address.label}: {addressLabel(address)}</option>
                                ))}
                            </Select>
                            <FieldError>{form.errors['billing_address.line1']}</FieldError>
                        </div>

                        <div className={cx(ui.fieldGrid, 'mt-3')}>
                            <div>
                                <div className={ui.metaK}>{t('admin.orders.email', 'Email')}</div>
                                <div className={ui.metaV}>{form.data.email || '—'}</div>
                            </div>
                            <div>
                                <div className={ui.metaK}>{t('admin.orders.phone', 'Phone')}</div>
                                <div className={ui.metaV}>{form.data.billing_address.phone || selectedCustomer?.phone || '—'}</div>
                            </div>
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
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th className="min-w-[240px]">{t('admin.orders.product', 'Product')}</Th>
                                        <Th className="w-28 text-right">{t('admin.orders.price', 'Price')}</Th>
                                        <Th className="w-28 text-center">{t('admin.orders.qty', 'Qty')}</Th>
                                        <Th className="w-32 text-center">{t('admin.orders.tax', 'Tax')}</Th>
                                        <Th className="w-36 text-center">{t('admin.orders.discount', 'Discount')}</Th>
                                        <Th className="w-28 text-right">{t('admin.orders.total', 'Total')}</Th>
                                        <Th actions />
                                    </Tr>
                                </THead>
                                <tbody>
                                    {form.data.items.length === 0 ? (
                                        <EmptyState colSpan={7}>
                                            {t('admin.orders.add_a_product_or_custom_item_to_this_order', 'Add a product or custom item to this order.')}
                                        </EmptyState>
                                    ) : null}
                                    {form.data.items.map((item) => {
                                        const math = lineMath(item);

                                        return (
                                            <Tr key={item.key}>
                                                <Td>
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
                                                </Td>
                                                <Td className="text-right">
                                                    <div className="flex justify-end">
                                                        <Input
                                                            narrow
                                                            className="text-right"
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            value={item.unit_price}
                                                            onChange={(e) => updateItem(item.key, { unit_price: e.target.value })}
                                                        />
                                                    </div>
                                                </Td>
                                                <Td className="text-center">
                                                    <div className="flex justify-center">
                                                        <div className={ui.qtyControl}>
                                                            <button type="button" className={ui.qtyBtn} onClick={() => updateItem(item.key, { qty: Math.max(1, Number(item.qty) - 1) })}>−</button>
                                                            <span className={ui.qtyValue}>{item.qty}</span>
                                                            <button type="button" className={ui.qtyBtn} onClick={() => updateItem(item.key, { qty: Number(item.qty) + 1 })}>+</button>
                                                        </div>
                                                    </div>
                                                </Td>
                                                <Td className="text-center">
                                                    <Select
                                                        value={item.tax_rate}
                                                        onChange={(e) => updateItem(item.key, { tax_rate: Number(e.target.value) })}
                                                    >
                                                        {taxOptions.map((tax) => (
                                                            <option key={tax.name} value={tax.rate}>{tax.name}</option>
                                                        ))}
                                                    </Select>
                                                </Td>
                                                <Td className="text-center">
                                                    <div className="flex items-center justify-center gap-1">
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
                                                </Td>
                                                <Td className="text-right font-medium tabular-nums text-neutral-900">
                                                    {money(math.total, form.data.currency_code)}
                                                </Td>
                                                <Td actions className="relative">
                                                    <button type="button" className={ui.menuBtn} onClick={() => setMenuId(menuId === item.key ? null : item.key)} aria-label={t('admin.orders.item_actions', 'Item actions')}>
                                                        <Icon name="more" />
                                                    </button>
                                                    {menuId === item.key ? (
                                                        <div className={ui.popMenu}>
                                                            <button type="button" className={ui.popItem} onClick={() => removeItem(item.key)}>{t('admin.common.remove', 'Remove')}</button>
                                                        </div>
                                                    ) : null}
                                                </Td>
                                            </Tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                        </div>
                        <FieldError>{form.errors.items}</FieldError>

                        <div className="mt-4">
                            <label className={ui.label}>{t('admin.orders.add_order_note', 'Add Order Note')}</label>
                            <Textarea
                                className="mt-1"
                                maxLength={500}
                                value={form.data.customer_note}
                                onChange={(e) => form.setData('customer_note', e.target.value)}
                                placeholder={t('admin.orders.internal_or_customer_facing_note', 'Internal or customer-facing note')}
                            />
                            <div className={cx(ui.muted, 'mt-1')}>{(form.data.customer_note || '').length} / 500</div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.orders.order_settings', 'Order Settings')}</h2>
                        </div>
                        <div className={ui.fieldGrid3}>
                            <div>
                                <label className={ui.label}>{t('admin.orders.sales_channel', 'Sales Channel')}</label>
                                <Select wide className="mt-1" value={form.data.sales_channel_id} onChange={(e) => form.setData('sales_channel_id', e.target.value)}>
                                    {salesChannels.map((row) => (
                                        <option key={row.id} value={row.id}>{row.name}</option>
                                    ))}
                                </Select>
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.order_date', 'Order Date')}<span className={ui.req}>*</span></label>
                                <Input wide className="mt-1" type="datetime-local" value={form.data.created_at} onChange={(e) => form.setData('created_at', e.target.value)} />
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.currency', 'Currency')}<span className={ui.req}>*</span></label>
                                <Select wide className="mt-1" value={form.data.currency_code} onChange={(e) => form.setData('currency_code', e.target.value)}>
                                    {(currencies.length ? currencies : [{ code: 'INR', name: 'Indian Rupee', symbol: '₹' }]).map((currency) => (
                                        <option key={currency.code} value={currency.code}>{currency.code} ({currency.symbol || currency.code})</option>
                                    ))}
                                </Select>
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.payment_method', 'Payment Method')}</label>
                                <Select wide className="mt-1" value={form.data.payment_method_id} onChange={(e) => form.setData('payment_method_id', e.target.value)}>
                                    <option value="">{t('admin.orders.select_a_method', 'Select a method')}</option>
                                    {paymentMethods.map((method) => (
                                        <option key={method.id} value={method.id}>{method.name}</option>
                                    ))}
                                </Select>
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.shipping_method', 'Shipping Method')}</label>
                                <Select wide className="mt-1" value={form.data.shipping_method_id} onChange={(e) => form.setData('shipping_method_id', e.target.value)}>
                                    <option value="">{t('admin.orders.select_a_method', 'Select a method')}</option>
                                    {shippingMethods.map((method) => (
                                        <option key={method.id} value={method.id}>{method.name}</option>
                                    ))}
                                </Select>
                            </div>
                            <div>
                                <label className={ui.label}>{t('admin.orders.warehouse', 'Warehouse')}</label>
                                <Select wide className="mt-1" value={form.data.warehouse} onChange={(e) => form.setData('warehouse', e.target.value)}>
                                    <option>{t('admin.orders.default_warehouse', 'Default Warehouse')}</option>
                                </Select>
                            </div>
                        </div>
                    </section>
                </div>

                <aside className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-3')}>{t('admin.orders.order_summary', 'Order Summary')}</h2>
                        <div className={ui.summaryRow}><span>{t('admin.orders.subtotal', 'Subtotal')}</span><span>{money(totals.subtotal, form.data.currency_code)}</span></div>
                        <div className={ui.summaryRow}><span>{t('admin.orders.discount', 'Discount')}</span><span>-{money(totals.discount, form.data.currency_code)}</span></div>
                        <div className={ui.summaryRow}><span>{t('admin.orders.shipping', 'Shipping')}</span><span>{money(totals.shipping, form.data.currency_code)}</span></div>
                        <div className={ui.summaryRow}><span>{t('admin.orders.tax', 'Tax')}</span><span>{money(totals.tax, form.data.currency_code)}</span></div>
                        <div className={ui.summaryGrand}>
                            <span className="font-medium">{t('admin.orders.order_total', 'Order Total')}</span>
                            <span className={ui.summaryGrandAmt}>{money(totals.grand, form.data.currency_code)}</span>
                        </div>
                        <div className={ui.summaryRow}>
                            <span>{t('admin.orders.total_items', 'Total Items')}</span>
                            <span>{form.data.items.length}</span>
                        </div>
                        <div className={ui.summaryRow}>
                            <span>{t('admin.orders.total_quantity', 'Total Quantity')}</span>
                            <span>{totals.qty}</span>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-3')}>{t('admin.orders.payment_summary', 'Payment Summary')}</h2>
                        <label className={ui.label}>{t('admin.orders.payment_status', 'Payment Status')}</label>
                        <Select
                            wide
                            className="mt-1"
                            value={form.data.payment_status_id}
                            onChange={(e) => {
                                const nextId = e.target.value;
                                const next = paymentStatuses.find((row) => String(row.id) === String(nextId));
                                const newStatus = next?.code || 'pending';
                                form.setData((prev) => {
                                    let newPaid = prev.paid_amount;
                                    if (newStatus === 'paid') {
                                        newPaid = totals.grand;
                                    } else if (newStatus === 'pending') {
                                        newPaid = 0;
                                    }
                                    return {
                                        ...prev,
                                        payment_status_id: nextId,
                                        payment_status: newStatus,
                                        paid_amount: newPaid,
                                    };
                                });
                            }}
                        >
                            {paymentStatuses
                                .filter((row) => ['pending', 'partial', 'paid', 'failed'].includes(row.code))
                                .map((row) => (
                                    <option key={row.id} value={row.id}>{row.name}</option>
                                ))}
                        </Select>
                        <label className={cx(ui.label, 'mt-3')}>{t('admin.orders.paid_amount', 'Paid Amount')}</label>
                        <Input
                            wide
                            className="mt-1"
                            type="number"
                            min="0"
                            step="0.01"
                            value={form.data.paid_amount}
                            onChange={(e) => {
                                const rawVal = e.target.value;
                                const val = rawVal === '' ? '' : parseFloat(rawVal) || 0;
                                form.setData((prev) => {
                                    let status = prev.payment_status;
                                    const numVal = Number(val);
                                    if (numVal >= totals.grand && totals.grand > 0) {
                                        status = 'paid';
                                    } else if (numVal > 0) {
                                        status = 'partial';
                                    } else if (status === 'paid' || status === 'partial') {
                                        status = 'pending';
                                    }
                                    return {
                                        ...prev,
                                        paid_amount: rawVal,
                                        payment_status: status,
                                    };
                                });
                            }}
                        />
                        <div className="mt-3">
                            <div className={ui.metaK}>{t('admin.orders.balance_due', 'Balance Due')}</div>
                            <div className={cx('text-base font-semibold mt-0.5', balance > 0.009 ? 'text-amber-600' : 'text-emerald-600')}>
                                {money(balance, form.data.currency_code)}
                            </div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-3')}>{t('admin.orders.customer_summary', 'Customer Summary')}</h2>
                        {selectedCustomer ? (
                            <>
                                <div className={ui.row}>
                                    <Avatar name={selectedCustomer.name} />
                                    <div className={ui.cellStack}>
                                        <div className={ui.cellStrong}>{selectedCustomer.name}</div>
                                        <div className={ui.cellSub}>{selectedCustomer.email}</div>
                                    </div>
                                </div>
                                <div className={cx(ui.muted, 'mt-3')}>Total Orders: {selectedCustomer.orders_count}</div>
                                <div className={ui.muted}>Total Spent: {money(selectedCustomer.total_spent, form.data.currency_code)}</div>
                                <Link className={cx(ui.btnLink, 'inline-block mt-3 text-sm')} href={`/admin/customers/${selectedCustomer.id}`}>
                                    View Customer Profile →
                                </Link>
                            </>
                        ) : (
                            <div className={ui.muted}>{t('admin.orders.select_a_customer_to_see_their_profile', 'Select a customer to see their profile.')}</div>
                        )}
                    </section>

                    <section className={ui.sectionCard}>
                        <h2 className={cx(ui.sectionTitle, 'mb-2')}>{t('admin.orders.actions', 'Actions')}</h2>
                        <div className={ui.actionList}>
                            <button type="button" className={ui.actionItem} onClick={() => window.alert(t('admin.orders.save_the_order_first_to_send_email', 'Save the order first to send email.'))}>
                                <Icon name="file" /> {t('admin.orders.send_order_email', 'Send Order Email')}
                            </button>
                            {order_pdf ? (
                                <button type="button" className={ui.actionItem} onClick={() => window.print()}>
                                    <Icon name="download" /> {t('admin.orders.print_order', 'Print Order')}
                                </button>
                            ) : null}
                            <button type="button" className={ui.actionItem} onClick={() => save(true)}>
                                <Icon name="check" /> {t('admin.orders.save_as_draft', 'Save as Draft')}
                            </button>
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
        </Layout>
    );
}
