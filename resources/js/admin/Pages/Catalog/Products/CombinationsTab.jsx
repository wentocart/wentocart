import { useMemo, useState } from 'react';
import {
    Badge,
    BulkBar,
    Button,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    ProductMediaField,
    RowActions,
    Select,
    SelectionCheckbox,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Thumb,
    Toggle,
    moveItem,
    ui,
    cx,
    useListSelection,
} from '../../../ui';

const STOCK_STATUS_OPTIONS = [
    { value: 'in_stock', label: 'In stock' },
    { value: 'out_of_stock', label: 'Out of stock' },
    { value: 'on_backorder', label: 'On backorder' },
];

function cartesianCount(axes) {
    if (!axes.length) {
        return 0;
    }
    return axes.reduce((count, axis) => {
        const values = axis.value_ids?.length || 0;
        return values ? count * values : 0;
    }, 1);
}

function cartesianKeys(axes) {
    if (!axes.length) {
        return new Set();
    }
    const groups = axes.map((axis) => (axis.value_ids || []).map(Number));
    let combos = [[]];
    groups.forEach((group) => {
        const next = [];
        combos.forEach((prefix) => {
            group.forEach((valueId) => next.push([...prefix, valueId]));
        });
        combos = next;
    });

    return new Set(combos.map((valueIds) => combinationKey(valueIds)));
}

export function combinationKey(valueIds) {
    return [...valueIds].map(Number).sort((a, b) => a - b).join('-');
}

function variantRowId(variant) {
    return variant.id || variant.combination_key || combinationKey(variant.attribute_value_ids || []);
}

function formatMoney(value, symbol) {
    if (value === '' || value === null || value === undefined) {
        return '—';
    }
    return `${symbol}${Number(value).toFixed(2)}`;
}

function stockStatusLabel(status) {
    return STOCK_STATUS_OPTIONS.find((opt) => opt.value === status)?.label || status;
}

function buildLabel(axes, catalogById, valueIds) {
    return axes.map((axis) => {
        const catalogValues = catalogById[axis.attribute_id]?.values || [];
        const axisValues = axis.values || [];
        const values = [...catalogValues];
        axisValues.forEach((value) => {
            if (!values.some((row) => row.id === value.id)) {
                values.push(value);
            }
        });
        const matchId = valueIds.find((id) => (axis.value_ids || []).includes(id));
        const value = values.find((row) => row.id === matchId);
        return value?.value;
    }).filter(Boolean).join(' / ');
}

function buildWarnings(axes, catalogById, valueIds) {
    const warnings = [];
    valueIds.forEach((valueId) => {
        axes.forEach((axis) => {
            const catalogValues = catalogById[axis.attribute_id]?.values || [];
            const axisValues = axis.values || [];
            const values = [...catalogValues, ...axisValues];
            const value = values.find((row) => row.id === valueId);
            if (!value) {
                return;
            }
            const attribute = catalogById[axis.attribute_id] || axis;
            if (attribute.is_enabled === false) {
                warnings.push(`Attribute "${attribute.name || axis.name}" is disabled`);
            }
            if (value.is_enabled === false) {
                warnings.push(`${attribute.name || axis.name} value "${value.value}" is disabled`);
            }
        });
    });

    return [...new Set(warnings)];
}

function emptyVariant(valueIds = [], defaults = {}) {
    return {
        id: null,
        label: '',
        combination_key: combinationKey(valueIds),
        attribute_value_ids: valueIds,
        sku: '',
        barcode: '',
        price: defaults.price ?? '',
        sale_price: defaults.sale_price ?? '',
        sale_price_type: defaults.sale_price_type || 'fixed',
        cost_price: defaults.cost_price ?? '',
        stock_qty: 0,
        manage_stock: true,
        stock_status: 'in_stock',
        allow_backorder: false,
        weight: defaults.weight ?? '',
        length: defaults.length ?? '',
        width: defaults.width ?? '',
        height: defaults.height ?? '',
        cover_path: '',
        cover_url: null,
        cover_image_id: null,
        is_enabled: true,
        is_default: false,
        position: 0,
        warnings: [],
        can_delete: true,
    };
}

