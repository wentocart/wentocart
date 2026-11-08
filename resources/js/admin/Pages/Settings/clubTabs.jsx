import { router } from '@inertiajs/react';
import { StatusTabs, t } from '../../ui';

const TAB_CLASS = 'mb-4 bg-white rounded-xl border border-neutral-200 px-2';

export function SettingsClubTabs({ tabs, value, base }) {
    return (
        <StatusTabs
            className={TAB_CLASS}
            tabs={tabs}
            value={value}
            onChange={(next) => router.get(base, { tab: next }, { preserveState: true, preserveScroll: true, replace: true })}
        />
    );
}

export const PRODUCT_CLUB_TABS = [
    { key: 'defaults', label: t('admin.product_settings.tab_defaults', 'Products') },
    { key: 'inventory', label: t('admin.nav.inventory_settings', 'Inventory') },
    { key: 'search', label: t('admin.nav.search_catalog', 'Search & Catalog') },
    { key: 'pricing', label: t('admin.nav.pricing_settings', 'Pricing') },
];

export const ORDER_CLUB_TABS = [
    { key: 'defaults', label: t('admin.order_settings.tab_defaults', 'Orders') },
    { key: 'cart', label: t('admin.nav.cart_checkout', 'Cart & Checkout') },
    { key: 'returns', label: t('admin.nav.return_settings', 'Returns') },
    { key: 'invoices', label: t('admin.nav.document_settings', 'Invoices') },
];

export const CUSTOMER_CLUB_TABS = [
    { key: 'accounts', label: t('admin.customer_settings.tab_accounts', 'Customer Accounts') },
    { key: 'groups', label: t('admin.customer_settings.tab_groups', 'Customer Groups') },
    { key: 'addresses', label: t('admin.customer_settings.tab_addresses', 'Addresses') },
    { key: 'reviews', label: t('admin.nav.review_settings', 'Reviews') },
];

export const STOREFRONT_CLUB_TABS = [
    { key: 'urls', href: '/admin/urls', label: t('admin.nav.urls', 'URLs') },
    { key: 'seo', href: '/admin/seo', label: t('admin.nav.seo', 'SEO') },
    { key: 'images', href: '/admin/images', label: t('admin.nav.images', 'Images') },
];

export function StorefrontClubTabs({ current }) {
    return (
        <StatusTabs
            className={TAB_CLASS}
            tabs={STOREFRONT_CLUB_TABS}
            value={current}
            onChange={(next) => {
                const tab = STOREFRONT_CLUB_TABS.find((row) => row.key === next);
                if (tab?.href) {
                    router.get(tab.href);
                }
            }}
        />
    );
}

export const ADVANCED_CLUB_TABS = [
    { key: 'profiler', label: t('admin.advanced.profiler', 'Profiler') },
    { key: 'queries', label: t('admin.advanced.database_queries', 'Database queries') },
    { key: 'files', label: t('admin.advanced.file_logs', 'File changes') },
    { key: 'info', label: t('admin.advanced.info', 'Shop information') },
    { key: 'maintenance', label: t('admin.advanced.maintenance', 'Maintenance') },
    { key: 'demo', label: t('admin.advanced.demo', 'Demo data') },
    { key: 'performance', label: t('admin.nav.performance', 'Performance') },
];

export function AdvancedClubTabs({ current, queryCount }) {
    const tabs = ADVANCED_CLUB_TABS.map((tab) => (
        tab.key === 'queries' ? { ...tab, count: queryCount } : tab
    ));

    return (
        <StatusTabs
            className={TAB_CLASS}
            tabs={tabs}
            value={current}
            onChange={(next) => {
                if (next === 'performance') {
                    router.get('/admin/performance');
                    return;
                }
                router.get('/admin/advanced', { tab: next }, { preserveState: true, replace: true });
            }}
        />
    );
}
