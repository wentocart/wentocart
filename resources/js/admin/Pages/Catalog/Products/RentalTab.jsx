import { useMemo, useState } from 'react';
import {
    Button,
    Checkbox,
    ChoiceCards,
    Field,
    Input,
    Select,
    Textarea,
    ui,
    cx,
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

function moveItem(items, from, to) {
    if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
        return items;
    }
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next.map((row, index) => ({ ...row, position: index }));
}

function FeeFields({ prefix, type, value, onTypeChange, onValueChange, error, allowedTypes = ['none', 'fixed', 'percent'] }) {
    return (
        <div className={ui.fieldGrid}>
            <Field label={`${prefix} type`}>
                <Select wide value={type || 'none'} onChange={(e) => onTypeChange(e.target.value)}>
                    {allowedTypes.includes('none') ? <option value="none">None</option> : null}
                    {allowedTypes.includes('fixed') ? <option value="fixed">Fixed amount</option> : null}
                    {allowedTypes.includes('percent') ? <option value="percent">Percentage</option> : null}
                    {allowedTypes.includes('per_hour') ? <option value="per_hour">Per hour</option> : null}
                    {allowedTypes.includes('per_day') ? <option value="per_day">Per day</option> : null}
                </Select>
            </Field>
            {type && type !== 'none' ? (
                <Field label={`${prefix} value`} error={error}>
                    <Input wide type="number" min="0" step="0.01" value={value ?? ''} onChange={(e) => onValueChange(e.target.value)} />
                </Field>
            ) : null}
        </div>
    );
}

export function defaultRentalConfig() {
    return {
        rental_unit: 'day',
        min_duration: 1,
        max_duration: 30,
        default_duration: 1,
        pricing_model: 'per_day',
        availability_config: {
            available_from: '',
            available_until: '',
            min_advance_booking: '',
            min_advance_unit: 'days',
            max_advance_booking: '',
            max_advance_unit: 'days',
            buffer_time: '',
            buffer_unit: 'days',
            booking_start_time: '09:00',
            booking_end_time: '18:00',
        },
        security_deposit_type: 'none',
        security_deposit_value: '',
        inventory_mode: 'quantity',
        maintenance_units: 0,
        out_of_service_units: 0,
        fulfillment_method: 'both',
        return_method: 'both',
        return_location: '',
        grace_period: '',
        grace_period_unit: 'hour',
        late_fee_type: 'none',
        late_fee_value: '',
        damage_fee_type: 'none',
        damage_fee_value: '',
        lost_item_fee_type: 'none',
        lost_item_fee_value: '',
        cleaning_fee: '',
    };
}

export function rentalPayload(config, tiers, assets) {
    const availability = { ...(config.availability_config || {}) };

    return {
        rental: {
            ...config,
            security_deposit_value: config.security_deposit_value === '' ? null : config.security_deposit_value,
            late_fee_value: config.late_fee_value === '' ? null : config.late_fee_value,
            damage_fee_value: config.damage_fee_value === '' ? null : config.damage_fee_value,
            lost_item_fee_value: config.lost_item_fee_value === '' ? null : config.lost_item_fee_value,
            cleaning_fee: config.cleaning_fee === '' ? null : config.cleaning_fee,
            grace_period: config.grace_period === '' ? null : config.grace_period,
            return_location: config.return_location === '' ? null : config.return_location,
            availability_config: availability,
        },
        rental_pricing_tiers: tiers.map((row, index) => ({
            id: row.id || null,
            min_duration: row.min_duration,
            max_duration: row.max_duration,
            unit: row.unit,
            price: row.price,
            position: index,
        })),
        rental_assets: assets.map((row, index) => ({
            id: row.id || null,
            asset_id: row.asset_id,
            serial_number: row.serial_number || null,
            barcode: row.barcode || null,
            status: row.status,
            location: row.location || null,
            condition: row.condition || null,
            position: index,
        })),
    };
}