function CombinationVariantEditor({
    draft,
    axes,
    catalogById,
    productImages = [],
    onUploadImage,
    uploadingImage = false,
    baseProduct = {},
    currencySymbol,
    onChange,
    onClose,
    onSave,
}) {
    const [errors, setErrors] = useState({});
    const selectedImage = productImages.find((image) => Number(image.id) === Number(draft.cover_image_id)) || null;
    const valueDetails = (draft.attribute_value_ids || []).map((valueId) => {
        for (const axis of axes) {
            const catalogValues = catalogById[axis.attribute_id]?.values || [];
            const axisValues = axis.values || [];
            const values = [...catalogValues, ...axisValues];
            const value = values.find((row) => row.id === valueId);
            if (value) {
                const attribute = catalogById[axis.attribute_id] || axis;
                return {
                    attribute_name: attribute.name || axis.name,
                    value: value.value,
                    is_enabled: value.is_enabled !== false,
                };
            }
        }
        return { attribute_name: 'Attribute', value: `#${valueId}`, is_enabled: false };
    });

    const validate = () => {
        const next = {};
        if ((draft.attribute_value_ids || []).length === 0) {
            next.attribute_value_ids = 'Combination must include attribute values.';
        }
        if (draft.price !== '' && draft.price !== null && Number(draft.price) < 0) {
            next.price = 'Price cannot be negative.';
        }
        if (draft.sale_price !== '' && draft.sale_price !== null && Number(draft.sale_price) < 0) {
            next.sale_price = 'Sale price cannot be negative.';
        }
        if (draft.sale_price_type === 'percent' && Number(draft.sale_price || 0) > 100) {
            next.sale_price = 'Percentage sale price cannot exceed 100%.';
        }
        if (draft.cost_price !== '' && draft.cost_price !== null && Number(draft.cost_price) < 0) {
            next.cost_price = 'Cost price cannot be negative.';
        }
        if (Number(draft.stock_qty || 0) < 0) {
            next.stock_qty = 'Stock quantity cannot be negative.';
        }
        ['weight', 'length', 'width', 'height'].forEach((field) => {
            if (draft[field] !== '' && draft[field] !== null && Number(draft[field]) < 0) {
                next[field] = 'Adjustment cannot be negative.';
            }
        });
        if (draft.is_default && !draft.is_enabled) {
            next.is_default = 'A disabled combination cannot be default.';
        }
        setErrors(next);
        return Object.keys(next).length === 0;
    };

    const set = (patch) => onChange({ ...draft, ...patch });

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={`${ui.modal} max-w-3xl`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="combination-variant-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="combination-variant-title" className={ui.modalTitle}>
                            Edit combination
                        </h2>
                        <p className={ui.modalSub}>{draft.label || 'Configure SKU, pricing, inventory, and media for this variant.'}</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label="Close" onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    <FormErrors errors={errors} />
                    <Field label="Combination">
                        <div className="rounded-lg border border-neutral-200 p-3 space-y-2">
                            {valueDetails.map((detail) => (
                                <div key={`${detail.attribute_name}-${detail.value}`} className="flex items-center justify-between gap-2 text-sm">
                                    <span><span className={ui.muted}>{detail.attribute_name}:</span> {detail.value}</span>
                                    {!detail.is_enabled ? <Badge tone="warning">Disabled</Badge> : null}
                                </div>
                            ))}
                        </div>
                    </Field>
                    {(draft.warnings || []).length > 0 ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            {(draft.warnings || []).map((warning) => (
                                <div key={warning}>⚠ {warning}</div>
                            ))}
                        </div>
                    ) : null}
                    <div className={ui.fieldGrid}>
                        <Field label="SKU" error={errors.sku}>
                            <Input wide value={draft.sku || ''} onChange={(e) => set({ sku: e.target.value })} placeholder="e.g. TS-BLK-M" />
                        </Field>
                        <Field label="Barcode" error={errors.barcode}>
                            <Input wide value={draft.barcode || ''} onChange={(e) => set({ barcode: e.target.value })} />
                        </Field>
                    </div>
                    <div className={ui.fieldGrid}>
                        <Field label={`Price (${currencySymbol})`} error={errors.price}>
                            <Input wide type="number" min="0" step="0.01" value={draft.price ?? ''} onChange={(e) => set({ price: e.target.value })} />
                        </Field>
                        <Field label={`Cost price (${currencySymbol})`} error={errors.cost_price}>
                            <Input wide type="number" min="0" step="0.01" value={draft.cost_price ?? ''} onChange={(e) => set({ cost_price: e.target.value })} />
                        </Field>
                    </div>
                    <div className={ui.fieldGrid}>
                        <Field label="Sale price type">
                            <Select wide value={draft.sale_price_type || 'fixed'} onChange={(e) => set({ sale_price_type: e.target.value })}>
                                <option value="fixed">Fixed amount</option>
                                <option value="percent">Percent off</option>
                            </Select>
                        </Field>
                        <Field label={draft.sale_price_type === 'percent' ? 'Sale price (%)' : `Sale price (${currencySymbol})`} error={errors.sale_price}>
                            <Input wide type="number" min="0" step="0.01" value={draft.sale_price ?? ''} onChange={(e) => set({ sale_price: e.target.value })} />
                        </Field>
                    </div>
                    <div className={ui.fieldGrid}>
                        <div>
                            <span className={ui.label}>Track inventory</span>
                            <div className="mt-1">
                                <Toggle value={draft.manage_stock !== false} onChange={(next) => set({ manage_stock: next })} />
                            </div>
                        </div>
                        <Field label="Stock quantity" error={errors.stock_qty}>
                            <Input wide type="number" min="0" value={draft.stock_qty ?? 0} onChange={(e) => set({ stock_qty: e.target.value })} />
                        </Field>
                    </div>
                    <div className={ui.fieldGrid}>
                        <Field label="Stock status">
                            <Select wide value={draft.stock_status || 'in_stock'} onChange={(e) => set({ stock_status: e.target.value })}>
                                {STOCK_STATUS_OPTIONS.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <div>
                            <span className={ui.label}>Allow backorders</span>
                            <div className="mt-1">
                                <Toggle value={!!draft.allow_backorder} onChange={(next) => set({ allow_backorder: next })} />
                            </div>
                        </div>
                    </div>
                    <div className={ui.fieldGrid}>
                        <Field label="Weight adjustment (kg)" hint={baseProduct.weight ? `Base product weight: ${baseProduct.weight}${baseProduct.weight_class || 'kg'}` : undefined} error={errors.weight}>
                            <Input wide type="number" min="0" step="0.001" value={draft.weight ?? ''} onChange={(e) => set({ weight: e.target.value })} />
                        </Field>
                        <Field label="Length adjustment" error={errors.length}>
                            <Input wide type="number" min="0" step="0.001" value={draft.length ?? ''} onChange={(e) => set({ length: e.target.value })} />
                        </Field>
                        <Field label="Width adjustment" error={errors.width}>
                            <Input wide type="number" min="0" step="0.001" value={draft.width ?? ''} onChange={(e) => set({ width: e.target.value })} />
                        </Field>
                        <Field label="Height adjustment" error={errors.height}>
                            <Input wide type="number" min="0" step="0.001" value={draft.height ?? ''} onChange={(e) => set({ height: e.target.value })} />
                        </Field>
                    </div>
                    <ProductMediaField
                        label="Combination image"
                        hint="Pick an image from this product’s media library."
                        images={productImages}
                        valueId={selectedImage?.id || draft.cover_image_id || null}
                        valueUrl={selectedImage?.url || draft.cover_url || null}
                        uploading={uploadingImage}
                        onUpload={onUploadImage}
                        onChange={(image) => set({
                            cover_image_id: image?.id || null,
                            cover_path: image?.path || '',
                            cover_url: image?.url || null,
                        })}
                    />
                    <div className={ui.fieldGrid}>
                        <div>
                            <span className={ui.label}>Enabled</span>
                            <div className="mt-1">
                                <Toggle
                                    value={draft.is_enabled !== false}
                                    onChange={(next) => set({
                                        is_enabled: next,
                                        is_default: next ? draft.is_default : false,
                                    })}
                                />
                            </div>
                        </div>
                        <div>
                            <span className={ui.label}>Default combination</span>
                            <div className="mt-1">
                                <Toggle
                                    value={!!draft.is_default}
                                    disabled={draft.is_enabled === false}
                                    onChange={(next) => set({ is_default: next })}
                                />
                            </div>
                        </div>
                    </div>
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button
                        type="button"
                        onClick={() => {
                            if (!validate()) {
                                return;
                            }
                            const image = productImages.find((row) => Number(row.id) === Number(draft.cover_image_id)) || null;
                            onSave({
                                ...draft,
                                label: draft.label || buildLabel(axes, catalogById, draft.attribute_value_ids || []),
                                warnings: buildWarnings(axes, catalogById, draft.attribute_value_ids || []),
                                cover_image_id: image?.id ?? draft.cover_image_id ?? null,
                                cover_path: image?.path ?? draft.cover_path ?? '',
                                cover_url: image?.url ?? draft.cover_url ?? null,
                            });
                        }}
                    >
                        Save combination
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function CombinationsTab({
    catalog = [],
    axes: initialAxes = [],
    variants: initialVariants = [],
    productImages = [],
    onUploadImage,
    uploadingImage = false,
    baseProduct = {},
    onAxesChange,
    onVariantsChange,
    onDeletedVariantIdsChange,
    errors = {},
    currencySymbol = '$',
}) {
    const [pickerId, setPickerId] = useState('');
    const [editorDraft, setEditorDraft] = useState(null);
    const [axisFromIndex, setAxisFromIndex] = useState(null);
    const [axisOverIndex, setAxisOverIndex] = useState(null);
    const [variantFromIndex, setVariantFromIndex] = useState(null);
    const [variantOverIndex, setVariantOverIndex] = useState(null);
    const axes = initialAxes;
    const variants = initialVariants;

    const catalogById = useMemo(() => Object.fromEntries(catalog.map((item) => [item.id, item])), [catalog]);
    const usedAttributeIds = axes.map((axis) => Number(axis.attribute_id));
    const availableAttributes = catalog.filter((item) => !usedAttributeIds.includes(Number(item.id)));
    const previewCount = cartesianCount(axes);
    const generatedKeys = useMemo(() => cartesianKeys(axes), [axes]);

    const variantsWithSelection = useMemo(() => variants.map((variant) => ({
        ...variant,
        selection_id: variantRowId(variant),
    })), [variants]);

    const selection = useListSelection(variantsWithSelection, {
        idKey: 'selection_id',
        canSelect: () => true,
    });

    const addAxis = () => {
        const attributeId = Number(pickerId);
        if (!attributeId || usedAttributeIds.includes(attributeId)) {
            return;
        }
        const attribute = catalogById[attributeId];
        onAxesChange([
            ...axes,
            {
                attribute_id: attributeId,
                name: attribute?.name,
                code: attribute?.code,
                type: attribute?.type,
                supports_swatches: attribute?.supports_swatches,
                value_ids: [],
            },
        ]);
        setPickerId('');
    };

    const removeAxis = (attributeId) => {
        onAxesChange(axes.filter((axis) => Number(axis.attribute_id) !== Number(attributeId)));
    };

    const reorderAxis = (from, to) => {
        if (from === to || from < 0 || to < 0 || from >= axes.length || to >= axes.length) {
            return;
        }
        onAxesChange(moveItem(axes, from, to));
    };

    const toggleValue = (attributeId, valueId) => {
        onAxesChange(axes.map((axis) => {
            if (Number(axis.attribute_id) !== Number(attributeId)) {
                return axis;
            }
            const ids = axis.value_ids || [];
            const next = ids.includes(valueId) ? ids.filter((id) => id !== valueId) : [...ids, valueId];
            return { ...axis, value_ids: next };
        }));
    };

    const generateCombinations = (removeMissing = false) => {
        if (previewCount === 0) {
            return;
        }
        const groups = axes.map((axis) => (axis.value_ids || []).map(Number));
        let combos = [[]];
        groups.forEach((group) => {
            const next = [];
            combos.forEach((prefix) => {
                group.forEach((valueId) => next.push([...prefix, valueId]));
            });
            combos = next;
        });

        const existing = Object.fromEntries(
            variants.map((variant) => [combinationKey(variant.attribute_value_ids || []), variant]),
        );

        const nextKeys = new Set(combos.map((valueIds) => combinationKey(valueIds)));
        const orphanCount = variants.filter((variant) => !nextKeys.has(combinationKey(variant.attribute_value_ids || []))).length;

        if (orphanCount > 0 && !removeMissing) {
            const keep = window.confirm(
                `${orphanCount} existing combination${orphanCount === 1 ? '' : 's'} no longer match the selected attribute values. They will be kept. Generate missing combinations now?`,
            );
            if (!keep) {
                return;
            }
        }

        const nextVariants = combos.map((valueIds, index) => {
            const key = combinationKey(valueIds);
            const current = existing[key];
            const label = buildLabel(axes, catalogById, valueIds);
            const warnings = buildWarnings(axes, catalogById, valueIds);

            return current ? {
                ...current,
                label: current.label || label,
                warnings,
                position: index,
            } : {
                ...emptyVariant(valueIds, baseProduct),
                label,
                warnings,
                is_default: index === 0 && variants.length === 0,
                position: index,
            };
        });

        if (removeMissing) {
            onVariantsChange(nextVariants);
            return;
        }

        const merged = [...variants];
        nextVariants.forEach((variant) => {
            const key = combinationKey(variant.attribute_value_ids || []);
            if (!merged.some((row) => combinationKey(row.attribute_value_ids || []) === key)) {
                merged.push({ ...variant, position: merged.length });
            }
        });
        onVariantsChange(merged.map((variant, index) => ({ ...variant, position: index })));
    };

    const patchVariants = (mapper) => {
        onVariantsChange(variants.map(mapper));
    };

    const setDefaultVariant = (key) => {
        onVariantsChange(variants.map((variant) => {
            const variantKey = variant.combination_key || combinationKey(variant.attribute_value_ids || []);
            const enabled = variantKey === key ? true : variant.is_enabled;
            return {
                ...variant,
                is_enabled: enabled !== false,
                is_default: variantKey === key,
            };
        }));
    };

    const reorderVariant = (from, to) => {
        if (from === to || from < 0 || to < 0 || from >= variants.length || to >= variants.length) {
            return;
        }
        onVariantsChange(moveItem(variants, from, to).map((variant, position) => ({ ...variant, position })));
    };

    const deleteVariant = (variant) => {
        if (variant.can_delete === false) {
            window.alert('This combination is used by existing records and cannot be deleted.');
            return;
        }
        if (!window.confirm(`Delete combination "${variant.label}"?`)) {
            return;
        }
        const key = variant.combination_key || combinationKey(variant.attribute_value_ids || []);
        if (variant.id && onDeletedVariantIdsChange) {
            onDeletedVariantIdsChange((current) => [...new Set([...current, variant.id])]);
        }
        onVariantsChange(
            variants
                .filter((row) => (row.combination_key || combinationKey(row.attribute_value_ids || [])) !== key)
                .map((row, index) => ({ ...row, position: index })),
        );
        selection.clear();
    };

    const bulkPatch = (patch) => {
        const selected = new Set(selection.selectedIds.map(String));
        onVariantsChange(variants.map((variant) => (
            selected.has(String(variantRowId(variant))) ? { ...variant, ...patch } : variant
        )));
        selection.clear();
    };

    const bulkDelete = () => {
        const selected = new Set(selection.selectedIds.map(String));
        const blocked = variants.filter((variant) => selected.has(String(variantRowId(variant))) && variant.can_delete === false);
        if (blocked.length) {
            window.alert('Some selected combinations are used by existing records and cannot be deleted.');
            return;
        }
        if (!window.confirm(`Delete ${selection.selectedCount} selected combination${selection.selectedCount === 1 ? '' : 's'}?`)) {
            return;
        }
        const deletedIds = variants
            .filter((variant) => selected.has(String(variantRowId(variant))) && variant.id)
            .map((variant) => variant.id);
        if (deletedIds.length && onDeletedVariantIdsChange) {
            onDeletedVariantIdsChange((current) => [...new Set([...current, ...deletedIds])]);
        }
        onVariantsChange(
            variants
                .filter((variant) => !selected.has(String(variantRowId(variant))))
                .map((variant, index) => ({ ...variant, position: index })),
        );
        selection.clear();
    };

    return (
        <div className="space-y-4">
            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>Combination attributes</h2>
                        <p className={ui.muted}>Select existing attributes and values. Manage attributes under Catalog → Attributes.</p>
                    </div>
                    <Button type="button" variant="link" href="/admin/attributes" native target="_blank" rel="noreferrer">
                        Manage attributes
                    </Button>
                </div>
                {errors.combination_axes ? <p className={ui.fieldError}>{errors.combination_axes}</p> : null}
                <div className={cx(ui.row, 'flex-wrap mb-4')}>
                    <Select value={pickerId} onChange={(e) => setPickerId(e.target.value)}>
                        <option value="">Select attribute</option>
                        {availableAttributes.map((item) => (
                            <option key={item.id} value={item.id}>{item.name}</option>
                        ))}
                    </Select>
                    <Button type="button" icon="plus" disabled={!pickerId} onClick={addAxis}>
                        Add attribute
                    </Button>
                </div>

                {axes.length === 0 ? (
                    <p className={ui.empty}>No combination attributes yet.</p>
                ) : axes.map((axis, index) => {
                    const attribute = catalogById[axis.attribute_id] || axis;
                    const catalogValues = attribute.values || [];
                    const axisValues = axis.values || [];
                    const values = [...catalogValues];
                    axisValues.forEach((value) => {
                        if (!values.some((row) => row.id === value.id)) {
                            values.push(value);
                        }
                    });

                    return (
                        <div
                            key={axis.attribute_id}
                            className={cx('mb-4 rounded-xl border border-neutral-200 p-4', axisOverIndex === index && axisFromIndex !== null && axisFromIndex !== index && ui.dragOver)}
                            onDragOver={(e) => {
                                if (axisFromIndex === null || axes.length < 2) {
                                    return;
                                }
                                e.preventDefault();
                                setAxisOverIndex(index);
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                if (axisFromIndex === null || axisFromIndex === index) {
                                    setAxisFromIndex(null);
                                    setAxisOverIndex(null);
                                    return;
                                }
                                reorderAxis(axisFromIndex, index);
                                setAxisFromIndex(null);
                                setAxisOverIndex(null);
                            }}
                        >
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div className={ui.row}>
                                    {axes.length > 1 ? (
                                        <button
                                            type="button"
                                            className={ui.dragHandle}
                                            title="Drag to reorder"
                                            aria-label={`Reorder ${attribute.name || axis.name}`}
                                            draggable
                                            onDragStart={(e) => {
                                                e.dataTransfer.setData('text/plain', String(index));
                                                e.dataTransfer.effectAllowed = 'move';
                                                setAxisFromIndex(index);
                                            }}
                                            onDragEnd={() => {
                                                setAxisFromIndex(null);
                                                setAxisOverIndex(null);
                                            }}
                                        >
                                            <Icon name="grip" />
                                        </button>
                                    ) : null}
                                    <div>
                                        <h3 className={ui.sectionTitle}>{attribute.name || axis.name}</h3>
                                    </div>
                                </div>
                                <div className={ui.actionRow}>
                                    <Button type="button" variant="danger" icon="trash" onClick={() => removeAxis(axis.attribute_id)} />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-3">
                                {values.map((value) => (
                                    <Checkbox
                                        key={value.id}
                                        label={value.swatch_type === 'color' && value.color_code ? (
                                            <span className="inline-flex items-center gap-2">
                                                <span className="inline-block h-4 w-4 rounded border border-neutral-200" style={{ backgroundColor: value.color_code }} />
                                                {value.value}
                                                {value.is_enabled === false ? <Badge tone="warning">Disabled</Badge> : null}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-2">
                                                {value.value}
                                                {value.is_enabled === false ? <Badge tone="warning">Disabled</Badge> : null}
                                            </span>
                                        )}
                                        checked={(axis.value_ids || []).includes(value.id)}
                                        onChange={() => toggleValue(axis.attribute_id, value.id)}
                                    />
                                ))}
                            </div>
                        </div>
                    );
                })}
            </section>

            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>Generate combinations</h2>
                        <p className={ui.muted}>
                            {previewCount > 0
                                ? `${previewCount} combination${previewCount === 1 ? '' : 's'} can be generated from the selected values.`
                                : 'Select at least one value on each attribute to generate combinations.'}
                        </p>
                    </div>
                    <div className={ui.actionRow}>
                        <Button type="button" icon="plus" disabled={previewCount === 0} onClick={() => generateCombinations(false)}>
                            Generate combinations
                        </Button>
                    </div>
                </div>
                <p className={ui.muted}>Existing combinations keep their SKU, price, stock, image, and other data. Only missing combinations are added.</p>
            </section>

            {variants.length > 0 ? (
                <section className={ui.sectionCard}>
                    <div className={ui.sectionHead}>
                        <h2 className={ui.sectionTitle}>Combinations ({variants.length})</h2>
                    </div>
                    {selection.selectedCount > 0 ? (
                        <BulkBar count={selection.selectedCount} onClear={selection.clear}>
                            <Button type="button" variant="secondary" onClick={() => bulkPatch({ is_enabled: true })}>Enable selected</Button>
                            <Button type="button" variant="secondary" onClick={() => bulkPatch({ is_enabled: false, is_default: false })}>Disable selected</Button>
                            <Button type="button" variant="danger" onClick={bulkDelete}>Delete selected</Button>
                        </BulkBar>
                    ) : null}
                    <div className={ui.tableWrap}>
                        <Table>
                            <THead>
                                <tr>
                                    <Th check>
                                        <SelectionCheckbox
                                            checked={selection.allSelected}
                                            indeterminate={selection.someSelected}
                                            onChange={selection.toggleAll}
                                            ariaLabel="Select all combinations"
                                        />
                                    </Th>
                                    <Th>Order</Th>
                                    <Th>Combination</Th>
                                    <Th>SKU</Th>
                                    <Th>Barcode</Th>
                                    <Th>Price</Th>
                                    <Th>Sale price</Th>
                                    <Th>Cost</Th>
                                    <Th>Stock</Th>
                                    <Th>Stock status</Th>
                                    <Th>Status</Th>
                                    <Th>Default</Th>
                                    <Th>Image</Th>
                                    <Th actions>Action</Th>
                                </tr>
                            </THead>
                            <tbody>
                                {variantsWithSelection.map((variant, index) => {
                                    const key = variant.combination_key || combinationKey(variant.attribute_value_ids || []);
                                    const isOrphan = generatedKeys.size > 0 && !generatedKeys.has(key);
                                    const warnings = [
                                        ...(variant.warnings || []),
                                        ...(isOrphan ? ['No longer matches selected attribute values'] : []),
                                    ];
                                    return (
                                        <Tr
                                            key={key}
                                            selected={selection.isSelected(variant.selection_id)}
                                            className={cx(variantOverIndex === index && variantFromIndex !== null && variantFromIndex !== index && ui.dragOver)}
                                            onDragOver={(e) => {
                                                if (variantFromIndex === null || variants.length < 2) {
                                                    return;
                                                }
                                                e.preventDefault();
                                                setVariantOverIndex(index);
                                            }}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                if (variantFromIndex === null || variantFromIndex === index) {
                                                    setVariantFromIndex(null);
                                                    setVariantOverIndex(null);
                                                    return;
                                                }
                                                reorderVariant(variantFromIndex, index);
                                                setVariantFromIndex(null);
                                                setVariantOverIndex(null);
                                            }}
                                        >
                                            <Td check>
                                                <SelectionCheckbox
                                                    checked={selection.isSelected(variant.selection_id)}
                                                    onChange={() => selection.toggle(variant.selection_id)}
                                                    ariaLabel={`Select ${variant.label}`}
                                                />
                                            </Td>
                                            <Td>
                                                <div className={ui.row}>
                                                    {variants.length > 1 ? (
                                                        <button
                                                            type="button"
                                                            className={ui.dragHandle}
                                                            title="Drag to reorder"
                                                            aria-label={`Reorder ${variant.label}`}
                                                            draggable
                                                            onDragStart={(e) => {
                                                                e.dataTransfer.setData('text/plain', String(index));
                                                                e.dataTransfer.effectAllowed = 'move';
                                                                setVariantFromIndex(index);
                                                            }}
                                                            onDragEnd={() => {
                                                                setVariantFromIndex(null);
                                                                setVariantOverIndex(null);
                                                            }}
                                                        >
                                                            <Icon name="grip" />
                                                        </button>
                                                    ) : null}
                                                    <span>{index + 1}</span>
                                                </div>
                                            </Td>
                                            <Td>
                                                <div className="font-medium">{variant.label}</div>
                                                {warnings.length ? (
                                                    <div className="mt-1 space-y-1">
                                                        {warnings.map((warning) => (
                                                            <div key={warning} className="text-xs text-amber-700">⚠ {warning}</div>
                                                        ))}
                                                    </div>
                                                ) : null}
                                            </Td>
                                            <Td>{variant.sku || '—'}</Td>
                                            <Td>{variant.barcode || '—'}</Td>
                                            <Td>{formatMoney(variant.price, currencySymbol)}</Td>
                                            <Td>{formatMoney(variant.sale_price, currencySymbol)}</Td>
                                            <Td>{formatMoney(variant.cost_price, currencySymbol)}</Td>
                                            <Td>{variant.manage_stock === false ? '—' : (variant.stock_qty ?? 0)}</Td>
                                            <Td>{stockStatusLabel(variant.stock_status)}</Td>
                                            <Td>
                                                <Badge tone={variant.is_enabled ? 'success' : 'warning'}>{variant.is_enabled ? 'Active' : 'Disabled'}</Badge>
                                            </Td>
                                            <Td>{variant.is_default ? 'Yes' : 'No'}</Td>
                                            <Td>
                                                <Thumb
                                                    src={
                                                        variant.cover_url
                                                        || productImages.find((image) => Number(image.id) === Number(variant.cover_image_id))?.url
                                                        || null
                                                    }
                                                    className="h-10 w-10 object-cover"
                                                />
                                            </Td>
                                            <Td actions>
                                                <RowActions
                                                    items={[
                                                        {
                                                            key: 'edit',
                                                            label: 'Edit',
                                                            icon: 'edit',
                                                            onClick: () => setEditorDraft({ ...variant }),
                                                        },
                                                    ]}
                                                    onDelete={() => deleteVariant(variant)}
                                                />
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </div>
                </section>
            ) : null}

            {editorDraft ? (
                <CombinationVariantEditor
                    draft={editorDraft}
                    axes={axes}
                    catalogById={catalogById}
                    productImages={productImages}
                    onUploadImage={onUploadImage}
                    uploadingImage={uploadingImage}
                    baseProduct={baseProduct}
                    currencySymbol={currencySymbol}
                    onChange={(next) => {
                        setEditorDraft(next);
                        // Keep list + product-save payload in sync as soon as media changes,
                        // so an image upload/Inertia refresh cannot drop the selection.
                        const key = next.combination_key || combinationKey(next.attribute_value_ids || []);
                        onVariantsChange((current) => current.map((variant) => {
                            const variantKey = variant.combination_key || combinationKey(variant.attribute_value_ids || []);
                            if (variantKey !== key) {
                                return variant;
                            }
                            return {
                                ...variant,
                                ...next,
                                cover_image_id: next.cover_image_id ?? null,
                                cover_path: next.cover_path || '',
                                cover_url: next.cover_url || null,
                            };
                        }));
                    }}
                    onClose={() => setEditorDraft(null)}
                    onSave={(saved) => {
                        const key = saved.combination_key || combinationKey(saved.attribute_value_ids || []);
                        onVariantsChange((current) => current.map((variant) => {
                            const variantKey = variant.combination_key || combinationKey(variant.attribute_value_ids || []);
                            if (variantKey !== key) {
                                return saved.is_default ? { ...variant, is_default: false } : variant;
                            }
                            return saved;
                        }));
                        setEditorDraft(null);
                    }}
                />
            ) : null}
        </div>
    );
}
