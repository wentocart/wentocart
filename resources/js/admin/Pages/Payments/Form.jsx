import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    Field,
    FormErrors,
    Input,
    PageHeader,
    SearchSelect,
    Textarea,
    Alert,
    Badge,
    ui,
    t,
} from '../../ui';
import ExtensionSettingsFields from '../Apps/ExtensionSettingsFields';

const emptyRules = () => ({
    category_ids: [],
    customer_ids: [],
    customer_group_ids: [],
    shipping_method_ids: [],
    country_ids: [],
    currency_ids: [],
    language_ids: [],
});

export default function Form({ method, options = {}, selected = {}, drivers = [], extension = null }) {
    const form = useForm({
        name: method?.name || '',
        description: method?.description || '',
        driver: method?.driver || 'offline',
        is_enabled: method?.is_enabled ?? true,
        config: {
            client_id: method?.config?.client_id || '',
            secret: '',
            sandbox: method?.config?.sandbox ?? true,
            key_id: method?.config?.key_id || '',
            key_secret: '',
            vpa: method?.config?.vpa || '',
        },
        rules: { ...emptyRules(), ...(method?.rules || {}) },
        extension_values: extension?.values || {},
    });

    const setRule = (key, value) => {
        form.setData('rules', { ...form.data.rules, [key]: value });
    };

    const submit = (e) => {
        e.preventDefault();
        if (method) {
            form.put(`/admin/payments/${method.id}`);
        } else {
            form.post('/admin/payments');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={method ? t('admin.payments.edit_payment_method', 'Edit payment method') : t('admin.payments.add_payment_method', 'Add payment method')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/payments', label: t('admin.payments.payment_methods', 'Payment methods') },
                    { label: method ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    method ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.payments.delete_this_payment_method', 'Delete this payment method?'))) {
                                    router.delete(`/admin/payments/${method.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.payments.general', 'General')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.payments.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.payments.e_g_cash_on_delivery', 'e.g. Cash on delivery')}
                                />
                            </Field>
                            <Field label={t('admin.payments.description', 'Description')} error={form.errors.description}>
                                <Textarea
                                    rows={4}
                                    invalid={!!form.errors.description}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder={t('admin.payments.shown_to_the_customer_at_checkout', 'Shown to the customer at checkout')}
                                />
                            </Field>
                            <Field label={t('admin.payments.driver', 'Driver')} error={form.errors.driver}>
                                <select
                                    className={ui.input}
                                    value={form.data.driver}
                                    onChange={(e) => form.setData('driver', e.target.value)}
                                    disabled={!!extension}
                                >
                                    {(drivers.length ? drivers : [
                                        { value: 'offline', label: 'Offline / bank transfer' },
                                        { value: 'paypal', label: 'PayPal' },
                                        { value: 'razorpay', label: 'Razorpay' },
                                        { value: 'upi', label: 'UPI' },
                                        { value: 'stripe', label: 'Stripe' },
                                    ]).map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </select>
                            </Field>
                            {extension ? (
                                <p className={ui.muted}>
                                    {t('admin.payments.extension_driver_hint', 'This payment method is managed by an extension. API keys are saved below.')}
                                </p>
                            ) : null}
                        </div>
                    </section>

                    {form.data.driver === 'paypal' ? (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.payments.paypal_keys', 'PayPal keys')}</h2>
                            </div>
                            <p className={`${ui.metaK} mb-3`}>{t('admin.payments.paypal_keys_hint', 'Leave blank to complete checkout with a sandbox capture in local. Paste live keys when you have a PayPal account.')}</p>
                            <div className="grid gap-3">
                                <Field label={t('admin.payments.client_id', 'Client ID')}>
                                    <Input wide value={form.data.config.client_id} onChange={(e) => form.setData('config', { ...form.data.config, client_id: e.target.value })} />
                                </Field>
                                <Field label={t('admin.payments.secret', 'Secret')}>
                                    <Input wide type="password" value={form.data.config.secret} onChange={(e) => form.setData('config', { ...form.data.config, secret: e.target.value })} placeholder={method?.config?.has_secret ? t('admin.payments.unchanged', 'Leave blank to keep') : ''} />
                                </Field>
                                <Checkbox
                                    label={t('admin.payments.sandbox', 'Sandbox')}
                                    checked={!!form.data.config.sandbox}
                                    onChange={(e) => form.setData('config', { ...form.data.config, sandbox: e.target.checked })}
                                />
                            </div>
                        </section>
                    ) : null}

                    {form.data.driver === 'razorpay' ? (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.payments.razorpay_keys', 'Razorpay keys')}</h2>
                            </div>
                            <p className={`${ui.metaK} mb-3`}>{t('admin.payments.razorpay_keys_hint', 'Leave blank to complete checkout with a sandbox capture in local. Paste live keys when you have a Razorpay account.')}</p>
                            <div className="grid gap-3">
                                <Field label={t('admin.payments.key_id', 'Key ID')}>
                                    <Input wide value={form.data.config.key_id} onChange={(e) => form.setData('config', { ...form.data.config, key_id: e.target.value })} />
                                </Field>
                                <Field label={t('admin.payments.key_secret', 'Key secret')}>
                                    <Input wide type="password" value={form.data.config.key_secret} onChange={(e) => form.setData('config', { ...form.data.config, key_secret: e.target.value })} placeholder={method?.config?.has_key_secret ? t('admin.payments.unchanged', 'Leave blank to keep') : ''} />
                                </Field>
                            </div>
                        </section>
                    ) : null}

                    {form.data.driver === 'upi' ? (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{t('admin.payments.upi', 'UPI')}</h2>
                            </div>
                            <p className={`${ui.metaK} mb-3`}>{t('admin.payments.upi_hint', 'Shop UPI VPA. Without a VPA, non-production uses the hosted sandbox pay page.')}</p>
                            <Field label={t('admin.payments.vpa', 'UPI ID / VPA')}>
                                <Input wide value={form.data.config.vpa || ''} onChange={(e) => form.setData('config', { ...form.data.config, vpa: e.target.value })} placeholder="merchant@upi" />
                            </Field>
                        </section>
                    ) : null}

                    {extension ? (
                        <section className={ui.sectionCard}>
                            <div className={ui.sectionHead}>
                                <h2 className={ui.sectionTitle}>{extension.schema?.title || extension.display_name}</h2>
                                {extension.complete ? (
                                    <Badge tone="success">{t('admin.payments.keys_configured', 'Keys configured')}</Badge>
                                ) : (
                                    <Badge tone="warning">{t('admin.payments.keys_missing', 'Needs API keys')}</Badge>
                                )}
                            </div>
                            {!extension.enabled ? (
                                <Alert type="warning" className="mb-4">
                                    {t('admin.payments.extension_disabled', 'Enable this extension under Design → Extensions before checkout can use it.')}
                                </Alert>
                            ) : null}
                            <p className={`${ui.metaK} mb-4`}>
                                {extension.schema?.hint || t('admin.payments.stripe_keys_hint', 'Use sk_test_… for sandbox or sk_live_… for production. Stripe switches mode from the key prefix — no separate sandbox toggle.')}
                            </p>
                            <div className="grid gap-3">
                                <ExtensionSettingsFields
                                    fields={extension.schema?.fields || []}
                                    values={form.data.extension_values}
                                    masked={extension.masked || {}}
                                    layout={extension.schema?.layout || 'stack'}
                                    onChange={(name, value) => form.setData('extension_values', { ...form.data.extension_values, [name]: value })}
                                />
                            </div>
                            <div className={`${ui.toolbar} mt-4`}>
                                <Button type="button" variant="secondary" href={extension.configure_href}>
                                    {t('admin.payments.open_extension_settings', 'Open extension page')}
                                </Button>
                            </div>
                        </section>
                    ) : null}

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.payments.rules', 'Rules')}</h2>
                        </div>
                        <p className={`${ui.metaK} mb-4`}>
                            {t('admin.payments.leave_a_rule_empty_to_allow_all_type_to_search_l', 'Leave a rule empty to allow all. Type to search — large lists (customers, categories) load matches from the server.')}
                        </p>
                        <div className={ui.fieldGrid}>
                            <SearchSelect
                                label={t('admin.payments.category', 'Category')}
                                searchUrl="/admin/payments/search/categories"
                                value={form.data.rules.category_ids}
                                selectedOptions={selected.categories || []}
                                onChange={(v) => setRule('category_ids', v)}
                                error={form.errors['rules.category_ids']}
                                placeholder={t('admin.payments.search_categories', 'Search categories…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.customer', 'Customer')}
                                searchUrl="/admin/payments/search/customers"
                                value={form.data.rules.customer_ids}
                                selectedOptions={selected.customers || []}
                                onChange={(v) => setRule('customer_ids', v)}
                                error={form.errors['rules.customer_ids']}
                                placeholder={t('admin.payments.search_by_name_or_email', 'Search by name or email…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.customer_group', 'Customer group')}
                                options={options.customer_groups || []}
                                value={form.data.rules.customer_group_ids}
                                selectedOptions={selected.customer_groups || []}
                                onChange={(v) => setRule('customer_group_ids', v)}
                                error={form.errors['rules.customer_group_ids']}
                                placeholder={t('admin.payments.filter_groups', 'Filter groups…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.shipping', 'Shipping')}
                                options={options.shipping_methods || []}
                                value={form.data.rules.shipping_method_ids}
                                selectedOptions={selected.shipping_methods || []}
                                onChange={(v) => setRule('shipping_method_ids', v)}
                                error={form.errors['rules.shipping_method_ids']}
                                placeholder={t('admin.payments.filter_shipping', 'Filter shipping…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.country', 'Country')}
                                options={options.countries || []}
                                value={form.data.rules.country_ids}
                                selectedOptions={selected.countries || []}
                                onChange={(v) => setRule('country_ids', v)}
                                error={form.errors['rules.country_ids']}
                                placeholder={t('admin.payments.filter_countries', 'Filter countries…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.currency', 'Currency')}
                                options={(options.currencies || []).map((c) => ({
                                    id: c.id,
                                    name: c.code ? `${c.name} (${c.code})` : c.name,
                                }))}
                                value={form.data.rules.currency_ids}
                                selectedOptions={selected.currencies || []}
                                onChange={(v) => setRule('currency_ids', v)}
                                error={form.errors['rules.currency_ids']}
                                placeholder={t('admin.payments.filter_currencies', 'Filter currencies…')}
                            />
                            <SearchSelect
                                label={t('admin.payments.language', 'Language')}
                                options={(options.languages || []).map((l) => ({
                                    id: l.id,
                                    name: l.code ? `${l.name} (${l.code})` : l.name,
                                }))}
                                value={form.data.rules.language_ids}
                                selectedOptions={selected.languages || []}
                                onChange={(v) => setRule('language_ids', v)}
                                error={form.errors['rules.language_ids']}
                                placeholder={t('admin.payments.filter_languages', 'Filter languages…')}
                            />
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.payments.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>{t('admin.payments.inactive_methods_are_hidden_at_checkout', 'Inactive methods are hidden at checkout.')}</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {method ? t('admin.common.save', 'Save changes') : t('admin.payments.create_payment_method', 'Create payment method')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/payments">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