function TierEditor({ draft, onChange, onSave, onCancel, currencySymbol, errors = {} }) {
    return (
        <div className={cx(ui.fieldGrid, 'mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4')}>
            <Field label="Minimum duration" error={errors.min_duration}>
                <Input wide type="number" min="1" value={draft.min_duration} onChange={(e) => onChange({ ...draft, min_duration: e.target.value })} />
            </Field>
            <Field label="Maximum duration" error={errors.max_duration}>
                <Input wide type="number" min="1" value={draft.max_duration} onChange={(e) => onChange({ ...draft, max_duration: e.target.value })} />
            </Field>
            <Field label="Unit">
                <Select wide value={draft.unit} onChange={(e) => onChange({ ...draft, unit: e.target.value })}>
                    <option value="hour">Hour</option>
                    <option value="day">Day</option>
                    <option value="week">Week</option>
                    <option value="month">Month</option>
                </Select>
            </Field>
            <Field label="Price" error={errors.price}>
                <Input wide type="number" min="0" step="0.01" value={draft.price} onChange={(e) => onChange({ ...draft, price: e.target.value })} />
            </Field>
            <div className="col-span-full flex justify-end gap-2">
                <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                <Button type="button" onClick={onSave}>Save tier</Button>
            </div>
        </div>
    );
}

function AssetEditor({ draft, onChange, onSave, onCancel, errors = {} }) {
    return (
        <div className={cx(ui.fieldGrid, 'mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4')}>
            <Field label="Asset ID" error={errors.asset_id}>
                <Input wide value={draft.asset_id} onChange={(e) => onChange({ ...draft, asset_id: e.target.value })} />
            </Field>
            <Field label="Serial number">
                <Input wide value={draft.serial_number || ''} onChange={(e) => onChange({ ...draft, serial_number: e.target.value })} />
            </Field>
            <Field label="Barcode">
                <Input wide value={draft.barcode || ''} onChange={(e) => onChange({ ...draft, barcode: e.target.value })} />
            </Field>
            <Field label="Status">
                <Select wide value={draft.status} onChange={(e) => onChange({ ...draft, status: e.target.value })}>
                    <option value="available">Available</option>
                    <option value="reserved">Reserved</option>
                    <option value="rented">Rented</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="damaged">Damaged</option>
                    <option value="lost">Lost</option>
                    <option value="out_of_service">Out of service</option>
                </Select>
            </Field>
            <Field label="Location">
                <Input wide value={draft.location || ''} onChange={(e) => onChange({ ...draft, location: e.target.value })} />
            </Field>
            <Field label="Condition">
                <Input wide value={draft.condition || ''} onChange={(e) => onChange({ ...draft, condition: e.target.value })} />
            </Field>
            <div className="col-span-full flex justify-end gap-2">
                <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
                <Button type="button" onClick={onSave}>Save asset</Button>
            </div>
        </div>
    );
}

