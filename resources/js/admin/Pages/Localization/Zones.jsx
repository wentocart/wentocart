import { router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    Badge,
    Checkbox,
    EmptyState,
    Field,
    FilterReset,
    FormErrors,
    Input,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    Select,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    useListSelection,
} from '../../ui';

function clean(payload) {
    const result = { ...payload };
    Object.keys(result).forEach((key) => {
        if (result[key] === '' || result[key] == null || result[key] === 'all') delete result[key];
    });
    return result;
}
function tabs(counts) {
    return [
        { key: 'all', label: t('admin.common.all', 'All'), count: counts.all },
        { key: 'active', label: t('admin.common.active', 'Active'), count: counts.active },
        { key: 'inactive', label: t('admin.common.inactive', 'Inactive'), count: counts.inactive },
    ];
}

function CountryChecklist({ countries, value, zoneId, onChange }) {
    const [query, setQuery] = useState('');
    const selected = useMemo(() => new Set((value || []).map(String)), [value]);
    const needle = query.trim().toLowerCase();
    const visible = useMemo(() => (
        countries.filter((country) => !needle || country.name.toLowerCase().includes(needle))
    ), [countries, needle]);

    const toggle = (id) => {
        const key = String(id);
        if (selected.has(key)) {
            onChange(value.filter((item) => String(item) !== key));
            return;
        }
        onChange([...value, id]);
    };

    return (
        <div>
            <div className="mb-2 flex items-center justify-between gap-3">
                <span className={ui.label}>{t('admin.shipping.countries', 'Countries')}</span>
                <Badge tone="neutral" dot={false}>{t('admin.zones.selected_count', '{count} selected').replace('{count}', String(value.length))}</Badge>
            </div>
            <Input
                wide
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('admin.zones.search_countries', 'Search countries')}
            />
            <div className="mt-2 max-h-80 overflow-auto rounded-xl border border-neutral-200">
                {visible.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-neutral-500">{t('admin.zones.no_country_match', 'No countries match that search.')}</p>
                ) : visible.map((country) => (
                    <div key={country.id} className="border-b border-neutral-100 px-3 py-2 last:border-b-0 hover:bg-neutral-50">
                        <Checkbox
                            checked={selected.has(String(country.id))}
                            onChange={() => toggle(country.id)}
                            label={(
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span>{country.name}</span>
                                    {country.zone_name && String(country.zone_id) !== String(zoneId || '') ? (
                                        <span className={ui.cellSub}>{country.zone_name}</span>
                                    ) : null}
                                </span>
                            )}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
}

function ZoneForm({ zone, countries, onClose }) {
    const editing = !!zone;
    const form = useForm({
        name: zone?.name || '',
        is_enabled: zone?.is_enabled !== false,
        country_ids: zone?.country_ids || [],
    });

    useEffect(() => {
        form.setData({
            name: zone?.name || '',
            is_enabled: zone?.is_enabled !== false,
            country_ids: zone?.country_ids || [],
        });
        form.clearErrors();
        document.getElementById('shipping-zone-form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [zone?.id]);

    const submit = (event) => {
        event.preventDefault();
        const payload = { ...form.data, is_enabled: form.data.is_enabled ? 1 : 0 };
        const options = { preserveScroll: true, onSuccess: onClose };
        if (editing) {
            router.put(`/admin/localization/zones/${zone.id}`, payload, options);
        } else {
            router.post('/admin/localization/zones', payload, options);
        }
    };

    return (
        <section id="shipping-zone-form" className={`${ui.sectionCard} mb-4`}>
            <div className={ui.sectionHead}>
                <div>
                    <h2 className={ui.sectionTitle}>{editing ? t('admin.shipping.edit_zone', 'Edit zone') : t('admin.shipping.add_zone', 'Add zone')}</h2>
                    <p className={ui.muted}>{t('admin.zones.form_hint', 'Each country belongs to one zone. Choosing a country here moves it out of its previous zone.')}</p>
                </div>
                <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
            </div>
            <FormErrors errors={form.errors} />
            <form className="space-y-4" onSubmit={submit}>
                <div className={ui.fieldGrid}>
                    <Field label={t('admin.shipping.name', 'Name')} error={form.errors.name} required className="sm:col-span-2">
                        <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('admin.zones.name_placeholder', 'Europe')} />
                    </Field>
                    <Field label={t('admin.common.status', 'Status')}>
                        <Select wide value={form.data.is_enabled ? '1' : '0'} onChange={(e) => form.setData('is_enabled', e.target.value === '1')}>
                            <option value="1">{t('admin.common.active', 'Active')}</option>
                            <option value="0">{t('admin.common.inactive', 'Inactive')}</option>
                        </Select>
                    </Field>
                </div>
                <CountryChecklist
                    countries={countries}
                    value={form.data.country_ids}
                    zoneId={zone?.id}
                    onChange={(ids) => form.setData('country_ids', ids)}
                />
                <Button icon="check" busy={form.processing} type="submit">
                    {editing ? t('admin.common.save', 'Save') : t('admin.shipping.save_zone', 'Save zone')}
                </Button>
            </form>
        </section>
    );
}

export default function Zones({ zones, countries = [], filters = {}, counts = {} }) {
    const rows = zones?.data || [];
    const [editing, setEditing] = useState(null);
    const [creating, setCreating] = useState(false);
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);
    const formOpen = creating || !!editing;

    const query = useMemo(() => ({
        status: filters.status || 'all',
        name: filters.name || '',
        country: filters.country || '',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'position',
        dir: filters.dir === 'desc' ? 'desc' : 'asc',
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/localization/zones', clean({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        apply(typeof nameOrPatch === 'object' ? nameOrPatch : { [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        apply({ sort: column, dir: query.sort === column && query.dir === 'asc' ? 'desc' : 'asc' });
    }, [apply, query.sort, query.dir]);

    const runBulk = (url, payload = {}) => {
        if (!selection.selectedIds.length || bulkBusy) return;
        setBulkBusy(true);
        router.post(url, { ...payload, ids: selection.selectedIds }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    const closeForm = () => {
        setCreating(false);
        setEditing(null);
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.zones.title', 'Zones')}
                description={t('admin.zones.page_hint', 'A zone is a geographic group, such as Europe or North America. Each country belongs to one zone. Shipping methods choose zones, not countries.')}
                total={zones?.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.nav.localization', 'Localization') },
                    { label: t('admin.zones.title', 'Zones') },
                ]}
                actions={!formOpen ? (
                    <>
                        <Button icon="plus" type="button" onClick={() => setCreating(true)}>{t('admin.shipping.add_zone', 'Add zone')}</Button>
                        <ListSettings
                            listUrl="/admin/localization/zones"
                            importUrl="/admin/localization/zones/import"
                            importSampleUrl="/admin/localization/zones/import/sample"
                            exportUrl="/admin/localization/zones/export"
                            query={query}
                            tableName="shipping-zones"
                        />
                    </>
                ) : null}
            />
            {formOpen ? <ZoneForm zone={editing} countries={countries} onClose={closeForm} /> : (
            <>
            <StatusTabs tabs={tabs(counts)} value={query.status} onChange={(status) => apply({ status })} />
            <ListPanel
                footer={<ListFooter pagination={zones} query={query} />}
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            { key: 'enable', label: t('admin.common.enable', 'Enable'), icon: 'check', onClick: () => runBulk('/admin/localization/zones/bulk-status', { enabled: 1 }) },
                            { key: 'disable', label: t('admin.common.disable', 'Disable'), icon: 'ban', onClick: () => runBulk('/admin/localization/zones/bulk-status', { enabled: 0 }) },
                            { key: 'delete', label: t('admin.common.delete', 'Delete'), icon: 'trash', tone: 'danger', confirm: t('admin.shipping.delete_selected_zones', 'Delete selected shipping zones?'), onClick: () => runBulk('/admin/localization/zones/bulk-delete') },
                        ]}
                    />
                )}
            >
                    <Table>
                        <THead>
                            <tr>
                                <Th check><SelectionCheckbox checked={selection.allSelected} indeterminate={selection.someSelected} onChange={selection.toggleAll} ariaLabel={t('admin.common.select_all', 'Select all on this page')} /></Th>
                                <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="name" label={t('admin.shipping.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="countries_count" label={t('admin.shipping.countries', 'Countries')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader col="status" column="is_enabled" label={t('admin.common.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                            </tr>
                            <tr>
                                <Th check className={ui.thFilter} />
                                <Th className={ui.thFilterId}><ColumnRangeFilter minName="id_min" maxName="id_max" minValue={query.id_min} maxValue={query.id_max} onApply={applyColumn} compact /></Th>
                                <Th className={ui.thFilter}><ColumnFilter name="name" value={query.name} placeholder={t('admin.shipping.name', 'Name')} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilter}><ColumnFilter name="country" value={query.country} placeholder={t('admin.shipping.country', 'Country')} onApply={applyColumn} /></Th>
                                <Th className={ui.thFilterStatus}>
                                    <ColumnFilter type="select" name="status" value={query.status === 'all' ? '' : query.status} placeholder={t('admin.common.all', 'All')} options={[
                                        { value: 'active', label: t('admin.common.active', 'Active') },
                                        { value: 'inactive', label: t('admin.common.inactive', 'Inactive') },
                                    ]} onApply={applyColumn} compact />
                                </Th>
                                <Th actions className={ui.thFilter}><FilterReset query={query} href="/admin/localization/zones" /></Th>
                            </tr>
                        </THead>
                        <tbody>
                            {rows.length === 0 ? (
                                <EmptyState colSpan={6}>{t('admin.shipping.no_zones_yet', 'No shipping zones yet.')}</EmptyState>
                            ) : rows.map((zone) => (
                                <Tr key={zone.id} selected={selection.isSelected(zone.id)}>
                                    <Td check><SelectionCheckbox checked={selection.isSelected(zone.id)} onChange={() => selection.toggle(zone.id)} ariaLabel={`${t('admin.common.select', 'Select')} ${zone.name}`} /></Td>
                                    <Td col="id">{zone.id}</Td>
                                    <Td><span className={ui.cellStrong}>{zone.name}</span></Td>
                                    <Td>
                                        <Badge tone="neutral" dot={false}>{zone.countries_count}</Badge>
                                    </Td>
                                    <Td col="status"><StatusToggle active={!!zone.is_enabled} onToggle={() => router.post(`/admin/localization/zones/${zone.id}/toggle`, {}, { preserveScroll: true })} /></Td>
                                    <Td actions>
                                        <RowActions
                                            onEdit={() => setEditing(zone)}
                                            confirmMessage={t('admin.shipping.delete_zone', 'Delete this zone?')}
                                            onDelete={() => router.delete(`/admin/localization/zones/${zone.id}`, { preserveScroll: true })}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
            </ListPanel>
            </>
            )}
        </Layout>
    );
}
