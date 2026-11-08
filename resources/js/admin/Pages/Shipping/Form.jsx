import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    ChoiceCards,
    Field,
    FormErrors,
    Input,
    PageHeader,
    RateTiersField,
    SearchSelect,
    Select,
    Textarea,
    ui,
    t,
} from '../../ui';

const emptyConditions = () => ({
    min_order: '',
    max_order: '',
    min_weight: '',
    max_weight: '',
    customer_group_ids: [],
    country_ids: [],
    zone_ids: [],
});

export default function Form({ method, types = [], options = {}, selected = {}, shipping_tax_enabled = false }) {
    const shippingTaxEnabled = !!shipping_tax_enabled;
    const form = useForm({
        name: method?.name || '',
        description: method?.description || '',
        type: method?.type || 'flat',
        driver: method?.driver || 'sandbox',
        price: method?.price ?? 0,
        tax_class_id: method?.tax_class_id ?? '',
        is_enabled: method?.is_enabled ?? true,
        min_days: method?.min_days ?? '',
        max_days: method?.max_days ?? '',
        tiers: method?.tiers?.length ? method.tiers : [{ min: '', max: '', price: '' }],
        conditions: {
            ...emptyConditions(),
            ...(method?.conditions || {}),
            min_order: method?.conditions?.min_order ?? '',
            max_order: method?.conditions?.max_order ?? '',
            min_weight: method?.conditions?.min_weight ?? '',
            max_weight: method?.conditions?.max_weight ?? '',
        },
    });

    const setCondition = (key, value) => {
        form.setData('conditions', { ...form.data.conditions, [key]: value });
    };

    const needsTiers = form.data.type === 'price_based' || form.data.type === 'weight_based';
    const needsPrice = form.data.type === 'flat' || form.data.type === 'local_pickup' || form.data.type === 'carrier';

    const submit = (e) => {
        e.preventDefault();
        // Do not send tax_class_id while charge-tax-on-shipping is off (server preserves stored value).
        form.transform((data) => {
            if (shippingTaxEnabled) {
                return data;
            }
            const next = { ...data };
            delete next.tax_class_id;
            return next;
        });
        if (method) {
            form.put(`/admin/shipping/${method.id}`);
        } else {
            form.post('/admin/shipping');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={method ? t('admin.shipping.edit_shipping_method', 'Edit shipping method') : t('admin.shipping.add_shipping_method', 'Add shipping method')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/shipping', label: t('admin.shipping.shipping_methods', 'Shipping methods') },
                    { label: method ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    method ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.shipping.delete_this_shipping_method', 'Delete this shipping method?'))) {
                                    router.delete(`/admin/shipping/${method.id}`);
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
                            <h2 className={ui.sectionTitle}>{t('admin.shipping.basic', 'Basic')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.shipping.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.shipping.e_g_standard_shipping', 'e.g. Standard shipping')}
                                />
                            </Field>
                            <Field label={t('admin.shipping.description', 'Description')} error={form.errors.description}>
                                <Textarea
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder={t('admin.shipping.shown_to_the_customer_at_checkout', 'Shown to the customer at checkout')}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.shipping.rate_type', 'Rate type')}</h2>
                        </div>
                        <ChoiceCards
                            name="type"
                            options={types}
                            value={form.data.type}
                            onChange={(type) => form.setData('type', type)}
                        />
                        {form.errors.type ? <p className={ui.fieldError}>{form.errors.type}</p> : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.shipping.rate', 'Rate')}</h2>
                        </div>
                        {needsPrice ? (
                            <Field label={t('admin.shipping.price', 'Price')} error={form.errors.price}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.price}
                                    onChange={(e) => form.setData('price', e.target.value)}
                                />
                                <p className={`${ui.metaK} mt-1`}>{t('admin.shipping.added_to_the_order_as_shipping_charges', 'Added to the order as shipping charges.')}</p>
                            </Field>
                        ) : null}
                        {shippingTaxEnabled ? (
                            <Field label={t('admin.shipping.tax_class', 'Tax class')} error={form.errors.tax_class_id}>
                                <Select
                                    wide
                                    value={form.data.tax_class_id ?? ''}
                                    onChange={(e) => form.setData('tax_class_id', e.target.value)}
                                >
                                    <option value="">{t('admin.shipping.tax_class_none', 'No tax on this shipping method')}</option>
                                    {(options.tax_classes || []).map((item) => (
                                        <option key={item.id} value={item.id}>{item.name}</option>
                                    ))}
                                </Select>
                                <p className={`${ui.metaK} mt-1`}>
                                    {t(
                                        'admin.shipping.tax_class_hint',
                                        'Applies only to this shipping method. Leave empty for no shipping tax.',
                                    )}
                                </p>
                            </Field>
                        ) : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label={t('admin.shipping.min_days', 'Min delivery days')}>
                                <Input wide type="number" min="0" value={form.data.min_days} onChange={(e) => form.setData('min_days', e.target.value)} />
                            </Field>
                            <Field label={t('admin.shipping.max_days', 'Max delivery days')}>
                                <Input wide type="number" min="0" value={form.data.max_days} onChange={(e) => form.setData('max_days', e.target.value)} />
                            </Field>
                        </div>
                        {form.data.type === 'carrier' ? (
                            <p className={`${ui.metaK} mt-1`}>{t('admin.shipping.carrier_sandbox_hint', 'Uses the built-in sandbox carrier until you install a live FedEx or DHL extension.')}</p>
                        ) : null}
                        {form.data.type === 'free' ? (
                            <p className={ui.muted}>{t('admin.shipping.shipping_fee_is_0_use_conditions_to_require_a_mi', 'Shipping fee is 0. Use conditions to require a minimum order if needed.')}</p>
                        ) : null}
                        {needsTiers ? (
                            <>
                                <RateTiersField
                                    value={form.data.tiers}
                                    onChange={(tiers) => form.setData('tiers', tiers)}
                                    minLabel={form.data.type === 'weight_based' ? t('admin.shipping.from_kg', 'From (kg)') : t('admin.shipping.from_order', 'From (order)')}
                                    maxLabel={form.data.type === 'weight_based' ? t('admin.shipping.to_kg', 'To (kg)') : t('admin.shipping.to_order', 'To (order)')}
                                    priceLabel={t('admin.shipping.shipping_fee', 'Shipping fee')}
                                />
                                {form.errors.tiers ? <p className={ui.fieldError}>{form.errors.tiers}</p> : null}
                            </>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.shipping.conditions', 'Conditions')}</h2>
                        </div>
                        <p className={`${ui.metaK} mb-4`}>{t('admin.shipping.conditions_hint', 'Order value, weight, and customer group limits are optional. Leave them empty to allow every order.')}</p>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.shipping.minimum_order_value', 'Minimum order value')} error={form.errors['conditions.min_order']}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.conditions.min_order}
                                    onChange={(e) => setCondition('min_order', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.shipping.maximum_order_value', 'Maximum order value')} error={form.errors['conditions.max_order']}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.conditions.max_order}
                                    onChange={(e) => setCondition('max_order', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.shipping.minimum_weight_kg', 'Minimum weight (kg)')} error={form.errors['conditions.min_weight']}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.conditions.min_weight}
                                    onChange={(e) => setCondition('min_weight', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.shipping.maximum_weight_kg', 'Maximum weight (kg)')} error={form.errors['conditions.max_weight']}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.conditions.max_weight}
                                    onChange={(e) => setCondition('max_weight', e.target.value)}
                                />
                            </Field>
                            <SearchSelect
                                label={t('admin.shipping.customer_groups', 'Customer groups')}
                                options={options.customer_groups || []}
                                value={form.data.conditions.customer_group_ids}
                                selectedOptions={selected.customer_groups || []}
                                onChange={(v) => setCondition('customer_group_ids', v)}
                                placeholder={t('admin.shipping.filter_groups', 'Filter groups…')}
                            />
                            <div className={ui.fieldSpan2}>
                                <p className={ui.sectionTitle}>{t('admin.shipping.ships_to', 'Ships to')}</p>
                                <p className={`${ui.metaK} mb-3`}>
                                    {t('admin.shipping.zone_explain', 'Zones live under International. Each country belongs to one zone. Choose the zones this method delivers to. Leave this empty to offer it in every country.')}
                                </p>
                                {(options.zones || []).length ? (
                                    <SearchSelect
                                        label={t('admin.shipping.zones', 'Shipping zones')}
                                        options={options.zones || []}
                                        value={form.data.conditions.zone_ids || []}
                                        selectedOptions={selected.zones || []}
                                        onChange={(v) => form.setData('conditions', { ...form.data.conditions, zone_ids: v, country_ids: v.length ? [] : form.data.conditions.country_ids })}
                                        placeholder={t('admin.shipping.filter_zones', 'Filter zones…')}
                                    />
                                ) : (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <p className={ui.muted}>{t('admin.shipping.no_zones_on_method', 'No shipping zones yet. This method ships to every country until you create one and select it.')}</p>
                                        <Button type="button" variant="secondary" href="/admin/localization/zones">{t('admin.shipping.add_zone', 'Add zone')}</Button>
                                    </div>
                                )}
                                {(form.data.conditions.country_ids || []).length && !(form.data.conditions.zone_ids || []).length ? (
                                    <p className={`${ui.metaK} mt-3`}>
                                        {t('admin.shipping.legacy_countries', 'This method is still limited to specific countries from an earlier setup. Choose a shipping zone to replace that list.')}
                                        {' '}
                                        <button type="button" className="font-semibold text-violet-700" onClick={() => setCondition('country_ids', [])}>
                                            {t('admin.shipping.clear_country_limit', 'Ship to every country')}
                                        </button>
                                    </p>
                                ) : null}
                            </div>
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.shipping.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>{t('admin.shipping.inactive_methods_are_hidden_at_checkout', 'Inactive methods are hidden at checkout.')}</p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {method ? t('admin.common.save', 'Save changes') : t('admin.shipping.create_shipping_method', 'Create shipping method')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/shipping">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
