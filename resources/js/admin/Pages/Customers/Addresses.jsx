import { router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    Checkbox,
    ColumnFilter,
    ColumnRangeFilter,
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
    SearchSelect,
    SelectionCheckbox,
    Select,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    cx,
    ui,
    t,
    useListSelection,
} from '../../ui';

function regionsForCountry(countries, code) {
    const country = (countries || []).find((row) => row.code === code);
    return country?.regions || [];
}

function regionNames(countries, code) {
    return regionsForCountry(countries, code).map((row) => row.name);
}

function customerName(row) {
    const customer = row.customer || {};
    const name = customer.name || [customer.first_name, customer.last_name].filter(Boolean).join(' ');
    return name || customer.email || '—';
}

function addressLine(row) {
    return [row.line1, row.line2, row.city, row.postal_code, row.country_code].filter(Boolean).join(', ');
}

function emptyAddress() {
    return {
        customer_id: '',
        customer_label: '',
        label: 'Shipping',
        first_name: '',
        last_name: '',
        phone: '',
        line1: '',
        line2: '',
        city: '',
        state: '',
        postal_code: '',
        country_code: 'IN',
        is_default_billing: false,
        is_default_shipping: true,
    };
}

const FILTER_KEYS = ['customer', 'customer_id', 'label', 'address', 'type', 'id_min', 'id_max', 'sort', 'dir'];

function listQuery(filters) {
    const payload = {};
    FILTER_KEYS.forEach((key) => {
        const value = filters[key];
        if (value && value !== 'all') {
            payload[key] = value;
        }
    });
    return payload;
}

function pageTabs() {
    return [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'shipping', label: t('admin.addresses.shipping', 'Shipping') },
        { key: 'billing', label: t('admin.addresses.billing', 'Billing') },
        { key: 'other', label: t('admin.addresses.other', 'Other') },
    ];
}

