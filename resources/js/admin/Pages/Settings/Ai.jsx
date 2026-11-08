import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Badge,
    BulkActions,
    Button,
    Card,
    ColumnFilter,
    EmptyState,
    FeatureTile,
    Field,
    FilterReset,
    FormErrors,
    Input,
    ListFooter,
    ListPanel,
    PageHeader,
    Panel,
    RowActions,
    Select,
    SelectionCheckbox,
    SettingsCount,
    SettingsSectionHead,
    SortHeader,
    StatusTabs,
    Table,
    Textarea,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
    cx,
    useListSelection,
} from '../../ui';

const TAB_KEYS = ['options', 'assistant', 'storefront', 'connections'];

function SettingsPanel({ tone = 'primary', title, hint, errors, onSubmit, children, actions }) {
    return (
        <Panel as="form" tone={tone} onSubmit={onSubmit}>
            <Panel.Heading>
                <Panel.Title>{title}</Panel.Title>
            </Panel.Heading>
            <Panel.Body>
                {hint ? <p className={ui.muted}>{hint}</p> : null}
                <FormErrors errors={errors} />
                {children}
            </Panel.Body>
            {actions ? <Panel.Footer>{actions}</Panel.Footer> : null}
        </Panel>
    );
}

function emptyForm(catalog = [], driver = 'openai') {
    const meta = catalog.find((row) => row.driver === driver) || catalog[0];
    const out = {
        driver: meta?.driver || 'openai',
        label: '',
        api_key: '',
        model: '',
        base_url: '',
        clear_api_key: false,
    };
    (meta?.fields || []).forEach((field) => {
        if (field.name === 'label' || field.name === 'api_key') {
            return;
        }
        if (field.default != null) {
            out[field.name] = field.default;
        }
    });
    return out;
}

function goTab(next) {
    router.get('/admin/ai', { tab: next }, { preserveState: true, replace: true });
}

function saveFlags(flags, patch) {
    const next = { ...flags.data, ...patch };
    flags.setData(next);
    router.put('/admin/ai', next, { preserveScroll: true, preserveState: true });
}

function saveAssistant(assistant, patch) {
    const next = { ...assistant.data, ...patch };
    assistant.setData(next);
    router.put('/admin/ai/assistant', next, { preserveScroll: true, preserveState: true });
}

function saveStorefront(storefront, patch) {
    const next = { ...storefront.data, ...patch };
    storefront.setData(next);
    router.put('/admin/ai/storefront-assistant', next, { preserveScroll: true, preserveState: true });
}

const parentOffHint = () => t('admin.ai.needs_parent', 'Turn on Enable AI first. This stays off while AI is off.');

function OptionsTab({ flags }) {
    return (
        <Card>
            <SettingsSectionHead
                icon="sparkles"
                title={t('admin.ai.options', 'Options')}
                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 2 })}</SettingsCount>}
            />
            <FormErrors errors={flags.errors} />
            <div className="grid gap-3 md:grid-cols-2">
                <FeatureTile
                    icon="sparkles"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.ai.enable', 'Enable AI')}
                    hint={t('admin.ai.enable_hint', 'Master switch. While this is off, admin and storefront AI stay off.')}
                    value={flags.data.enabled}
                    disabled={flags.processing}
                    onChange={(on) => saveFlags(flags, { enabled: on })}
                />
                <FeatureTile
                    icon="pencil"
                    tone="bg-sky-100 text-sky-600"
                    title={t('admin.ai.content_enabled', 'Generate AI Content on fields')}
                    hint={flags.data.enabled
                        ? t('admin.ai.content_enabled_hint', 'Show Generate / Improve with AI on title, description, and SEO fields.')
                        : parentOffHint()}
                    value={!!flags.data.enabled && !!flags.data.content_enabled}
                    disabled={flags.processing || !flags.data.enabled}
                    onChange={(on) => saveFlags(flags, { content_enabled: on })}
                />
            </div>
        </Card>
    );
}

