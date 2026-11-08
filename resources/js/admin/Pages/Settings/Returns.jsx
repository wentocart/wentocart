import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import {
    Card,
    ChoiceMenu,
    FeatureTile,
    FormErrors,
    Input,
    PageHeader,
    SettingsCount,
    SettingsSectionHead,
    t,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.return_settings', 'Returns & Refunds') },
];

export default function Returns(props) {
    const form = useForm({
        enabled: props.enabled !== false,
        window_days: Number(props.window_days ?? 14),
        reason_required: props.reason_required !== false,
        approval: props.approval || 'required',
        partial: props.partial !== false,
        refund_method: props.refund_method === 'voucher' ? 'voucher' : 'credit_slip',
        refund_shipping: props.refund_shipping === 'yes' ? 'yes' : 'no',
        restock: props.restock || 'automatic',
        restocking_fee: !!props.restocking_fee,
        restocking_fee_percent: Number(props.restocking_fee_percent ?? 10),
    });

    useSaveOnChange(form, '/admin/return-settings', {
        textKeys: ['window_days', 'restocking_fee_percent'],
    });

    const returnsOn = !!form.data.enabled;
    const body = (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); }}>
            <FormErrors errors={form.errors} />
            <Card>
                <SettingsSectionHead
                    icon="package"
                    title={t('admin.return_settings.policy', 'Policy')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: returnsOn ? 5 : 1 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="package"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.return_settings.enabled', 'Allow customer return')}
                        hint={t('admin.return_settings.enabled_hint', 'Customers can request a return from their order.')}
                        value={returnsOn}
                        onChange={(on) => form.setData('enabled', on)}
                    />
                    {returnsOn ? (
                        <>
                            <FeatureTile
                                icon="calendar"
                                tone="bg-sky-100 text-sky-600"
                                title={t('admin.return_settings.window', 'Return window (days)')}
                                hint={t('admin.return_settings.window_hint', 'Days after the order that a return can be opened. 0 means no limit.')}
                                control={(
                                    <Input className="w-24 shrink-0" type="number" min="0" value={form.data.window_days} onChange={(e) => form.setData('window_days', Number(e.target.value))} />
                                )}
                            />
                            <FeatureTile
                                icon="file"
                                tone="bg-amber-100 text-amber-600"
                                title={t('admin.return_settings.reason', 'Return reason required')}
                                hint={t('admin.return_settings.reason_hint', 'The customer must give a reason.')}
                                value={!!form.data.reason_required}
                                onChange={(on) => form.setData('reason_required', on)}
                            />
                            <FeatureTile
                                icon="shield"
                                tone="bg-emerald-100 text-emerald-600"
                                title={t('admin.return_settings.approval', 'Admin approval')}
                                hint={t('admin.return_settings.approval_hint', 'Required waits for a staff decision. Automatic accepts the return as soon as the customer sends it.')}
                                control={(
                                    <ChoiceMenu
                                        className="w-40 shrink-0"
                                        ariaLabel={t('admin.return_settings.approval', 'Admin approval')}
                                        value={form.data.approval}
                                        options={[
                                            { value: 'required', label: t('admin.return_settings.approval_required', 'Required') },
                                            { value: 'automatic', label: t('admin.return_settings.approval_auto', 'Automatic') },
                                        ]}
                                        onChange={(value) => form.setData('approval', value)}
                                    />
                                )}
                            />
                            <FeatureTile
                                icon="layers"
                                tone="bg-indigo-100 text-indigo-600"
                                title={t('admin.return_settings.partial', 'Partial returns')}
                                hint={t('admin.return_settings.partial_hint', 'Allow returning some lines from an order.')}
                                value={!!form.data.partial}
                                onChange={(on) => form.setData('partial', on)}
                            />
                        </>
                    ) : null}
                </div>
            </Card>
            {returnsOn ? (
                <Card>
                    <SettingsSectionHead
                        icon="orders"
                        title={t('admin.return_settings.refund', 'Refund and restock')}
                        extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                    />
                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="card"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.return_settings.method', 'Refund method')}
                            hint={t('admin.return_settings.method_hint', 'A credit slip records the refund on the order. A voucher is a one-use coupon the customer can spend later.')}
                            control={(
                                <ChoiceMenu
                                    className="w-40 shrink-0"
                                    ariaLabel={t('admin.return_settings.method', 'Refund method')}
                                    value={form.data.refund_method}
                                    options={[
                                        { value: 'credit_slip', label: t('admin.return_settings.credit_slip', 'Credit slip') },
                                        { value: 'voucher', label: t('admin.return_settings.voucher', 'Voucher') },
                                    ]}
                                    onChange={(value) => form.setData('refund_method', value)}
                                />
                            )}
                        />
                        <FeatureTile
                            icon="truck"
                            tone="bg-amber-100 text-amber-600"
                            title={t('admin.return_settings.ship_cost', 'Refund shipping cost')}
                            hint={t('admin.return_settings.ship_cost_hint', 'Yes adds the original shipping charge to the refund. No leaves it out.')}
                            control={(
                                <ChoiceMenu
                                    className="w-28 shrink-0"
                                    ariaLabel={t('admin.return_settings.ship_cost', 'Refund shipping cost')}
                                    value={form.data.refund_shipping}
                                    options={[
                                        { value: 'yes', label: t('admin.common.yes', 'Yes') },
                                        { value: 'no', label: t('admin.common.no', 'No') },
                                    ]}
                                    onChange={(value) => form.setData('refund_shipping', value)}
                                />
                            )}
                        />
                        <FeatureTile
                            icon="package"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.return_settings.restock', 'Restock returned items')}
                            hint={t('admin.return_settings.restock_hint', 'Automatic puts the units back in stock when the return is refunded. Manual leaves stock unchanged so you can edit it yourself.')}
                            control={(
                                <ChoiceMenu
                                    className="w-36 shrink-0"
                                    ariaLabel={t('admin.return_settings.restock', 'Restock returned items')}
                                    value={form.data.restock}
                                    options={[
                                        { value: 'automatic', label: t('admin.return_settings.restock_auto', 'Automatic') },
                                        { value: 'manual', label: t('admin.return_settings.restock_manual', 'Manual') },
                                    ]}
                                    onChange={(value) => form.setData('restock', value)}
                                />
                            )}
                        />
                        <FeatureTile
                            icon="tag"
                            tone="bg-rose-100 text-rose-600"
                            title={t('admin.return_settings.fee', 'Restocking fee')}
                            hint={t('admin.return_settings.fee_hint', 'When this is on, that percent of the returned item total is taken off the refund. Stock is not changed by this fee.')}
                            value={!!form.data.restocking_fee}
                            onChange={(on) => form.setData('restocking_fee', on)}
                        >
                            {form.data.restocking_fee ? (
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-neutral-700">{t('admin.return_settings.fee_percent', 'Fee percent')}</span>
                                    <Input className="w-24 shrink-0" type="number" min="0" max="100" step="0.01" value={form.data.restocking_fee_percent} onChange={(e) => form.setData('restocking_fee_percent', Number(e.target.value))} />
                                </div>
                            ) : null}
                        </FeatureTile>
                    </div>
                </Card>
            ) : null}
        </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.return_settings.title', 'Returns & Refunds')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.return_settings.subtitle', 'Global return policy. Order cancellation stays on Order Settings.')}
            />
            {body}
        </Layout>
    );
}