export default function RentalTab({
    config,
    tiers,
    assets,
    inventorySummary,
    onConfigChange,
    onTiersChange,
    onAssetsChange,
    errors = {},
    currencySymbol = '$',
}) {
    const [tierEditor, setTierEditor] = useState(null);
    const [tierDraft, setTierDraft] = useState(null);
    const [tierErrors, setTierErrors] = useState({});
    const [assetEditor, setAssetEditor] = useState(null);
    const [assetDraft, setAssetDraft] = useState(null);
    const [assetErrors, setAssetErrors] = useState({});

    const availability = useMemo(
        () => ({ ...defaultRentalConfig().availability_config, ...(config.availability_config || {}) }),
        [config.availability_config],
    );

    const setConfig = (patch) => onConfigChange({ ...config, ...patch });
    const setAvailability = (patch) => setConfig({
        availability_config: { ...availability, ...patch },
    });

    const sortedTiers = useMemo(
        () => [...tiers].sort((a, b) => (a.position ?? 0) - (b.position ?? 0)),
        [tiers],
    );

    const validateTier = (row) => {
        const next = {};
        const min = Number(row.min_duration);
        const max = Number(row.max_duration);
        if (!min || min <= 0) next.min_duration = 'Minimum duration must be greater than zero.';
        if (!max || max < min) next.max_duration = 'Maximum duration must be greater than or equal to minimum.';
        if (row.price === '' || Number(row.price) < 0) next.price = 'Price cannot be negative.';
        return next;
    };

    const saveTier = () => {
        const nextErrors = validateTier(tierDraft);
        setTierErrors(nextErrors);
        if (Object.keys(nextErrors).length) return;
        if (tierEditor === 'create') {
            onTiersChange([...tiers, { ...tierDraft, position: tiers.length }]);
        } else {
            onTiersChange(tiers.map((row) => (row.key === tierDraft.key ? { ...tierDraft } : row)));
        }
        setTierEditor(null);
        setTierDraft(null);
    };

    const saveAsset = () => {
        const next = {};
        if (!String(assetDraft.asset_id || '').trim()) next.asset_id = 'Asset ID is required.';
        setAssetErrors(next);
        if (Object.keys(next).length) return;
        if (assetEditor === 'create') {
            onAssetsChange([...assets, { ...assetDraft, position: assets.length }]);
        } else {
            onAssetsChange(assets.map((row) => (row.key === assetDraft.key ? { ...assetDraft } : row)));
        }
        setAssetEditor(null);
        setAssetDraft(null);
    };

    return (
        <div className="space-y-4">
            <Section title="Rental duration">
                <div className={ui.fieldGrid}>
                    <Field label="Rental unit">
                        <Select wide value={config.rental_unit} onChange={(e) => setConfig({ rental_unit: e.target.value })}>
                            <option value="hour">Hour</option>
                            <option value="day">Day</option>
                            <option value="week">Week</option>
                            <option value="month">Month</option>
                        </Select>
                    </Field>
                    <Field label="Minimum duration" error={errors['rental.min_duration']}>
                        <Input wide type="number" min="1" value={config.min_duration} onChange={(e) => setConfig({ min_duration: e.target.value })} />
                    </Field>
                    <Field label="Maximum duration" error={errors['rental.max_duration']}>
                        <Input wide type="number" min="1" value={config.max_duration} onChange={(e) => setConfig({ max_duration: e.target.value })} />
                    </Field>
                    <Field label="Default duration" error={errors['rental.default_duration']}>
                        <Input wide type="number" min="1" value={config.default_duration} onChange={(e) => setConfig({ default_duration: e.target.value })} />
                    </Field>
                </div>
            </Section>

            <Section title="Pricing model" hint="Base rental price is configured on the Pricing tab. Optional tiers below override by duration range.">
                <ChoiceCards
                    name="rental-pricing-model"
                    options={[
                        { value: 'per_hour', label: 'Per hour' },
                        { value: 'per_day', label: 'Per day' },
                        { value: 'per_week', label: 'Per week' },
                        { value: 'per_month', label: 'Per month' },
                    ]}
                    value={config.pricing_model}
                    onChange={(value) => setConfig({ pricing_model: value })}
                />
            </Section>

            <Section title="Tiered rental pricing">
                <div className="flex items-center justify-between gap-3 mb-3">
                    <p className={ui.muted}>Optional duration-based pricing tiers. Overlapping ranges are rejected.</p>
                    <Button type="button" icon="plus" onClick={() => {
                        setTierEditor('create');
                        setTierDraft({ key: nextKey('tier'), min_duration: '', max_duration: '', unit: config.rental_unit, price: '' });
                        setTierErrors({});
                    }}
                    >
                        Add tier
                    </Button>
                </div>
                {tierEditor === 'create' && tierDraft ? (
                    <TierEditor draft={tierDraft} onChange={setTierDraft} onSave={saveTier} onCancel={() => { setTierEditor(null); setTierDraft(null); }} currencySymbol={currencySymbol} errors={tierErrors} />
                ) : null}
                <div className={ui.tableWrap}>
                    <table className={ui.table}>
                        <thead>
                            <tr>
                                <th>Duration range</th>
                                <th>Unit</th>
                                <th>Price</th>
                                <th className="w-28">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sortedTiers.length === 0 ? (
                                <tr><td colSpan="4" className={ui.muted}>No pricing tiers configured.</td></tr>
                            ) : sortedTiers.map((row, index) => (
                                <tr key={row.key}>
                                    <td>{row.min_duration}–{row.max_duration}</td>
                                    <td>{row.unit}</td>
                                    <td>{currencySymbol}{row.price}</td>
                                    <td>
                                        <div className="flex gap-1">
                                            <Button type="button" variant="ghost" icon="edit" aria-label="Edit" onClick={() => { setTierEditor(row.key); setTierDraft({ ...row }); setTierErrors({}); }} />
                                            <Button type="button" variant="ghost" icon="trash" aria-label="Delete" onClick={() => onTiersChange(tiers.filter((item) => item.key !== row.key))} />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {tierEditor !== 'create' && tierDraft ? (
                    <TierEditor draft={tierDraft} onChange={setTierDraft} onSave={saveTier} onCancel={() => { setTierEditor(null); setTierDraft(null); }} currencySymbol={currencySymbol} errors={tierErrors} />
                ) : null}
            </Section>

            <Section title="Availability" hint="Product-level availability rules. Reservation conflict detection is not implemented yet.">
                <div className={ui.fieldGrid}>
                    <Field label="Available from">
                        <Input wide type="date" value={availability.available_from || ''} onChange={(e) => setAvailability({ available_from: e.target.value })} />
                    </Field>
                    <Field label="Available until">
                        <Input wide type="date" value={availability.available_until || ''} onChange={(e) => setAvailability({ available_until: e.target.value })} />
                    </Field>
                    <Field label="Minimum advance booking">
                        <Input wide type="number" min="0" value={availability.min_advance_booking ?? ''} onChange={(e) => setAvailability({ min_advance_booking: e.target.value })} />
                    </Field>
                    <Field label="Maximum advance booking">
                        <Input wide type="number" min="0" value={availability.max_advance_booking ?? ''} onChange={(e) => setAvailability({ max_advance_booking: e.target.value })} />
                    </Field>
                    <Field label="Buffer time">
                        <Input wide type="number" min="0" value={availability.buffer_time ?? ''} onChange={(e) => setAvailability({ buffer_time: e.target.value })} />
                    </Field>
                    <Field label="Booking start time">
                        <Input wide type="time" value={availability.booking_start_time || ''} onChange={(e) => setAvailability({ booking_start_time: e.target.value })} />
                    </Field>
                    <Field label="Booking end time">
                        <Input wide type="time" value={availability.booking_end_time || ''} onChange={(e) => setAvailability({ booking_end_time: e.target.value })} />
                    </Field>
                </div>
            </Section>

            <Section title="Security deposit">
                <FeeFields
                    prefix="Security deposit"
                    type={config.security_deposit_type}
                    value={config.security_deposit_value}
                    onTypeChange={(value) => setConfig({ security_deposit_type: value })}
                    onValueChange={(value) => setConfig({ security_deposit_value: value })}
                    error={errors['rental.security_deposit_value']}
                    allowedTypes={['none', 'fixed', 'percent']}
                />
                <p className={cx(ui.muted, 'mt-2')}>Deposit collection and refund handling happens during the rental order lifecycle.</p>
            </Section>

            <Section title="Inventory mode">
                <ChoiceCards
                    name="rental-inventory-mode"
                    options={[
                        { value: 'quantity', label: 'Quantity-based', hint: 'Use the Inventory tab for total units.' },
                        { value: 'asset', label: 'Asset-based', hint: 'Track individual rental assets below.' },
                    ]}
                    value={config.inventory_mode}
                    onChange={(value) => setConfig({ inventory_mode: value })}
                />
                {config.inventory_mode === 'quantity' ? (
                    <div className={ui.fieldGrid}>
                        <Field label="Maintenance units" error={errors['rental.maintenance_units']}>
                            <Input wide type="number" min="0" value={config.maintenance_units} onChange={(e) => setConfig({ maintenance_units: e.target.value })} />
                        </Field>
                        <Field label="Out-of-service units">
                            <Input wide type="number" min="0" value={config.out_of_service_units} onChange={(e) => setConfig({ out_of_service_units: e.target.value })} />
                        </Field>
                    </div>
                ) : null}
                {inventorySummary ? (
                    <div className="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div><span className={ui.label}>Total</span><p>{inventorySummary.total_units}</p></div>
                        <div><span className={ui.label}>Available</span><p>{inventorySummary.available_units}</p></div>
                        <div><span className={ui.label}>Reserved</span><p>{inventorySummary.reserved_units}</p></div>
                        <div><span className={ui.label}>Rented</span><p>{inventorySummary.rented_units}</p></div>
                    </div>
                ) : null}
            </Section>

            {config.inventory_mode === 'asset' ? (
                <Section title="Rental assets">
                    <div className="flex justify-end mb-3">
                        <Button type="button" icon="plus" onClick={() => {
                            setAssetEditor('create');
                            setAssetDraft({ key: nextKey('asset'), asset_id: '', serial_number: '', barcode: '', status: 'available', location: '', condition: '' });
                            setAssetErrors({});
                        }}
                        >
                            Add asset
                        </Button>
                    </div>
                    {assetEditor === 'create' && assetDraft ? (
                        <AssetEditor draft={assetDraft} onChange={setAssetDraft} onSave={saveAsset} onCancel={() => { setAssetEditor(null); setAssetDraft(null); }} errors={assetErrors} />
                    ) : null}
                    <div className={ui.tableWrap}>
                        <table className={ui.table}>
                            <thead>
                                <tr>
                                    <th>Asset ID</th>
                                    <th>Serial</th>
                                    <th>Status</th>
                                    <th>Location</th>
                                    <th className="w-28">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assets.length === 0 ? (
                                    <tr><td colSpan="5" className={ui.muted}>No rental assets configured.</td></tr>
                                ) : assets.map((row) => (
                                    <tr key={row.key}>
                                        <td>{row.asset_id}</td>
                                        <td>{row.serial_number || '—'}</td>
                                        <td>{row.status}</td>
                                        <td>{row.location || '—'}</td>
                                        <td>
                                            <div className="flex gap-1">
                                                <Button type="button" variant="ghost" icon="edit" aria-label="Edit" onClick={() => { setAssetEditor(row.key); setAssetDraft({ ...row }); setAssetErrors({}); }} />
                                                <Button type="button" variant="ghost" icon="trash" aria-label="Delete" onClick={() => onAssetsChange(assets.filter((item) => item.key !== row.key))} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {assetEditor !== 'create' && assetDraft ? (
                        <AssetEditor draft={assetDraft} onChange={setAssetDraft} onSave={saveAsset} onCancel={() => { setAssetEditor(null); setAssetDraft(null); }} errors={assetErrors} />
                    ) : null}
                </Section>
            ) : null}

            <Section title="Fulfillment">
                <ChoiceCards
                    name="rental-fulfillment"
                    options={[
                        { value: 'pickup', label: 'Customer pickup' },
                        { value: 'delivery', label: 'Delivery' },
                        { value: 'both', label: 'Both' },
                    ]}
                    value={config.fulfillment_method}
                    onChange={(value) => setConfig({ fulfillment_method: value })}
                />
                <p className={cx(ui.muted, 'mt-2')}>Delivery uses the existing Shipping tab and shipping methods.</p>
            </Section>

            <Section title="Return">
                <ChoiceCards
                    name="rental-return"
                    options={[
                        { value: 'customer_return', label: 'Customer return' },
                        { value: 'pickup', label: 'Pickup' },
                        { value: 'both', label: 'Both' },
                    ]}
                    value={config.return_method}
                    onChange={(value) => setConfig({ return_method: value })}
                />
                <div className={ui.fieldGrid}>
                    <Field label="Return location">
                        <Input wide value={config.return_location || ''} onChange={(e) => setConfig({ return_location: e.target.value })} />
                    </Field>
                    <Field label="Grace period">
                        <Input wide type="number" min="0" value={config.grace_period ?? ''} onChange={(e) => setConfig({ grace_period: e.target.value })} />
                    </Field>
                    <Field label="Grace period unit">
                        <Select wide value={config.grace_period_unit || 'hour'} onChange={(e) => setConfig({ grace_period_unit: e.target.value })}>
                            <option value="hour">Hours</option>
                            <option value="day">Days</option>
                        </Select>
                    </Field>
                </div>
                <div className="mt-4">
                    <p className={ui.label}>Late return fee</p>
                    <FeeFields
                        prefix="Late fee"
                        type={config.late_fee_type}
                        value={config.late_fee_value}
                        onTypeChange={(value) => setConfig({ late_fee_type: value })}
                        onValueChange={(value) => setConfig({ late_fee_value: value })}
                        error={errors['rental.late_fee_value']}
                        allowedTypes={['none', 'fixed', 'per_hour', 'per_day', 'percent']}
                    />
                </div>
            </Section>

            <Section title="Damage / loss policies">
                <div className="space-y-4">
                    <div>
                        <p className={ui.label}>Damage fee</p>
                        <FeeFields prefix="Damage fee" type={config.damage_fee_type} value={config.damage_fee_value} onTypeChange={(value) => setConfig({ damage_fee_type: value })} onValueChange={(value) => setConfig({ damage_fee_value: value })} />
                    </div>
                    <div>
                        <p className={ui.label}>Lost item fee</p>
                        <FeeFields prefix="Lost item fee" type={config.lost_item_fee_type} value={config.lost_item_fee_value} onTypeChange={(value) => setConfig({ lost_item_fee_type: value })} onValueChange={(value) => setConfig({ lost_item_fee_value: value })} />
                    </div>
                    <Field label="Cleaning fee">
                        <Input wide type="number" min="0" step="0.01" value={config.cleaning_fee ?? ''} onChange={(e) => setConfig({ cleaning_fee: e.target.value })} />
                    </Field>
                </div>
            </Section>
        </div>
    );
}