function AssistantTab({ assistant, masterOn }) {
    const tabLabels = {
        all: t('admin.ai.tab_all', 'All'),
        dashboard: t('admin.ai.tab_dashboard', 'Dashboard'),
        orders: t('admin.ai.tab_orders', 'Orders'),
        customers: t('admin.ai.tab_customers', 'Customers'),
        products: t('admin.ai.tab_products', 'Products'),
    };

    const places = ['dashboard', 'orders', 'customers', 'products'];
    const selected = assistant.data.accessible_tabs || [];
    const allOn = selected.includes('all') || places.every((key) => selected.includes(key));

    const setPlaces = (nextPlaces) => {
        assistant.setData('accessible_tabs', nextPlaces.length === places.length ? ['all'] : nextPlaces);
    };

    return (
        <form className="space-y-6" onSubmit={(e) => { e.preventDefault(); assistant.put('/admin/ai/assistant'); }}>
        <Card>
            <SettingsSectionHead
                icon="message"
                title={t('admin.ai.assistant_settings', 'Admin Assistant (Ask AI)')}
                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 8 })}</SettingsCount>}
            />
            <FormErrors errors={assistant.errors} />
            <div className="grid gap-3 md:grid-cols-2">
                <FeatureTile
                    icon="message"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.ai.assistant_enabled', 'Enable Admin Assistant Widget')}
                    hint={masterOn
                        ? t('admin.ai.assistant_enabled_hint', 'Show Ask AI for staff in the admin.')
                        : parentOffHint()}
                    value={!!masterOn && !!assistant.data.assistant_enabled}
                    disabled={!masterOn}
                    onChange={(on) => saveAssistant(assistant, { assistant_enabled: on })}
                />
                <FeatureTile
                    icon="file"
                    tone="bg-sky-100 text-sky-600"
                    title={t('admin.ai.assistant_logging', 'Log AI Requests & Responses')}
                    hint={t('admin.ai.assistant_logging_hint', 'Keep admin chat transcripts you can open later.')}
                    value={!!assistant.data.logging}
                    onChange={(on) => saveAssistant(assistant, { logging: on })}
                />
                <FeatureTile
                    icon="pencil"
                    tone="bg-amber-100 text-amber-600"
                    title={t('admin.ai.assistant_writes', 'Allow AI to Modify Store Data')}
                    hint={t('admin.ai.assistant_writes_hint', 'The assistant can change shop data, not only answer questions.')}
                    value={!!assistant.data.writes_enabled}
                    onChange={(on) => saveAssistant(assistant, { writes_enabled: on })}
                />
                <FeatureTile
                    icon="clock"
                    tone="bg-emerald-100 text-emerald-600"
                    title={t('admin.ai.timeout', 'AI Response Timeout')}
                    hint={assistant.errors.timeout || t('admin.ai.timeout_hint', 'Seconds to wait before the request stops. 10 to 120.')}
                    control={(
                        <Input className="w-24 shrink-0" type="number" min={10} max={120} value={assistant.data.timeout} onChange={(e) => assistant.setData('timeout', Number(e.target.value))} />
                    )}
                />
                <FeatureTile
                    icon="layers"
                    tone="bg-indigo-100 text-indigo-600"
                    title={t('admin.ai.result_limit', 'Result Limit Row Wise')}
                    hint={assistant.errors.result_limit || t('admin.ai.result_limit_hint', 'Max rows for list/table answers.')}
                    control={(
                        <Input className="w-24 shrink-0" type="number" min={1} max={100} value={assistant.data.result_limit} onChange={(e) => assistant.setData('result_limit', Number(e.target.value))} />
                    )}
                />
                <FeatureTile
                    icon="sparkles"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.ai.icon_type', 'AI Icon Type')}
                    hint={assistant.errors.icon_type}
                    control={(
                        <Select className="w-36 shrink-0" value={assistant.data.icon_type} onChange={(e) => assistant.setData('icon_type', e.target.value)}>
                            <option value="text">{t('admin.ai.icon_text_option', 'Text')}</option>
                            <option value="icon">{t('admin.ai.icon_icon_option', 'Icon')}</option>
                        </Select>
                    )}
                />
            </div>
            <div className="mt-3 grid items-stretch gap-3 md:grid-cols-2">
                <div className="grid h-full grid-rows-[auto_1fr] gap-3">
                    <FeatureTile
                        icon="file"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.ai.icon_text', 'AI Icon Text')}
                        hint={assistant.errors.icon_text}
                        control={(
                            <Input className="w-32 shrink-0" value={assistant.data.icon_text} onChange={(e) => assistant.setData('icon_text', e.target.value)} />
                        )}
                    />
                    <FeatureTile
                        fill
                        className="h-full"
                        icon="message"
                        tone="bg-rose-100 text-rose-600"
                        title={t('admin.ai.fallback', 'Fallback Message')}
                        hint={assistant.errors.fallback_message || t('admin.ai.fallback_hint', 'Shown when the assistant cannot answer.')}
                    >
                        <Textarea
                            className="min-h-16 w-full flex-1 resize-y"
                            value={assistant.data.fallback_message}
                            onChange={(e) => assistant.setData('fallback_message', e.target.value)}
                        />
                    </FeatureTile>
                </div>
                <FeatureTile
                    fill
                    className="h-full"
                    icon="eye"
                    tone="bg-blue-100 text-blue-600"
                    title={t('admin.ai.accessible_tabs', 'Show AI Icon')}
                    hint={t('admin.ai.accessible_tabs_hint', 'Where the Ask AI button appears.')}
                    extra={(
                        <div className="mt-3 flex flex-col gap-2.5" role="group" aria-label={t('admin.ai.accessible_tabs', 'Show AI Icon')}>
                            <label className={ui.checkboxRow}>
                                <input
                                    type="checkbox"
                                    className={ui.checkboxInput}
                                    checked={allOn && selected.length > 0}
                                    onChange={(e) => assistant.setData('accessible_tabs', e.target.checked ? ['all'] : [])}
                                />
                                {tabLabels.all}
                            </label>
                            {places.map((tab) => (
                                <label key={tab} className={ui.checkboxRow}>
                                    <input
                                        type="checkbox"
                                        className={ui.checkboxInput}
                                        checked={allOn || selected.includes(tab)}
                                        onChange={(e) => {
                                            const current = allOn ? places : selected.filter((key) => places.includes(key));
                                            const next = e.target.checked
                                                ? [...new Set([...current, tab])]
                                                : current.filter((key) => key !== tab);
                                            setPlaces(next);
                                        }}
                                    />
                                    {tabLabels[tab]}
                                </label>
                            ))}
                        </div>
                    )}
                />
            </div>
        </Card>
        <div className={cx(ui.formSticky, 'justify-end')}>
            <Button icon="check" busy={assistant.processing}>{t('admin.ai.save_assistant', 'Save assistant')}</Button>
            <Button type="button" variant="secondary" icon="message" native href="/admin/ai/logs">
                {t('admin.ai.view_logs', 'Admin chats')}
            </Button>
        </div>
        </form>
    );
}

