import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Layout from '../../Layout';
import {
    BulkActions,
    Button,
    Checkbox,
    ColumnFilter,
    EmptyState,
    Field,
    FilterBar,
    FilterReset,
    FormErrors,
    Input,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
    KpiCards,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    listStatusFilterOptions,
    useListSelection,
    ui,
    t,
} from '../../../ui';

const emptyRule = {
    tax_class_id: '',
    tax_zone_id: '',
    tax_rate_id: '',
    name: '',
    priority: 10,
    is_compound: false,
    is_enabled: true,
};

const emptyClass = { name: '' };

const emptyZone = {
    name: '',
    country_id: '',
    region_ids: [],
    is_enabled: true,
};

const emptyRate = {
    name: '',
    rate: 0,
    is_enabled: true,
};

function RuleModal({ open, editing, classes, zones, rates, onClose }) {
    const form = useForm({ ...emptyRule });

    useEffect(() => {
        if (!open) return;
        if (editing) {
            form.setData({
                tax_class_id: editing.tax_class_id || '',
                tax_zone_id: editing.tax_zone_id || '',
                tax_rate_id: editing.tax_rate_id || '',
                name: editing.name || '',
                priority: editing.priority ?? 10,
                is_compound: !!editing.is_compound,
                is_enabled: !!editing.is_enabled,
            });
        } else {
            form.setData({ ...emptyRule });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when modal opens / row changes
    }, [open, editing?.id]);

    if (!open) return null;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, is_enabled: !!d.is_enabled }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };
        if (editing) {
            form.put(`/admin/tax/rules/${editing.id}`, opts);
        } else {
            form.post('/admin/tax/rules', opts);
        }
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={ui.modal}
                role="dialog"
                aria-modal="true"
                aria-labelledby="tax-rule-modal-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="tax-rule-modal-title" className={ui.modalTitle}>
                            {editing
                                ? t('admin.taxes.edit_rule', 'Edit tax rule')
                                : t('admin.taxes.add_rule', 'Add rule')}
                        </h2>
                        <p className={ui.modalSub}>
                            {t('admin.taxes.class_zone_rate_used_at_checkout_per_line_item', 'Class + zone → rate. Used at checkout per line item.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <form onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.taxes.tax_class', 'Tax class')} error={form.errors.tax_class_id} required>
                                <Select wide value={form.data.tax_class_id} onChange={(e) => form.setData('tax_class_id', e.target.value)}>
                                    <option value="">{t('admin.taxes.select', 'Select…')}</option>
                                    {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </Select>
                            </Field>
                            <Field label={t('admin.taxes.zone', 'Zone')} error={form.errors.tax_zone_id} required>
                                <Select wide value={form.data.tax_zone_id} onChange={(e) => form.setData('tax_zone_id', e.target.value)}>
                                    <option value="">{t('admin.taxes.select', 'Select…')}</option>
                                    {zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
                                </Select>
                            </Field>
                            <Field label={t('admin.taxes.rate', 'Rate')} error={form.errors.tax_rate_id} required>
                                <Select wide value={form.data.tax_rate_id} onChange={(e) => form.setData('tax_rate_id', e.target.value)}>
                                    <option value="">{t('admin.taxes.select', 'Select…')}</option>
                                    {rates.map((r) => <option key={r.id} value={r.id}>{r.name} ({r.rate}%)</option>)}
                                </Select>
                            </Field>
                            <Field label={t('admin.taxes.priority', 'Priority')}>
                                <Input wide type="number" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)} />
                            </Field>
                            <Field label={t('admin.taxes.label_optional', 'Label (optional)')}>
                                <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                        </div>
                        <Checkbox
                            label={t('admin.taxes.compound', 'Compound (tax on tax)')}
                            checked={!!form.data.is_compound}
                            onChange={(e) => form.setData('is_compound', e.target.checked)}
                        />
                        <Checkbox
                            label={t('admin.taxes.enabled', 'Enabled')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {editing ? t('admin.common.save', 'Save') : t('admin.taxes.add_rule', 'Add rule')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ClassModal({ open, editing, onClose }) {
    const form = useForm({ ...emptyClass });

    useEffect(() => {
        if (!open) return;
        if (editing) {
            form.setData({ name: editing.name || '' });
        } else {
            form.setData({ ...emptyClass });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when modal opens / row changes
    }, [open, editing?.id]);

    if (!open) return null;

    const submit = (e) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };
        if (editing) {
            form.put(`/admin/tax/classes/${editing.id}`, opts);
        } else {
            form.post('/admin/tax/classes', opts);
        }
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={ui.modal}
                role="dialog"
                aria-modal="true"
                aria-labelledby="tax-class-modal-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="tax-class-modal-title" className={ui.modalTitle}>
                            {editing
                                ? t('admin.taxes.edit_class', 'Edit tax class')
                                : t('admin.taxes.add_class', 'Add class')}
                        </h2>
                        <p className={ui.modalSub}>
                            {t('admin.taxes.class_modal_hint', 'Assign this class on products. Rules link class + zone → rate.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <form onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        <Field label={t('admin.taxes.name', 'Name')} error={form.errors.name} required>
                            <Input
                                wide
                                placeholder={t('admin.taxes.class_name_example', 'e.g. GST 18%, Sales tax')}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {editing ? t('admin.common.save', 'Save') : t('admin.taxes.add_class', 'Add class')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ZoneModal({ open, editing, options = {}, onClose }) {
    const form = useForm({ ...emptyZone });

    useEffect(() => {
        if (!open) return;
        if (editing) {
            form.setData({
                name: editing.name || '',
                country_id: editing.country_id || editing.country?.id || '',
                region_ids: (editing.regions || []).map((r) => r.id),
                is_enabled: !!editing.is_enabled,
            });
        } else {
            form.setData({ ...emptyZone });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when modal opens / row changes
    }, [open, editing?.id]);

    const regionsForCountry = useMemo(() => {
        const cid = Number(form.data.country_id || 0);
        return (options.regions || []).filter((r) => Number(r.country_id) === cid);
    }, [options.regions, form.data.country_id]);

    if (!open) return null;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            country_id: d.country_id || null,
            region_ids: d.region_ids || [],
            is_enabled: !!d.is_enabled,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };
        if (editing) {
            form.put(`/admin/tax/zones/${editing.id}`, opts);
        } else {
            form.post('/admin/tax/zones', opts);
        }
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={ui.modal}
                role="dialog"
                aria-modal="true"
                aria-labelledby="tax-zone-modal-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="tax-zone-modal-title" className={ui.modalTitle}>
                            {editing
                                ? t('admin.taxes.edit_zone', 'Edit tax zone')
                                : t('admin.taxes.add_zone', 'Add zone')}
                        </h2>
                        <p className={ui.modalSub}>
                            {t('admin.taxes.zone_modal_hint', 'Where tax applies — country and optional regions.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <form onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.taxes.name', 'Name')} error={form.errors.name} required>
                                <Input
                                    wide
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.taxes.india_gst_zone', 'India GST zone')}
                                />
                            </Field>
                            <Field label={t('admin.taxes.country', 'Country')}>
                                <Select
                                    wide
                                    value={form.data.country_id}
                                    onChange={(e) => form.setData({ ...form.data, country_id: e.target.value, region_ids: [] })}
                                >
                                    <option value="">{t('admin.taxes.worldwide', 'Worldwide')}</option>
                                    {(options.countries || []).map((c) => (
                                        <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        {regionsForCountry.length > 0 ? (
                            <Field label={t('admin.taxes.regions_optional_empty_whole_country', 'Regions (optional — empty = whole country)')}>
                                <div className="grid gap-1 max-h-40 overflow-auto rounded-lg border border-neutral-200 p-2">
                                    {regionsForCountry.map((r) => {
                                        const checked = form.data.region_ids.includes(r.id);
                                        return (
                                            <Checkbox
                                                key={r.id}
                                                label={`${r.name} (${r.code})`}
                                                checked={checked}
                                                onChange={(e) => {
                                                    const next = e.target.checked
                                                        ? [...form.data.region_ids, r.id]
                                                        : form.data.region_ids.filter((id) => id !== r.id);
                                                    form.setData('region_ids', next);
                                                }}
                                            />
                                        );
                                    })}
                                </div>
                            </Field>
                        ) : null}
                        <Checkbox
                            label={t('admin.taxes.enabled', 'Enabled')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {editing ? t('admin.common.save', 'Save') : t('admin.taxes.add_zone', 'Add zone')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function RateModal({ open, editing, onClose }) {
    const form = useForm({ ...emptyRate });

    useEffect(() => {
        if (!open) return;
        if (editing) {
            form.setData({
                name: editing.name || '',
                rate: editing.rate ?? 0,
                is_enabled: !!editing.is_enabled,
            });
        } else {
            form.setData({ ...emptyRate });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when modal opens / row changes
    }, [open, editing?.id]);

    if (!open) return null;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, is_enabled: !!d.is_enabled }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };
        if (editing) {
            form.put(`/admin/tax/rates/${editing.id}`, opts);
        } else {
            form.post('/admin/tax/rates', opts);
        }
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={ui.modal}
                role="dialog"
                aria-modal="true"
                aria-labelledby="tax-rate-modal-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="tax-rate-modal-title" className={ui.modalTitle}>
                            {editing
                                ? t('admin.taxes.edit_rate', 'Edit tax rate')
                                : t('admin.taxes.add_rate', 'Add rate')}
                        </h2>
                        <p className={ui.modalSub}>
                            {t('admin.taxes.rate_modal_hint', 'Percent charged when a rule uses this rate.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <form onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.taxes.name', 'Name')} error={form.errors.name} required>
                                <Input
                                    wide
                                    placeholder={t('admin.taxes.rate_name', 'Rate name')}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.taxes.rate_2', 'Rate %')} error={form.errors.rate} required>
                                <Input
                                    wide
                                    type="number"
                                    step="0.01"
                                    value={form.data.rate}
                                    onChange={(e) => form.setData('rate', e.target.value)}
                                />
                            </Field>
                        </div>
                        <Checkbox
                            label={t('admin.taxes.enabled', 'Enabled')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {editing ? t('admin.common.save', 'Save') : t('admin.taxes.add_rate', 'Add rate')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function Index({ classes = [], zones = [], rates = [], rules = [], options = {}, report = { rows: [], orders: [], total: 0 } }) {
    const [tab, setTab] = useState('rules');
    const [ruleModal, setRuleModal] = useState({ open: false, editing: null });
    const [classModal, setClassModal] = useState({ open: false, editing: null });
    const [zoneModal, setZoneModal] = useState({ open: false, editing: null });
    const [rateModal, setRateModal] = useState({ open: false, editing: null });
    const [bulkBusy, setBulkBusy] = useState(false);

    const [ruleFilters, setRuleFilters] = useState({
        q: '',
        status: 'all',
        class_id: '',
        zone_id: '',
    });
    const [classFilters, setClassFilters] = useState({ q: '' });
    const [zoneFilters, setZoneFilters] = useState({ q: '', status: 'all' });
    const [rateFilters, setRateFilters] = useState({ q: '', status: 'all' });

    const ruleCounts = useMemo(() => ({
        all: rules.length,
        active: rules.filter((r) => r.is_enabled).length,
        inactive: rules.filter((r) => !r.is_enabled).length,
    }), [rules]);

    const zoneCounts = useMemo(() => ({
        all: zones.length,
        active: zones.filter((z) => z.is_enabled).length,
        inactive: zones.filter((z) => !z.is_enabled).length,
    }), [zones]);

    const rateCounts = useMemo(() => ({
        all: rates.length,
        active: rates.filter((r) => r.is_enabled).length,
        inactive: rates.filter((r) => !r.is_enabled).length,
    }), [rates]);

    const filteredRules = useMemo(() => {
        const needle = ruleFilters.q.trim().toLowerCase();
        return rules.filter((r) => {
            if (ruleFilters.status === 'active' && !r.is_enabled) return false;
            if (ruleFilters.status === 'inactive' && r.is_enabled) return false;
            if (ruleFilters.class_id && String(r.tax_class_id) !== String(ruleFilters.class_id)) return false;
            if (ruleFilters.zone_id && String(r.tax_zone_id) !== String(ruleFilters.zone_id)) return false;
            if (!needle) return true;
            const hay = `${r.class_name || ''} ${r.zone_name || ''} ${r.rate_name || ''} ${r.name || ''} ${r.rate || ''}`.toLowerCase();
            return hay.includes(needle);
        });
    }, [rules, ruleFilters]);

    const filteredClasses = useMemo(() => {
        const needle = classFilters.q.trim().toLowerCase();
        if (!needle) return classes;
        return classes.filter((c) => String(c.name || '').toLowerCase().includes(needle));
    }, [classes, classFilters]);

    const filteredZones = useMemo(() => {
        const needle = zoneFilters.q.trim().toLowerCase();
        return zones.filter((z) => {
            if (zoneFilters.status === 'active' && !z.is_enabled) return false;
            if (zoneFilters.status === 'inactive' && z.is_enabled) return false;
            if (!needle) return true;
            const country = z.country ? `${z.country.name} ${z.country.code}` : '';
            const regions = (z.regions || []).map((r) => `${r.name} ${r.code}`).join(' ');
            return `${z.name || ''} ${country} ${regions}`.toLowerCase().includes(needle);
        });
    }, [zones, zoneFilters]);

    const filteredRates = useMemo(() => {
        const needle = rateFilters.q.trim().toLowerCase();
        return rates.filter((r) => {
            if (rateFilters.status === 'active' && !r.is_enabled) return false;
            if (rateFilters.status === 'inactive' && r.is_enabled) return false;
            if (!needle) return true;
            return `${r.name || ''} ${r.rate || ''}`.toLowerCase().includes(needle);
        });
    }, [rates, rateFilters]);

    const ruleSelection = useListSelection(filteredRules);
    const classSelection = useListSelection(filteredClasses);
    const zoneSelection = useListSelection(filteredZones);
    const rateSelection = useListSelection(filteredRates);

    const ruleFiltersActive = !!(ruleFilters.q || ruleFilters.class_id || ruleFilters.zone_id || (ruleFilters.status && ruleFilters.status !== 'all'));
    const classFiltersActive = !!classFilters.q;
    const zoneFiltersActive = !!(zoneFilters.q || (zoneFilters.status && zoneFilters.status !== 'all'));
    const rateFiltersActive = !!(rateFilters.q || (rateFilters.status && rateFilters.status !== 'all'));

    const applyRuleFilter = (patch) => setRuleFilters((prev) => ({ ...prev, ...patch }));
    const applyRuleColumn = (nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            applyRuleFilter(nameOrPatch);
            return;
        }
        applyRuleFilter({ [nameOrPatch]: value });
    };
    const resetRuleFilters = () => setRuleFilters({ q: '', status: 'all', class_id: '', zone_id: '' });

    const applyZoneFilter = (patch) => setZoneFilters((prev) => ({ ...prev, ...patch }));
    const applyRateFilter = (patch) => setRateFilters((prev) => ({ ...prev, ...patch }));

    const runBulk = (selection, url, payload = {}) => {
        const ids = (selection.selectedIds || []).map(Number).filter((id) => id > 0);
        if (!ids.length || bulkBusy) return;
        setBulkBusy(true);
        router.post(url, { ...payload, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    const entityTabs = [
        { key: 'rules', label: t('admin.taxes.rules', 'Rules'), count: rules.length },
        { key: 'classes', label: t('admin.taxes.classes', 'Classes'), count: classes.length },
        { key: 'zones', label: t('admin.taxes.zones', 'Zones'), count: zones.length },
        { key: 'rates', label: t('admin.taxes.rates', 'Rates'), count: rates.length },
        { key: 'report', label: t('admin.taxes.report', 'Report') },
    ];

    const ruleStatusTabs = [
        { key: 'all', label: t('admin.common.all', 'All'), count: ruleCounts.all },
        { key: 'active', label: t('admin.common.active', 'Active'), count: ruleCounts.active },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: ruleCounts.inactive },
    ];

    const zoneStatusTabs = [
        { key: 'all', label: t('admin.common.all', 'All'), count: zoneCounts.all },
        { key: 'active', label: t('admin.common.active', 'Active'), count: zoneCounts.active },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: zoneCounts.inactive },
    ];

    const rateStatusTabs = [
        { key: 'all', label: t('admin.common.all', 'All'), count: rateCounts.all },
        { key: 'active', label: t('admin.common.active', 'Active'), count: rateCounts.active },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: rateCounts.inactive },
    ];

    const kpis = [
        { key: 'total', label: t('admin.taxes.all_rules', 'All rules'), value: Number(ruleCounts.all ?? 0).toLocaleString(), trend: 0 },
        { key: 'active', label: t('admin.common.active', 'Active'), value: Number(ruleCounts.active ?? 0).toLocaleString(), trend: 0 },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), value: Number(ruleCounts.inactive ?? 0).toLocaleString(), trend: 0 },
        { key: 'classes', label: t('admin.taxes.classes', 'Classes'), value: classes.length.toLocaleString(), trend: 0 },
    ];

    const classOptions = classes.map((c) => ({ value: String(c.id), label: c.name }));
    const zoneOptions = zones.map((z) => ({ value: String(z.id), label: z.name }));

    const headerTotal = tab === 'rules'
        ? filteredRules.length
        : tab === 'classes'
            ? filteredClasses.length
            : tab === 'zones'
                ? filteredZones.length
                : tab === 'report'
                    ? (report.orders?.length || 0)
                    : filteredRates.length;

    const openAdd = () => {
        if (tab === 'rules') setRuleModal({ open: true, editing: null });
        else if (tab === 'classes') setClassModal({ open: true, editing: null });
        else if (tab === 'zones') setZoneModal({ open: true, editing: null });
        else if (tab === 'rates') setRateModal({ open: true, editing: null });
    };

    const addLabel = tab === 'rules'
        ? t('admin.taxes.add_rule', 'Add rule')
        : tab === 'classes'
            ? t('admin.taxes.add_class', 'Add class')
            : tab === 'zones'
                ? t('admin.taxes.add_zone', 'Add zone')
                : t('admin.taxes.add_rate', 'Add rate');

    return (
        <Layout>
            <PageHeader
                title={t('admin.taxes.taxes', 'Taxes')}
                total={headerTotal}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.taxes.localization', 'Localization') },
                    { label: t('admin.taxes.taxes', 'Taxes') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" href="/admin/localization/packs">
                            {t('admin.taxes.localization_packs', 'Localization packs')}
                        </Button>
                        {tab !== 'report' ? (
                            <Button icon="plus" onClick={openAdd}>
                                {addLabel}
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/tax"
                            importUrl="/admin/tax/import"
                            importSampleUrl="/admin/tax/import/sample"
                            exportUrl="/admin/tax/export"
                        />
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t(
                    'admin.taxes.howto_plain',
                    'A rule says: for this product tax class, in this place (zone), charge this percent (rate). Assign the class on each product. Import country packs to create rules automatically.',
                )}
            </p>

            <div className="mb-4">
                <StatusTabs tabs={entityTabs} value={tab} onChange={setTab} />
            </div>

            {tab === 'rules' && (
                <>
                    <KpiCards items={kpis} />
                    <ListPanel
                        tabs={(
                            <StatusTabs
                                tabs={ruleStatusTabs}
                                value={ruleFilters.status}
                                onChange={(status) => applyRuleFilter({ status })}
                            />
                        )}
                        filters={(
                            <FilterBar
                                search={ruleFilters.q}
                                searchPlaceholder={t('admin.taxes.search_rules', 'Search class, zone, rate…')}
                                onSearchSubmit={(q) => applyRuleFilter({ q })}
                            />
                        )}
                        bulk={(
                            <BulkActions
                                count={ruleSelection.selectedCount}
                                busy={bulkBusy}
                                items={[
                                    {
                                        key: 'enable',
                                        label: t('admin.taxes.active_selection', 'Active selection'),
                                        onClick: () => runBulk(ruleSelection, '/admin/tax/rules/bulk-status', { enabled: 1 }),
                                    },
                                    {
                                        key: 'disable',
                                        label: t('admin.taxes.deactivate_selection', 'Deactivate selection'),
                                        onClick: () => runBulk(ruleSelection, '/admin/tax/rules/bulk-status', { enabled: 0 }),
                                    },
                                    {
                                        key: 'delete',
                                        label: t('admin.taxes.delete_selection', 'Delete selection'),
                                        icon: 'trash',
                                        tone: 'danger',
                                        confirm: t('admin.taxes.delete_selected_rules', 'Delete selected tax rules?'),
                                        onClick: () => runBulk(ruleSelection, '/admin/tax/rules/bulk-delete'),
                                    },
                                ]}
                            />
                        )}
                    >
                        <Table>
                            <THead>
                                <tr>
                                    <Th check>
                                        <SelectionCheckbox
                                            checked={ruleSelection.allSelected}
                                            indeterminate={ruleSelection.someSelected}
                                            onChange={ruleSelection.toggleAll}
                                            ariaLabel={t('admin.common.select_all', 'Select all')}
                                        />
                                    </Th>
                                    <Th>{t('admin.taxes.class_on_product', 'Tax class')}</Th>
                                    <Th>{t('admin.taxes.where_applies', 'Where (zone)')}</Th>
                                    <Th>{t('admin.taxes.percent_rate', 'Percent')}</Th>
                                    <Th>{t('admin.taxes.priority', 'Priority')}</Th>
                                    <Th>{t('admin.taxes.compound', 'Compound')}</Th>
                                    <Th col="status">{t('admin.taxes.status', 'Status')}</Th>
                                    <Th actions>{t('admin.common.action', 'Action')}</Th>
                                </tr>
                                <tr>
                                    <Th check className={ui.thFilter} />
                                    <Th className={ui.thFilter}>
                                        {rules.length > 0 ? (
                                            <ColumnFilter
                                                name="class_id"
                                                value={ruleFilters.class_id}
                                                type="select"
                                                options={classOptions}
                                                placeholder={t('admin.taxes.filter_by_class', 'Filter by class')}
                                                onApply={applyRuleColumn}
                                            />
                                        ) : null}
                                    </Th>
                                    <Th className={ui.thFilter}>
                                        {rules.length > 0 ? (
                                            <ColumnFilter
                                                name="zone_id"
                                                value={ruleFilters.zone_id}
                                                type="select"
                                                options={zoneOptions}
                                                placeholder={t('admin.taxes.filter_by_zone', 'Filter by place')}
                                                onApply={applyRuleColumn}
                                            />
                                        ) : null}
                                    </Th>
                                    <Th className={ui.thFilter} />
                                    <Th className={ui.thFilter} />
                                    <Th className={ui.thFilter} />
                                    <Th className={ui.thFilter}>
                                        {rules.length > 0 ? (
                                            <ColumnFilter
                                                name="status"
                                                value={ruleFilters.status === 'all' ? '' : ruleFilters.status}
                                                type="select"
                                                options={listStatusFilterOptions()}
                                                placeholder={t('admin.common.select_status', 'Select status')}
                                                onApply={(name, value) => applyRuleFilter({ status: value || 'all' })}
                                            />
                                        ) : null}
                                    </Th>
                                    <Th actions className={ui.thFilter}>
                                        {rules.length > 0 ? (
                                            <FilterReset active={ruleFiltersActive} onClick={resetRuleFilters} />
                                        ) : null}
                                    </Th>
                                </tr>
                            </THead>
                            <tbody>
                                {filteredRules.length === 0 ? (
                                    <EmptyState colSpan={7}>
                                        <div className="space-y-3 max-w-lg mx-auto text-center">
                                            <p>
                                                {rules.length === 0
                                                    ? t(
                                                        'admin.taxes.no_rules_explain',
                                                        'No rules yet. Classes, zones, and rates can exist without a rule — checkout only uses a rule that links all three.',
                                                    )
                                                    : t('admin.taxes.no_rules_match', 'No rules match your filters.')}
                                            </p>
                                            {rules.length === 0 ? (
                                                <div className="flex flex-wrap justify-center gap-2">
                                                    <Button href="/admin/localization/packs" icon="download">
                                                        {t('admin.taxes.import_from_packs', 'Import from localization packs')}
                                                    </Button>
                                                    <Button
                                                        variant="secondary"
                                                        icon="plus"
                                                        onClick={() => setRuleModal({ open: true, editing: null })}
                                                    >
                                                        {t('admin.taxes.add_rule', 'Add rule')}
                                                    </Button>
                                                </div>
                                            ) : null}
                                        </div>
                                    </EmptyState>
                                ) : filteredRules.map((r) => (
                                    <Tr key={r.id} selected={ruleSelection.isSelected(r.id)}>
                                        <Td check>
                                            <SelectionCheckbox
                                                checked={ruleSelection.isSelected(r.id)}
                                                onChange={() => ruleSelection.toggle(r.id)}
                                                ariaLabel={t('admin.taxes.select_rule', 'Select rule')}
                                            />
                                        </Td>
                                        <Td>
                                            <div className={ui.cellStack}>
                                                <span className={ui.cellStrong}>{r.class_name}</span>
                                                {r.name && r.name !== r.rate_name ? (
                                                    <span className={ui.cellSub}>{r.name}</span>
                                                ) : null}
                                            </div>
                                        </Td>
                                        <Td>{r.zone_name || '—'}</Td>
                                        <Td>{r.rate_name} ({r.rate}%)</Td>
                                        <Td>{r.priority}</Td>
                                        <Td>{r.is_compound ? t('admin.common.yes', 'Yes') : t('admin.common.no', 'No')}</Td>
                                        <Td>
                                            <StatusToggle
                                                active={!!r.is_enabled}
                                                onToggle={() => router.post(`/admin/tax/rules/${r.id}/toggle`, {}, { preserveScroll: true })}
                                            />
                                        </Td>
                                        <Td actions>
                                            <RowActions
                                                onEdit={() => setRuleModal({ open: true, editing: r })}
                                                onDelete={() => {
                                                    if (confirm(t('admin.taxes.delete_rule', 'Delete rule?'))) {
                                                        router.delete(`/admin/tax/rules/${r.id}`, { preserveScroll: true });
                                                    }
                                                }}
                                            />
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </ListPanel>
                </>
            )}

            {tab === 'classes' && (
                <ListPanel
                    tabs={(
                        <StatusTabs
                            tabs={[{ key: 'all', label: t('admin.common.all', 'All'), count: classes.length }]}
                            value="all"
                            onChange={() => {}}
                        />
                    )}
                    filters={(
                        <FilterBar
                            search={classFilters.q}
                            searchPlaceholder={t('admin.taxes.search_classes', 'Search classes…')}
                            onSearchSubmit={(q) => setClassFilters({ q })}
                        />
                    )}
                    bulk={(
                        <BulkActions
                            count={classSelection.selectedCount}
                            busy={bulkBusy}
                            items={[
                                {
                                    key: 'delete',
                                    label: t('admin.taxes.delete_selection', 'Delete selection'),
                                    icon: 'trash',
                                    tone: 'danger',
                                    confirm: t('admin.taxes.delete_selected_classes', 'Delete selected tax classes?'),
                                    onClick: () => runBulk(classSelection, '/admin/tax/classes/bulk-delete'),
                                },
                            ]}
                        />
                    )}
                >
                    <Table>
                        <THead>
                            <tr>
                                <Th check>
                                    <SelectionCheckbox
                                        checked={classSelection.allSelected}
                                        indeterminate={classSelection.someSelected}
                                        onChange={classSelection.toggleAll}
                                        ariaLabel={t('admin.common.select_all', 'Select all')}
                                    />
                                </Th>
                                <Th>{t('admin.taxes.name_2', 'Name')}</Th>
                                <Th>{t('admin.taxes.rules', 'Rules')}</Th>
                                <Th actions>{t('admin.common.action', 'Action')}</Th>
                            </tr>
                            <tr>
                                <Th check className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th actions className={ui.thFilter}>
                                    {classes.length > 0 ? (
                                        <FilterReset
                                            active={classFiltersActive}
                                            onClick={() => setClassFilters({ q: '' })}
                                        />
                                    ) : null}
                                </Th>
                            </tr>
                        </THead>
                        <tbody>
                            {filteredClasses.length === 0 ? (
                                <EmptyState colSpan={4}>
                                    <div className="space-y-3 max-w-lg mx-auto text-center">
                                        <p>
                                            {classes.length === 0
                                                ? t('admin.taxes.no_classes', 'No tax classes yet.')
                                                : t('admin.taxes.no_classes_match', 'No classes match your search.')}
                                        </p>
                                        {classes.length === 0 ? (
                                            <Button
                                                icon="plus"
                                                onClick={() => setClassModal({ open: true, editing: null })}
                                            >
                                                {t('admin.taxes.add_class', 'Add class')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </EmptyState>
                            ) : filteredClasses.map((c) => (
                                <Tr key={c.id} selected={classSelection.isSelected(c.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={classSelection.isSelected(c.id)}
                                            onChange={() => classSelection.toggle(c.id)}
                                            ariaLabel={t('admin.taxes.select_class', 'Select class')}
                                        />
                                    </Td>
                                    <Td>
                                        <span className={ui.cellStrong}>{c.name}</span>
                                    </Td>
                                    <Td>{c.rules_count}</Td>
                                    <Td actions>
                                        <RowActions
                                            onEdit={() => setClassModal({ open: true, editing: c })}
                                            onDelete={() => {
                                                if (confirm(t('admin.taxes.delete_this_tax_class', 'Delete this tax class?'))) {
                                                    router.delete(`/admin/tax/classes/${c.id}`, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </ListPanel>
            )}

            {tab === 'zones' && (
                <ListPanel
                    tabs={(
                        <StatusTabs
                            tabs={zoneStatusTabs}
                            value={zoneFilters.status}
                            onChange={(status) => applyZoneFilter({ status })}
                        />
                    )}
                    filters={(
                        <FilterBar
                            search={zoneFilters.q}
                            searchPlaceholder={t('admin.taxes.search_zones', 'Search zones…')}
                            onSearchSubmit={(q) => applyZoneFilter({ q })}
                        />
                    )}
                    bulk={(
                        <BulkActions
                            count={zoneSelection.selectedCount}
                            busy={bulkBusy}
                            items={[
                                {
                                    key: 'enable',
                                    label: t('admin.taxes.active_selection', 'Active selection'),
                                    onClick: () => runBulk(zoneSelection, '/admin/tax/zones/bulk-status', { enabled: 1 }),
                                },
                                {
                                    key: 'disable',
                                    label: t('admin.taxes.deactivate_selection', 'Deactivate selection'),
                                    onClick: () => runBulk(zoneSelection, '/admin/tax/zones/bulk-status', { enabled: 0 }),
                                },
                                {
                                    key: 'delete',
                                    label: t('admin.taxes.delete_selection', 'Delete selection'),
                                    icon: 'trash',
                                    tone: 'danger',
                                    confirm: t('admin.taxes.delete_selected_zones', 'Delete selected tax zones?'),
                                    onClick: () => runBulk(zoneSelection, '/admin/tax/zones/bulk-delete'),
                                },
                            ]}
                        />
                    )}
                >
                    <Table>
                        <THead>
                            <tr>
                                <Th check>
                                    <SelectionCheckbox
                                        checked={zoneSelection.allSelected}
                                        indeterminate={zoneSelection.someSelected}
                                        onChange={zoneSelection.toggleAll}
                                        ariaLabel={t('admin.common.select_all', 'Select all')}
                                    />
                                </Th>
                                <Th>{t('admin.taxes.zone_2', 'Zone')}</Th>
                                <Th>{t('admin.taxes.country', 'Country')}</Th>
                                <Th>{t('admin.taxes.regions', 'Regions')}</Th>
                                <Th>{t('admin.taxes.rules', 'Rules')}</Th>
                                <Th col="status">{t('admin.taxes.status', 'Status')}</Th>
                                <Th actions>{t('admin.common.action', 'Action')}</Th>
                            </tr>
                            <tr>
                                <Th check className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter}>
                                    {zones.length > 0 ? (
                                        <ColumnFilter
                                            name="status"
                                            value={zoneFilters.status === 'all' ? '' : zoneFilters.status}
                                            type="select"
                                            options={listStatusFilterOptions()}
                                            placeholder={t('admin.common.select_status', 'Select status')}
                                            onApply={(name, value) => applyZoneFilter({ status: value || 'all' })}
                                        />
                                    ) : null}
                                </Th>
                                <Th actions className={ui.thFilter}>
                                    {zones.length > 0 ? (
                                        <FilterReset
                                            active={zoneFiltersActive}
                                            onClick={() => setZoneFilters({ q: '', status: 'all' })}
                                        />
                                    ) : null}
                                </Th>
                            </tr>
                        </THead>
                        <tbody>
                            {filteredZones.length === 0 ? (
                                <EmptyState colSpan={7}>
                                    <div className="space-y-3 max-w-lg mx-auto text-center">
                                        <p>
                                            {zones.length === 0
                                                ? t('admin.taxes.no_zones', 'No tax zones yet.')
                                                : t('admin.taxes.no_zones_match', 'No zones match your filters.')}
                                        </p>
                                        {zones.length === 0 ? (
                                            <Button
                                                icon="plus"
                                                onClick={() => setZoneModal({ open: true, editing: null })}
                                            >
                                                {t('admin.taxes.add_zone', 'Add zone')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </EmptyState>
                            ) : filteredZones.map((z) => (
                                <Tr key={z.id} selected={zoneSelection.isSelected(z.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={zoneSelection.isSelected(z.id)}
                                            onChange={() => zoneSelection.toggle(z.id)}
                                            ariaLabel={t('admin.taxes.select_zone', 'Select zone')}
                                        />
                                    </Td>
                                    <Td>
                                        <span className={ui.cellStrong}>{z.name}</span>
                                    </Td>
                                    <Td>{z.country ? `${z.country.name} (${z.country.code})` : t('admin.taxes.worldwide', 'Worldwide')}</Td>
                                    <Td>{z.regions?.length ? z.regions.map((r) => r.code).join(', ') : '—'}</Td>
                                    <Td>{z.rules_count}</Td>
                                    <Td>
                                        <StatusToggle
                                            active={!!z.is_enabled}
                                            onToggle={() => router.post(`/admin/tax/zones/${z.id}/toggle`, {}, { preserveScroll: true })}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            onEdit={() => setZoneModal({ open: true, editing: z })}
                                            onDelete={() => {
                                                if (confirm(t('admin.taxes.delete_zone', 'Delete zone?'))) {
                                                    router.delete(`/admin/tax/zones/${z.id}`, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </ListPanel>
            )}

            {tab === 'rates' && (
                <ListPanel
                    tabs={(
                        <StatusTabs
                            tabs={rateStatusTabs}
                            value={rateFilters.status}
                            onChange={(status) => applyRateFilter({ status })}
                        />
                    )}
                    filters={(
                        <FilterBar
                            search={rateFilters.q}
                            searchPlaceholder={t('admin.taxes.search_rates', 'Search rates…')}
                            onSearchSubmit={(q) => applyRateFilter({ q })}
                        />
                    )}
                    bulk={(
                        <BulkActions
                            count={rateSelection.selectedCount}
                            busy={bulkBusy}
                            items={[
                                {
                                    key: 'enable',
                                    label: t('admin.taxes.active_selection', 'Active selection'),
                                    onClick: () => runBulk(rateSelection, '/admin/tax/rates/bulk-status', { enabled: 1 }),
                                },
                                {
                                    key: 'disable',
                                    label: t('admin.taxes.deactivate_selection', 'Deactivate selection'),
                                    onClick: () => runBulk(rateSelection, '/admin/tax/rates/bulk-status', { enabled: 0 }),
                                },
                                {
                                    key: 'delete',
                                    label: t('admin.taxes.delete_selection', 'Delete selection'),
                                    icon: 'trash',
                                    tone: 'danger',
                                    confirm: t('admin.taxes.delete_selected_rates', 'Delete selected tax rates?'),
                                    onClick: () => runBulk(rateSelection, '/admin/tax/rates/bulk-delete'),
                                },
                            ]}
                        />
                    )}
                >
                    <Table>
                        <THead>
                            <tr>
                                <Th check>
                                    <SelectionCheckbox
                                        checked={rateSelection.allSelected}
                                        indeterminate={rateSelection.someSelected}
                                        onChange={rateSelection.toggleAll}
                                        ariaLabel={t('admin.common.select_all', 'Select all')}
                                    />
                                </Th>
                                <Th>{t('admin.taxes.name_2', 'Name')}</Th>
                                <Th>{t('admin.taxes.rate_2', 'Rate %')}</Th>
                                <Th>{t('admin.taxes.rules', 'Rules')}</Th>
                                <Th col="status">{t('admin.taxes.status', 'Status')}</Th>
                                <Th actions>{t('admin.common.action', 'Action')}</Th>
                            </tr>
                            <tr>
                                <Th check className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter} />
                                <Th className={ui.thFilter}>
                                    {rates.length > 0 ? (
                                        <ColumnFilter
                                            name="status"
                                            value={rateFilters.status === 'all' ? '' : rateFilters.status}
                                            type="select"
                                            options={listStatusFilterOptions()}
                                            placeholder={t('admin.common.select_status', 'Select status')}
                                            onApply={(name, value) => applyRateFilter({ status: value || 'all' })}
                                        />
                                    ) : null}
                                </Th>
                                <Th actions className={ui.thFilter}>
                                    {rates.length > 0 ? (
                                        <FilterReset
                                            active={rateFiltersActive}
                                            onClick={() => setRateFilters({ q: '', status: 'all' })}
                                        />
                                    ) : null}
                                </Th>
                            </tr>
                        </THead>
                        <tbody>
                            {filteredRates.length === 0 ? (
                                <EmptyState colSpan={6}>
                                    <div className="space-y-3 max-w-lg mx-auto text-center">
                                        <p>
                                            {rates.length === 0
                                                ? t('admin.taxes.no_rates', 'No tax rates yet.')
                                                : t('admin.taxes.no_rates_match', 'No rates match your filters.')}
                                        </p>
                                        {rates.length === 0 ? (
                                            <Button
                                                icon="plus"
                                                onClick={() => setRateModal({ open: true, editing: null })}
                                            >
                                                {t('admin.taxes.add_rate', 'Add rate')}
                                            </Button>
                                        ) : null}
                                    </div>
                                </EmptyState>
                            ) : filteredRates.map((r) => (
                                <Tr key={r.id} selected={rateSelection.isSelected(r.id)}>
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={rateSelection.isSelected(r.id)}
                                            onChange={() => rateSelection.toggle(r.id)}
                                            ariaLabel={t('admin.taxes.select_rate', 'Select rate')}
                                        />
                                    </Td>
                                    <Td>
                                        <span className={ui.cellStrong}>{r.name}</span>
                                    </Td>
                                    <Td>{r.rate}%</Td>
                                    <Td>{r.rules_count}</Td>
                                    <Td>
                                        <StatusToggle
                                            active={!!r.is_enabled}
                                            onToggle={() => router.post(`/admin/tax/rates/${r.id}/toggle`, {}, { preserveScroll: true })}
                                        />
                                    </Td>
                                    <Td actions>
                                        <RowActions
                                            onEdit={() => setRateModal({ open: true, editing: r })}
                                            onDelete={() => {
                                                if (confirm(t('admin.taxes.delete_rate', 'Delete rate?'))) {
                                                    router.delete(`/admin/tax/rates/${r.id}`, { preserveScroll: true });
                                                }
                                            }}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </ListPanel>
            )}

            {tab === 'report' && (
                <ListPanel>
                    <section className={ui.sectionCard}>
                        <h2 className={ui.sectionTitle}>{t('admin.taxes.collected', 'Tax collected')}</h2>
                        <p className={ui.muted}>{t('admin.taxes.report_hint', 'Summed from recent orders that stored tax_details.')}</p>
                        <p className="text-2xl font-semibold mt-2">{Number(report.total || 0).toFixed(2)}</p>
                        <ul className="mt-4 space-y-1">
                            {(report.rows || []).map((row) => (
                                <li key={row.label} className="flex justify-between text-sm">
                                    <span>{row.label}</span>
                                    <span>{Number(row.amount).toFixed(2)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                    <Table>
                        <THead>
                            <tr>
                                <Th>{t('admin.orders.number', 'Order')}</Th>
                                <Th>{t('admin.taxes.tax', 'Tax')}</Th>
                                <Th>{t('admin.common.date', 'Date')}</Th>
                            </tr>
                        </THead>
                        <tbody>
                            {(report.orders || []).map((row) => (
                                <Tr key={row.number}>
                                    <Td>{row.number}</Td>
                                    <Td>{Number(row.tax_total).toFixed(2)} {row.currency_code}</Td>
                                    <Td>{row.created_at}</Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </ListPanel>
            )}

            <RuleModal
                open={ruleModal.open}
                editing={ruleModal.editing}
                classes={classes}
                zones={zones}
                rates={rates}
                onClose={() => setRuleModal({ open: false, editing: null })}
            />
            <ClassModal
                open={classModal.open}
                editing={classModal.editing}
                onClose={() => setClassModal({ open: false, editing: null })}
            />
            <ZoneModal
                open={zoneModal.open}
                editing={zoneModal.editing}
                options={options}
                onClose={() => setZoneModal({ open: false, editing: null })}
            />
            <RateModal
                open={rateModal.open}
                editing={rateModal.editing}
                onClose={() => setRateModal({ open: false, editing: null })}
            />
        </Layout>
    );
}
