import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Layout from '../Layout';
import {
    Button,
    Card,
    FeatureTile,
    FormErrors,
    Input,
    PageHeader,
    Select,
    SettingsCount,
    SettingsSectionHead,
    t,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.pricing_settings', 'Pricing') },
];

export default function Pricing(props) {
    const form = useForm({
        tax_inclusive_input: !!props.tax_inclusive_input,
        storefront_display: props.storefront_display || 'with_tax',
        decimal_precision: Number(props.decimal_precision ?? 2),
        rounding: props.rounding || 'none',
        zero_price: props.zero_price || 'allow',
        sale_enabled: props.sale_enabled !== false,
        compare_at_enabled: props.compare_at_enabled !== false,
        omnibus_enabled: !!props.omnibus_enabled,
        shipping_tax: props.shipping_tax !== false,
    });
    const formRef = useRef(form);
    formRef.current = form;
    const booted = useRef(false);
    const lastData = useRef(form.data);
    const lastSent = useRef('');
    const saveTimer = useRef(null);

    useEffect(() => {
        const snapshot = JSON.stringify(form.data);
        if (!booted.current) {
            booted.current = true;
            lastData.current = form.data;
            lastSent.current = snapshot;

            return undefined;
        }
        if (snapshot === lastSent.current) {
            return undefined;
        }

        const previous = lastData.current || {};
        lastData.current = form.data;
        const textOnly = JSON.stringify(form.data.decimal_precision) !== JSON.stringify(previous.decimal_precision)
            && Object.keys(form.data).filter((key) => JSON.stringify(form.data[key]) !== JSON.stringify(previous[key])).every((key) => key === 'decimal_precision');

        if (saveTimer.current) {
            clearTimeout(saveTimer.current);
        }
        saveTimer.current = setTimeout(() => {
            const current = formRef.current;
            lastSent.current = JSON.stringify(current.data);
            current.put('/admin/pricing-settings', {
                preserveScroll: true,
                preserveState: true,
            });
        }, textOnly ? 1000 : 0);

        return () => {
            if (saveTimer.current) {
                clearTimeout(saveTimer.current);
            }
        };
    }, [form.data]);

    const body = (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); }}>
            <FormErrors errors={form.errors} />
            <Card>
                <SettingsSectionHead
                    icon="tag"
                    title={t('admin.pricing_settings.tax', 'Tax and display')}
                    hint={t('admin.pricing_settings.tax_hint', 'How prices are entered and shown on the storefront.')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="tag"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.pricing_settings.inclusive', 'Price input is tax inclusive')}
                        hint={t('admin.pricing_settings.inclusive_hint', 'Catalog prices already include tax.')}
                        value={!!form.data.tax_inclusive_input}
                        onChange={(on) => form.setData('tax_inclusive_input', on)}
                    />
                    <FeatureTile
                        icon="eye"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.pricing_settings.display', 'Storefront price display')}
                        hint={t('admin.pricing_settings.display_hint', 'Show prices with tax, without tax, or both.')}
                        control={(
                            <Select className="w-40 shrink-0" value={form.data.storefront_display} onChange={(e) => form.setData('storefront_display', e.target.value)}>
                                <option value="with_tax">{t('admin.pricing_settings.with_tax', 'With tax')}</option>
                                <option value="without_tax">{t('admin.pricing_settings.without_tax', 'Without tax')}</option>
                                <option value="both">{t('admin.pricing_settings.both', 'Both')}</option>
                            </Select>
                        )}
                    />
                    <FeatureTile
                        icon="pin"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.pricing_settings.decimals', 'Decimal precision')}
                        hint={t('admin.pricing_settings.decimals_hint', 'Digits after the decimal point. 0 to 4.')}
                        control={(
                            <Input className="w-24 shrink-0" type="number" min="0" max="4" value={form.data.decimal_precision} onChange={(e) => form.setData('decimal_precision', Number(e.target.value))} />
                        )}
                    />
                    <FeatureTile
                        icon="refresh"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.pricing_settings.rounding', 'Rounding')}
                        hint={t('admin.pricing_settings.rounding_hint', 'How calculated prices are rounded.')}
                        control={(
                            <Select className="w-40 shrink-0" value={form.data.rounding} onChange={(e) => form.setData('rounding', e.target.value)}>
                                <option value="none">{t('admin.pricing_settings.round_none', 'None')}</option>
                                <option value="nearest">{t('admin.pricing_settings.round_nearest', 'Nearest')}</option>
                                <option value="up">{t('admin.pricing_settings.round_up', 'Up')}</option>
                                <option value="down">{t('admin.pricing_settings.round_down', 'Down')}</option>
                            </Select>
                        )}
                    />
                </div>
            </Card>
            <Card>
                <SettingsSectionHead
                    icon="star"
                    title={t('admin.pricing_settings.sale', 'Sale and zero price')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="tag"
                        tone="bg-rose-100 text-rose-600"
                        title={t('admin.pricing_settings.zero', 'Zero-price products')}
                        hint={t('admin.pricing_settings.zero_hint', 'Allow, hide the price, or show contact us.')}
                        control={(
                            <Select className="w-40 shrink-0" value={form.data.zero_price} onChange={(e) => form.setData('zero_price', e.target.value)}>
                                <option value="allow">{t('admin.pricing_settings.zero_allow', 'Allow')}</option>
                                <option value="hide">{t('admin.pricing_settings.zero_hide', 'Hide price')}</option>
                                <option value="contact">{t('admin.pricing_settings.zero_contact', 'Contact us')}</option>
                            </Select>
                        )}
                    />
                    <FeatureTile
                        icon="star"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.pricing_settings.sale_on', 'Sale pricing')}
                        hint={t('admin.pricing_settings.sale_on_hint', 'Let products use a sale price.')}
                        value={!!form.data.sale_enabled}
                        onChange={(on) => form.setData('sale_enabled', on)}
                    />
                    <FeatureTile
                        icon="price-tag"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.pricing_settings.compare_at', 'Compare-at / MSRP')}
                        hint={t('admin.pricing_settings.compare_at_hint', 'Show the original price next to the sale price.')}
                        value={!!form.data.compare_at_enabled}
                        onChange={(on) => form.setData('compare_at_enabled', on)}
                    />
                    <FeatureTile
                        icon="scale"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.pricing_settings.omnibus', 'EU Omnibus (30-day lowest)')}
                        hint={t('admin.pricing_settings.omnibus_hint', 'Show the lowest price in the last 30 days when a product is on sale.')}
                        value={!!form.data.omnibus_enabled}
                        onChange={(on) => form.setData('omnibus_enabled', on)}
                    />
                    <FeatureTile
                        icon="truck"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.pricing_settings.shipping_tax', 'Charge tax on shipping')}
                        hint={t('admin.pricing_settings.shipping_tax_hint', 'When enabled, each shipping method can use its own tax class. Shipping with no tax class is not taxed.')}
                        value={!!form.data.shipping_tax}
                        onChange={(on) => form.setData('shipping_tax', on)}
                    />
                </div>
                {props.embedded ? null : (
                    <div className="mt-4">
                        <Button type="button" variant="secondary" href="/admin/localization/currencies">
                            {t('admin.nav.currencies', 'Currencies')}
                        </Button>
                    </div>
                )}
            </Card>
        </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.pricing_settings.title', 'Pricing')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.pricing_settings.subtitle', 'How prices are entered and shown. Currencies stay under International.')}
            />
            {body}
        </Layout>
    );
}
