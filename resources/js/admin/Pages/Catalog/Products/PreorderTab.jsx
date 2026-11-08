import { useMemo } from 'react';
import {
    Alert,
    ChoiceCards,
    Field,
    Input,
    Label,
    Select,
    Textarea,
    Toggle,
    ui,
    cx,
} from '../../../ui';

function Section({ title, hint, extra, children }) {
    return (
        <section className={ui.sectionCard}>
            {title || extra ? (
                <div className={ui.sectionHead}>
                    <div>
                        {title ? <h2 className={ui.sectionTitle}>{title}</h2> : null}
                        {hint ? <p className={ui.sectionHint}>{hint}</p> : null}
                    </div>
                    {extra}
                </div>
            ) : null}
            {children}
        </section>
    );
}

export function defaultPreorderConfig() {
    return {
        start_at: '',
        end_at: '',
        release_at: '',
        fulfillment_at: '',
        expected_delivery_at: '',
        payment_type: 'full',
        deposit_type: 'percent',
        deposit_value: '',
        min_quantity: 1,
        max_quantity: '',
        total_limit: '',
        orders_placed: 0,
        orders_remaining: null,
        message: '',
        show_badge: true,
        show_release_date: true,
        show_delivery_date: false,
        show_countdown_pdp: false,
        show_countdown_listing: false,
        show_image_label: false,
        notify_me_enabled: false,
        fulfillment_rule: 'auto',
        preorder_price: '',
        preorder_price_valid_until: '',
    };
}

export function preorderPayload(enabled, config) {
    return {
        preorder_enabled: !!enabled,
        preorder: enabled ? {
            ...config,
            end_at: config.end_at === '' ? null : config.end_at,
            fulfillment_at: config.fulfillment_at === '' ? null : config.fulfillment_at,
            expected_delivery_at: config.expected_delivery_at === '' ? null : config.expected_delivery_at,
            deposit_value: config.deposit_value === '' ? null : config.deposit_value,
            max_quantity: config.max_quantity === '' ? null : config.max_quantity,
            total_limit: config.total_limit === '' ? null : config.total_limit,
            preorder_price: config.preorder_price === '' ? null : config.preorder_price,
            preorder_price_valid_until: config.preorder_price_valid_until === '' ? null : config.preorder_price_valid_until,
        } : null,
    };
}

