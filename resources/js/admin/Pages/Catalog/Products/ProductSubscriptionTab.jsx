import { useState } from 'react';
import { createPortal } from 'react-dom';
import {
    Alert,
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    LocalizedField,
    Select,
    SelectionCheckbox,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    Toggle,
    ui,
    cx,
    useGrowl,
    useListSelection,
} from '../../../ui';

function nextKey(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function roundMoney(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

export function subscriptionPrice(regular, discountType, discountValue, explicitPrice) {
    const base = Math.max(0, roundMoney(regular));
    if (discountType === 'percent') {
        const pct = Math.min(100, Math.max(0, Number(discountValue) || 0));

        return Math.max(0, roundMoney(base * (1 - (pct / 100))));
    }
    if (discountType === 'fixed') {
        const amount = Math.max(0, Number(discountValue) || 0);

        return Math.max(0, roundMoney(base - Math.min(amount, base)));
    }

    return Math.max(0, roundMoney(explicitPrice));
}

export function assignedPlansPayload(rows) {
    return rows.map((row) => ({
        subscription_plan_id: row.subscription_plan_id,
        price: row.price === '' ? null : row.price,
        discount_type: row.discount_type || null,
        discount_value: row.discount_type ? (row.discount_value === '' ? 0 : row.discount_value) : null,
        is_enabled: !!row.is_enabled,
        is_default: !!row.is_default,
    }));
}

export const PRODUCT_REQUIRES_ACTIVE_PLAN = 'Please add at least one active subscription plan before saving this product.';
export const PLAN_SAVED_MESSAGE = 'Subscription plan saved.';
export const PLAN_ENABLE_DEFAULT_HINT = 'Enable this plan before setting it as default.';

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    };
}

export function applyAssignedPlanDraft(rows, next) {
    let updated = rows.map((row) => (row.key === next.key
        ? { ...row, ...next }
        : row));
    if (next.is_default && next.is_enabled) {
        updated = updated.map((row) => ({
            ...row,
            is_default: row.key === next.key,
        }));
    } else if (!updated.some((row) => row.is_default && row.is_enabled)) {
        const fallback = updated.find((row) => row.is_enabled);
        updated = updated.map((row) => ({ ...row, is_default: fallback ? row.key === fallback.key : false }));
    }

    return updated;
}

function rowsFromApi(plans) {
    return (plans || []).map((row) => ({
        key: `saved-${row.subscription_plan_id}`,
        ...row,
    }));
}

export function subscriptionPurchaseSelected(purchaseOption) {
    return purchaseOption === 'subscription' || purchaseOption === 'both';
}

export function productRequiresActivePlan(enabled, purchaseOption) {
    return !!enabled && subscriptionPurchaseSelected(purchaseOption);
}

export function hasActiveAssignedPlan(rows) {
    return (rows || []).some((row) => (
        Number(row.subscription_plan_id) > 0
        && row.is_enabled !== false
        && row.plan_enabled !== false
    ));
}

/** Admin create URL; Cancel from create can return to the product Subscription tab. */
export function subscriptionPlanCreateHref(productId = null) {
    const base = '/admin/subscription-plans/create';
    if (!productId) {
        return base;
    }

    const returnTo = `/admin/products/${productId}/edit?tab=subscription`;

    return `${base}?return=${encodeURIComponent(returnTo)}`;
}

export function hasEnabledCatalogPlans(availablePlans) {
    return (availablePlans || []).some((plan) => plan.is_enabled);
}

function purchaseFromChecks(oneTime, subscription) {
    if (oneTime && subscription) {
        return 'both';
    }
    if (subscription) {
        return 'subscription';
    }

    return 'one_time';
}

function discountLabel(row, currencySymbol) {
    if (row.discount_type === 'percent') {
        return `${row.discount_value || 0}%`;
    }
    if (row.discount_type === 'fixed') {
        return `${currencySymbol}${row.discount_value || 0}`;
    }

    return 'None';
}

