import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    Textarea,
    Toggle,
    ui,
    cx,
    t,
} from '../../ui';
import { tidyVirtualUri, virtualUriMessage } from './virtualUri';

export default function Form({
    record,
    policies = [],
    sources = [],
    presets = [],
    import_options = [],
    modes = [],
    groups = [],
    default_copy_from_id,
    url_defaults = {},
    install_physical = '/',
    themes = [],
    virtual_reserved = [],
}) {
    const themeChoices = themes.length ? themes : [{ name: 'classic', label: 'Classic' }];
    const savedTheme = record?.theme && record.theme !== 'default' ? record.theme : 'classic';
    const installFolder = install_physical && install_physical !== '/'
        ? install_physical
        : (url_defaults.physical_uri || '/');
    const form = useForm({
        name: record?.name || '',
        email: record?.email || '',
        address: record?.address || '',
        scheme: record?.scheme || url_defaults.scheme || 'http',
        domain: record?.domain || url_defaults.domain || '',
        port: record?.port ?? url_defaults.port ?? '',
        physical_uri: record?.physical_uri || installFolder,
        virtual_uri: record?.virtual_uri || '',
        theme: themeChoices.some((row) => row.name === savedTheme) ? savedTheme : (themeChoices[0]?.name || 'classic'),
        is_enabled: record?.is_enabled ?? true,
        import_data: false,
        import: [],
        copy_from_id: default_copy_from_id || sources[0]?.id || '',
        group_id: record?.group_id || groups[0]?.id || '',
        policies: policies.map((row) => ({
            family: row.family,
            mode: row.mode,
            source_store_id: row.source_store_id || '',
        })),
    });

    const virtualClientError = virtualUriMessage(form.data.virtual_uri, virtual_reserved);
    const virtualError = form.errors.virtual_uri || form.errors.shop_url || virtualClientError;
    const virtualSegment = virtualClientError ? '' : tidyVirtualUri(form.data.virtual_uri);
    const physicalPrefix = String(form.data.physical_uri || installFolder || '/');
    const shopPreview = (() => {
        const scheme = form.data.scheme || 'http';
        const domain = String(form.data.domain || '').trim();
        const port = form.data.port ? `:${form.data.port}` : '';
        const physical = physicalPrefix.replace(/\/+$/, '');
        const path = `${physical}${virtualSegment ? `/${virtualSegment}` : ''}`;

        if (virtualClientError || !domain) {
            return '';
        }

        return `${scheme}://${domain}${port}${path}`;
    })();

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            virtual_uri: tidyVirtualUri(data.virtual_uri),
        }));
        if (record) {
            form.put(`/admin/shops/${record.id}`);
        } else {
            form.post('/admin/shops');
        }
    };

    const setPolicy = (family, patch) => {
        form.setData('policies', form.data.policies.map((row) => (
            row.family === family ? { ...row, ...patch } : row
        )));
    };

    return (
        <Layout>
            <PageHeader
                title={record ? t('admin.shops.edit_shop', 'Edit shop') : t('admin.shops.add_shop', 'Add shop')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/shops', label: t('admin.nav.shops', 'Multishop') },
                    { label: record ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.shops.identity', 'Identity')}</h2>
                            <p className={ui.fieldHint}>{t('admin.shops.required_hint', 'Fields marked with * are required.')}</p>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.shops.name', 'Name')} required error={form.errors.name}>
                                <Input wide required invalid={!!form.errors.name} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </Field>
                            <Field
                                label={t('admin.shops.domain', 'Domain')}
                                required
                                hint={t('admin.shops.domain_hint', 'Host only. Same as this install unless you point a second hostname here.')}
                                error={form.errors.domain}
                            >
                                <Input wide required invalid={!!form.errors.domain} value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} />
                            </Field>
                            <Field label={t('admin.shops.port', 'Port')} optional error={form.errors.port}>
                                <Input wide value={form.data.port ?? ''} onChange={(e) => form.setData('port', e.target.value)} />
                            </Field>
                            <Field
                                label={t('admin.shops.physical_uri', 'Physical URI')}
                                required
                                hint={t('admin.shops.physical_uri_hint', 'This copy’s folder. It stays fixed so a second shop opens on a virtual path instead of a missing folder.')}
                                error={form.errors.physical_uri}
                            >
                                <Input wide readOnly required invalid={!!form.errors.physical_uri} value={form.data.physical_uri} />
                            </Field>
                            <Field
                                label={t('admin.shops.virtual_uri', 'Virtual URI')}
                                optional
                                hint={t('admin.shops.virtual_uri_hint', 'One extra folder on this install. Letters and numbers in any language. Capitals stay as you type. Join words with - or _. Leave empty when this shop uses its own domain.')}
                                error={virtualError}
                            >
                                <div className={cx(ui.slugRow, virtualError && 'rounded-xl ring-4 ring-red-500/10')}>
                                    <span className={cx(ui.slugPrefix, virtualError && 'border-red-400')}>{physicalPrefix}</span>
                                    <Input
                                        unstyled
                                        className={cx(ui.slugInput, virtualError && ui.inputInvalid)}
                                        placeholder={t('admin.shops.virtual_uri_ph', 'Shop-2')}
                                        invalid={!!virtualError}
                                        value={form.data.virtual_uri}
                                        spellCheck={false}
                                        autoCapitalize="off"
                                        autoCorrect="off"
                                        autoComplete="off"
                                        aria-label={t('admin.shops.virtual_uri', 'Virtual URI')}
                                        onChange={(e) => {
                                            form.setData('virtual_uri', e.target.value);
                                            form.clearErrors('virtual_uri', 'shop_url');
                                        }}
                                        onBlur={(e) => form.setData('virtual_uri', tidyVirtualUri(e.target.value))}
                                    />
                                </div>
                            </Field>
                            {shopPreview ? (
                                <p className={ui.fieldHint}>
                                    {t('admin.shops.url_preview', 'Storefront')}:{' '}
                                    <a className="text-sky-700 underline" href={shopPreview} target="_blank" rel="noreferrer">{shopPreview}</a>
                                </p>
                            ) : null}
                            <Field label={t('admin.shops.email', 'Email')} optional error={form.errors.email}>
                                <Input wide type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                            <Field label={t('admin.shops.address', 'Address')} optional error={form.errors.address}>
                                <Textarea rows="3" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            </Field>
                            <Field label={t('admin.shops.theme', 'Theme')} required hint={t('admin.shops.theme_hint', 'Classic is bundled. Upload more zips from Design → Themes.')} error={form.errors.theme}>
                                <Select wide required invalid={!!form.errors.theme} value={form.data.theme} onChange={(e) => form.setData('theme', e.target.value)}>
                                    {themeChoices.map((row) => (
                                        <option key={row.name} value={row.name}>{row.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field
                                label={t('admin.shops.group', 'Shop group')}
                                required
                                hint={form.data.import_data && (form.data.import || []).some((item) => ['products', 'categories', 'catalog'].includes(item))
                                    ? t('admin.shops.group_same_hint', 'Shared catalog stays in the source shop group.')
                                    : t('admin.shops.group_pick_hint', 'Pick an existing group. Add new groups from the Shop groups list first.')}
                                error={form.errors.group_id}
                            >
                                <Select
                                    wide
                                    value={String(form.data.group_id || '')}
                                    onChange={(e) => form.setData('group_id', e.target.value)}
                                    disabled={!record && form.data.import_data && (form.data.import || []).some((item) => ['products', 'categories', 'catalog'].includes(item))}
                                >
                                    {groups.map((group) => (
                                        <option key={group.id} value={group.id}>{group.name}</option>
                                    ))}
                                </Select>
                            </Field>
                            {record ? (
                                <Toggle
                                    label={t('admin.common.active', 'Active')}
                                    value={!!form.data.is_enabled}
                                    disabled={record.is_default && form.data.is_enabled}
                                    onChange={(value) => form.setData('is_enabled', value)}
                                />
                            ) : null}
                        </div>
                    </section>

                    {record ? (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.shops.sharing', 'Sharing')}</h2>
                            </div>
                            <p className={`${ui.muted} mb-4`}>
                                {t('admin.shops.sharing_hint', 'Catalog, CMS, and email sharing stay on this shop. Customers and orders follow the shop group. Writes still target this shop.')}
                            </p>
                            <div className="grid gap-4">
                                {policies.map((family) => {
                                    const row = form.data.policies.find((item) => item.family === family.family) || family;
                                    const needsSource = row.mode !== 'own';
                                    return (
                                        <div key={family.family} className={ui.sectionRule}>
                                            <div className="mb-2">
                                                <div className="text-sm font-medium text-neutral-900">{family.label}</div>
                                                <div className={ui.fieldHint}>{family.hint}</div>
                                            </div>
                                            <Select
                                                wide
                                                value={row.mode}
                                                onChange={(e) => setPolicy(family.family, {
                                                    mode: e.target.value,
                                                    source_store_id: e.target.value === 'own' ? '' : (row.source_store_id || default_copy_from_id || ''),
                                                })}
                                                aria-label={family.label}
                                            >
                                                {modes.map((mode) => (
                                                    <option key={mode.value} value={mode.value}>{mode.label}</option>
                                                ))}
                                            </Select>
                                            {needsSource ? (
                                                <Field className="mt-2" label={t('admin.shops.source_shop', 'Source shop')}>
                                                    <Select
                                                        wide
                                                        value={String(row.source_store_id || '')}
                                                        onChange={(e) => setPolicy(family.family, { source_store_id: e.target.value })}
                                                    >
                                                        <option value="">{t('admin.shops.pick_source', 'Pick a source shop')}</option>
                                                        {sources
                                                            .filter((source) => source.id !== record.id && (!record.group_id || source.group_id === record.group_id))
                                                            .map((source) => (
                                                            <option key={source.id} value={source.id}>{source.name}</option>
                                                        ))}
                                                    </Select>
                                                </Field>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    ) : (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.shops.import_data_title', 'Import data from another shop')}</h2>
                            </div>
                            <p className={`${ui.muted} mb-4`}>
                                {t(
                                    'admin.shops.empty_shop_hint',
                                    'A new shop starts empty: no products, categories, customers, orders, webhooks, or copied settings. Clothes and phones should not share a catalog unless you turn import on.',
                                )}
                            </p>
                            <Toggle
                                inline
                                label={t('admin.shops.import_data', 'Import data')}
                                hint={t('admin.shops.import_data_hint', 'Off = empty shop. On = copy only what you tick below.')}
                                value={!!form.data.import_data}
                                onChange={(value) => form.setData('import_data', value)}
                            />
                            {form.data.import_data ? (
                                <div className="mt-4 grid gap-3">
                                    <Field label={t('admin.shops.import_source', 'Choose the source shop')} required error={form.errors.copy_from_id}>
                                        <Select
                                            wide
                                            value={String(form.data.copy_from_id || '')}
                                            onChange={(e) => form.setData('copy_from_id', e.target.value)}
                                        >
                                            {sources.map((source) => (
                                                <option key={source.id} value={source.id}>{source.name}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label={t('admin.shops.import_choose', 'Choose data to import')} hint={t('admin.shops.import_never', 'Customers, orders, and configure settings are never copied. Tick only what this shop should see.')}>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {import_options.map((option) => (
                                                <Checkbox
                                                    key={option.value}
                                                    size="lg"
                                                    checked={(form.data.import || []).includes(option.value)}
                                                    onChange={(e) => {
                                                        const on = e.target.checked;
                                                        const current = form.data.import || [];
                                                        form.setData('import', on
                                                            ? [...current, option.value]
                                                            : current.filter((item) => item !== option.value));
                                                    }}
                                                    label={(
                                                        <span>
                                                            <span className="block font-medium text-neutral-900">{option.label}</span>
                                                            {option.hint ? <span className={ui.fieldHint}>{option.hint}</span> : null}
                                                        </span>
                                                    )}
                                                />
                                            ))}
                                        </div>
                                    </Field>
                                </div>
                            ) : null}
                        </section>
                    )}
                </div>
                <aside className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <p className={ui.muted}>
                            {t('admin.shops.save_hint', 'Each save writes this shop only. A new shop is empty until you import. All shops (view) never becomes a write context.')}
                        </p>
                        <div className="mt-4">
                            <Button icon="check" busy={form.processing}>
                                {t('admin.common.save', 'Save')}
                            </Button>
                        </div>
                    </section>
                </aside>
            </form>
        </Layout>
    );
}