function StorefrontTab({ storefront, cmsPages, masterOn }) {
    return (
        <form className="space-y-6" onSubmit={(e) => { e.preventDefault(); storefront.put('/admin/ai/storefront-assistant'); }}>
        <Card>
            <SettingsSectionHead
                icon="message"
                title={t('admin.ai.storefront_settings', 'Customer Assistant (storefront)')}
                extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 7 })}</SettingsCount>}
            />
            <FormErrors errors={storefront.errors} />
            <div className="grid gap-3 md:grid-cols-2">
                <FeatureTile
                    icon="message"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.ai.storefront_enabled', 'Enable Customer Assistant')}
                    hint={masterOn
                        ? t('admin.ai.storefront_enabled_hint', 'Show the assistant to shoppers on the storefront.')
                        : parentOffHint()}
                    value={!!masterOn && !!storefront.data.enabled}
                    disabled={!masterOn}
                    onChange={(on) => saveStorefront(storefront, { enabled: on })}
                />
                <FeatureTile
                    icon="clock"
                    tone="bg-sky-100 text-sky-600"
                    title={t('admin.ai.timeout', 'AI Response Timeout')}
                    hint={storefront.errors.timeout || t('admin.ai.timeout_hint', 'Seconds to wait before the request stops. 10 to 120.')}
                    control={(
                        <Input className="w-24 shrink-0" type="number" min={10} max={120} value={storefront.data.timeout} onChange={(e) => storefront.setData('timeout', Number(e.target.value))} />
                    )}
                />
                <FeatureTile
                    icon="file"
                    tone="bg-amber-100 text-amber-600"
                    title={t('admin.ai.terms_cms_page', 'Terms & Conditions CMS Page')}
                    hint={storefront.errors.terms_cms_page_id || t('admin.ai.terms_cms_page_hint', 'Shown in a modal when the shopper opens the terms link.')}
                    control={(
                        <Select className="w-52 shrink-0" value={storefront.data.terms_cms_page_id || ''} onChange={(e) => storefront.setData('terms_cms_page_id', e.target.value ? Number(e.target.value) : '')}>
                            <option value="">{t('admin.ai.terms_auto', 'Auto (terms or privacy page)')}</option>
                            {cmsPages.map((page) => (
                                <option key={page.id} value={page.id}>
                                    {page.title}{page.slug ? ` (/${page.slug})` : ''}
                                </option>
                            ))}
                        </Select>
                    )}
                />
                <FeatureTile
                    icon="sparkles"
                    tone="bg-violet-100 text-violet-600"
                    title={t('admin.ai.icon_type', 'AI Icon type')}
                    hint={storefront.errors.icon_type}
                    control={(
                        <Select className="w-36 shrink-0" value={storefront.data.icon_type} onChange={(e) => storefront.setData('icon_type', e.target.value)}>
                            <option value="text">{t('admin.ai.icon_text_option', 'Text')}</option>
                            <option value="icon">{t('admin.ai.icon_icon_option', 'Icon')}</option>
                        </Select>
                    )}
                />
            </div>
            <div className="mt-3 grid items-stretch gap-3 md:grid-cols-2">
                <div className="grid h-full content-start gap-3">
                    <FeatureTile
                        icon="file"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.ai.icon_text', 'AI Icon text')}
                        hint={storefront.errors.icon_text}
                        control={(
                            <Input className="w-32 shrink-0" value={storefront.data.icon_text} onChange={(e) => storefront.setData('icon_text', e.target.value)} />
                        )}
                    />
                    <FeatureTile
                        icon="alert-triangle"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.ai.off_topic', 'Out-of-Scope Response')}
                        hint={storefront.errors.off_topic_message || t('admin.ai.off_topic_hint', 'Reply when the question is outside the shop.')}
                    >
                        <Input wide value={storefront.data.off_topic_message} onChange={(e) => storefront.setData('off_topic_message', e.target.value)} />
                    </FeatureTile>
                </div>
                <FeatureTile
                    fill
                    className="h-full"
                    icon="message"
                    tone="bg-rose-100 text-rose-600"
                    title={t('admin.ai.fallback', 'Fallback Message')}
                    hint={storefront.errors.fallback_message || t('admin.ai.fallback_hint', 'Shown when the assistant cannot answer.')}
                >
                    <Textarea
                        className="min-h-28 w-full flex-1 resize-y"
                        value={storefront.data.fallback_message}
                        onChange={(e) => storefront.setData('fallback_message', e.target.value)}
                    />
                </FeatureTile>
            </div>
        </Card>
        <div className={cx(ui.formSticky, 'justify-end')}>
            <Button icon="check" busy={storefront.processing}>{t('admin.ai.save_storefront', 'Save customer assistant')}</Button>
            <Button type="button" variant="secondary" icon="message" native href="/admin/ai/customer-chats">
                {t('admin.ai.view_customer_chats', 'Customer chats')}
            </Button>
        </div>
        </form>
    );
}