function validateDraft(draft, regular) {
    const errors = {};
    const price = Number(draft.price);
    if (draft.price !== '' && (Number.isNaN(price) || price < 0)) {
        errors.price = 'Subscription price cannot be negative.';
    }
    const discount = Number(draft.discount_value);
    if (draft.discount_type && (Number.isNaN(discount) || discount < 0)) {
        errors.discount_value = 'Discount cannot be negative.';
    }
    if (draft.discount_type === 'percent' && discount > 100) {
        errors.discount_value = 'Percentage discount cannot exceed 100%.';
    }
    if (draft.discount_type === 'fixed' && discount > regular) {
        errors.discount_value = 'Fixed discount cannot exceed the regular product price.';
    }

    return errors;
}

const FREQUENCY_LABELS = {
    hour: 'Hour',
    day: 'Day',
    week: 'Week',
    month: 'Month',
    year: 'Year',
};

export function planFrequencyLabel(value) {
    if (!value) {
        return '—';
    }

    return FREQUENCY_LABELS[value] || String(value);
}

export function selectedPlansLabel(count) {
    const n = Math.max(0, Number(count) || 0);
    if (n === 1) {
        return '1 plan selected';
    }

    return `${n} plans selected`;
}

/** Build one local assignment row from a catalog plan (picker / form state). */
export function assignmentRowFromPlan(plan, { regularPrice = 0, isDefault = false, key = null } = {}) {
    return {
        key: key || nextKey('plan'),
        subscription_plan_id: plan.id,
        name: plan.name,
        billing_summary: plan.billing_summary,
        trial_summary: plan.trial_summary,
        duration_summary: plan.duration_summary,
        trial_enabled: !!plan.trial_enabled,
        trial_duration: plan.trial_duration,
        trial_cycle: plan.trial_cycle,
        trial_frequency: plan.trial_frequency,
        cycle: plan.cycle,
        frequency: plan.frequency,
        duration: plan.duration,
        plan_enabled: !!plan.is_enabled,
        price: roundMoney(regularPrice),
        discount_type: null,
        discount_value: '',
        is_enabled: true,
        is_default: !!isDefault,
    };
}

/**
 * Merge selected catalog plans into assigned rows.
 * Skips duplicates already on the product; first new row becomes default when none assigned yet.
 */
export function mergeSelectedPlans(rows, selectedPlans, regularPrice = 0) {
    const current = Array.isArray(rows) ? rows : [];
    const used = new Set(current.map((row) => Number(row.subscription_plan_id)).filter((id) => id > 0));
    const additions = [];
    const needsDefault = current.length === 0;

    (selectedPlans || []).forEach((plan) => {
        const id = Number(plan?.id);
        if (!id || used.has(id)) {
            return;
        }
        used.add(id);
        additions.push(assignmentRowFromPlan(plan, {
            regularPrice,
            isDefault: needsDefault && additions.length === 0,
        }));
    });

    return [...current, ...additions];
}

/** Structured plan facts for picker / editor (uses presentPlan fields). */
export function PlanDetailRows({ plan }) {
    if (!plan) {
        return null;
    }

    const trialOn = !!plan.trial_enabled;

    return (
        <div className={ui.sectionSplitTight}>
            <div className={ui.detailRow}>
                <span className={ui.detailLabel}>Cycle</span>
                <span className={ui.detailValue}>{plan.cycle ?? '—'}</span>
            </div>
            <div className={ui.detailRow}>
                <span className={ui.detailLabel}>Frequency</span>
                <span className={ui.detailValue}>{planFrequencyLabel(plan.frequency)}</span>
            </div>
            <div className={ui.detailRow}>
                <span className={ui.detailLabel}>Billing</span>
                <span className={ui.detailValue}>{plan.billing_summary || '—'}</span>
            </div>
            <div className={ui.detailRow}>
                <span className={ui.detailLabel}>Trial</span>
                <span className={ui.detailValue}>{trialOn ? (plan.trial_summary || 'Yes') : 'No'}</span>
            </div>
            {trialOn ? (
                <>
                    <div className={ui.detailRow}>
                        <span className={ui.detailLabel}>Trial duration</span>
                        <span className={ui.detailValue}>{plan.trial_duration ?? '—'}</span>
                    </div>
                    <div className={ui.detailRow}>
                        <span className={ui.detailLabel}>Trial cycle</span>
                        <span className={ui.detailValue}>{plan.trial_cycle ?? '—'}</span>
                    </div>
                    <div className={ui.detailRow}>
                        <span className={ui.detailLabel}>Trial frequency</span>
                        <span className={ui.detailValue}>{planFrequencyLabel(plan.trial_frequency)}</span>
                    </div>
                </>
            ) : null}
            <div className={ui.detailRow}>
                <span className={ui.detailLabel}>Duration</span>
                <span className={ui.detailValue}>{plan.duration_summary || '—'}</span>
            </div>
        </div>
    );
}

