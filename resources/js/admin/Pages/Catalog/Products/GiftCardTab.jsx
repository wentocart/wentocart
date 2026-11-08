import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    Button,
    Checkbox,
    ChoiceCards,
    Field,
    Icon,
    Input,
    moveItem,
    SearchSelect,
    Select,
    Toggle,
    ui,
    cx,
    t,
} from '../../../ui';

function Section({ title, hint, children }) {
    return (
        <section className={ui.sectionCard}>
            {title ? (
                <div className={ui.sectionHead}>
                    <h2 className={ui.sectionTitle}>{title}</h2>
                    {hint ? <p className={ui.sectionHint}>{hint}</p> : null}
                </div>
            ) : null}
            {children}
        </section>
    );
}

function nextKey(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export function defaultGiftCardConfig() {
    return {
        amount_type: 'fixed',
        min_amount: '',
        max_amount: '',
        default_amount: '',
        amount_step: '',
        delivery_method: 'email',
        recipient_name_enabled: true,
        recipient_name_required: false,
        recipient_email_enabled: true,
        recipient_email_required: true,
        sender_name_enabled: true,
        sender_name_required: false,
        gift_message_enabled: true,
        gift_message_required: false,
        validity_type: 'never',
        validity_days: '',
        expiry_date: '',
        allow_online_redemption: true,
        allow_offline_redemption: false,
        usage_type: 'multiple',
        allow_partial_redemption: true,
        code_length: 12,
        code_prefix: '',
        code_charset: 'alphanumeric',
        code_uppercase: true,
        min_order_amount: '',
        max_order_amount: '',
        restrictions: {
            category_ids: [],
            product_ids: [],
            exclude_category_ids: [],
            exclude_product_ids: [],
        },
    };
}

/**
 * Restrictions arrive from the API in a fixed shape; normalise so the form can
 * bind to it safely even for products created before restrictions existed.
 */
export function normalizeRestrictions(restrictions) {
    const src = restrictions && typeof restrictions === 'object' ? restrictions : {};
    const list = (key) => (Array.isArray(src[key]) ? src[key].map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0) : []);

    return {
        category_ids: list('category_ids'),
        product_ids: list('product_ids'),
        exclude_category_ids: list('exclude_category_ids'),
        exclude_product_ids: list('exclude_product_ids'),
    };
}

export function giftCardPayload(config, amounts) {
    return {
        gift_card: {
            ...config,
            min_amount: config.min_amount === '' ? null : config.min_amount,
            max_amount: config.max_amount === '' ? null : config.max_amount,
            default_amount: config.default_amount === '' ? null : config.default_amount,
            amount_step: config.amount_step === '' ? null : config.amount_step,
            validity_days: config.validity_days === '' ? null : config.validity_days,
            expiry_date: config.expiry_date === '' ? null : config.expiry_date,
            code_prefix: config.code_prefix === '' ? null : config.code_prefix,
            min_order_amount: config.min_order_amount === '' ? null : config.min_order_amount,
            max_order_amount: config.max_order_amount === '' ? null : config.max_order_amount,
            restrictions: normalizeRestrictions(config.restrictions),
        },
        gift_card_amounts: amounts.map((row, index) => ({
            id: row.id || null,
            value: row.value,
            selling_price: row.selling_price === '' ? null : row.selling_price,
            is_enabled: !!row.is_enabled,
            position: index,
        })),
    };
}

function RecipientFieldRow({ label, enabled, required, onEnabled, onRequired }) {
    return (
        <div className="grid grid-cols-[1fr_auto_auto] items-center gap-3 py-2 border-b border-neutral-100 last:border-b-0">
            <span className={ui.label}>{label}</span>
            <Checkbox label="Enabled" checked={enabled} onChange={(e) => onEnabled(e.target.checked)} />
            <Checkbox label="Required" checked={required} disabled={!enabled} onChange={(e) => onRequired(e.target.checked)} />
        </div>
    );
}

function AmountEditor({ draft, onChange, onSave, onCancel, currencySymbol, errors = {} }) {
    return (
        <div className={cx(ui.fieldGrid, 'mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4')}>
            <Field label="Gift card value" error={errors.value}>
                <Input wide type="number" min="0" step="0.01" value={draft.value} onChange={(e) => onChange({ ...draft, value: e.target.value })} />
            </Field>
            <Field label="Selling price" hint="Leave empty to use gift card value." error={errors.selling_price}>
                <Input wide type="number" min="0" step="0.01" value={draft.selling_price} onChange={(e) => onChange({ ...draft, selling_price: e.target.value })} />
            </Field>
            <div className="col-span-full flex items-center gap-3">
                <Checkbox label="Enabled" checked={!!draft.is_enabled} onChange={(e) => onChange({ ...draft, is_enabled: e.target.checked })} />
                <div className="ml-auto flex gap-2">
                    <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                    <Button type="button" onClick={onSave}>Save amount</Button>
                </div>
            </div>
        </div>
    );
}

