import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Layout from '../Layout';
import {
    Button,
    Card,
    FeatureTile,
    FormErrors,
    PageHeader,
    Select,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    t,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.inventory_settings', 'Inventory') },
];

export default function InventoryEngine({ linked = {}, ...props }) {
    const form = useForm({
        enabled: props.enabled !== false,
        allow_negative: !!props.allow_negative,
        notifications: props.notifications !== false,
        scope: props.scope || 'global',
    });
    const formRef = useRef(form);
    formRef.current = form;
    const booted = useRef(false);
    const lastSent = useRef('');

    useEffect(() => {
        const snapshot = JSON.stringify(form.data);
        if (!booted.current) {
            booted.current = true;
            lastSent.current = snapshot;

            return undefined;
        }
        if (snapshot === lastSent.current) {
            return undefined;
        }
        lastSent.current = snapshot;
        formRef.current.put('/admin/inventory-settings', {
            preserveScroll: true,
            preserveState: true,
        });

        return undefined;
    }, [form.data]);

    const body = (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
            }}
        >
            <FormErrors errors={form.errors} />
            <Card>
                <SettingsSectionHead
                    icon="package"
                    title={t('admin.inventory_settings.panel', 'Stock engine')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 4 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="package"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.inventory_settings.enabled', 'Inventory management')}
                        hint={t('admin.inventory_settings.enabled_hint', 'On: each sale checks quantity and reduces it. Off: orders do not change stock.')}
                        value={!!form.data.enabled}
                        onChange={(on) => form.setData('enabled', on)}
                    />
                    <FeatureTile
                        icon="alert-triangle"
                        tone="bg-rose-100 text-rose-600"
                        title={t('admin.inventory_settings.negative', 'Allow negative stock')}
                        hint={t('admin.inventory_settings.negative_hint', 'Storefront and checkout can still sell after quantity hits zero. The saved quantity can go below zero.')}
                        value={!!form.data.allow_negative}
                        onChange={(on) => form.setData('allow_negative', on)}
                    />
                    <FeatureTile
                        icon="bell"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.inventory_settings.notifications', 'Inventory notifications')}
                        hint={t('admin.inventory_settings.notifications_hint', 'The admin bell rings when a tracked product hits its low-stock level. The store email gets the same notice on the hourly check.')}
                        value={!!form.data.notifications}
                        onChange={(on) => form.setData('notifications', on)}
                    />
                    <FeatureTile
                        icon="layers"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.inventory_settings.scope', 'Inventory scope')}
                        hint={t('admin.inventory_settings.scope_warehouse_hint', 'Global always takes stock from the default warehouse. Warehouse takes it from the location that has enough quantity.')}
                        control={(
                            <Select className="w-40 shrink-0" value={form.data.scope === 'shop' ? 'global' : form.data.scope} onChange={(e) => form.setData('scope', e.target.value)}>
                                <option value="global">{t('admin.inventory_settings.scope_global', 'Global')}</option>
                                <option value="warehouse">{t('admin.inventory_settings.scope_warehouse', 'Warehouse')}</option>
                            </Select>
                        )}
                    />
                </div>
            </Card>
            {props.embedded ? null : (
                <Card>
                    <SettingsSectionHead
                        icon="link"
                        title={t('admin.inventory_settings.owned', 'Owned on other pages')}
                        hint={t('admin.inventory_settings.owned_hint', 'One setting, one owner.')}
                    />
                    <SettingsNote>
                        {t('admin.inventory_settings.product_link', 'Low-stock alert, out-of-stock behavior, and backorders: Product Settings.')}
                        {' '}
                        {t('admin.inventory_settings.order_link', 'Deduct at order/payment/processing, unpaid reservation, restore on cancel: Order Settings.')}
                    </SettingsNote>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" href="/admin/product-settings">
                            {t('admin.nav.product_settings', 'Product Settings')}
                        </Button>
                        <Button type="button" variant="secondary" href="/admin/order-settings">
                            {t('admin.nav.order_settings', 'Order Settings')}
                        </Button>
                    </div>
                </Card>
            )}
        </form>
    );

    if (props.embedded) {
        return body;
    }

    return (
        <Layout>
            <PageHeader
                title={t('admin.inventory_settings.title', 'Inventory')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.inventory_settings.subtitle', 'Store-wide stock engine. Product pages own product defaults. Orders own deduction timing.')}
            />
            {body}
        </Layout>
    );
}