function PlanPicker({ available, onAdd, onClose, createHref }) {
    const selection = useListSelection(available, { idKey: 'id' });
    const [selectError, setSelectError] = useState('');
    const selectedCount = selection.selectedCount;

    const submitSelected = () => {
        const picked = available.filter((plan) => selection.isSelected(plan.id));
        if (picked.length === 0) {
            setSelectError('Select at least one plan.');
            return;
        }
        setSelectError('');
        onAdd(picked);
    };

    return createPortal(
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={cx(ui.modal, '!max-w-4xl !max-h-[85vh]')}
                role="dialog"
                aria-modal="true"
                aria-labelledby="add-plan-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="add-plan-title" className={ui.modalTitle}>Add subscription plan</h2>
                        <p className={ui.modalSub}>Choose one or more active plans. Billing rules stay on the plan, not this product.</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label="Close" onClick={onClose} />
                </div>
                {available.length === 0 ? (
                    <div className={ui.modalBody}>
                        <div className={cx(ui.toolbar, 'items-center')}>
                            <p className={ui.muted}>
                                No unused active plans. Create a plan, then assign it here.
                            </p>
                            <Button icon="plus" variant="secondary" href={createHref}>
                                Create Plan
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className={cx(ui.modalBody, '!p-0 !space-y-0 flex min-h-0 flex-1 flex-col overflow-hidden')}>
                        {selectError ? (
                            <div className="px-5 pt-3">
                                <Alert type="error">{selectError}</Alert>
                            </div>
                        ) : null}
                        <div className={cx(ui.tableWrap, 'min-h-0 flex-1 overflow-auto')}>
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th check>
                                            <SelectionCheckbox
                                                checked={selection.allSelected}
                                                indeterminate={selection.someSelected}
                                                onChange={() => {
                                                    setSelectError('');
                                                    selection.toggleAll();
                                                }}
                                                ariaLabel="Select all plans"
                                            />
                                        </Th>
                                        <Th>Plan name</Th>
                                        <Th col="center">Cycle</Th>
                                        <Th>Frequency</Th>
                                        <Th>Billing</Th>
                                        <Th>Trial</Th>
                                        <Th>Duration</Th>
                                    </Tr>
                                </THead>
                                <tbody>
                                    {available.map((plan) => {
                                        const checked = selection.isSelected(plan.id);

                                        return (
                                            <Tr
                                                key={plan.id}
                                                selected={checked}
                                                onClick={() => {
                                                    setSelectError('');
                                                    selection.toggle(plan.id);
                                                }}
                                                className="hover:bg-violet-50/60"
                                                title={`Select ${plan.name || 'plan'}`}
                                                tabIndex={0}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' || e.key === ' ') {
                                                        e.preventDefault();
                                                        setSelectError('');
                                                        selection.toggle(plan.id);
                                                    }
                                                }}
                                            >
                                                <Td check>
                                                    <SelectionCheckbox
                                                        checked={checked}
                                                        onChange={() => {
                                                            setSelectError('');
                                                            selection.toggle(plan.id);
                                                        }}
                                                        ariaLabel={`Select ${plan.name || 'plan'}`}
                                                    />
                                                </Td>
                                                <Td>
                                                    <div className={ui.cellStrong}>{plan.name || 'Untitled plan'}</div>
                                                </Td>
                                                <Td col="center">{plan.cycle ?? '—'}</Td>
                                                <Td>{planFrequencyLabel(plan.frequency)}</Td>
                                                <Td>
                                                    <div className={ui.cellSub}>{plan.billing_summary || '—'}</div>
                                                </Td>
                                                <Td>
                                                    <div className={ui.cellSub}>
                                                        {plan.trial_enabled ? (plan.trial_summary || 'Yes') : 'No'}
                                                    </div>
                                                </Td>
                                                <Td>
                                                    <div className={ui.cellSub}>{plan.duration_summary || '—'}</div>
                                                </Td>
                                            </Tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                        </div>
                    </div>
                )}
                <div className={cx(ui.modalFoot, 'items-center justify-between gap-3')}>
                    <p className={ui.muted} aria-live="polite">
                        {available.length === 0 ? '' : selectedPlansLabel(selectedCount)}
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        {available.length > 0 ? (
                            <Button
                                type="button"
                                icon="plus"
                                disabled={selectedCount < 1}
                                onClick={submitSelected}
                            >
                                Add Selected Plans
                            </Button>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function PlanEditor({ draft, regular, currencySymbol, onChange, onClose, onSave, busy }) {
    const [errors, setErrors] = useState({});
    const set = (patch) => onChange({ ...draft, ...patch });
    const finalPrice = subscriptionPrice(regular, draft.discount_type, draft.discount_value, draft.price);
    const commit = () => {
        const next = validateDraft(draft, regular);
        setErrors(next);
        if (Object.keys(next).length) {
            return;
        }
        onSave({
            ...draft,
            price: finalPrice,
        });
    };

    return createPortal(
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={`${ui.modal} max-w-lg`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="edit-plan-title"
                onClick={(e) => e.stopPropagation()}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && e.target?.tagName !== 'BUTTON') {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!busy) {
                            commit();
                        }
                    }
                }}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="edit-plan-title" className={ui.modalTitle}>{draft.name || 'Subscription plan'}</h2>
                        <p className={ui.modalSub}>Set the product price for this plan. Billing rules stay on the plan.</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label="Close" onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    <PlanDetailRows plan={draft} />
                    <FormErrors errors={errors} />
                    <p className={`${ui.metaK} mb-3`}>Regular product price: {currencySymbol}{roundMoney(regular).toFixed(2)}</p>
                    <div className={ui.fieldGrid}>
                        <Field label="Discount type">
                            <Select
                                wide
                                value={draft.discount_type || ''}
                                onChange={(e) => set({ discount_type: e.target.value || null, discount_value: e.target.value ? draft.discount_value : '' })}
                            >
                                <option value="">None — set a price</option>
                                <option value="percent">Percentage</option>
                                <option value="fixed">Fixed amount</option>
                            </Select>
                        </Field>
                        {draft.discount_type ? (
                            <Field label="Discount value" error={errors.discount_value}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    max={draft.discount_type === 'percent' ? '100' : undefined}
                                    invalid={!!errors.discount_value}
                                    value={draft.discount_value}
                                    onChange={(e) => set({ discount_value: e.target.value })}
                                />
                            </Field>
                        ) : (
                            <Field label="Subscription price" error={errors.price}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    invalid={!!errors.price}
                                    value={draft.price}
                                    onChange={(e) => set({ price: e.target.value })}
                                />
                            </Field>
                        )}
                    </div>
                    <p className={`${ui.muted} mt-3`}>Final subscription price: {currencySymbol}{finalPrice.toFixed(2)}</p>
                    <div className="mt-4 grid gap-3">
                        <Toggle label="Enabled for this product" value={!!draft.is_enabled} onChange={(next) => set({ is_enabled: next })} />
                        <Checkbox
                            label="Default plan"
                            checked={!!draft.is_default}
                            onChange={(e) => set({ is_default: e.target.checked })}
                        />
                    </div>
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="button" icon="check" busy={busy} onClick={commit}>Save</Button>
                </div>
            </div>
        </div>,
        document.body,
    );
}

export default function ProductSubscriptionTab({
    enabled,
    purchaseOption,
    rows,
    availablePlans,
    regularPrice,
    currencySymbol = '$',
    errors,
    productId = null,
    languages = [],
    selectedLanguage,
    onLanguageChange,
    message = '',
    onMessage,
    messageError,
    onEnabled,
    onPurchaseOption,
    onChange,
    blockedByPreorder = false,
    conflictMessage = '',
    conflictActive = false,
}) {
    const growl = useGrowl();
    const [pickerOpen, setPickerOpen] = useState(false);
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const [busyKey, setBusyKey] = useState(null);
    const oneTime = purchaseOption === 'one_time' || purchaseOption === 'both';
    const subscription = purchaseOption === 'subscription' || purchaseOption === 'both';
    const usedIds = new Set(rows.map((row) => Number(row.subscription_plan_id)));
    const available = (availablePlans || []).filter((plan) => plan.is_enabled && !usedIds.has(Number(plan.id)));
    const catalogHasPlans = hasEnabledCatalogPlans(availablePlans);
    const createPlanHref = subscriptionPlanCreateHref(productId);
    const regular = roundMoney(regularPrice);

    const setPurchase = (nextOneTime, nextSub) => {
        onPurchaseOption(purchaseFromChecks(nextOneTime, nextSub));
    };

    const addPlans = (plans) => {
        const next = mergeSelectedPlans(rows, plans, regular);
        if (next.length === rows.length) {
            growl?.push({ type: 'error', message: 'Selected plans are already assigned to this product.' });
            return;
        }
        onChange(next);
        setPickerOpen(false);
        const added = next.length - rows.length;
        growl?.push({
            type: 'success',
            message: added === 1
                ? '1 plan added. Save the product to keep the assignment.'
                : `${added} plans added. Save the product to keep the assignments.`,
        });
    };

    const persistRow = async (next, { closeDraft = false } = {}) => {
        const updated = applyAssignedPlanDraft(rows, next);
        const persisted = productId && (next.id || String(next.key || '').startsWith('saved-'));
        if (!persisted) {
            onChange(updated);
            if (closeDraft) {
                setDraft(null);
            }
            growl?.push({ type: 'success', message: PLAN_SAVED_MESSAGE });
            return true;
        }

        const rowKey = next.key || `saved-${next.subscription_plan_id}`;
        if (closeDraft) {
            setSaving(true);
        } else {
            setBusyKey(rowKey);
        }
        try {
            const res = await fetch(`/admin/products/${productId}/subscription-plans/${next.subscription_plan_id}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: csrfHeaders(),
                body: JSON.stringify({
                    price: next.price,
                    discount_type: next.discount_type || null,
                    discount_value: next.discount_type ? (next.discount_value === '' ? 0 : next.discount_value) : null,
                    is_enabled: !!next.is_enabled,
                    is_default: !!next.is_default,
                }),
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                const messages = payload.errors
                    ? Object.values(payload.errors).flat()
                    : [payload.message || 'Could not save the subscription plan.'];
                messages.filter(Boolean).forEach((message) => growl?.push({ type: 'error', message: String(message) }));
                return false;
            }
            onChange(payload.plans ? rowsFromApi(payload.plans) : updated);
            if (closeDraft) {
                setDraft(null);
            }
            growl?.push({ type: 'success', message: payload.message || PLAN_SAVED_MESSAGE });
            return true;
        } catch {
            growl?.push({ type: 'error', message: 'Could not save the subscription plan.' });
            return false;
        } finally {
            setSaving(false);
            setBusyKey(null);
        }
    };

    const saveDraft = (next) => persistRow(next, { closeDraft: true });

    const toggleEnabled = (row) => {
        const enabling = !row.is_enabled;
        persistRow({
            ...row,
            key: row.key || `saved-${row.subscription_plan_id}`,
            is_enabled: enabling,
            is_default: enabling ? !!row.is_default : false,
        });
    };

    const setAsDefault = (row) => {
        if (!row.is_enabled || row.is_default) {
            return;
        }
        persistRow({
            ...row,
            key: row.key || `saved-${row.subscription_plan_id}`,
            is_enabled: true,
            is_default: true,
        });
    };

    const removeRow = (row) => {
        if (!window.confirm(`Remove “${row.name}” from this product? The global plan is not deleted.`)) {
            return;
        }
        let next = rows.filter((item) => item.key !== row.key);
        if (row.is_default) {
            const fallback = next.find((item) => item.is_enabled) || next[0];
            if (fallback) {
                next = next.map((item) => ({ ...item, is_default: item.key === fallback.key }));
            }
        }
        onChange(next);
    };

    const toggleLocked = blockedByPreorder && !enabled;

    return (
        <section className={ui.sectionCard}>
            <div className={ui.sectionHead}>
                <div>
                    <h2 className={ui.sectionTitle}>Subscription</h2>
                    <p className={cx(ui.muted, 'mt-1')}>Assign reusable billing plans and set this product’s subscription prices.</p>
                </div>
                <Field label="Enable subscription" error={errors?.subscription_enabled}>
                    <div className="flex justify-end">
                        <Toggle
                            value={!!enabled}
                            ariaLabel="Enable subscription"
                            disabled={toggleLocked}
                            onChange={onEnabled}
                        />
                    </div>
                </Field>
            </div>
            <FormErrors errors={Object.fromEntries(Object.entries(errors || {}).filter(([key]) => String(key).startsWith('subscription') || String(key).startsWith('purchase_option') || String(key).includes('subscription_message')))} />
            {blockedByPreorder || conflictActive ? (
                <Alert type={conflictActive ? 'warning' : 'info'} className="mb-4">
                    {conflictMessage || 'Disable pre-order on the Pre-order tab before enabling subscription for this product.'}
                </Alert>
            ) : null}
            {!enabled ? (
                <p className={ui.muted}>Turn this on to offer subscription purchase options. Assigned plans are kept if you disable it later.</p>
            ) : (
                <>
                    <div className="mb-5">
                        <div className={ui.label}>Purchase options</div>
                        <div className="mt-2 grid gap-2">
                            <Checkbox
                                label="One-time purchase"
                                checked={oneTime}
                                onChange={(e) => setPurchase(e.target.checked, e.target.checked ? subscription : true)}
                            />
                            <Checkbox
                                label="Subscription"
                                checked={subscription}
                                onChange={(e) => setPurchase(e.target.checked ? oneTime : true, e.target.checked)}
                            />
                        </div>
                        <p className={`${ui.metaK} mt-2`}>
                            {purchaseOption === 'subscription'
                                ? 'Customers must buy this product as a subscription.'
                                : purchaseOption === 'both'
                                    ? 'Customers can choose one-time purchase or a subscription.'
                                    : 'Customers can only buy this product as a one-time purchase.'}
                        </p>
                    </div>

                    {languages.length ? (
                        <div className="mb-5">
                            <LocalizedField
                                label="Customer message"
                                hint="Shown on the product page when a subscription is available. Leave blank to hide it."
                                languages={languages}
                                currentLanguage={selectedLanguage}
                                onLanguageChange={onLanguageChange}
                                error={messageError}
                            >
                                {() => (
                                    <Textarea
                                        rows="4"
                                        value={message}
                                        onChange={(e) => onMessage?.(e.target.value)}
                                    />
                                )}
                            </LocalizedField>
                        </div>
                    ) : null}

                    <div className={cx(ui.row, 'justify-between mb-3')}>
                        <h3 className={ui.sectionTitle}>Subscription plans</h3>
                        {catalogHasPlans ? (
                            <Button type="button" variant="secondary" icon="plus" onClick={() => setPickerOpen(true)}>
                                Add subscription plan
                            </Button>
                        ) : (
                            <Button icon="plus" variant="secondary" href={createPlanHref}>
                                Create Plan
                            </Button>
                        )}
                    </div>
                    {rows.length === 0 ? (
                        <Alert type="info">
                            {catalogHasPlans
                                ? 'No plans assigned yet. Add an active subscription plan.'
                                : 'No plans available yet. Create an active subscription plan, then assign it here.'}
                        </Alert>
                    ) : (
                        <div className={ui.tableWrap}>
                            <Table>
                                <THead>
                                    <Tr>
                                        <Th>Plan</Th>
                                        <Th>Billing interval</Th>
                                        <Th>Subscription price</Th>
                                        <Th>Discount</Th>
                                        <Th col="status">Status</Th>
                                        <Th>Default</Th>
                                        <Th actions>Actions</Th>
                                    </Tr>
                                </THead>
                                <tbody>
                                    {rows.map((row) => {
                                        const price = subscriptionPrice(regular, row.discount_type, row.discount_value, row.price);
                                        const rowKey = row.key || `saved-${row.subscription_plan_id}`;
                                        const busy = busyKey === rowKey;

                                        return (
                                            <Tr key={rowKey}>
                                                <Td>
                                                    <div className={ui.cellStack}>
                                                        <div className={ui.cellStrong}>{row.name || 'Untitled plan'}</div>
                                                        {row.plan_enabled === false ? (
                                                            <div className={ui.cellSub}>Global plan is disabled</div>
                                                        ) : null}
                                                    </div>
                                                </Td>
                                                <Td>
                                                    <div className={ui.cellSub}>{row.billing_summary || '—'}</div>
                                                </Td>
                                                <Td>
                                                    <div className={ui.cellStrong}>{currencySymbol}{price.toFixed(2)}</div>
                                                </Td>
                                                <Td>{discountLabel(row, currencySymbol)}</Td>
                                                <Td col="status">
                                                    <StatusToggle
                                                        active={!!row.is_enabled}
                                                        busy={busy}
                                                        activeLabel="Enabled"
                                                        inactiveLabel="Disabled"
                                                        onToggle={() => {
                                                            if (!busy) {
                                                                toggleEnabled(row);
                                                            }
                                                        }}
                                                    />
                                                </Td>
                                                <Td>
                                                    {row.is_default ? (
                                                        <Badge tone="success" dot={false}>Default</Badge>
                                                    ) : (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            disabled={busy || !row.is_enabled}
                                                            title={!row.is_enabled ? PLAN_ENABLE_DEFAULT_HINT : 'Set as default'}
                                                            onClick={() => setAsDefault(row)}
                                                        >
                                                            Set as default
                                                        </Button>
                                                    )}
                                                </Td>
                                                <Td actions>
                                                    <button
                                                        type="button"
                                                        className={ui.menuBtn}
                                                        title="Edit"
                                                        onClick={() => setDraft({ ...row, key: rowKey })}
                                                    >
                                                        <Icon name="pencil" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className={ui.menuBtn}
                                                        title="Remove"
                                                        onClick={() => removeRow({ ...row, key: rowKey })}
                                                    >
                                                        <Icon name="trash" />
                                                    </button>
                                                </Td>
                                            </Tr>
                                        );
                                    })}
                                </tbody>
                            </Table>
                        </div>
                    )}
                </>
            )}
            {pickerOpen ? (
                <PlanPicker
                    available={available}
                    onAdd={addPlans}
                    onClose={() => setPickerOpen(false)}
                    createHref={createPlanHref}
                />
            ) : null}
            {draft ? (
                <PlanEditor
                    draft={draft}
                    regular={regular}
                    currencySymbol={currencySymbol}
                    onChange={setDraft}
                    onClose={() => setDraft(null)}
                    onSave={saveDraft}
                    busy={saving}
                />
            ) : null}
        </section>
    );
}
