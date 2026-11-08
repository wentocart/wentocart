import { useEffect, useState } from 'react';
import {
    Badge,
    Button,
    Checkbox,
    Field,
    Icon,
    Input,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    cx,
} from '../../../ui';

export function itemsSubtotal(items) {
    return items.reduce((sum, item) => (
        sum + (Number(item.selling_price ?? item.price) || 0) * Math.max(1, Number(item.quantity) || 1)
    ), 0);
}

export function composedPrice(mode, subtotal, discountType, discountValue, fixedPrice) {
    if (mode === 'fixed') {
        return Math.max(0, Number(fixedPrice) || 0);
    }
    let total = Number(subtotal) || 0;
    const value = Number(discountValue) || 0;
    if (mode === 'sum_discount') {
        if (discountType === 'percent') {
            total *= (1 - (Math.min(100, Math.max(0, value)) / 100));
        } else {
            total -= Math.max(0, Math.min(total, value));
        }
    }
    if (mode === 'sum_adjustment') {
        total += value;
    }

    return Math.max(0, Math.round(total * 100) / 100);
}

export function derivedQty(items, { type, selectionMode } = {}) {
    const rows = items.filter((item) => {
        if (type === 'bundle') {
            return item.is_required !== false;
        }
        if (selectionMode === 'customer') {
            return !!item.default_selected;
        }
        return true;
    });
    const pool = rows.length ? rows : items;
    let max = null;
    pool.forEach((item) => {
        const need = Math.max(1, Number(item.quantity) || 1);
        const can = Math.floor(Math.max(0, Number(item.stock_qty) || 0) / need);
        max = max === null ? can : Math.min(max, can);
    });

    return max === null ? 0 : max;
}

function emptyItem(row) {
    return {
        id: row.id,
        sku: row.sku,
        name: row.name,
        price: row.price ?? 0,
        selling_price: row.selling_price ?? row.price ?? 0,
        stock_qty: row.stock_qty ?? 0,
        stock_status: row.stock_status,
        is_enabled: row.is_enabled !== false,
        type: row.type,
        quantity: 1,
        min_quantity: '',
        max_quantity: '',
        qty_editable: true,
        is_required: true,
        default_selected: true,
        option_group: '',
    };
}

