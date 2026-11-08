import { router, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import Layout from '../Layout';
import {
    Button,
    ChoiceCards,
    ChoiceMenu,
    Field,
    FormErrors,
    Input,
    PageHeader,
    SearchSelect,
    Select,
    Checkbox,
    ui,
    t,
} from '../../ui';

const emptyCondition = () => ({ type: 'product', value_ids: [] });

const CONDITION_META = {
    catalog: { mode: 'none', hint: t('admin.discounts.applies_to_every_product', 'Applies to every product.') },
    product: { mode: 'remote', searchUrl: '/admin/discounts/search/products', placeholder: 'Search products…', selectedKey: 'products' },
    category: { mode: 'remote', searchUrl: '/admin/discounts/search/categories', placeholder: 'Search categories…', selectedKey: 'categories' },
    brand: { mode: 'remote', searchUrl: '/admin/discounts/search/brands', placeholder: 'Search brands…', selectedKey: 'brands' },
    customer: { mode: 'remote', searchUrl: '/admin/discounts/search/customers', placeholder: 'Search customers…', selectedKey: 'customers' },
    customer_group: { mode: 'local', optionsKey: 'customer_groups', selectedKey: 'customer_groups', placeholder: 'Filter groups…' },
    country: { mode: 'local', optionsKey: 'countries', selectedKey: 'countries', placeholder: 'Filter countries…' },
    region: { mode: 'remote', searchUrl: '/admin/discounts/search/regions', placeholder: 'Search states / regions…', selectedKey: 'regions' },
};

function MoneyInput({ symbol, value, onChange, invalid, ...props }) {
    return (
        <div className={ui.inputGroup}>
            <span className={ui.inputPrefix}>{symbol}</span>
            <Input
                unstyled
                className={ui.inputBare}
                type="number"
                min="0"
                step="0.01"
                invalid={invalid}
                value={value}
                onChange={onChange}
                {...props}
            />
        </div>
    );
}

export default function Form({
    discount,
    applyAs = [],
    actions = [],
    conditionTypes = [],
    options = {},
    selected = {},
    defaultCurrency = null,
}) {
    const currencyOptions = options.currencies || [];
    const defaultCode = discount?.currency_code
        || defaultCurrency?.code
        || currencyOptions[0]?.code
        || 'USD';

    const form = useForm({
        name: discount?.name || '',
        apply_as: discount?.apply_as || 'catalog',
        code: discount?.code || '',
        action: discount?.action || 'percent',
        value: discount?.value ?? 10,
        currency_code: defaultCode,
        priority: discount?.priority ?? 100,
        stop_further: discount?.stop_further ?? false,
        min_cart: discount?.min_cart ?? '',
        min_qty: discount?.min_qty ?? '',
        max_discount: discount?.max_discount ?? '',
        max_uses: discount?.max_uses ?? '',
        max_uses_per_customer: discount?.max_uses_per_customer ?? '',
        starts_at: discount?.starts_at || '',
        expires_at: discount?.expires_at || '',
        is_enabled: discount?.is_enabled ?? true,
        conditions: (discount?.conditions || []).filter((row) => row.type !== 'currency'),
    });

    const selectedCurrency = useMemo(
        () => currencyOptions.find((opt) => String(opt.code).toUpperCase() === String(form.data.currency_code).toUpperCase())
            || defaultCurrency
            || currencyOptions[0]
            || null,
        [currencyOptions, defaultCurrency, form.data.currency_code],
    );

    const currencySymbol = selectedCurrency?.symbol || selectedCurrency?.code || '$';

    const actionOptions = useMemo(() => {
        if (form.data.apply_as === 'catalog') {
            return actions.filter((opt) => opt.value !== 'buy_x_get_y');
        }
        return actions.filter((opt) => opt.value !== 'set_price');
    }, [actions, form.data.apply_as]);

    const visibleConditionTypes = useMemo(
        () => conditionTypes.filter((opt) => opt.value !== 'currency'),
        [conditionTypes],
    );

    const moneyAction = form.data.action === 'amount' || form.data.action === 'set_price';

    const setApplyAs = (value) => {
        const next = { apply_as: value };
        if (value !== 'coupon') {
            next.code = '';
        }
        if (value !== 'catalog' && form.data.action === 'set_price') {
            next.action = 'percent';
        }
        if (value === 'catalog' && form.data.action === 'buy_x_get_y') {
            next.action = 'percent';
        }
        form.setData({ ...form.data, ...next });
    };

    const setCondition = (index, patch) => {
        form.setData('conditions', form.data.conditions.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const addCondition = () => {
        form.setData('conditions', [...form.data.conditions, emptyCondition()]);
    };

    const removeCondition = (index) => {
        form.setData('conditions', form.data.conditions.filter((_, i) => i !== index));
    };

    const submit = (e) => {
        e.preventDefault();
        if (discount) {
            form.put(`/admin/discounts/${discount.id}`);
        } else {
            form.post('/admin/discounts');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={discount ? t('admin.discounts.edit_discount', 'Edit discount') : t('admin.discounts.add_discount', 'Add discount')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/discounts', label: t('admin.discounts.discounts', 'Discounts') },
                    { label: discount ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    discount ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (window.confirm(t('admin.discounts.delete_this_discount', 'Delete this discount?'))) {
                                    router.delete(`/admin/discounts/${discount.id}`);
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
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.basic', 'Basic')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.discounts.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.discounts.e_g_summer_sale', 'e.g. Summer sale')}
                                />
                            </Field>
                            <div className={ui.fieldGrid}>
                                <Field label={t('admin.discounts.starts', 'Starts')} error={form.errors.starts_at}>
                                    <Input
                                        wide
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(e) => form.setData('starts_at', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('admin.discounts.expires', 'Expires')} error={form.errors.expires_at}>
                                    <Input
                                        wide
                                        type="datetime-local"
                                        value={form.data.expires_at}
                                        onChange={(e) => form.setData('expires_at', e.target.value)}
                                    />
                                </Field>
                            </div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.apply_as', 'Apply as')}</h2>
                        </div>
                        <ChoiceCards name="apply_as" options={applyAs} value={form.data.apply_as} onChange={setApplyAs} />
                        {form.data.apply_as === 'coupon' ? (
                            <div className={`${ui.blockGap} max-w-sm`}>
                                <Field label={t('admin.discounts.coupon_code', 'Coupon code')} error={form.errors.code}>
                                    <Input
                                        wide
                                        invalid={!!form.errors.code}
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                        placeholder={t('admin.discounts.save10', 'SAVE10')}
                                    />
                                </Field>
                            </div>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.action', 'Action')}</h2>
                        </div>
                        <ChoiceCards
                            name="action"
                            options={actionOptions}
                            value={form.data.action}
                            onChange={(value) => form.setData('action', value)}
                        />
                        <div className={`${ui.blockGap} grid gap-3 sm:grid-cols-2 max-w-xl`}>
                            <Field label={t('admin.discounts.currency', 'Currency')} error={form.errors.currency_code} required>
                                {currencyOptions.length === 0 ? (
                                    <p className={ui.muted}>
                                        {t('admin.discounts.no_currencies_enabled', 'No enabled currencies found. Enable currencies under International first.')}
                                    </p>
                                ) : (
                                    <Select
                                        wide
                                        invalid={!!form.errors.currency_code}
                                        value={form.data.currency_code}
                                        onChange={(e) => form.setData('currency_code', e.target.value)}
                                    >
                                        {currencyOptions.map((opt) => (
                                            <option key={opt.code || opt.id} value={opt.code}>
                                                {opt.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                            <Field
                                label={form.data.action === 'percent'
                                    ? t('admin.discounts.percent_off', 'Percent off')
                                    : form.data.action === 'set_price'
                                        ? t('admin.discounts.set_price_to', 'Set price to')
                                        : form.data.action === 'buy_x_get_y'
                                            ? t('admin.discounts.buy_qty', 'Buy quantity (get 1 free)')
                                            : t('admin.discounts.amount_off', 'Amount off')}
                                error={form.errors.value}
                            >
                                {moneyAction ? (
                                    <MoneyInput
                                        symbol={currencySymbol}
                                        invalid={!!form.errors.value}
                                        value={form.data.value}
                                        onChange={(e) => form.setData('value', e.target.value)}
                                    />
                                ) : (
                                    <Input
                                        wide
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        invalid={!!form.errors.value}
                                        value={form.data.value}
                                        onChange={(e) => form.setData('value', e.target.value)}
                                    />
                                )}
                            </Field>
                        </div>
                        <p className={`${ui.metaK} mt-3`}>
                            {t('admin.discounts.currency_convert_hint', 'Amounts are saved in this currency. On the storefront they convert automatically when the shopper changes currency.')}
                        </p>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.conditions', 'Conditions')}</h2>
                            <Button type="button" variant="secondary" icon="plus" onClick={addCondition}>
                                {t('admin.discounts.add_condition', 'Add condition')}
                            </Button>
                        </div>
                        <p className={`${ui.metaK} mb-4`}>
                            {t('admin.discounts.all_conditions_must_match_leave_this_list_empty_', 'All conditions must match. Leave this list empty to apply to the whole catalog.')}
                        </p>
                        {form.data.conditions.length === 0 ? (
                            <p className={ui.muted}>{t('admin.discounts.whole_catalog_every_product_is_eligible', 'Whole catalog — every product is eligible.')}</p>
                        ) : (
                            <div className={ui.stack}>
                                {form.data.conditions.map((row, index) => {
                                    const meta = CONDITION_META[row.type] || CONDITION_META.product;
                                    const groupConditionOff = row.type === 'customer_group' && !visibleConditionTypes.some((opt) => opt.value === 'customer_group');
                                    return (
                                        <div key={`${row.type}-${index}`} className="rounded-xl border border-neutral-200 bg-white p-4 space-y-3">
                                            <div className={ui.fieldGrid}>
                                                <Field label={t('admin.discounts.condition', 'Condition')}>
                                                    {groupConditionOff ? (
                                                        <p className={ui.metaK}>
                                                            {t('admin.discounts.customer_group_off', 'Customer group is turned off under Configure → Customers. Remove this condition, or turn Group-specific discounts back on.')}
                                                        </p>
                                                    ) : (
                                                        <ChoiceMenu
                                                            className="w-full min-w-0"
                                                            ariaLabel={t('admin.discounts.condition', 'Condition')}
                                                            value={row.type}
                                                            options={visibleConditionTypes}
                                                            onChange={(type) => setCondition(index, { type, value_ids: [] })}
                                                        />
                                                    )}
                                                </Field>
                                                <div className="flex items-end justify-end">
                                                    <Button type="button" variant="danger" onClick={() => removeCondition(index)}>
                                                        {t('admin.common.remove', 'Remove')}
                                                    </Button>
                                                </div>
                                            </div>
                                            {groupConditionOff ? null : meta.mode === 'none' ? (
                                                <p className={ui.metaK}>{meta.hint}</p>
                                            ) : meta.mode === 'remote' ? (
                                                <SearchSelect
                                                    label={t('admin.discounts.values', 'Values')}
                                                    searchUrl={meta.searchUrl}
                                                    value={row.value_ids}
                                                    selectedOptions={selected[meta.selectedKey] || []}
                                                    onChange={(value_ids) => setCondition(index, { value_ids })}
                                                    placeholder={meta.placeholder}
                                                    error={form.errors[`conditions.${index}.value_ids`]}
                                                />
                                            ) : (
                                                <SearchSelect
                                                    label={t('admin.discounts.values', 'Values')}
                                                    options={options[meta.optionsKey] || []}
                                                    value={row.value_ids}
                                                    selectedOptions={selected[meta.selectedKey] || []}
                                                    onChange={(value_ids) => setCondition(index, { value_ids })}
                                                    placeholder={meta.placeholder}
                                                    error={form.errors[`conditions.${index}.value_ids`]}
                                                />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.limits', 'Limits')}</h2>
                        </div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.discounts.minimum_cart', 'Minimum cart')} error={form.errors.min_cart}>
                                <MoneyInput
                                    symbol={currencySymbol}
                                    value={form.data.min_cart}
                                    onChange={(e) => form.setData('min_cart', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.discounts.minimum_quantity', 'Minimum quantity')} error={form.errors.min_qty}>
                                <Input
                                    wide
                                    type="number"
                                    min="1"
                                    step="1"
                                    value={form.data.min_qty}
                                    onChange={(e) => form.setData('min_qty', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.discounts.maximum_discount', 'Maximum discount')} error={form.errors.max_discount}>
                                <MoneyInput
                                    symbol={currencySymbol}
                                    value={form.data.max_discount}
                                    onChange={(e) => form.setData('max_discount', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.discounts.maximum_uses', 'Maximum uses')} error={form.errors.max_uses}>
                                <Input
                                    wide
                                    type="number"
                                    min="1"
                                    step="1"
                                    value={form.data.max_uses}
                                    onChange={(e) => form.setData('max_uses', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.discounts.maximum_uses_per_customer', 'Maximum uses per customer')} error={form.errors.max_uses_per_customer}>
                                <Input
                                    wide
                                    type="number"
                                    min="1"
                                    step="1"
                                    value={form.data.max_uses_per_customer}
                                    onChange={(e) => form.setData('max_uses_per_customer', e.target.value)}
                                />
                            </Field>
                            <Field label={t('admin.discounts.priority_lower_runs_first', 'Priority (lower runs first)')} error={form.errors.priority}>
                                <Input
                                    wide
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.priority}
                                    onChange={(e) => form.setData('priority', e.target.value)}
                                />
                            </Field>
                        </div>
                        <div className={ui.sectionRule}>
                            <Checkbox
                                label={t('admin.discounts.stop_further_rules', 'Stop further rules')}
                                checked={!!form.data.stop_further}
                                onChange={(e) => form.setData('stop_further', e.target.checked)}
                            />
                            <p className={`${ui.metaK} mt-2`}>
                                {t('admin.discounts.after_this_rule_matches_later_rules_in_the_same_', 'After this rule matches, later rules in the same layer are ignored. The best remaining reduction still wins.')}
                            </p>
                        </div>
                    </section>
                </div>

                <aside className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.discounts.status', 'Status')}</h2>
                        </div>
                        <Checkbox
                            label={t('admin.common.active', 'Active')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <p className={`${ui.metaK} mt-2`}>{t('admin.discounts.inactive_discounts_never_apply_at_catalog_or_che', 'Inactive discounts never apply at catalog or checkout.')}</p>
                        <div className={`${ui.actionList} mt-4`}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {discount ? t('admin.common.save', 'Save changes') : t('admin.discounts.create_discount', 'Create discount')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/discounts">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </aside>
            </form>
        </Layout>
    );
}