export default function PreorderTab({
    enabled,
    onEnabled,
    config,
    onChange,
    errors = {},
    currencySymbol = '$',
    productPrice = 0,
    blockedBySubscription = false,
    conflictMessage = '',
    conflictActive = false,
}) {
    const setConfig = (patch) => onChange({ ...config, ...patch });
    const depositMode = config.payment_type === 'deposit';
    const depositPreview = useMemo(() => {
        if (config.payment_type !== 'deposit') return null;
        const preorderAmount = Number(config.preorder_price);
        const price = Number.isFinite(preorderAmount) && String(config.preorder_price).trim() !== ''
            ? preorderAmount
            : (Number(productPrice) || 0);
        const value = Number(config.deposit_value) || 0;
        if (config.deposit_type === 'percent') {
            const deposit = Math.round((price * value / 100) * 100) / 100;
            return { deposit, remaining: Math.round(Math.max(0, price - deposit) * 100) / 100 };
        }
        return { deposit: value, remaining: Math.round(Math.max(0, price - value) * 100) / 100 };
    }, [config.payment_type, config.deposit_type, config.deposit_value, config.preorder_price, productPrice]);

    const remainingLabel = config.total_limit === '' || config.total_limit === null
        ? 'Unlimited'
        : String(Math.max(0, Number(config.total_limit || 0) - Number(config.orders_placed || 0)));

    const toggleLocked = blockedBySubscription && !enabled;

    return (
        <div className="space-y-4">
            <Section
                title="Pre-order"
                hint="When enabled, customers can pre-order this product before its release date."
                extra={(
                    <Field
                        label="Enable pre-order"
                        hint="Turns pre-order on for this product. Not supported with subscription on the same product."
                        error={errors.preorder_enabled}
                    >
                        <div className="flex justify-end">
                            <Toggle
                                value={!!enabled}
                                ariaLabel="Enable pre-order"
                                disabled={toggleLocked}
                                onChange={onEnabled}
                            />
                        </div>
                    </Field>
                )}
            />

            {blockedBySubscription || conflictActive ? (
                <Alert type={conflictActive ? 'warning' : 'info'}>
                    {conflictMessage || 'Disable subscription on the Subscription tab before enabling pre-order for this product.'}
                </Alert>
            ) : null}

            {!enabled ? null : (
                <>
            <Section title="Availability">
                <div className={ui.fieldGrid3}>
                    <Field
                        label="Pre-order start date"
                        hint="Window opens at this date/time (local). Leave empty to open immediately once enabled."
                        error={errors['preorder.start_at']}
                    >
                        <Input wide type="datetime-local" value={config.start_at || ''} onChange={(e) => setConfig({ start_at: e.target.value })} />
                    </Field>
                    <Field
                        label="Pre-order end date"
                        hint="Must be after the start date when both are set. Leave empty for no end."
                        error={errors['preorder.end_at']}
                    >
                        <Input wide type="datetime-local" value={config.end_at || ''} onChange={(e) => setConfig({ end_at: e.target.value })} />
                    </Field>
                    <Field
                        label="Release date"
                        hint="Product availability date shown to customers. Cannot be before the start date."
                        error={errors['preorder.release_at']}
                    >
                        <Input wide type="datetime-local" value={config.release_at || ''} onChange={(e) => setConfig({ release_at: e.target.value })} />
                    </Field>
                    <Field
                        label="Expected fulfillment date"
                        hint="Internal target date. Cannot be before the release date when both are set."
                        error={errors['preorder.fulfillment_at']}
                    >
                        <Input wide type="datetime-local" value={config.fulfillment_at || ''} onChange={(e) => setConfig({ fulfillment_at: e.target.value })} />
                    </Field>
                    <Field
                        label="Expected delivery date"
                        hint="Customer-facing estimate. Cannot be before the release date when both are set."
                        error={errors['preorder.expected_delivery_at']}
                    >
                        <Input wide type="datetime-local" value={config.expected_delivery_at || ''} onChange={(e) => setConfig({ expected_delivery_at: e.target.value })} />
                    </Field>
                </div>
            </Section>

            <Section title="Pre-order pricing" hint="Promotional pre-order price. Regular pricing remains on the Pricing tab.">
                <div className={ui.fieldGrid}>
                    <Field
                        label="Pre-order price"
                        hint="Must be zero or greater. Leave empty to charge the regular product price."
                        error={errors['preorder.preorder_price']}
                    >
                        <Input wide type="number" min="0" step="0.01" value={config.preorder_price ?? ''} onChange={(e) => setConfig({ preorder_price: e.target.value })} />
                    </Field>
                    <Field
                        label="Pre-order price valid until"
                        hint="After this date/time the promotional pre-order price no longer applies."
                        error={errors['preorder.preorder_price_valid_until']}
                    >
                        <Input wide type="datetime-local" value={config.preorder_price_valid_until || ''} onChange={(e) => setConfig({ preorder_price_valid_until: e.target.value })} />
                    </Field>
                </div>
            </Section>

            <Section title="Payment">
                <div className="space-y-1">
                    <Label>Payment type</Label>
                    <ChoiceCards
                        name="preorder-payment"
                        options={[
                            { value: 'full', label: 'Full payment', hint: 'Customer pays the full amount at checkout.' },
                            { value: 'deposit', label: 'Deposit', hint: 'Customer pays a deposit now; remaining balance is shown on the order.' },
                        ]}
                        value={config.payment_type}
                        onChange={(value) => setConfig({ payment_type: value })}
                    />
                    <span className={ui.fieldHint}>
                        Choose how checkout charges this pre-order. Deposit requires a deposit type and amount below.
                    </span>
                </div>
                {depositMode ? (
                    <div className={cx(ui.fieldGrid, 'mt-4')}>
                        <Field
                            label="Deposit type"
                            required
                            hint="Fixed amount or percentage of the charged price."
                            error={errors['preorder.deposit_type']}
                        >
                            <Select wide value={config.deposit_type || 'percent'} onChange={(e) => setConfig({ deposit_type: e.target.value })}>
                                <option value="fixed">Fixed amount</option>
                                <option value="percent">Percentage</option>
                            </Select>
                        </Field>
                        <Field
                            label="Deposit amount"
                            required
                            hint={
                                config.deposit_type === 'fixed'
                                    ? 'Fixed amount ≥ 0 and not greater than the product price.'
                                    : 'Percentage from 0 to 100 of the charged price.'
                            }
                            error={errors['preorder.deposit_value']}
                        >
                            <Input wide type="number" min="0" step="0.01" value={config.deposit_value ?? ''} onChange={(e) => setConfig({ deposit_value: e.target.value })} />
                        </Field>
                        {depositPreview ? (
                            <div className="col-span-full rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm">
                                <p>Customer pays: {currencySymbol}{depositPreview.deposit}</p>
                                <p>Remaining balance: {currencySymbol}{depositPreview.remaining}</p>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </Section>

            <Section title="Quantity limits">
                <div className={ui.fieldGrid3}>
                    <Field
                        label="Minimum pre-order quantity"
                        required
                        hint="Integer ≥ 1. Lowest quantity a customer can pre-order in one order."
                        error={errors['preorder.min_quantity']}
                    >
                        <Input wide type="number" min="1" value={config.min_quantity ?? 1} onChange={(e) => setConfig({ min_quantity: e.target.value })} />
                    </Field>
                    <Field
                        label="Maximum per customer"
                        hint="Integer ≥ minimum. Cap across all active pre-orders of this product for one signed-in customer. Leave empty for no customer cap."
                        error={errors['preorder.max_quantity']}
                    >
                        <Input wide type="number" min="1" value={config.max_quantity ?? ''} onChange={(e) => setConfig({ max_quantity: e.target.value })} />
                    </Field>
                    <Field
                        label="Total pre-order limit"
                        hint="Integer ≥ 1 for a store-wide cap. Leave empty for unlimited."
                        error={errors['preorder.total_limit']}
                    >
                        <Input wide type="number" min="1" value={config.total_limit ?? ''} onChange={(e) => setConfig({ total_limit: e.target.value })} />
                    </Field>
                </div>
                <div className="mt-4 grid grid-cols-2 gap-3 max-w-md text-sm">
                    <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                        <span className={ui.label}>Pre-orders placed</span>
                        <p>{config.orders_placed ?? 0}</p>
                        <span className={ui.fieldHint}>Counted units already sold under this pre-order.</span>
                    </div>
                    <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                        <span className={ui.label}>Pre-orders remaining</span>
                        <p>{remainingLabel}</p>
                        <span className={ui.fieldHint}>Total limit minus placed, or Unlimited when no cap.</span>
                    </div>
                </div>
            </Section>

            <Section title="Customer messaging">
                <Field
                    label="Pre-order message"
                    hint="Text shown to customers on the product page. Leave blank to hide."
                    error={errors['preorder.message']}
                >
                    <Textarea rows="4" value={config.message || ''} onChange={(e) => setConfig({ message: e.target.value })} />
                </Field>
                <div className={cx(ui.fieldGrid3, 'mt-3')}>
                    <Field
                        label="Show pre-order badge"
                        hint="Shows a pre-order badge on storefront product UI."
                    >
                        <Toggle value={!!config.show_badge} ariaLabel="Show pre-order badge" onChange={(value) => setConfig({ show_badge: value })} />
                    </Field>
                    <Field
                        label="Show release date"
                        hint="Displays the release date when one is set."
                    >
                        <Toggle value={!!config.show_release_date} ariaLabel="Show release date" onChange={(value) => setConfig({ show_release_date: value })} />
                    </Field>
                    <Field
                        label="Show expected delivery date"
                        hint="Displays the expected delivery date when one is set."
                    >
                        <Toggle value={!!config.show_delivery_date} ariaLabel="Show expected delivery date" onChange={(value) => setConfig({ show_delivery_date: value })} />
                    </Field>
                    <Field
                        label="Enable pre-order countdown on product page"
                        hint="Countdown to the next start, end, or release time on the PDP."
                    >
                        <Toggle value={!!config.show_countdown_pdp} ariaLabel="Enable pre-order countdown on product page" onChange={(value) => setConfig({ show_countdown_pdp: value })} />
                    </Field>
                    <Field
                        label="Enable pre-order countdown on product listing"
                        hint="Same countdown on category/listing cards."
                    >
                        <Toggle value={!!config.show_countdown_listing} ariaLabel="Enable pre-order countdown on product listing" onChange={(value) => setConfig({ show_countdown_listing: value })} />
                    </Field>
                    <Field
                        label="Enable pre-order image label"
                        hint="Overlay label on the product image for pre-order items."
                    >
                        <Toggle value={!!config.show_image_label} ariaLabel="Enable pre-order image label" onChange={(value) => setConfig({ show_image_label: value })} />
                    </Field>
                    <Field
                        label="Enable Notify Me"
                        hint="Shows a Notify Me button next to Pre-order on the product page. Logged-in customers can subscribe for an email when this product becomes available for purchase."
                        error={errors['preorder.notify_me_enabled']}
                    >
                        <Toggle
                            value={!!config.notify_me_enabled}
                            ariaLabel="Enable Notify Me"
                            onChange={(value) => setConfig({ notify_me_enabled: value })}
                        />
                    </Field>
                </div>
                {config.notify_me_enabled ? (
                    <Alert type="info" className="mt-3 mb-0">
                        <p className="text-sm">
                            Automatic Notify Me emails only send when the Laravel scheduler cron is configured on the server.
                            The server admin must add this cron; no separate Notify Me cron is required.
                        </p>
                        <p className={cx(ui.muted, 'mt-2 text-xs')}>
                            Recommended production cron (every minute):
                        </p>
                        <pre className={cx(ui.codePreview, 'mt-1 font-mono')}>
                            {'* * * * * cd /path/to/wentocart && php artisan schedule:run >> /dev/null 2>&1'}
                        </pre>
                    </Alert>
                ) : null}
            </Section>

            <Section title="Order handling">
                <div className="space-y-1">
                    <Label>Fulfillment rule</Label>
                    <ChoiceCards
                        name="preorder-fulfillment"
                        options={[
                            { value: 'auto', label: 'Fulfill when product becomes available', hint: 'Marks lines ready when release passes or the order moves to processing.' },
                            { value: 'manual', label: 'Manual fulfillment', hint: 'Staff mark pre-order lines fulfilled themselves.' },
                        ]}
                        value={config.fulfillment_rule}
                        onChange={(value) => setConfig({ fulfillment_rule: value })}
                    />
                    <span className={ui.fieldHint}>
                        Controls how pre-order order lines move to fulfilled after purchase.
                    </span>
                </div>
            </Section>
                </>
            )}
        </div>
    );
}