export default function GiftCardTab({
    config,
    amounts,
    onConfigChange,
    onAmountsChange,
    errors = {},
    currencySymbol = '$',
    categories = [],
    productId = null,
}) {
    const [editor, setEditor] = useState(null);
    const [draft, setDraft] = useState(null);
    const [localErrors, setLocalErrors] = useState({});
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);

    const sortedAmounts = useMemo(
        () => [...amounts].sort((a, b) => (a.position ?? 0) - (b.position ?? 0)),
        [amounts],
    );

    const setConfig = (patch) => onConfigChange({ ...config, ...patch });

    const restrictions = normalizeRestrictions(config.restrictions);

    const setFor = (key) => new Set(restrictions[key]);

    const toggleId = (key, id, on) => {
        const numeric = Number(id);
        const next = new Set(restrictions[key]);
        if (on) {
            next.add(numeric);
        } else {
            next.delete(numeric);
        }
        setConfig({ restrictions: { ...restrictions, [key]: Array.from(next) } });
    };

    const startCreate = () => {
        setEditor('create');
        setDraft({ key: nextKey('amount'), value: '', selling_price: '', is_enabled: true, position: amounts.length });
        setLocalErrors({});
    };

    const startEdit = (row) => {
        setEditor(row.key);
        setDraft({ ...row });
        setLocalErrors({});
    };

    const validateDraft = (row) => {
        const next = {};
        const value = Number(row.value);
        if (row.value === '' || Number.isNaN(value) || value <= 0) {
            next.value = 'Amount must be greater than zero.';
        }
        const duplicate = sortedAmounts.some((item) => item.key !== row.key && Number(item.value) === value);
        if (!next.value && duplicate) {
            next.value = 'Duplicate amounts are not allowed.';
        }
        if (row.selling_price !== '' && Number(row.selling_price) < 0) {
            next.selling_price = 'Selling price cannot be negative.';
        }
        return next;
    };

    const saveDraft = () => {
        const nextErrors = validateDraft(draft);
        setLocalErrors(nextErrors);
        if (Object.keys(nextErrors).length) {
            return;
        }
        if (editor === 'create') {
            onAmountsChange([...amounts, { ...draft, position: amounts.length }]);
        } else {
            onAmountsChange(amounts.map((row) => (row.key === draft.key ? { ...draft } : row)));
        }
        setEditor(null);
        setDraft(null);
    };

    const removeAmount = (key) => {
        onAmountsChange(amounts.filter((row) => row.key !== key).map((row, index) => ({ ...row, position: index })));
    };

    const reorderAmount = (from, to) => {
        if (from === to || from < 0 || to < 0 || from >= sortedAmounts.length || to >= sortedAmounts.length) {
            return;
        }
        onAmountsChange(moveItem(sortedAmounts, from, to).map((row, index) => ({ ...row, position: index })));
    };

    return (
        <div className="space-y-4">
            <Section title="Gift card amount type">
                <ChoiceCards
                    name="gift-card-amount-type"
                    options={[
                        { value: 'fixed', label: 'Fixed amount', hint: 'Offer predefined gift card values.' },
                        { value: 'custom', label: 'Customer chooses amount', hint: 'Let the customer enter an amount within your limits.' },
                    ]}
                    value={config.amount_type}
                    onChange={(value) => setConfig({ amount_type: value })}
                />
            </Section>

            {config.amount_type === 'fixed' ? (
                <Section title="Fixed amounts">
                    <div className="flex items-center justify-between gap-3 mb-3">
                        <p className={ui.muted}>Configure predefined gift card values. Selling price can differ from gift card value for promotions.</p>
                        <Button type="button" icon="plus" onClick={startCreate}>Add amount</Button>
                    </div>
                    {editor === 'create' && draft ? (
                        <AmountEditor
                            draft={draft}
                            onChange={setDraft}
                            onSave={saveDraft}
                            onCancel={() => { setEditor(null); setDraft(null); }}
                            currencySymbol={currencySymbol}
                            errors={localErrors}
                        />
                    ) : null}
                    <div className={ui.tableWrap}>
                        <table className={ui.table}>
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Gift card value</th>
                                    <th>Selling price</th>
                                    <th>Status</th>
                                    <th className="w-28">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sortedAmounts.length === 0 ? (
                                    <tr>
                                        <td colSpan="5" className={ui.muted}>No fixed amounts yet.</td>
                                    </tr>
                                ) : sortedAmounts.map((row, index) => (
                                    <tr
                                        key={row.key}
                                        className={cx(overIndex === index && fromIndex !== null && fromIndex !== index && ui.dragOver)}
                                        onDragOver={(e) => {
                                            if (fromIndex === null || sortedAmounts.length < 2) {
                                                return;
                                            }
                                            e.preventDefault();
                                            setOverIndex(index);
                                        }}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            if (fromIndex === null || fromIndex === index) {
                                                setFromIndex(null);
                                                setOverIndex(null);
                                                return;
                                            }
                                            reorderAmount(fromIndex, index);
                                            setFromIndex(null);
                                            setOverIndex(null);
                                        }}
                                    >
                                        <td>
                                            <div className={ui.row}>
                                                {sortedAmounts.length > 1 ? (
                                                    <button
                                                        type="button"
                                                        className={ui.dragHandle}
                                                        title="Drag to reorder"
                                                        aria-label="Reorder amount"
                                                        draggable
                                                        onDragStart={(e) => {
                                                            e.dataTransfer.setData('text/plain', String(index));
                                                            e.dataTransfer.effectAllowed = 'move';
                                                            setFromIndex(index);
                                                        }}
                                                        onDragEnd={() => {
                                                            setFromIndex(null);
                                                            setOverIndex(null);
                                                        }}
                                                    >
                                                        <Icon name="grip" />
                                                    </button>
                                                ) : null}
                                                <span>{index + 1}</span>
                                            </div>
                                        </td>
                                        <td>{currencySymbol}{row.value}</td>
                                        <td>{currencySymbol}{row.selling_price === '' ? row.value : row.selling_price}</td>
                                        <td>{row.is_enabled ? 'Enabled' : 'Disabled'}</td>
                                        <td>
                                            <div className="flex items-center gap-1">
                                                <Button type="button" variant="ghost" icon="edit" aria-label="Edit" onClick={() => startEdit(row)} />
                                                <Button type="button" variant="ghost" icon="trash" aria-label="Delete" onClick={() => removeAmount(row.key)} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {editor !== 'create' && draft ? (
                        <AmountEditor
                            draft={draft}
                            onChange={setDraft}
                            onSave={saveDraft}
                            onCancel={() => { setEditor(null); setDraft(null); }}
                            currencySymbol={currencySymbol}
                            errors={localErrors}
                        />
                    ) : null}
                    {errors.gift_card_amounts ? <p className="text-sm text-red-600 mt-2">{errors.gift_card_amounts}</p> : null}
                </Section>
            ) : (
                <Section title="Custom amount limits">
                    <div className={ui.fieldGrid}>
                        <Field label="Minimum amount" error={errors['gift_card.min_amount']}>
                            <Input wide type="number" min="0" step="0.01" value={config.min_amount} onChange={(e) => setConfig({ min_amount: e.target.value })} />
                        </Field>
                        <Field label="Maximum amount" error={errors['gift_card.max_amount']}>
                            <Input wide type="number" min="0" step="0.01" value={config.max_amount} onChange={(e) => setConfig({ max_amount: e.target.value })} />
                        </Field>
                        <Field label="Default amount" error={errors['gift_card.default_amount']}>
                            <Input wide type="number" min="0" step="0.01" value={config.default_amount} onChange={(e) => setConfig({ default_amount: e.target.value })} />
                        </Field>
                        <Field label="Amount step" error={errors['gift_card.amount_step']}>
                            <Input wide type="number" min="0" step="0.01" value={config.amount_step} onChange={(e) => setConfig({ amount_step: e.target.value })} />
                        </Field>
                    </div>
                </Section>
            )}

            <Section title="Delivery">
                <ChoiceCards
                    name="gift-card-delivery"
                    options={[
                        { value: 'email', label: 'Email', hint: 'Deliver the gift card to a recipient email after purchase.' },
                        { value: 'account', label: 'Customer account', hint: 'Store the gift card on the purchaser account.' },
                        { value: 'both', label: 'Both', hint: 'Email delivery and customer account.' },
                    ]}
                    value={config.delivery_method}
                    onChange={(value) => setConfig({ delivery_method: value })}
                />
            </Section>

            <Section title="Recipient fields">
                <p className={cx(ui.muted, 'mb-3')}>Configure which recipient details customers can provide at checkout. Values are stored on the issued gift card, not the product.</p>
                <RecipientFieldRow
                    label="Recipient name"
                    enabled={config.recipient_name_enabled}
                    required={config.recipient_name_required}
                    onEnabled={(value) => setConfig({ recipient_name_enabled: value, recipient_name_required: value ? config.recipient_name_required : false })}
                    onRequired={(value) => setConfig({ recipient_name_required: value })}
                />
                <RecipientFieldRow
                    label="Recipient email"
                    enabled={config.recipient_email_enabled}
                    required={config.recipient_email_required}
                    onEnabled={(value) => setConfig({ recipient_email_enabled: value, recipient_email_required: value ? config.recipient_email_required : false })}
                    onRequired={(value) => setConfig({ recipient_email_required: value })}
                />
                <RecipientFieldRow
                    label="Sender name"
                    enabled={config.sender_name_enabled}
                    required={config.sender_name_required}
                    onEnabled={(value) => setConfig({ sender_name_enabled: value, sender_name_required: value ? config.sender_name_required : false })}
                    onRequired={(value) => setConfig({ sender_name_required: value })}
                />
                <RecipientFieldRow
                    label="Gift message"
                    enabled={config.gift_message_enabled}
                    required={config.gift_message_required}
                    onEnabled={(value) => setConfig({ gift_message_enabled: value, gift_message_required: value ? config.gift_message_required : false })}
                    onRequired={(value) => setConfig({ gift_message_required: value })}
                />
            </Section>

            <Section title="Validity">
                <ChoiceCards
                    name="gift-card-validity"
                    options={[
                        { value: 'never', label: 'Never expires' },
                        { value: 'days', label: 'Expires after X days', hint: 'Expiry is calculated from issue date.' },
                        { value: 'fixed_date', label: 'Fixed expiry date' },
                    ]}
                    value={config.validity_type}
                    onChange={(value) => setConfig({ validity_type: value })}
                />
                {config.validity_type === 'days' ? (
                    <div className="mt-4 max-w-xs">
                        <Field label="Validity days" error={errors['gift_card.validity_days']}>
                            <Input wide type="number" min="1" value={config.validity_days} onChange={(e) => setConfig({ validity_days: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
                {config.validity_type === 'fixed_date' ? (
                    <div className="mt-4 max-w-xs">
                        <Field label="Expiry date" error={errors['gift_card.expiry_date']}>
                            <Input wide type="date" value={config.expiry_date} onChange={(e) => setConfig({ expiry_date: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
            </Section>

            <Section title="Redemption">
                <div className="space-y-4">
                    <Toggle label="Allow online redemption" value={!!config.allow_online_redemption} onChange={(value) => setConfig({ allow_online_redemption: value })} />
                    <Toggle label="Allow offline/store redemption" value={!!config.allow_offline_redemption} onChange={(value) => setConfig({ allow_offline_redemption: value })} />
                    <ChoiceCards
                        name="gift-card-usage"
                        options={[
                            { value: 'single', label: 'Single use' },
                            { value: 'multiple', label: 'Multiple use' },
                        ]}
                        value={config.usage_type}
                        onChange={(value) => setConfig({ usage_type: value })}
                    />
                    <Toggle label="Allow partial redemption" value={!!config.allow_partial_redemption} onChange={(value) => setConfig({ allow_partial_redemption: value })} />
                    <div className={ui.fieldGrid}>
                        <Field label="Minimum order amount" hint="Optional redemption restriction.">
                            <Input wide type="number" min="0" step="0.01" value={config.min_order_amount} onChange={(e) => setConfig({ min_order_amount: e.target.value })} />
                        </Field>
                        <Field label="Maximum order amount" hint="Optional redemption restriction.">
                            <Input wide type="number" min="0" step="0.01" value={config.max_order_amount} onChange={(e) => setConfig({ max_order_amount: e.target.value })} />
                        </Field>
                    </div>
                </div>
            </Section>

            <Section title="Restrictions">
                <p className={cx(ui.muted, 'mb-3')}>
                    Limit where this gift card can be used. Include lists restrict the card to those items;
                    exclude lists block those items. Leave everything empty for no restriction.
                </p>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <h3 className={ui.label}>Allowed categories</h3>
                        <p className={cx(ui.muted, 'mb-2')}>Cart must only contain products from these categories.</p>
                        <div className="max-h-48 overflow-y-auto rounded-lg border border-neutral-200 p-3 space-y-1">
                            {categories.length === 0 ? (
                                <p className={ui.muted}>No categories available.</p>
                            ) : categories.map((row) => (
                                <Checkbox
                                    key={`inc-cat-${row.id}`}
                                    label={row.name}
                                    checked={setFor('category_ids').has(Number(row.id))}
                                    onChange={(e) => toggleId('category_ids', row.id, e.target.checked)}
                                />
                            ))}
                        </div>
                    </div>

                    <div>
                        <h3 className={ui.label}>Excluded categories</h3>
                        <p className={cx(ui.muted, 'mb-2')}>Cart must not contain products from these categories.</p>
                        <div className="max-h-48 overflow-y-auto rounded-lg border border-neutral-200 p-3 space-y-1">
                            {categories.length === 0 ? (
                                <p className={ui.muted}>No categories available.</p>
                            ) : categories.map((row) => (
                                <Checkbox
                                    key={`exc-cat-${row.id}`}
                                    label={row.name}
                                    checked={setFor('exclude_category_ids').has(Number(row.id))}
                                    onChange={(e) => toggleId('exclude_category_ids', row.id, e.target.checked)}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                {errors['gift_card.restrictions'] ? (
                    <p className="text-sm text-red-600 mt-2">{errors['gift_card.restrictions']}</p>
                ) : null}
            </Section>

            <Section title="Code generation">
                <p className={cx(ui.muted, 'mb-3')}>Codes are generated when a gift card is issued after successful payment. They are never stored on the product.</p>
                <div className={ui.fieldGrid}>
                    <Field label="Code length">
                        <Input wide type="number" min="8" max="32" value={config.code_length} onChange={(e) => setConfig({ code_length: e.target.value })} />
                    </Field>
                    <Field label="Prefix">
                        <Input wide value={config.code_prefix} onChange={(e) => setConfig({ code_prefix: e.target.value })} />
                    </Field>
                    <Field label="Character set">
                        <Select wide value={config.code_charset} onChange={(e) => setConfig({ code_charset: e.target.value })}>
                            <option value="alphanumeric">Alphanumeric</option>
                            <option value="numeric">Numeric</option>
                            <option value="alpha">Alpha</option>
                        </Select>
                    </Field>
                    <div className="flex items-end pb-2">
                        <Checkbox label="Uppercase codes" checked={!!config.code_uppercase} onChange={(e) => setConfig({ code_uppercase: e.target.checked })} />
                    </div>
                </div>
            </Section>

            <ManualIssuePanel
                productId={productId}
                config={config}
                currencySymbol={currencySymbol}
            />
        </div>
    );
}

function defaultExpiryForConfig(config) {
    if (!config) {
        return '';
    }
    if (config.validity_type === 'days' && config.validity_days) {
        const days = Math.max(1, Number(config.validity_days) || 1);
        const date = new Date();
        date.setDate(date.getDate() + days);
        return date.toISOString().slice(0, 10);
    }
    if (config.validity_type === 'fixed_date' && config.expiry_date) {
        return String(config.expiry_date).slice(0, 10);
    }
    return '';
}

/**
 * Manual ledger issuance for an already-saved gift card product.
 * Product configuration itself is only edited above in this tab — not on a separate page.
 */
function ManualIssuePanel({ productId, config, currencySymbol }) {
    const pageErrors = usePage().props.errors || {};
    const [amount, setAmount] = useState('');
    const [recipientName, setRecipientName] = useState('');
    const [recipientEmail, setRecipientEmail] = useState('');
    const [senderName, setSenderName] = useState('');
    const [giftMessage, setGiftMessage] = useState('');
    const [expiresAt, setExpiresAt] = useState(() => defaultExpiryForConfig(config));
    const [status, setStatus] = useState('active');
    const [reason, setReason] = useState('');
    const [reference, setReference] = useState('');
    const [customerId, setCustomerId] = useState('');
    const [customerSelected, setCustomerSelected] = useState([]);
    const [orderId, setOrderId] = useState('');
    const [orderSelected, setOrderSelected] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setExpiresAt(defaultExpiryForConfig(config));
    }, [config?.validity_type, config?.validity_days, config?.expiry_date]);

    if (!productId) {
        return (
            <Section
                title={t('admin.gift_cards.issue_card', 'Issue gift card')}
                hint={t('admin.gift_cards.save_product_first', 'Save this product first, then you can manually issue a card from this tab.')}
            >
                <p className={ui.muted}>
                    {t(
                        'admin.gift_cards.configure_on_product',
                        'Gift card amounts, delivery, and redemption rules are configured on this product tab. There is no separate gift card setup page.',
                    )}
                </p>
            </Section>
        );
    }

    const cannotChangeExpiry = config?.validity_type === 'never';

    const submit = () => {
        if (busy || !amount) {
            return;
        }
        setBusy(true);
        router.post('/admin/gift-cards', {
            product_id: productId,
            amount,
            recipient_name: recipientName,
            recipient_email: recipientEmail,
            sender_name: senderName,
            gift_message: giftMessage,
            expires_at: expiresAt || null,
            status,
            reason,
            reference,
            customer_id: customerId || null,
            order_id: orderId || null,
        }, {
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Section
            title={t('admin.gift_cards.issue_card', 'Issue gift card')}
            hint={t('admin.gift_cards.issue_hint', 'Cards issued here are recorded on the ledger with your admin account as the source.')}
        >
            <div className={ui.fieldGrid}>
                <Field
                    label={t('admin.gift_cards.amount', 'Amount')}
                    hint={currencySymbol || undefined}
                    error={pageErrors.amount}
                    required
                >
                    <Input wide type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.status', 'Status')}>
                    <Select wide value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </Select>
                </Field>
                <Field
                    label={t('admin.gift_cards.expires_at', 'Expires')}
                    hint={cannotChangeExpiry ? t('admin.gift_cards.never_expires', 'This product never expires; leave empty.') : undefined}
                    error={pageErrors.expires_at}
                >
                    <Input wide type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} disabled={cannotChangeExpiry} />
                </Field>
                <Field label={t('admin.gift_cards.recipient_name', 'Recipient name')} error={pageErrors.recipient_name}>
                    <Input wide value={recipientName} onChange={(e) => setRecipientName(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.recipient_email', 'Recipient email')} error={pageErrors.recipient_email}>
                    <Input wide type="email" value={recipientEmail} onChange={(e) => setRecipientEmail(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.sender_name', 'Sender name')} error={pageErrors.sender_name}>
                    <Input wide value={senderName} onChange={(e) => setSenderName(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.gift_message', 'Gift message')} error={pageErrors.gift_message}>
                    <Input wide value={giftMessage} onChange={(e) => setGiftMessage(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.reason', 'Reason')}>
                    <Input wide value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
                <Field label={t('admin.gift_cards.reference_code', 'Reference')}>
                    <Input wide value={reference} onChange={(e) => setReference(e.target.value)} />
                </Field>
            </div>

            <div className={`${ui.fieldGrid} mt-4`}>
                <SearchSelect
                    label={t('admin.gift_cards.customer', 'Customer')}
                    hint={t('admin.gift_cards.customer_hint', 'Links the card to a customer account.')}
                    searchUrl="/admin/gift-cards/customers"
                    value={customerId}
                    selectedOptions={customerSelected}
                    multiple={false}
                    onChange={(id, picked) => {
                        setCustomerId(id || '');
                        setCustomerSelected(picked ? [picked] : []);
                    }}
                    placeholder={t('admin.gift_cards.customer_search', 'Search by name or email…')}
                    error={pageErrors.customer_id}
                />
                <SearchSelect
                    label={t('admin.gift_cards.order', 'Order')}
                    hint={t('admin.gift_cards.order_hint', 'Optional order reference for this card.')}
                    searchUrl="/admin/gift-cards/orders"
                    value={orderId}
                    selectedOptions={orderSelected}
                    multiple={false}
                    onChange={(id, picked) => {
                        setOrderId(id || '');
                        setOrderSelected(picked ? [picked] : []);
                    }}
                    placeholder={t('admin.gift_cards.order_placeholder', 'Search by order number…')}
                    error={pageErrors.order_id}
                />
            </div>

            {pageErrors.product_id ? (
                <p className="text-sm text-red-600 mt-2">{pageErrors.product_id}</p>
            ) : null}

            <div className={`${ui.toolbar} mt-4`}>
                <Button onClick={submit} disabled={busy || !amount}>
                    {t('admin.gift_cards.issue_card', 'Issue gift card')}
                </Button>
                <Button variant="secondary" href="/admin/gift-cards">
                    {t('admin.gift_cards.view_issued', 'View issued cards')}
                </Button>
            </div>
        </Section>
    );
}
