import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Card,
    ChoiceMenu,
    FeatureTile,
    FormErrors,
    Input,
    PageHeader,
    SettingsCount,
    SettingsSectionHead,
    Textarea,
    ui,
    t,
} from '../../ui';
import { ORDER_CLUB_TABS, SettingsClubTabs } from './clubTabs';
import CartCheckout from './CartCheckout';
import Returns from './Returns';
import DocumentRules from './DocumentRules';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.order_settings', 'Order Settings') },
];

function previewNumber(format, sequence) {
    const safe = String(format || '').includes('{number}') ? format : 'WENTO-{number}';
    return String(safe).replace('{number}', String(sequence || 1));
}

function MoneyLimit({ symbol, value, onChange, invalid }) {
    return (
        <div className="flex w-28 shrink-0 items-center gap-1.5">
            {symbol ? <span className="shrink-0 text-sm text-neutral-500">{symbol}</span> : null}
            <Input className="min-w-0 flex-1" type="number" min="0" step="0.01" invalid={invalid} value={value} onChange={onChange} />
        </div>
    );
}

export default function Order({
    defaults = {},
    checkout = {},
    payment = {},
    notifications = {},
    statusChoices = [],
    currency = {},
    tab = 'defaults',
    cart = {},
    returns = {},
    invoices = {},
    next_sequence: nextSequence = null,
}) {
    const form = useForm({
        number_format: defaults.number_format || 'WENTO-{number}',
        number_start: Number(defaults.number_start ?? 10001),
        default_status: defaults.default_status || 'pending',
        min_amount: Number(defaults.min_amount ?? 0),
        max_amount: defaults.max_amount ?? '',
        customer_notes_enabled: checkout.customer_notes_enabled !== false,
        guest_lookup: checkout.guest_lookup === true,
        allow_cancellation: checkout.allow_cancellation !== false,
        cancellation_until: checkout.cancellation_until || 'processing',
        auto_cancel_unpaid: !!payment.auto_cancel_unpaid,
        unpaid_timeout_minutes: Number(payment.unpaid_timeout_minutes ?? 1440),
        reserve_stock_unpaid: !!payment.reserve_stock_unpaid,
        stock_reservation_minutes: Number(payment.stock_reservation_minutes ?? 30),
        reduce_stock_at: payment.reduce_stock_at || 'pending',
        restock_on_cancellation: payment.restock_on_cancellation !== false,
        email_customer_confirmation: notifications.email_customer_confirmation !== false,
        email_admin_new_order: notifications.email_admin_new_order !== false,
        admin_new_order_emails: notifications.admin_new_order_emails || notifications.store_email || '',
    });

    useSaveOnChange(form, '/admin/order-settings', {
        textKeys: ['number_format', 'number_start', 'min_amount', 'max_amount', 'unpaid_timeout_minutes', 'stock_reservation_minutes', 'admin_new_order_emails'],
        transform: (data) => ({
            ...data,
            max_amount: data.max_amount === '' || data.max_amount === null ? null : Number(data.max_amount),
        }),
    });

    const currencySymbol = currency.symbol || currency.code || '';
    const statusList = statusChoices.length
        ? statusChoices
        : [
            { value: 'pending', label: t('admin.order_settings.status_pending', 'Pending') },
            { value: 'processing', label: t('admin.order_settings.status_processing', 'Processing') },
        ];
    const savedStart = Number(defaults.number_start ?? 10001);
    const previewSeq = Number(form.data.number_start) === savedStart
        ? Number(nextSequence ?? savedStart)
        : Number(form.data.number_start || savedStart);

    return (
        <Layout>
            <PageHeader
                title={t('admin.order_settings.title', 'Order Settings')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.order_settings.subtitle', 'Store-wide order, checkout, stock, and notification behavior.')}
            />
            <SettingsClubTabs tabs={ORDER_CLUB_TABS} value={tab} base="/admin/order-settings" />
            {tab === 'cart' ? <CartCheckout embedded {...cart} currency={currency} /> : null}
            {tab === 'returns' ? <Returns embedded {...returns} /> : null}
            {tab === 'invoices' ? <DocumentRules embedded {...invoices} /> : null}
            {tab === 'defaults' ? (
            <form
                className="space-y-6"
                onSubmit={(e) => {
                    e.preventDefault();
                }}
            >
                <FormErrors errors={form.errors} />

                <div className="space-y-4">
                    <Card>
                        <SettingsSectionHead
                            icon="orders"
                            title={t('admin.order_settings.panel_defaults', 'Order Defaults')}
                            hint={t('admin.order_settings.panel_defaults_hint', 'Order numbers, starting sequence, default status, and order totals.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 6 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="pin"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.order_settings.number_format', 'Order number format')}
                                hint={form.errors.number_format || t('admin.order_settings.number_format_hint', 'Editable. A valid format is saved and used on the next order. Include {number} once. Example: ORD-{number}')}
                                control={(
                                    <Input className="w-44 shrink-0 font-mono text-xs" value={form.data.number_format} onChange={(e) => form.setData('number_format', e.target.value)} placeholder="ORD-{number}" aria-label={t('admin.order_settings.number_format_editable', 'Editable order number format')} />
                                )}
                            />
                            <FeatureTile
                                icon="orders"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.order_settings.number_start', 'Starting order number')}
                                hint={form.errors.number_start || t('admin.order_settings.number_start_hint', 'Changing this sets the next order to this number. A number already used is skipped. Later orders continue upward.')}
                                control={(
                                    <Input className="w-28 shrink-0" type="number" min="1" value={form.data.number_start} onChange={(e) => form.setData('number_start', Number(e.target.value))} />
                                )}
                            />
                            <FeatureTile
                                icon="check"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.order_settings.default_status', 'Default order status')}
                                hint={form.errors.default_status || t('admin.order_settings.default_status_hint', 'Enabled statuses for this shop. A new order starts in the one you pick.')}
                                control={(
                                    <ChoiceMenu
                                        className="w-48"
                                        ariaLabel={t('admin.order_settings.default_status', 'Default order status')}
                                        value={form.data.default_status}
                                        options={statusList}
                                        onChange={(value) => form.setData('default_status', value)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="eye"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.order_settings.preview_number', 'Next number preview')}
                                hint={t('admin.order_settings.preview_number_hint', 'Read-only. The id the next order will get. A number already used is skipped.')}
                                control={(
                                    <Input className="w-44 shrink-0 font-mono text-xs" readOnly value={previewNumber(form.data.number_format, previewSeq)} aria-label={t('admin.order_settings.preview_number_readonly', 'Read-only next number preview')} />
                                )}
                            />
                            <FeatureTile
                                icon="tag"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.order_settings.min_amount', 'Minimum order amount')}
                                hint={form.errors.min_amount || t('admin.order_settings.min_amount_hint', '0 = no minimum. Compared to the checkout grand total.')}
                                control={(
                                    <MoneyLimit
                                        symbol={currencySymbol}
                                        invalid={!!form.errors.min_amount}
                                        value={form.data.min_amount}
                                        onChange={(e) => form.setData('min_amount', e.target.value)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="tag"
                                tone="bg-indigo-100 text-indigo-600"
                                title={t('admin.order_settings.max_amount', 'Maximum order amount')}
                                hint={form.errors.max_amount || t('admin.order_settings.max_amount_hint', 'Leave empty for no maximum.')}
                                control={(
                                    <MoneyLimit
                                        symbol={currencySymbol}
                                        invalid={!!form.errors.max_amount}
                                        value={form.data.max_amount}
                                        onChange={(e) => form.setData('max_amount', e.target.value)}
                                    />
                                )}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="user"
                            title={t('admin.order_settings.panel_checkout', 'Checkout & Customer')}
                            hint={t('admin.order_settings.panel_checkout_hint', 'Customer notes, guest order tracking, and cancellation. Guest checkout lives under Configure → Customers.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: form.data.allow_cancellation ? 4 : 3 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="file"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.order_settings.customer_notes', 'Allow customer order notes')}
                                hint={t('admin.order_settings.customer_notes_hint', 'Shoppers can leave a note at checkout.')}
                                value={!!form.data.customer_notes_enabled}
                                onChange={(on) => form.setData('customer_notes_enabled', on)}
                            />
                            <FeatureTile
                                icon="search"
                                tone="bg-orange-100 text-orange-600"
                                title={t('admin.order_settings.guest_lookup', 'Guest order tracking')}
                                hint={t('admin.order_settings.guest_lookup_hint', 'When this is on, Track your order appears in the storefront header and footer. Guests open it and enter the order number, email, and postal code.')}
                                value={!!form.data.guest_lookup}
                                onChange={(on) => form.setData('guest_lookup', on)}
                            />
                            <FeatureTile
                                icon="x"
                                tone="bg-rose-100 text-rose-600"
                                title={t('admin.order_settings.allow_cancellation', 'Allow order cancellation')}
                                hint={t('admin.order_settings.allow_cancellation_hint', 'Customers can cancel while the order is in a status before the one you pick.')}
                                value={!!form.data.allow_cancellation}
                                onChange={(on) => form.setData('allow_cancellation', on)}
                            >
                                {form.data.allow_cancellation ? (
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm text-neutral-700">{t('admin.order_settings.cancellation_until', 'Cancellation allowed until')}</span>
                                        <ChoiceMenu
                                            className="w-44"
                                            ariaLabel={t('admin.order_settings.cancellation_until', 'Cancellation allowed until')}
                                            value={form.data.cancellation_until}
                                            options={statusList}
                                            onChange={(value) => form.setData('cancellation_until', value)}
                                        />
                                    </div>
                                ) : null}
                            </FeatureTile>
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="layers"
                            title={t('admin.order_settings.panel_payment', 'Payment & Inventory')}
                            hint={t('admin.order_settings.panel_payment_hint', 'When stock moves, and what happens to unpaid orders.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="package"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.order_settings.reduce_stock_at', 'Reduce stock at')}
                                hint={form.errors.reduce_stock_at || t('admin.order_settings.reduce_stock_at_hint', 'Inventory is deducted when the order reaches this status. If a new order already starts there, stock is deducted at placement.')}
                                control={(
                                    <ChoiceMenu
                                        className="w-44"
                                        ariaLabel={t('admin.order_settings.reduce_stock_at', 'Reduce stock at')}
                                        value={form.data.reduce_stock_at}
                                        options={statusList}
                                        onChange={(value) => form.setData('reduce_stock_at', value)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="clock"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.order_settings.auto_cancel_unpaid', 'Automatically cancel unpaid orders')}
                                hint={form.errors.unpaid_timeout_minutes || t('admin.order_settings.unpaid_timeout_hint', 'Example: 30 = half hour, 1440 = 24 hours.')}
                                value={!!form.data.auto_cancel_unpaid}
                                onChange={(on) => form.setData('auto_cancel_unpaid', on)}
                            >
                                {form.data.auto_cancel_unpaid ? (
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm text-neutral-700">{t('admin.order_settings.unpaid_timeout', 'Unpaid order timeout (minutes)')}</span>
                                        <Input className="w-24 shrink-0" type="number" min="1" value={form.data.unpaid_timeout_minutes} onChange={(e) => form.setData('unpaid_timeout_minutes', Number(e.target.value))} />
                                    </div>
                                ) : null}
                            </FeatureTile>
                            <FeatureTile
                                icon="lock"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.order_settings.reserve_stock', 'Reserve stock for unpaid orders')}
                                hint={form.errors.stock_reservation_minutes || t('admin.order_settings.reserve_stock_hint', 'Holds the units until the order reaches the Reduce stock at status. The hold ends after this many minutes if that status never happens.')}
                                value={!!form.data.reserve_stock_unpaid}
                                onChange={(on) => form.setData('reserve_stock_unpaid', on)}
                            >
                                {form.data.reserve_stock_unpaid && form.data.reduce_stock_at !== form.data.default_status ? (
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm text-neutral-700">{t('admin.order_settings.reserve_minutes', 'Stock reservation duration (minutes)')}</span>
                                        <Input className="w-24 shrink-0" type="number" min="1" value={form.data.stock_reservation_minutes} onChange={(e) => form.setData('stock_reservation_minutes', Number(e.target.value))} />
                                    </div>
                                ) : null}
                            </FeatureTile>
                            <FeatureTile
                                icon="refresh"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.order_settings.restock_on_cancel', 'Restore stock on cancellation')}
                                hint={t('admin.order_settings.restock_on_cancel_hint', 'Put reserved or deducted units back when an order is cancelled.')}
                                value={!!form.data.restock_on_cancellation}
                                onChange={(on) => form.setData('restock_on_cancellation', on)}
                            />
                        </div>
                    </Card>

                    <Card>
                        <SettingsSectionHead
                            icon="mail"
                            title={t('admin.order_settings.panel_notifications', 'Notifications')}
                            hint={t('admin.order_settings.panel_notifications_hint', 'Choose who is emailed when a customer places an order.')}
                            extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 2 })}</SettingsCount>}
                        />
                        <div className="grid gap-3 md:grid-cols-2">
                            <FeatureTile
                                icon="mail"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.order_settings.email_customer', 'Order confirmation email')}
                                hint={t('admin.order_settings.email_customer_hint', 'Email the customer when an order is placed.')}
                                value={!!form.data.email_customer_confirmation}
                                onChange={(on) => form.setData('email_customer_confirmation', on)}
                            />
                            <FeatureTile
                                icon="bell"
                                tone="bg-violet-100 text-violet-600"
                                title={t('admin.order_settings.email_admin', 'Admin new-order notification')}
                                hint={t('admin.order_settings.email_admin_hint', 'Each address here is notified when an order is placed. Separate extra addresses with commas.')}
                                error={form.errors.admin_new_order_emails}
                                value={!!form.data.email_admin_new_order}
                                onChange={(on) => form.setData({
                                    ...form.data,
                                    email_admin_new_order: on,
                                    admin_new_order_emails: on && !String(form.data.admin_new_order_emails || '').trim()
                                        ? (notifications.store_email || '')
                                        : form.data.admin_new_order_emails,
                                })}
                            >
                                {form.data.email_admin_new_order ? (
                                    <Textarea
                                        rows="3"
                                        value={form.data.admin_new_order_emails}
                                        invalid={!!form.errors.admin_new_order_emails}
                                        aria-label={t('admin.order_settings.admin_emails', 'Notification email addresses')}
                                        placeholder={notifications.store_email || 'orders@store.com, owner@store.com'}
                                        onChange={(e) => form.setData('admin_new_order_emails', e.target.value)}
                                    />
                                ) : null}
                            </FeatureTile>
                        </div>
                    </Card>
                </div>
            </form>
            ) : null}
        </Layout>
    );
}