function AddressInnerForm({ panel, countries, query }) {
    const editing = panel?.mode === 'edit';
    const form = useForm(emptyAddress());
    const seedKey = editing
        ? `edit-${panel.address?.id || ''}`
        : `create-${panel.customer_id || 'new'}`;
    const seededFor = useRef(null);

    useEffect(() => {
        if (seededFor.current === seedKey) {
            return;
        }
        seededFor.current = seedKey;

        if (editing && panel.address) {
            form.setData({
                customer_id: panel.address.customer_id,
                customer_label: panel.address.customer_label || '',
                label: panel.address.label || 'Shipping',
                first_name: panel.address.first_name || '',
                last_name: panel.address.last_name || '',
                phone: panel.address.phone || '',
                line1: panel.address.line1 || '',
                line2: panel.address.line2 || '',
                city: panel.address.city || '',
                state: panel.address.state || '',
                postal_code: panel.address.postal_code || '',
                country_code: panel.address.country_code || 'IN',
                is_default_billing: Boolean(panel.address.is_default_billing),
                is_default_shipping: Boolean(panel.address.is_default_shipping),
            });
            form.clearErrors();
            return;
        }
        if (panel?.mode === 'create') {
            form.setData({
                ...emptyAddress(),
                customer_id: panel.customer_id || '',
                customer_label: panel.customer_label || '',
                first_name: panel.first_name || '',
                last_name: panel.last_name || '',
                phone: panel.phone || '',
            });
            form.clearErrors();
        }
    }, [seedKey, editing, panel]);

    useEffect(() => {
        document.getElementById('list-inner-form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [seedKey]);

    const closeForm = () => {
        router.get('/admin/addresses', query, { preserveScroll: true, replace: true });
    };

    const save = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, preserveState: true };
        form.transform((data) => ({
            ...data,
            phone: data.phone || '',
            is_default_billing: data.is_default_billing ? 1 : 0,
            is_default_shipping: data.is_default_shipping ? 1 : 0,
        }));
        if (editing) {
            form.put(`/admin/addresses/${panel.address.id}`, options);
            return;
        }
        form.post('/admin/addresses', options);
    };

    return (
        <div id="list-inner-form" className="p-4 sm:p-6">
            <div className={ui.sectionHead}>
                <h2 className={ui.sectionTitle}>
                    {editing ? t('admin.addresses.edit_address', 'Edit address') : t('admin.addresses.new_address', 'New address')}
                </h2>
                <Button type="button" variant="secondary" onClick={closeForm}>
                    {t('admin.common.cancel', 'Cancel')}
                </Button>
            </div>
            <FormErrors errors={form.errors} />
            <form className={ui.fieldGrid} onSubmit={save}>
                <div className={ui.fieldSpan2}>
                    {editing || panel?.customer_locked ? (
                        <Field label={t('admin.addresses.customer', 'Customer')}>
                            <Input wide disabled value={form.data.customer_label || `#${form.data.customer_id}`} />
                        </Field>
                    ) : (
                        <SearchSelect
                            label={t('admin.addresses.customer', 'Customer')}
                            error={form.errors.customer_id}
                            required
                            multiple={false}
                            value={form.data.customer_id || ''}
                            selectedOptions={form.data.customer_id ? [{ id: form.data.customer_id, name: form.data.customer_label || `#${form.data.customer_id}` }] : []}
                            searchUrl="/admin/addresses/search/customers"
                            placeholder={t('admin.addresses.search_customers', 'Search customers…')}
                            onChange={(id, opt) => {
                                form.setData({
                                    ...form.data,
                                    customer_id: id || '',
                                    customer_label: opt?.name || '',
                                });
                            }}
                        />
                    )}
                </div>
                <Field label={t('admin.addresses.label', 'Label')}>
                    <Input wide value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />
                </Field>
                <Field label={t('admin.addresses.first_name', 'First name')}>
                    <Input wide value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} placeholder={t('admin.addresses.recipient_first_name', 'Recipient first name')} />
                </Field>
                <Field label={t('admin.addresses.last_name', 'Last name')}>
                    <Input wide value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} placeholder={t('admin.addresses.recipient_last_name', 'Recipient last name')} />
                </Field>
                <Field label={t('admin.addresses.phone', 'Phone')} error={form.errors.phone}>
                    <Input
                        wide
                        invalid={!!form.errors.phone}
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        placeholder="+1 555 123 4567"
                    />
                </Field>
                <Field label={t('admin.addresses.country', 'Country')} required>
                    <Select
                        wide
                        value={form.data.country_code}
                        onChange={(e) => {
                            const code = e.target.value;
                            const options = regionNames(countries, code);
                            form.setData({
                                ...form.data,
                                country_code: code,
                                state: options.includes(form.data.state) ? form.data.state : '',
                            });
                        }}
                    >
                        {(countries || []).map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
                    </Select>
                </Field>
                <Field label={t('admin.addresses.address_line_1', 'Address line 1')} error={form.errors.line1} required>
                    <Input wide value={form.data.line1} onChange={(e) => form.setData('line1', e.target.value)} />
                </Field>
                <Field label={t('admin.addresses.address_line_2', 'Address line 2')}>
                    <Input wide value={form.data.line2} onChange={(e) => form.setData('line2', e.target.value)} />
                </Field>
                <Field label={t('admin.addresses.city', 'City')} error={form.errors.city} required>
                    <Input wide value={form.data.city} onChange={(e) => form.setData('city', e.target.value)} />
                </Field>
                <Field label={t('admin.addresses.state', 'State')}>
                    {regionsForCountry(countries, form.data.country_code).length > 0 ? (
                        <Select wide value={form.data.state || ''} onChange={(e) => form.setData('state', e.target.value)}>
                            <option value="">{t('admin.addresses.select_state', 'Select state')}</option>
                            {regionsForCountry(countries, form.data.country_code).map((region) => (
                                <option key={region.code} value={region.name}>{region.name}</option>
                            ))}
                        </Select>
                    ) : (
                        <Input wide value={form.data.state} onChange={(e) => form.setData('state', e.target.value)} placeholder={t('admin.addresses.enter_state', 'Enter state')} />
                    )}
                </Field>
                <Field label={t('admin.addresses.postal_code', 'Postal code')}>
                    <Input wide inputMode="numeric" maxLength={10} value={form.data.postal_code} onChange={(e) => form.setData('postal_code', e.target.value.replace(/\D/g, ''))} />
                </Field>
                <div className={cx(ui.row, ui.fieldSpan2, 'flex-wrap')}>
                    <Checkbox label={t('admin.addresses.default_shipping', 'Default shipping')} checked={form.data.is_default_shipping} onChange={(e) => form.setData('is_default_shipping', e.target.checked)} />
                    <Checkbox label={t('admin.addresses.default_billing', 'Default billing')} checked={form.data.is_default_billing} onChange={(e) => form.setData('is_default_billing', e.target.checked)} />
                </div>
                <div className={cx(ui.toolbar, ui.fieldSpan2)}>
                    <Button type="submit" icon="check" busy={form.processing}>
                        {editing ? t('admin.addresses.save_address', 'Save address') : t('admin.addresses.add_address', 'Add address')}
                    </Button>
                </div>
            </form>
        </div>
    );
}

