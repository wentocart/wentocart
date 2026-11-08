import { useState } from 'react';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    Select,
    Toggle,
    ui,
    cx,
} from '../../../ui';

export const CUSTOMIZATION_FIELD_TYPES = [
    { value: 'text', label: 'Text' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'select', label: 'Dropdown / Select' },
    { value: 'radio', label: 'Radio' },
    { value: 'checkbox', label: 'Checkbox' },
    { value: 'file', label: 'File Upload' },
    { value: 'image', label: 'Image Upload' },
];

const CHOICE_TYPES = ['select', 'radio', 'checkbox'];
const TEXT_TYPES = ['text', 'textarea'];
const FILE_TYPES = ['file', 'image'];

function typeLabel(type) {
    return CUSTOMIZATION_FIELD_TYPES.find((item) => item.value === type)?.label || type;
}

function nextKey(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function emptyOption() {
    return {
        key: nextKey('opt'),
        label: '',
        value: '',
        price_type: 'fixed',
        price: '',
        is_default: false,
    };
}

export function emptyCustomizationField() {
    return {
        key: nextKey('field'),
        id: null,
        type: 'text',
        label: '',
        is_required: false,
        placeholder: '',
        help_text: '',
        default_value: '',
        min_length: '',
        max_length: '',
        allowed_file_types: '',
        max_file_size_kb: '2048',
        price_type: 'none',
        price_amount: '',
        options: [],
    };
}

export function customizationFieldsFromProduct(product) {
    return (product?.customization_fields || []).map((row) => {
        const config = row.config || {};
        const options = row.options || config.options || [];

        return {
            key: row.id ? `saved-${row.id}` : nextKey('field'),
            id: row.id || null,
            type: row.type || 'text',
            label: row.label || '',
            is_required: !!(row.is_required ?? false),
            placeholder: row.placeholder ?? config.placeholder ?? '',
            help_text: row.help_text ?? config.help_text ?? '',
            default_value: row.default_value ?? config.default_value ?? '',
            min_length: row.min_length ?? config.min_length ?? '',
            max_length: row.max_length ?? config.max_length ?? '',
            allowed_file_types: row.allowed_file_types ?? config.allowed_file_types ?? '',
            max_file_size_kb: row.max_file_size_kb ?? config.max_file_size_kb ?? '',
            price_type: row.price_type ?? config.price_type ?? 'none',
            price_amount: row.price_amount ?? config.price_amount ?? '',
            options: options.map((opt, index) => ({
                key: `${row.id || 'new'}-opt-${index}`,
                label: opt.label || '',
                value: opt.value || '',
                price_type: opt.price_type || 'fixed',
                price: opt.price ?? '',
                is_default: !!opt.is_default,
            })),
        };
    });
}

export function customizationFieldsPayload(fields) {
    return fields.map((field) => ({
        id: field.id || undefined,
        type: field.type,
        label: field.label,
        is_required: !!field.is_required,
        placeholder: field.placeholder,
        help_text: field.help_text,
        default_value: field.default_value,
        min_length: field.min_length === '' ? null : field.min_length,
        max_length: field.max_length === '' ? null : field.max_length,
        allowed_file_types: field.allowed_file_types,
        max_file_size_kb: field.max_file_size_kb === '' ? null : field.max_file_size_kb,
        price_type: field.price_type || 'none',
        price_amount: field.price_amount === '' ? 0 : field.price_amount,
        options: (field.options || []).map((opt) => ({
            label: opt.label,
            value: opt.value,
            price_type: opt.price_type || 'fixed',
            price: opt.price === '' ? 0 : opt.price,
            is_default: !!opt.is_default,
        })),
    }));
}

function optionSummary(field, currencySymbol) {
    const options = field.options || [];
    if (!CHOICE_TYPES.includes(field.type)) {
        if (field.price_type && field.price_type !== 'none' && field.price_amount) {
            return field.price_type === 'percent'
                ? `+${field.price_amount}%`
                : `+${currencySymbol}${field.price_amount}`;
        }

        return 'No options';
    }
    if (!options.length) {
        return 'No options yet';
    }

    return options.slice(0, 3).map((opt) => {
        const extra = Number(opt.price) > 0
            ? (opt.price_type === 'percent' ? ` +${opt.price}%` : ` +${currencySymbol}${opt.price}`)
            : '';

        return `${opt.label || 'Untitled'}${extra}`;
    }).concat(options.length > 3 ? [`+${options.length - 3} more`] : []).join(', ');
}

function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}