function ConnectionsTab({
    ai,
    flagsEnabled,
    shared,
    catalog,
    form,
    editingId,
    showAdd,
    testMsg,
    testBusy,
    activeMeta,
    onStartAdd,
    onStartEdit,
    onDriverChange,
    onSubmit,
    onCancel,
    onTest,
    renderDynamicFields,
}) {
    const connections = ai.connections || [];
    const [query, setQuery] = useState({
        name: '',
        provider: '',
        model: '',
        key: '',
        status: '',
        sort: 'label',
        dir: 'asc',
    });
    const filtered = useMemo(() => {
        const name = query.name.trim().toLowerCase();
        const provider = query.provider.trim().toLowerCase();
        const model = query.model.trim().toLowerCase();
        const key = query.key.trim().toLowerCase();
        const rows = connections.filter((row) => {
            if (name && !String(row.label || '').toLowerCase().includes(name)) return false;
            if (provider && !`${row.driver_label || ''} ${row.company || ''}`.toLowerCase().includes(provider)) return false;
            if (model && !String(row.model || '').toLowerCase().includes(model)) return false;
            if (key && !String(row.api_key_masked || '').toLowerCase().includes(key)) return false;
            if (query.status === 'active' && !row.is_active) return false;
            if (query.status === 'inactive' && row.is_active) return false;
            return true;
        });
        const dir = query.dir === 'desc' ? -1 : 1;
        const value = (row) => {
            if (query.sort === 'driver') return row.driver_label || '';
            if (query.sort === 'model') return row.model || '';
            if (query.sort === 'status') return row.is_active ? '1' : '0';
            return row.label || '';
        };
        return [...rows].sort((a, b) => String(value(a)).localeCompare(String(value(b))) * dir);
    }, [connections, query]);
    const selection = useListSelection(filtered);
    const applyColumn = (nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            setQuery((current) => ({ ...current, ...nameOrPatch }));
            return;
        }
        setQuery((current) => ({ ...current, [nameOrPatch]: value }));
    };
    const sortBy = (column) => {
        setQuery((current) => ({
            ...current,
            sort: column,
            dir: current.sort === column && current.dir === 'asc' ? 'desc' : 'asc',
        }));
    };

    if (showAdd) {
        return (
            <div className="space-y-4">
                <Card as="form" onSubmit={onSubmit}>
                    <SettingsSectionHead
                        icon="link"
                        title={editingId
                            ? t('admin.ai.edit_connection', 'Edit connection')
                            : t('admin.ai.add_connection', 'Add connection')}
                        hint={t('admin.ai.connection_hint', 'Save a provider key so AI features can call that service.')}
                    />
                    <FormErrors errors={form.errors} />
                    <Field label={t('admin.ai.provider_company', 'Provider')} error={form.errors.driver}>
                        <Select
                            wide
                            value={form.data.driver}
                            disabled={!!editingId}
                            onChange={(e) => onDriverChange(e.target.value)}
                        >
                            {catalog.map((row) => (
                                <option key={row.driver} value={row.driver}>
                                    {row.label}
                                </option>
                            ))}
                        </Select>
                        {activeMeta?.description ? <p className={ui.fieldHint}>{activeMeta.description}</p> : null}
                        {activeMeta?.docs_url ? (
                            <p className={ui.fieldHint}>
                                <a href={activeMeta.docs_url} target="_blank" rel="noreferrer" className="text-sky-700 underline">
                                    {t('admin.ai.get_api_key', 'Where to get an API key')}
                                </a>
                            </p>
                        ) : null}
                    </Field>
                    {renderDynamicFields()}
                    {editingId ? (
                        <label className={ui.checkboxRow}>
                            <input
                                type="checkbox"
                                checked={!!form.data.clear_api_key}
                                onChange={(e) => form.setData('clear_api_key', e.target.checked)}
                            />
                            {t('admin.ai.clear_api_key', 'Remove saved API key')}
                        </label>
                    ) : null}
                    <div className={ui.toolbar}>
                        <Button icon="check" busy={form.processing}>
                            {editingId ? t('admin.common.save', 'Save') : t('admin.ai.add_connection', 'Add connection')}
                        </Button>
                        <Button type="button" variant="secondary" onClick={onCancel}>
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                    </div>
                </Card>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {testMsg ? <Alert type="info">{testMsg}</Alert> : null}
            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        items={[{
                            key: 'delete',
                            label: t('admin.products.delete_selection', 'Delete selection'),
                            icon: 'trash',
                            tone: 'danger',
                            confirm: t('admin.ai.delete_connections', 'Remove the selected connections?'),
                            onClick: () => router.post('/admin/ai/connections/bulk-delete', { ids: selection.selectedIds }),
                        }]}
                    />
                )}
                footer={(
                    <ListFooter
                        pagination={{
                            from: filtered.length ? 1 : 0,
                            to: filtered.length,
                            total: filtered.length,
                            current_page: 1,
                            last_page: 1,
                            per_page: Math.max(filtered.length, 10),
                        }}
                        query={{ tab: 'connections' }}
                    />
                )}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={() => selection.toggleAll()}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader column="label" label={t('admin.ai.connection_name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="driver" label={t('admin.ai.provider_company', 'Provider')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="model" label={t('admin.ai.model', 'Model')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th>{t('admin.ai.api_key', 'API key')}</Th>
                            <SortHeader col="status" column="status" label={t('admin.common.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.ai.connection_name', 'Name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="provider" value={query.provider} placeholder={t('admin.ai.provider_company', 'Provider')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="model" value={query.model} placeholder={t('admin.ai.model', 'Model')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="key" value={query.key} placeholder={t('admin.ai.api_key', 'API key')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterStatus}>
                                <ColumnFilter
                                    name="status"
                                    value={query.status}
                                    type="select"
                                    placeholder={t('admin.common.all', 'All')}
                                    options={[
                                        { value: 'active', label: t('admin.ai.active', 'Active') },
                                        { value: 'inactive', label: t('admin.ai.inactive', 'Inactive') },
                                    ]}
                                    onApply={applyColumn}
                                />
                            </Th>
                            <Th actions className={ui.thFilter}>
                                <FilterReset
                                    query={query}
                                    ignore={['sort', 'dir']}
                                    onReset={() => setQuery((current) => ({
                                        ...current,
                                        name: '',
                                        provider: '',
                                        model: '',
                                        key: '',
                                        status: '',
                                    }))}
                                />
                            </Th>
                        </tr>
                    </THead>
                    <tbody>
                        {filtered.length === 0 ? (
                            <EmptyState colSpan={7} />
                        ) : filtered.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${row.label}`}
                                    />
                                </Td>
                                <Td>
                                    <span className={ui.cellStrong}>{row.label}</span>
                                </Td>
                                <Td>
                                    {row.driver_label}
                                    {row.company ? <div className={ui.muted}>{row.company}</div> : null}
                                </Td>
                                <Td>{row.model || '—'}</Td>
                                <Td>
                                    {row.has_api_key
                                        ? row.api_key_masked
                                        : t('admin.ai.missing_key', 'No API key')}
                                </Td>
                                <Td col="status">
                                    {row.is_active ? (
                                        <Badge tone="success">{t('admin.ai.active', 'Active')}</Badge>
                                    ) : (
                                        <Badge tone="neutral" dot={false}>{t('admin.ai.inactive', 'Inactive')}</Badge>
                                    )}
                                </Td>
                                <Td actions>
                                    <RowActions
                                        onEdit={() => onStartEdit(row)}
                                        onDelete={() => router.delete(`/admin/ai/connections/${row.id}`)}
                                        confirmMessage={t('admin.ai.delete_connection', 'Remove this connection?')}
                                        items={[
                                            !row.is_active ? {
                                                key: 'activate',
                                                icon: 'check',
                                                label: t('admin.ai.set_active', 'Set active'),
                                                onClick: () => router.post(`/admin/ai/connections/${row.id}/activate`),
                                            } : null,
                                            {
                                                key: 'test',
                                                icon: 'search',
                                                label: testBusy
                                                    ? t('admin.ai.testing', 'Testing…')
                                                    : t('admin.ai.test', 'Test'),
                                                onClick: () => onTest(row.id),
                                            },
                                        ]}
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>
        </div>
    );
}

export default function Ai({ ai, catalog = [], cmsPages = [], tab = 'options' }) {
    const current = TAB_KEYS.includes(tab) ? tab : 'options';
    const flags = useForm({
        enabled: !!ai.enabled,
        content_enabled: !!ai.content_enabled,
    });
    const assistant = useForm({
        assistant_enabled: !!ai.assistant?.assistant_enabled,
        timeout: ai.assistant?.timeout ?? 45,
        result_limit: ai.assistant?.result_limit ?? 10,
        logging: !!ai.assistant?.logging,
        writes_enabled: !!ai.assistant?.writes_enabled,
        accessible_tabs: ai.assistant?.accessible_tabs || ['all'],
        fallback_message: ai.assistant?.fallback_message
            || 'Sorry, I could not find that information in your shop data. Please try rephrasing.',
        icon_type: ai.assistant?.icon_type || 'text',
        icon_text: ai.assistant?.icon_text || 'Ask AI',
    });
    const storefront = useForm({
        enabled: !!ai.storefront?.enabled,
        timeout: ai.storefront?.timeout ?? 45,
        fallback_message: ai.storefront?.fallback_message
            || 'Sorry, I could not find that in our shop. Please try asking about products, orders, shipping, or payment.',
        off_topic_message: ai.storefront?.off_topic_message
            || 'I can only help with this shop — products, cart, orders, shipping, and payment. Please ask something store-related.',
        icon_type: ai.storefront?.icon_type || 'text',
        icon_text: ai.storefront?.icon_text || 'Ask AI',
        terms_cms_page_id: ai.storefront?.terms_cms_page_id || '',
    });
    const [editingId, setEditingId] = useState(null);
    const [showAdd, setShowAdd] = useState(false);
    const [testMsg, setTestMsg] = useState('');
    const [testBusy, setTestBusy] = useState(false);
    const { ai: shared } = usePage().props;

    const form = useForm(emptyForm(catalog, 'openai'));

    const activeMeta = useMemo(
        () => catalog.find((row) => row.driver === form.data.driver) || catalog[0],
        [catalog, form.data.driver],
    );

    const startAdd = () => {
        setEditingId(null);
        setShowAdd(true);
        form.setData(emptyForm(catalog, 'openai'));
        form.clearErrors();
        if (current !== 'connections') {
            goTab('connections');
        }
    };

    const startEdit = (row) => {
        setEditingId(row.id);
        setShowAdd(true);
        form.setData({
            driver: row.driver,
            label: row.label || '',
            api_key: '',
            model: row.model || '',
            base_url: row.base_url || '',
            clear_api_key: false,
        });
        form.clearErrors();
        if (current !== 'connections') {
            goTab('connections');
        }
    };

    const onDriverChange = (driver) => {
        const next = emptyForm(catalog, driver);
        form.setData({
            ...next,
            driver,
            // Keep a name the admin already typed; never prefill from provider title.
            label: form.data.label || '',
        });
    };

    const submitConnection = (e) => {
        e.preventDefault();
        if (editingId) {
            form.put(`/admin/ai/connections/${editingId}`, {
                onSuccess: () => {
                    setShowAdd(false);
                    setEditingId(null);
                },
            });
        } else {
            form.post('/admin/ai/connections', {
                onSuccess: () => {
                    setShowAdd(false);
                    form.setData(emptyForm(catalog, form.data.driver));
                },
            });
        }
    };

    const testConnection = async (connectionId) => {
        setTestMsg('');
        setTestBusy(true);
        try {
            const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
            const res = await fetch('/admin/ai/test', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ connection_id: connectionId || null }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(json.message || t('admin.ai.test_failed', 'Connection test failed.'));
            }
            setTestMsg(t('admin.ai.test_ok', 'Connection OK.') + (json.preview ? ` (${json.preview})` : ''));
        } catch (err) {
            setTestMsg(err.message);
        } finally {
            setTestBusy(false);
        }
    };

    const renderDynamicFields = () => (
        (activeMeta?.fields || []).map((field) => {
            if (field.name === 'label') {
                return (
                    <Field
                        key={field.name}
                        label={field.label}
                        hint={t('admin.ai.connection_name_hint', 'Label for your identification')}
                        error={form.errors.label}
                    >
                        <Input
                            wide
                            value={form.data.label || ''}
                            onChange={(e) => form.setData('label', e.target.value)}
                            placeholder=""
                        />
                    </Field>
                );
            }
            if (field.name === 'api_key') {
                return (
                    <Field
                        key={field.name}
                        label={field.label}
                        hint={
                            editingId
                                ? t('admin.ai.api_key_keep', 'Leave blank to keep the saved key.')
                                : (field.hint || t('admin.ai.api_key_hint', 'Stored encrypted. Never committed to git.'))
                        }
                        error={form.errors.api_key}
                    >
                        <Input
                            wide
                            type="password"
                            autoComplete="new-password"
                            value={form.data.api_key}
                            onChange={(e) => form.setData('api_key', e.target.value)}
                            placeholder={editingId ? '••••••••' : ''}
                        />
                    </Field>
                );
            }
            if (field.name === 'model' && field.options?.length) {
                const modelOptions = field.options.includes(form.data.model)
                    ? field.options
                    : (form.data.model ? [form.data.model, ...field.options] : field.options);
                return (
                    <Field
                        key={field.name}
                        label={field.label}
                        hint={t('admin.ai.model_pick_hint', 'Choose a model for this provider.')}
                        error={form.errors.model}
                    >
                        <Select
                            wide
                            value={form.data.model || field.options[0]}
                            onChange={(e) => form.setData('model', e.target.value)}
                        >
                            {modelOptions.map((opt) => (
                                <option key={opt} value={opt}>{opt}</option>
                            ))}
                        </Select>
                    </Field>
                );
            }
            return (
                <Field key={field.name} label={field.label} hint={field.hint} error={form.errors[field.name]}>
                    <Input
                        wide
                        type={field.type === 'password' ? 'password' : 'text'}
                        value={form.data[field.name] || ''}
                        onChange={(e) => form.setData(field.name, e.target.value)}
                    />
                </Field>
            );
        })
    );

    return (
        <Layout>
            <PageHeader
                title={t('admin.ai.title', 'AI Assistant')}
                description={t('admin.ai.description', 'Turn AI on for this shop, connect a provider, and choose where staff and shoppers can ask for help.')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.nav.ai', 'AI Assistant') },
                    { label: t('admin.ai.settings', 'Settings') },
                ]}
                actions={current === 'connections' && !showAdd ? (
                    <Button type="button" icon="plus" onClick={startAdd}>
                        {t('admin.ai.add_connection', 'Add connection')}
                    </Button>
                ) : null}
            />
            <StatusTabs
                className="mb-4 bg-white rounded-xl border border-neutral-200 px-2"
                tabs={[
                    { key: 'options', label: t('admin.ai.options', 'Configuration') },
                    { key: 'connections', label: t('admin.ai.tab_connections', 'Connections') },
                    { key: 'assistant', label: t('admin.ai.tab_assistant', 'Admin Assistant') },
                    { key: 'storefront', label: t('admin.ai.tab_storefront', 'Customer Assistant') },
                ]}
                value={current}
                onChange={goTab}
            />
            {current === 'options' ? <OptionsTab flags={flags} /> : null}
            {current === 'assistant' ? <AssistantTab assistant={assistant} masterOn={!!flags.data.enabled} /> : null}
            {current === 'storefront' ? <StorefrontTab storefront={storefront} cmsPages={cmsPages} masterOn={!!flags.data.enabled} /> : null}
            {current === 'connections' ? (
                <ConnectionsTab
                    ai={ai}
                    flagsEnabled={flags.data.enabled}
                    shared={shared}
                    catalog={catalog}
                    form={form}
                    editingId={editingId}
                    showAdd={showAdd}
                    testMsg={testMsg}
                    testBusy={testBusy}
                    activeMeta={activeMeta}
                    onStartAdd={startAdd}
                    onStartEdit={startEdit}
                    onDriverChange={onDriverChange}
                    onSubmit={submitConnection}
                    onCancel={() => {
                                    setShowAdd(false);
                                    setEditingId(null);
                                }}
                    onTest={testConnection}
                    renderDynamicFields={renderDynamicFields}
                />
                ) : null}
        </Layout>
    );
}