export default function Addresses({ addresses, countries = [], filters = {}, panel = null }) {
    const rows = addresses?.data || [];
    const query = useMemo(() => listQuery(filters), [filters]);
    const editingId = panel?.mode === 'edit' ? panel.address?.id : null;
    const formOpen = panel?.mode === 'create' || panel?.mode === 'edit';
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);

    const panelParams = useMemo(() => {
        if (panel?.mode === 'edit' && panel.address?.id) {
            return { edit: panel.address.id };
        }
        if (panel?.mode === 'create') {
            return { create: 1 };
        }
        return {};
    }, [panel]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...panelParams, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/addresses', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query, panelParams]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = (filters.sort || 'id') === column && (filters.dir || 'desc') === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, filters.dir, filters.sort]);

    const openCreate = () => {
        router.get('/admin/addresses', { ...query, create: 1 }, { preserveScroll: true });
    };

    const openEdit = (row) => {
        router.get('/admin/addresses', { ...query, edit: row.id }, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.addresses.addresses', 'Addresses')}
                total={addresses?.total}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { href: '/admin/customers', label: t('admin.addresses.customers', 'Customers') }, { label: t('admin.addresses.addresses', 'Addresses') }]}
                actions={!formOpen ? (
                    <>
                        <Button type="button" icon="plus" onClick={openCreate}>
                            {t('admin.addresses.add_address', 'Add address')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/addresses"
                            importUrl="/admin/addresses/import"
                            importSampleUrl="/admin/addresses/import/sample"
                            exportUrl="/admin/addresses/export"
                            query={query}
                            exportFields={[
                                { name: 'customer', label: t('admin.addresses.customer', 'Customer'), type: 'text' },
                                { name: 'label', label: t('admin.addresses.label', 'Label'), type: 'text' },
                                { name: 'address', label: t('admin.addresses.address', 'Address'), type: 'text' },
                                {
                                    name: 'type',
                                    label: t('admin.addresses.type', 'Type'),
                                    type: 'select',
                                    options: [
                                        { value: 'shipping', label: t('admin.addresses.shipping', 'Shipping') },
                                        { value: 'billing', label: t('admin.addresses.billing', 'Billing') },
                                        { value: 'other', label: t('admin.addresses.other', 'Other') },
                                    ],
                                },
                            ]}
                            tableName="addresses"
                        />
                    </>
                ) : null}
            />
            <ListPanel
                table={!formOpen}
                tabs={formOpen ? null : (
                    <StatusTabs
                        tabs={pageTabs()}
                        value={filters.type || 'all'}
                        onChange={(type) => apply({ type: type === 'all' ? '' : type })}
                    />
                )}
                bulk={formOpen ? null : (
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.addresses.delete_selected', 'Delete selected addresses?'),
                                onClick: () => {
                                    const ids = selection.selectedIds;
                                    if (!ids.length || bulkBusy) {
                                        return;
                                    }
                                    setBulkBusy(true);
                                    router.post('/admin/addresses/bulk-delete', { ids }, {
                                        preserveScroll: true,
                                        onSuccess: () => selection.clear(),
                                        onFinish: () => setBulkBusy(false),
                                    });
                                },
                            },
                        ]}
                    />
                )}
                footer={formOpen ? null : (
                    <ListFooter
                        pagination={addresses}
                        query={query}
                    />
                )}
            >
                {formOpen ? <AddressInnerForm panel={panel} countries={countries} query={query} /> : (
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={filters.sort || 'id'} dir={filters.dir || 'desc'} onSort={sortBy} />
                            <SortHeader column="customer" label={t('admin.addresses.customer', 'Customer')} sort={filters.sort || 'id'} dir={filters.dir || 'desc'} onSort={sortBy} />
                            <SortHeader column="label" label={t('admin.addresses.label', 'Label')} sort={filters.sort || 'id'} dir={filters.dir || 'desc'} onSort={sortBy} />
                            <SortHeader column="address" label={t('admin.addresses.address', 'Address')} sort={filters.sort || 'id'} dir={filters.dir || 'desc'} onSort={sortBy} />
                            <SortHeader column="type" label={t('admin.addresses.type', 'Type')} sort={filters.sort || 'id'} dir={filters.dir || 'desc'} onSort={sortBy} />
                            <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter
                                    minName="id_min"
                                    maxName="id_max"
                                    minValue={filters.id_min || ''}
                                    maxValue={filters.id_max || ''}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="customer" value={filters.customer || ''} placeholder={t('admin.addresses.customer', 'Customer')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="label" value={filters.label || ''} placeholder={t('admin.addresses.label', 'Label')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="address" value={filters.address || ''} placeholder={t('admin.addresses.address', 'Address')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter
                                    type="select"
                                    name="type"
                                    value={filters.type === 'all' ? '' : (filters.type || '')}
                                    placeholder={t('admin.addresses.type', 'Type')}
                                    options={[
                                        { value: 'shipping', label: t('admin.addresses.shipping', 'Shipping') },
                                        { value: 'billing', label: t('admin.addresses.billing', 'Billing') },
                                        { value: 'other', label: t('admin.addresses.other', 'Other') },
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset query={query} href="/admin/addresses" />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={7}>{t('admin.addresses.no_addresses_yet', 'No addresses yet.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)} className={row.id === editingId ? 'bg-sky-50/70' : undefined}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${customerName(row)}`}
                                    />
                                </Td>
                                <Td col="id">{row.id}</Td>
                                <Td>
                                    <Button variant="link" href={`/admin/customers/${row.customer_id}`}>{customerName(row)}</Button>
                                </Td>
                                <Td>{row.label || '—'}</Td>
                                <Td>
                                    <span className="block min-w-[12rem] max-w-[24rem] truncate sm:whitespace-normal">{addressLine(row)}</span>
                                </Td>
                                <Td>
                                    <div className={cx(ui.row, 'flex-wrap')}>
                                        {row.is_default_shipping ? <Badge tone="info">{t('admin.addresses.shipping', 'Shipping')}</Badge> : null}
                                        {row.is_default_billing ? <Badge tone="primary">{t('admin.addresses.billing', 'Billing')}</Badge> : null}
                                        {!row.is_default_shipping && !row.is_default_billing ? <Badge tone="neutral">{t('admin.addresses.other', 'Other')}</Badge> : null}
                                    </div>
                                </Td>
                                <Td actions>
                                    <RowActions
                                        onEdit={() => openEdit(row)}
                                        confirmMessage={t('admin.addresses.delete_this_address', 'Delete this address?')}
                                        onDelete={() => router.delete(`/admin/addresses/${row.id}`, { preserveScroll: true })}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
                )}
            </ListPanel>
        </Layout>
    );
}