function validateDraft(draft) {
    const errors = {};
    if (!String(draft.label || '').trim()) {
        errors.label = 'Enter a field label.';
    }
    if (TEXT_TYPES.includes(draft.type)) {
        const min = draft.min_length === '' ? null : Number(draft.min_length);
        const max = draft.max_length === '' ? null : Number(draft.max_length);
        if (min != null && max != null && min > max) {
            errors.max_length = 'Maximum length must be greater than or equal to minimum length.';
        }
    }
    if (CHOICE_TYPES.includes(draft.type)) {
        const options = (draft.options || []).filter((opt) => String(opt.label || '').trim());
        if (!options.length) {
            errors.options = 'Add at least one option.';
        }
    }

    return errors;
}

function FieldEditor({ draft, onChange, onClose, onSave, currencySymbol }) {
    const [errors, setErrors] = useState({});
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);
    const isChoice = CHOICE_TYPES.includes(draft.type);
    const isText = TEXT_TYPES.includes(draft.type);
    const isFile = FILE_TYPES.includes(draft.type);

    const set = (patch) => onChange({ ...draft, ...patch });

    const updateOption = (index, patch) => {
        const options = draft.options.map((opt, i) => {
            if (i !== index) {
                if (patch.is_default && draft.type !== 'checkbox') {
                    return { ...opt, is_default: false };
                }

                return opt;
            }

            return { ...opt, ...patch };
        });
        set({ options });
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <form
                className={`${ui.modal} max-w-2xl`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="custom-field-title"
                onClick={(e) => e.stopPropagation()}
                onSubmit={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const next = validateDraft(draft);
                    setErrors(next);
                    if (Object.keys(next).length) {
                        return;
                    }
                    onSave({
                        ...draft,
                        options: (draft.options || []).filter((opt) => String(opt.label || '').trim()),
                    });
                }}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="custom-field-title" className={ui.modalTitle}>
                            {draft.id ? 'Edit customization field' : 'Add customization field'}
                        </h2>
                        <p className={ui.modalSub}>Shown to customers on the product page when customization is enabled.</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label="Close" onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    <FormErrors errors={errors} />
                    <div className={ui.fieldGrid}>
                        <Field label={<>Field label <span className={ui.req}>*</span></>} error={errors.label}>
                            <Input wide value={draft.label} invalid={!!errors.label} onChange={(e) => set({ label: e.target.value })} />
                        </Field>
                        <Field label="Field type">
                            <Select
                                wide
                                value={draft.type}
                                onChange={(e) => {
                                    const type = e.target.value;
                                    set({
                                        type,
                                        options: CHOICE_TYPES.includes(type) && !draft.options.length
                                            ? [emptyOption(), emptyOption()]
                                            : draft.options,
                                    });
                                }}
                            >
                                {CUSTOMIZATION_FIELD_TYPES.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    {isText || draft.type === 'select' ? (
                        <Field label="Placeholder">
                            <Input wide value={draft.placeholder} onChange={(e) => set({ placeholder: e.target.value })} />
                        </Field>
                    ) : null}
                    <Field label="Help text">
                        <Input wide value={draft.help_text} onChange={(e) => set({ help_text: e.target.value })} />
                    </Field>
                    <div className={ui.fieldGrid}>
                        <div>
                            <span className={ui.label}>Required</span>
                            <div className="mt-1">
                                <Toggle value={!!draft.is_required} onChange={(next) => set({ is_required: next })} />
                            </div>
                        </div>
                        {isText ? (
                            <Field label="Default value">
                                <Input wide value={draft.default_value} onChange={(e) => set({ default_value: e.target.value })} />
                            </Field>
                        ) : <span />}
                    </div>
                    {isText ? (
                        <div className={ui.fieldGrid}>
                            <Field label="Minimum length">
                                <Input wide type="number" min="0" value={draft.min_length} onChange={(e) => set({ min_length: e.target.value })} />
                            </Field>
                            <Field label="Maximum length" error={errors.max_length}>
                                <Input wide type="number" min="0" invalid={!!errors.max_length} value={draft.max_length} onChange={(e) => set({ max_length: e.target.value })} />
                            </Field>
                        </div>
                    ) : null}
                    {isFile ? (
                        <div className={ui.fieldGrid}>
                            <Field label="Allowed file types" hint="Comma-separated, e.g. jpg, png, pdf">
                                <Input
                                    wide
                                    value={draft.allowed_file_types}
                                    placeholder={draft.type === 'image' ? 'jpg, png, webp' : 'pdf, jpg, png, zip'}
                                    onChange={(e) => set({ allowed_file_types: e.target.value })}
                                />
                            </Field>
                            <Field label="Maximum file size (KB)">
                                <Input wide type="number" min="1" value={draft.max_file_size_kb} onChange={(e) => set({ max_file_size_kb: e.target.value })} />
                            </Field>
                        </div>
                    ) : null}
                    <div className={ui.fieldGrid}>
                        <Field label="Additional price">
                            <Select wide value={draft.price_type} onChange={(e) => set({ price_type: e.target.value })}>
                                <option value="none">No extra charge</option>
                                <option value="fixed">Fixed amount</option>
                                <option value="percent">Percentage</option>
                            </Select>
                        </Field>
                        {draft.price_type !== 'none' ? (
                            <Field label={draft.price_type === 'percent' ? 'Percent' : 'Amount'}>
                                <div className={ui.inputGroup}>
                                    <span className={ui.inputPrefix}>{draft.price_type === 'percent' ? '%' : currencySymbol}</span>
                                    <input
                                        className={ui.inputBare}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={draft.price_amount}
                                        onChange={(e) => set({ price_amount: e.target.value })}
                                    />
                                </div>
                            </Field>
                        ) : <span />}
                    </div>
                    {isChoice ? (
                        <div>
                            <div className={ui.sectionHead}>
                                <h3 className={ui.sectionTitle}>Options</h3>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon="plus"
                                    onClick={() => set({ options: [...(draft.options || []), emptyOption()] })}
                                >
                                    Add option
                                </Button>
                            </div>
                            {errors.options ? <p className={ui.fieldError}>{errors.options}</p> : null}
                            <div className={ui.tierList}>
                                {(draft.options || []).map((opt, index) => (
                                    <div
                                        key={opt.key}
                                        className={cx(ui.cardPad, overIndex === index && fromIndex !== null && fromIndex !== index && ui.dragOver)}
                                        onDragOver={(e) => {
                                            e.preventDefault();
                                            setOverIndex(index);
                                        }}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            if (fromIndex === null) {
                                                return;
                                            }
                                            set({ options: moveItem(draft.options, fromIndex, index) });
                                            setFromIndex(null);
                                            setOverIndex(null);
                                        }}
                                    >
                                        <div className={cx(ui.row, 'justify-between mb-3')}>
                                            <button
                                                type="button"
                                                className={ui.dragHandle}
                                                title="Reorder"
                                                aria-label="Reorder option"
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
                                            <button
                                                type="button"
                                                className={ui.menuBtn}
                                                title="Remove option"
                                                onClick={() => set({ options: draft.options.filter((_, i) => i !== index) })}
                                            >
                                                <Icon name="x" />
                                            </button>
                                        </div>
                                        <div className={ui.fieldGrid}>
                                            <Field label="Option label">
                                                <Input wide value={opt.label} onChange={(e) => updateOption(index, { label: e.target.value })} />
                                            </Field>
                                            <Field label="Option value">
                                                <Input wide value={opt.value} placeholder="Auto from label" onChange={(e) => updateOption(index, { value: e.target.value })} />
                                            </Field>
                                            <Field label="Price type">
                                                <Select wide value={opt.price_type} onChange={(e) => updateOption(index, { price_type: e.target.value })}>
                                                    <option value="none">No extra</option>
                                                    <option value="fixed">Fixed</option>
                                                    <option value="percent">Percent</option>
                                                </Select>
                                            </Field>
                                            <Field label="Additional price">
                                                <Input wide type="number" min="0" step="0.01" value={opt.price} onChange={(e) => updateOption(index, { price: e.target.value })} />
                                            </Field>
                                        </div>
                                        <div className="mt-3">
                                            <Checkbox
                                                label="Default selection"
                                                checked={!!opt.is_default}
                                                onChange={(e) => updateOption(index, { is_default: e.target.checked })}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" icon="check">Save</Button>
                </div>
            </form>
        </div>
    );
}

export default function CustomizationTab({ enabled, fields, onEnabled, onChange, currencySymbol = '$', errors }) {
    const [draft, setDraft] = useState(null);
    const [editKey, setEditKey] = useState(null);
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);

    const openNew = () => {
        setEditKey(null);
        setDraft(emptyCustomizationField());
    };

    const openEdit = (field) => {
        setEditKey(field.key);
        setDraft({
            ...field,
            options: (field.options || []).map((opt) => ({ ...opt, key: opt.key || nextKey('opt') })),
        });
    };

    return (
        <section className={ui.sectionCard}>
            <div className={ui.sectionHead}>
                <div>
                    <h2 className={ui.sectionTitle}>Customization</h2>
                    <p className={cx(ui.muted, 'mt-1')}>Let customers add text, choices, or files before buying. Extra prices are added to the product price.</p>
                </div>
                <Toggle label="Enable customization" value={!!enabled} onChange={onEnabled} />
            </div>
            <FormErrors errors={Object.fromEntries(Object.entries(errors || {}).filter(([key]) => String(key).startsWith('customization')))} />
            {!enabled ? (
                <p className={ui.muted}>Turn this on to add customization fields.</p>
            ) : (
                <>
                    <div className={cx(ui.row, 'justify-end mb-3')}>
                        <Button type="button" variant="secondary" icon="plus" onClick={openNew}>
                            Add customization field
                        </Button>
                    </div>
                    {fields.length === 0 ? (
                        <p className={ui.muted}>No customization fields yet.</p>
                    ) : (
                        <div className={ui.stack}>
                            {fields.map((field, index) => (
                                <div
                                    key={field.key}
                                    className={cx(ui.cardPad, overIndex === index && fromIndex !== null && fromIndex !== index && ui.dragOver)}
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setOverIndex(index);
                                    }}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        if (fromIndex === null) {
                                            return;
                                        }
                                        onChange(moveItem(fields, fromIndex, index));
                                        setFromIndex(null);
                                        setOverIndex(null);
                                    }}
                                >
                                    <div className={cx(ui.row, 'justify-between gap-3')}>
                                        <div className={ui.row}>
                                            <button
                                                type="button"
                                                className={ui.dragHandle}
                                                title="Reorder"
                                                aria-label="Reorder field"
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
                                            <div className={ui.cellStack}>
                                                <div className={ui.cellStrong}>{field.label || 'Untitled field'}</div>
                                                <div className={ui.cellSub}>{optionSummary(field, currencySymbol)}</div>
                                            </div>
                                        </div>
                                        <div className={ui.row}>
                                            <Badge tone={field.is_required ? 'warning' : 'neutral'} dot={false}>
                                                {field.is_required ? 'Required' : 'Optional'}
                                            </Badge>
                                            <Badge tone="info" dot={false}>{typeLabel(field.type)}</Badge>
                                            <button type="button" className={ui.menuBtn} title="Edit" onClick={() => openEdit(field)}>
                                                <Icon name="pencil" />
                                            </button>
                                            <button
                                                type="button"
                                                className={ui.menuBtn}
                                                title="Delete"
                                                onClick={() => onChange(fields.filter((item) => item.key !== field.key))}
                                            >
                                                <Icon name="trash" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
            {draft ? (
                <FieldEditor
                    draft={draft}
                    currencySymbol={currencySymbol}
                    onChange={setDraft}
                    onClose={() => {
                        setDraft(null);
                        setEditKey(null);
                    }}
                    onSave={(next) => {
                        if (editKey) {
                            onChange(fields.map((item) => (item.key === editKey ? { ...item, ...next, key: item.key } : item)));
                        } else {
                            onChange([...fields, next]);
                        }
                        setDraft(null);
                        setEditKey(null);
                    }}
                />
            ) : null}
        </section>
    );
}