export default function ComponentItemsTab({
    variant = 'group',
    items,
    onChange,
    excludeId,
    errors = {},
    currencySymbol = '$',
    bundleType = 'fixed',
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [fromIndex, setFromIndex] = useState(null);
    const [overIndex, setOverIndex] = useState(null);
    const selectedIds = items.map((item) => item.id);
    const isBundle = variant === 'bundle';

    useEffect(() => {
        const q = query.trim();
        if (q.length < 1) {
            setResults([]);
            return undefined;
        }
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q, for: 'components' });
            if (excludeId) {
                params.set('exclude', String(excludeId));
            }
            fetch(`/admin/products/search?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => (res.ok ? res.json() : []))
                .then((rows) => setResults(Array.isArray(rows) ? rows.filter((row) => !selectedIds.includes(row.id)) : []))
                .catch(() => setResults([]));
        }, 250);
        return () => clearTimeout(timer);
    }, [query, excludeId, selectedIds.join(',')]);

    const patch = (id, next) => {
        onChange(items.map((item) => (item.id === id ? { ...item, ...next } : item)));
    };

    const move = (from, to) => {
        if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
            return;
        }
        const next = [...items];
        const [row] = next.splice(from, 1);
        next.splice(to, 0, row);
        onChange(next);
    };

    return (
        <div className="space-y-4">
            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{isBundle ? 'Bundle components' : 'Grouped products'}</h2>
                        <p className={ui.muted}>
                            {isBundle
                                ? 'Add existing products as package components. Removing a row here does not delete the product.'
                                : 'Add existing products to this group. Child products keep their own price, stock, and shipping.'}
                        </p>
                    </div>
                </div>
                <Field label={isBundle ? 'Add component' : 'Add products'} error={errors.components}>
                    <div className="relative">
                        <div className={ui.filterSearch}>
                            <Icon name="search" className={ui.filterSearchIcon} />
                            <input
                                className={ui.filterSearchInput}
                                type="text"
                                value={query}
                                placeholder="Search by name or SKU"
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        {results.length > 0 ? (
                            <div className={cx(ui.popMenu, 'left-0 right-0 w-full')}>
                                {results.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        className={ui.popItem}
                                        onClick={() => {
                                            onChange([...items, emptyItem(row)]);
                                            setQuery('');
                                            setResults([]);
                                        }}
                                    >
                                        {row.name} ({row.sku}) · {currencySymbol}{Number(row.selling_price ?? row.price ?? 0).toFixed(2)}
                                    </button>
                                ))}
                            </div>
                        ) : null}
                    </div>
                </Field>
            </section>

            <section className={ui.sectionCard}>
                {items.length === 0 ? (
                    <p className={ui.empty}>No products added yet.</p>
                ) : (
                    <div className={ui.tableWrap}>
                        <Table>
                            <THead>
                                <tr>
                                    <Th></Th>
                                    <Th>Product</Th>
                                    <Th>SKU</Th>
                                    <Th>Price</Th>
                                    <Th>Stock</Th>
                                    <Th>Qty</Th>
                                    {isBundle ? <Th>Required</Th> : <Th>Customer qty</Th>}
                                    <Th>Default</Th>
                                    {isBundle && bundleType === 'configurable' ? <Th>Group</Th> : null}
                                    <Th>Status</Th>
                                    <Th></Th>
                                </tr>
                            </THead>
                            <tbody>
                                {items.map((item, index) => (
                                    <Tr
                                        key={item.id}
                                        className={cx(overIndex === index && fromIndex !== null && fromIndex !== index && ui.dragOver)}
                                        onDragOver={(e) => {
                                            if (fromIndex === null || items.length < 2) {
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
                                            move(fromIndex, index);
                                            setFromIndex(null);
                                            setOverIndex(null);
                                        }}
                                    >
                                        <Td>
                                            {items.length > 1 ? (
                                                <button
                                                    type="button"
                                                    className={ui.dragHandle}
                                                    title="Drag to reorder"
                                                    aria-label={`Reorder ${item.name}`}
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
                                        </Td>
                                        <Td>
                                            <div className="font-medium">{item.name}</div>
                                            <div className={cx(ui.muted, 'text-xs')}>
                                                Min
                                                <Input
                                                    compact
                                                    className="ml-1 w-16 inline-block"
                                                    type="number"
                                                    min="1"
                                                    value={item.min_quantity}
                                                    onChange={(e) => patch(item.id, { min_quantity: e.target.value })}
                                                />
                                                Max
                                                <Input
                                                    compact
                                                    className="ml-1 w-16 inline-block"
                                                    type="number"
                                                    min="1"
                                                    value={item.max_quantity}
                                                    onChange={(e) => patch(item.id, { max_quantity: e.target.value })}
                                                />
                                            </div>
                                        </Td>
                                        <Td>{item.sku}</Td>
                                        <Td>{currencySymbol}{Number(item.selling_price ?? item.price ?? 0).toFixed(2)}</Td>
                                        <Td>{item.stock_qty}</Td>
                                        <Td>
                                            <Input
                                                compact
                                                className="w-20"
                                                type="number"
                                                min="1"
                                                value={item.quantity}
                                                onChange={(e) => patch(item.id, { quantity: e.target.value })}
                                            />
                                        </Td>
                                        {isBundle ? (
                                            <Td>
                                                <Checkbox
                                                    label="Required"
                                                    checked={item.is_required !== false}
                                                    onChange={(e) => patch(item.id, {
                                                        is_required: e.target.checked,
                                                        default_selected: e.target.checked ? true : item.default_selected,
                                                    })}
                                                />
                                            </Td>
                                        ) : (
                                            <Td>
                                                <Checkbox
                                                    label="Editable"
                                                    checked={!!item.qty_editable}
                                                    onChange={(e) => patch(item.id, { qty_editable: e.target.checked })}
                                                />
                                            </Td>
                                        )}
                                        <Td>
                                            <Checkbox
                                                checked={!!item.default_selected}
                                                disabled={isBundle && item.is_required !== false}
                                                onChange={(e) => patch(item.id, { default_selected: e.target.checked })}
                                            />
                                        </Td>
                                        {isBundle && bundleType === 'configurable' ? (
                                            <Td>
                                                <Input
                                                    compact
                                                    className="w-28"
                                                    value={item.option_group || ''}
                                                    placeholder="e.g. Keyboard"
                                                    onChange={(e) => patch(item.id, { option_group: e.target.value })}
                                                />
                                            </Td>
                                        ) : null}
                                        <Td>
                                            <Badge tone={item.is_enabled ? 'success' : 'warning'}>
                                                {item.is_enabled ? 'Active' : 'Disabled'}
                                            </Badge>
                                        </Td>
                                        <Td actions>
                                            <Button
                                                type="button"
                                                variant="danger"
                                                icon="trash"
                                                aria-label={`Remove ${item.name}`}
                                                onClick={() => onChange(items.filter((row) => row.id !== item.id))}
                                            />
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </section>
        </div>
    );
}
