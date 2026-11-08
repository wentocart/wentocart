import Button from './Button';
import { Field, Input } from './Field';
import { ui } from './classes';
import { t } from './i18n';

/**
 * Editable min/max/price (or fee) rows for price-based / weight-based rates.
 * value: [{ min, max, price }, ...]
 */
export default function RateTiersField({
    value = [],
    onChange,
    minLabel = t('admin.common.from', 'From'),
    maxLabel = t('admin.common.up_to', 'To'),
    priceLabel = t('admin.common.fee', 'Fee'),
    addLabel = t('admin.common.add_tier', 'Add tier'),
}) {
    const rows = value?.length ? value : [{ min: '', max: '', price: '' }];

    const update = (index, key, next) => {
        const copy = rows.map((row, i) => (i === index ? { ...row, [key]: next } : row));
        onChange?.(copy);
    };

    const add = () => onChange?.([...rows, { min: '', max: '', price: '' }]);

    const remove = (index) => {
        if (rows.length <= 1) {
            onChange?.([{ min: '', max: '', price: '' }]);
            return;
        }
        onChange?.(rows.filter((_, i) => i !== index));
    };

    return (
        <div className={ui.tierList}>
            {rows.map((row, index) => (
                <div key={index} className={ui.tierRow}>
                    <Field label={minLabel}>
                        <Input
                            wide
                            type="number"
                            min="0"
                            step="0.01"
                            value={row.min ?? ''}
                            onChange={(e) => update(index, 'min', e.target.value)}
                        />
                    </Field>
                    <Field label={maxLabel}>
                        <Input
                            wide
                            type="number"
                            min="0"
                            step="0.01"
                            value={row.max ?? ''}
                            onChange={(e) => update(index, 'max', e.target.value)}
                            placeholder="∞"
                        />
                    </Field>
                    <Field label={priceLabel}>
                        <Input
                            wide
                            type="number"
                            min="0"
                            step="0.01"
                            value={row.price ?? ''}
                            onChange={(e) => update(index, 'price', e.target.value)}
                        />
                    </Field>
                    <Button type="button" variant="danger" onClick={() => remove(index)}>
                        {t('admin.common.remove', 'Remove')}
                    </Button>
                </div>
            ))}
            <Button type="button" variant="secondary" icon="plus" onClick={add}>
                {addLabel}
            </Button>
        </div>
    );
}
