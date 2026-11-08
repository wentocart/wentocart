function isColorAxis(axis) {
    const name = String(axis?.name || axis?.code || '').toLowerCase();
    return name.includes('color') || name.includes('colour') || axis?.type === 'color';
}

export default function OptionPicker({ groups, picks, onPick }) {
    if (!groups?.length) {
        return null;
    }

    return (
        <div className="shop-pdp-options">
            {groups.map((group) => {
                const color = isColorAxis(group) || group.kind === 'color';
                return (
                    <div key={group.id}>
                        <p className="shop-field-label">{group.name}</p>
                        <div className={color ? 'shop-swatches' : 'shop-choices'}>
                            {(group.values || []).map((value) => {
                                const on = String(picks[group.id]) === String(value.id);
                                if (color) {
                                    return (
                                        <button
                                            key={value.id}
                                            type="button"
                                            className={`shop-swatch ${on ? 'is-on' : ''}`}
                                            style={{ background: value.hex || value.label || '#ccc' }}
                                            aria-label={value.label || value.name}
                                            onClick={() => onPick(group.id, value.id)}
                                        />
                                    );
                                }
                                return (
                                    <button
                                        key={value.id}
                                        type="button"
                                        className={`shop-choice ${on ? 'is-on' : ''}`}
                                        onClick={() => onPick(group.id, value.id)}
                                    >
                                        {value.label || value.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export function optionGroups(product) {
    const axes = product.combination_axes || [];
    const variants = (product.combination_variants || []).filter((row) => row.is_enabled !== false);
    if (!axes.length || !variants.length) {
        return [];
    }
    const used = new Set();
    variants.forEach((row) => (row.attribute_value_ids || []).forEach((id) => used.add(String(id))));
    return axes.map((axis) => ({
        id: axis.attribute_id,
        name: axis.name,
        kind: isColorAxis(axis) ? 'color' : 'choice',
        values: (axis.values || [])
            .filter((value) => value.is_enabled !== false && used.has(String(value.id)))
            .map((value) => ({
                id: value.id,
                label: value.label || value.name || value.value,
                hex: value.hex,
            })),
    })).filter((group) => group.values.length);
}
