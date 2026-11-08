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
    Select,
    SettingsCount,
    SettingsNote,
    SettingsSectionHead,
    Button,
    t,
} from '../../ui';

const crumbs = [
    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
    { label: t('admin.nav.configure', 'Configure') },
    { label: t('admin.nav.cart_checkout', 'Cart & Checkout') },
];

export default function CartCheckout(props) {
    const pages = Array.isArray(props.cms_pages) ? props.cms_pages : [];
    const pageOptions = [
        { value: '', label: t('admin.cart_checkout.terms_page_none', 'Select a page') },
        ...pages.map((page) => ({
            value: String(page.id),
            label: page.slug ? `${page.title} (/${page.slug})` : page.title,
        })),
    ];
    const currencySymbol = props.currency?.symbol || props.currency?.code || '';
    const form = useForm({
        cart_expiry_minutes: Number(props.cart_expiry_minutes ?? 10080),
        max_cart_items: Number(props.max_cart_items ?? 50),
        coupons_enabled: props.coupons_enabled !== false,
        terms_required: !!props.terms_required,
        terms_cms_page_id: props.terms_cms_page_id ? String(props.terms_cms_page_id) : '',
        stock_validation: props.stock_validation !== false,
        unavailable_items: props.unavailable_items || 'keep',
        layout: props.layout || 'one_page',
        gift_wrap_enabled: !!props.gift_wrap_enabled,
        gift_wrap_fee: Number(props.gift_wrap_fee ?? 0),
    });

    useSaveOnChange(form, '/admin/cart-settings', {
        textKeys: ['cart_expiry_minutes', 'max_cart_items', 'gift_wrap_fee'],
        transform: (data) => ({
            ...data,
            terms_cms_page_id: data.terms_cms_page_id ? Number(data.terms_cms_page_id) : null,
        }),
    });

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
                    icon="bag"
                    title={t('admin.cart_checkout.panel_cart', 'Cart')}
                    hint={t('admin.cart_checkout.panel_cart_hint', 'How long a cart lives and how large it may grow.')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 3 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="clock"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.cart_checkout.expiry', 'Cart expiration (minutes)')}
                        hint={form.errors.cart_expiry_minutes || t('admin.cart_checkout.expiry_hint', 'Unused carts are cleared after this many minutes. 60 minutes = 1 hour.')}
                        control={(
                            <Input className="w-28 shrink-0" type="number" min="1" value={form.data.cart_expiry_minutes} onChange={(e) => form.setData('cart_expiry_minutes', Number(e.target.value))} />
                        )}
                    />
                    <FeatureTile
                        icon="layers"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.cart_checkout.max_items', 'Maximum cart items')}
                        hint={form.errors.max_cart_items || t('admin.cart_checkout.max_items_hint', 'Most items one cart can hold. Every unit counts, and checkout is blocked once the cart is over this number.')}
                        control={(
                            <Input className="w-24 shrink-0" type="number" min="1" value={form.data.max_cart_items} onChange={(e) => form.setData('max_cart_items', Number(e.target.value))} />
                        )}
                    />
                    <FeatureTile
                        icon="package"
                        tone="bg-amber-100 text-amber-600"
                        title={t('admin.cart_checkout.unavailable', 'Unavailable items')}
                        hint={t('admin.cart_checkout.unavailable_hint', 'What happens to a line that can no longer be sold.')}
                        control={(
                            <Select className="w-44 shrink-0" value={form.data.unavailable_items} onChange={(e) => form.setData('unavailable_items', e.target.value)}>
                                <option value="keep">{t('admin.cart_checkout.keep', 'Keep unavailable items')}</option>
                                <option value="remove">{t('admin.cart_checkout.remove', 'Remove automatically')}</option>
                            </Select>
                        )}
                    />
                </div>
            </Card>
            <Card>
                <SettingsSectionHead
                    icon="check"
                    title={t('admin.cart_checkout.panel_checkout', 'Checkout')}
                    extra={<SettingsCount>{t('admin.customer_settings.setting_count', '{count} settings', { count: 5 })}</SettingsCount>}
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <FeatureTile
                        icon="tag"
                        tone="bg-violet-100 text-violet-600"
                        title={t('admin.cart_checkout.coupons', 'Show Coupon')}
                        hint={t('admin.cart_checkout.coupons_hint', 'Show the coupon field at checkout.')}
                        value={!!form.data.coupons_enabled}
                        onChange={(on) => form.setData('coupons_enabled', on)}
                    />
                    <FeatureTile
                        icon="file"
                        tone="bg-sky-100 text-sky-600"
                        title={t('admin.cart_checkout.terms', 'Require Terms Acceptance')}
                        hint={pages.length === 0
                            ? t('admin.cart_checkout.terms_needs_page', 'Create a page under Design → Pages before requiring terms.')
                            : (form.errors.terms_cms_page_id || t('admin.cart_checkout.terms_hint', 'The customer must accept the selected page before paying.'))}
                        value={!!form.data.terms_required}
                        onChange={(on) => {
                            if (on && pages.length === 0) {
                                return;
                            }
                            if (on && !form.data.terms_cms_page_id && pages[0]) {
                                form.setData({
                                    ...form.data,
                                    terms_required: true,
                                    terms_cms_page_id: String(pages[0].id),
                                });
                                return;
                            }
                            form.setData('terms_required', on);
                        }}
                    >
                        {form.data.terms_required ? (
                            <ChoiceMenu
                                className="w-full"
                                ariaLabel={t('admin.cart_checkout.terms_page', 'Terms and conditions page')}
                                value={form.data.terms_cms_page_id}
                                options={pageOptions}
                                onChange={(value) => form.setData('terms_cms_page_id', value)}
                            />
                        ) : null}
                    </FeatureTile>
                    <FeatureTile
                        icon="package"
                        tone="bg-emerald-100 text-emerald-600"
                        title={t('admin.cart_checkout.stock', 'Validate stock at checkout')}
                        hint={t('admin.cart_checkout.stock_hint', 'Refuse checkout when a line is no longer in stock.')}
                        value={!!form.data.stock_validation}
                        onChange={(on) => form.setData('stock_validation', on)}
                    />
                    <FeatureTile
                        icon="grid"
                        tone="bg-indigo-100 text-indigo-600"
                        title={t('admin.cart_checkout.layout', 'Checkout layout')}
                        hint={t('admin.cart_checkout.layout_hint', 'One page, or stepped sections.')}
                        control={(
                            <Select className="w-40 shrink-0" value={form.data.layout} onChange={(e) => form.setData('layout', e.target.value)}>
                                <option value="one_page">{t('admin.cart_checkout.one_page', 'One page')}</option>
                                <option value="stepped">{t('admin.cart_checkout.stepped', 'Stepped')}</option>
                            </Select>
                        )}
                    />
                    <FeatureTile
                        icon="gift"
                        tone="bg-pink-100 text-pink-600"
                        title={t('admin.cart_checkout.gift_wrap', 'Gift wrap')}
                        hint={t('admin.cart_checkout.gift_wrap_hint', 'Offer gift wrap and an optional fee.')}
                        value={!!form.data.gift_wrap_enabled}
                        onChange={(on) => form.setData('gift_wrap_enabled', on)}
                    >
                        {form.data.gift_wrap_enabled ? (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-neutral-700">{t('admin.cart_checkout.gift_wrap_fee', 'Gift wrap fee')}</span>
                                    <div className="flex w-28 shrink-0 items-center gap-1.5">
                                        {currencySymbol ? <span className="shrink-0 text-sm text-neutral-500">{currencySymbol}</span> : null}
                                        <Input className="min-w-0 flex-1" type="number" min="0" step="0.01" value={form.data.gift_wrap_fee} onChange={(e) => form.setData('gift_wrap_fee', Number(e.target.value))} />
                                    </div>
                                </div>
                                <p className="text-xs leading-5 text-neutral-500">
                                    {t('admin.cart_checkout.gift_wrap_fee_hint', 'This amount is added to the order when the customer chooses gift wrap. 0 means gift wrap is free.')}
                                </p>
                            </div>
                        ) : null}
                    </FeatureTile>
                </div>
                {props.embedded ? null : (
                    <SettingsNote>
                        {t('admin.cart_checkout.owned_elsewhere', 'Guest checkout: Customers. Order notes and min/max order value: Orders. Stock reservation: Inventory / Orders.')}
                        {' '}
                        <Button type="button" variant="link" href="/admin/customer-settings" className="px-0">
                            {t('admin.nav.customer_settings', 'Customers')}
                        </Button>
                        {' · '}
                        <Button type="button" variant="link" href="/admin/order-settings" className="px-0">
                            {t('admin.nav.order_settings', 'Order Settings')}
                        </Button>
                    </SettingsNote>
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
                title={t('admin.cart_checkout.title', 'Cart & Checkout')}
                crumbs={crumbs}
                crumbsFirst
                meta={t('admin.cart_checkout.subtitle', 'Cart persistence, coupons, terms, and checkout stock checks. Guest checkout lives under Customers. Order notes live under Orders.')}
            />
            {body}
        </Layout>
    );
}
