import { Button, ColorField, Field, Input, Select, Textarea, Toggle, ui, t } from '../../../ui';

export default function SchemaField({ field, value, onChange, error }) {
    const label = field.label || field.path;
    const type = field.type || 'text';

    if (type === 'toggle') {
        return (
            <Toggle
                label={label}
                hint={field.hint}
                value={Boolean(value)}
                onChange={(checked) => onChange(checked)}
            />
        );
    }

    if (type === 'textarea') {
        return (
            <Field label={label} hint={field.hint} error={error} className="space-y-2">
                <Textarea rows={field.rows || 3} value={value || ''} onChange={(e) => onChange(e.target.value)} />
            </Field>
        );
    }

    if (type === 'color') {
        return (
            <Field label={label} hint={field.hint} error={error} className="space-y-2">
                <ColorField value={value} onChange={onChange} fallback="#111111" placeholder="#111111" />
            </Field>
        );
    }

    if (type === 'number') {
        return (
            <Field label={label} hint={field.hint} error={error} className="space-y-2">
                <Input
                    type="number"
                    min={field.min}
                    max={field.max}
                    step={field.step || 1}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))}
                />
            </Field>
        );
    }

    if (type === 'select') {
        const options = (field.options || []).map((opt) => (typeof opt === 'string' ? { value: opt, label: opt } : opt));
        return (
            <Field label={label} hint={field.hint} error={error} className="space-y-2">
                <Select wide value={value || ''} onChange={(e) => onChange(e.target.value)}>
                    {options.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label || opt.value}</option>
                    ))}
                </Select>
            </Field>
        );
    }

    if (type === 'strings') {
        const rows = Array.isArray(value) ? value : [];
        const updateRow = (index, next) => {
            const copy = [...rows];
            copy[index] = next;
            onChange(copy);
        };
        return (
            <Field label={label} hint={field.hint || t('admin.theme_schema.strings_hint', 'One item per row.')} error={error} className="space-y-2">
                <div className="space-y-2">
                    {rows.map((row, index) => (
                        <div key={index} className="flex gap-2">
                            <Input className="flex-1" value={row || ''} onChange={(e) => updateRow(index, e.target.value)} />
                            <Button type="button" variant="ghost" onClick={() => onChange(rows.filter((_, i) => i !== index))}>
                                {t('admin.common.remove', 'Remove')}
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onChange([...rows, ''])}
                        disabled={rows.length >= (field.max_items || 20)}
                    >
                        {t('admin.theme_schema.add_item', 'Add item')}
                    </Button>
                </div>
            </Field>
        );
    }

    if (type === 'image' || type === 'media') {
        return (
            <Field label={label} hint={field.hint || t('admin.theme_schema.image_hint', 'Paste a public URL or /themes/... path.')} error={error} className="space-y-2">
                <Input wide value={value || ''} onChange={(e) => onChange(e.target.value)} placeholder="/themes/your-theme/assets/example.png" />
                {value ? <img src={value} alt="" className="mt-2 max-h-24 rounded-lg border border-neutral-200 bg-neutral-50 object-contain p-2" /> : null}
            </Field>
        );
    }

    if (type === 'repeater') {
        const rows = Array.isArray(value) ? value : [];
        const subFields = field.fields || [];
        const updateRow = (index, key, next) => {
            const copy = rows.map((row, i) => (i === index ? { ...row, [key]: next } : row));
            onChange(copy);
        };
        const addRow = () => {
            const blank = {};
            subFields.forEach((sub) => { blank[sub.key] = sub.type === 'toggle' ? false : ''; });
            onChange([...rows, blank]);
        };
        return (
            <div className="space-y-4">
                <div>
                    <h4 className={ui.sectionTitle}>{label}</h4>
                    {field.hint ? <p className={ui.sectionLead}>{field.hint}</p> : null}
                </div>
                {rows.map((row, index) => (
                    <div key={index} className={`${ui.sectionCard} space-y-4 !p-4`}>
                        <div className="flex items-center justify-between gap-3 border-b border-neutral-100 pb-3">
                            <span className="text-xs font-semibold uppercase tracking-wide text-neutral-400">
                                {t('admin.theme_schema.row_label', 'Item')} {index + 1}
                            </span>
                            <Button type="button" variant="ghost" onClick={() => onChange(rows.filter((_, i) => i !== index))}>
                                {t('admin.common.remove', 'Remove')}
                            </Button>
                        </div>
                        <div className="grid gap-x-5 gap-y-6 sm:grid-cols-2">
                            {subFields.map((sub) => (
                                <div key={`${index}-${sub.key}`} className={sub.type === 'textarea' || sub.type === 'repeater' ? 'sm:col-span-2' : ''}>
                                    <SchemaField
                                        field={{ ...sub, path: sub.key }}
                                        value={row?.[sub.key]}
                                        onChange={(next) => updateRow(index, sub.key, next)}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
                <Button type="button" variant="secondary" onClick={addRow} disabled={rows.length >= (field.max_items || 12)}>
                    {t('admin.theme_schema.add_row', 'Add row')}
                </Button>
            </div>
        );
    }

    return (
        <Field label={label} hint={field.hint} error={error} className="space-y-2">
            <Input wide value={value || ''} onChange={(e) => onChange(e.target.value)} />
        </Field>
    );
}
