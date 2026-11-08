import { t } from './i18n';

/** Shared admin sidebar menu â€” keep Layout + breadcrumbs in sync. */
function translateModuleNav(moduleNav = []) {
    return (moduleNav || []).map((group) => ({
        ...group,
        label: group.label_key ? t(group.label_key, group.label) : group.label,
        items: (group.items || []).map((item) => (
            item.heading
                ? item
                : {
                    ...item,
                    label: item.label_key ? t(item.label_key, item.label) : item.label,
                }
        )),
    }));
}

export function buildAdminMenu(multishop, appNav = [], moduleNav = [], multivendor = false, stockAlerts = false) {
    moduleNav = translateModuleNav(moduleNav);
    const shippingAppNav = (appNav || []).filter((app) => app.category === 'shipping');
    const paymentAppNav = (appNav || []).filter((app) => app.category === 'payment');
    const generalAppNav = (appNav || []).filter((app) => !shippingAppNav.includes(app) && !paymentAppNav.includes(app));
    const configureItems = [
        { href: '/admin/general', label: t('admin.nav.general_configuration', 'General'), permission: 'settings.store' },
        { href: '/admin/store', label: t('admin.nav.settings', 'Store'), permission: 'settings.store' },
    ];
    if (multishop) {
        configureItems.push({ href: '/admin/shops', label: t('admin.nav.shops', 'Multishop'), permission: 'settings.shops' });
    }
    configureItems.push(
        { href: '/admin/product-settings', label: t('admin.nav.product_settings', 'Products'), permission: 'settings.products' },
        { href: '/admin/order-settings', label: t('admin.nav.order_settings', 'Orders'), permission: 'settings.orders' },
        { href: '/admin/customer-settings', label: t('admin.nav.customer_settings', 'Customers'), permission: 'settings.customers' },
        { href: '/admin/gdpr', label: t('admin.nav.gdpr', 'GDPR'), permission: 'settings.gdpr' },
        {
            href: '/admin/urls',
            label: t('admin.nav.storefront', 'Storefront'),
            permission: 'settings.urls',
            match: ['/admin/urls', '/admin/seo', '/admin/images'],
        },
        { href: '/admin/email', label: t('admin.nav.email', 'Email'), permission: 'settings.email' },
        { href: '/admin/documents', label: t('admin.nav.document_designs', 'Document designs'), permission: 'settings.documents' },
        {
            href: '/admin/advanced',
            label: t('admin.nav.advanced', 'Advanced'),
            permission: 'settings.advanced',
            match: ['/admin/advanced', '/admin/performance'],
        },
        { href: '/admin/ui-kit', label: t('admin.nav.ui_kit', 'UI kit'), permission: '*' },
    );

    return [
        {
            id: 'dashboard',
            href: '/admin/dashboards',
            label: t('admin.nav.dashboard', 'Dashboard'),
            icon: 'home',
            permission: '*',
        },
        {
            id: 'operate',
            label: t('admin.nav.operate', 'Operate'),
            icon: 'gauge',
            items: [
                { href: '/admin/activity', label: t('admin.nav.activity', 'Activity'), permission: 'ops.activity' },
                { href: '/admin/operator', label: t('admin.nav.operator', 'AI operator'), permission: 'ops.operator', hidden: true },
                { href: '/admin/health', label: t('admin.nav.health', 'Store health'), permission: 'ops.health', match: ['/admin/health'] },
                { href: '/admin/automations', label: t('admin.nav.automations', 'Automations'), permission: 'ops.automations', hidden: true },
                { href: '/admin/import-studio', label: t('admin.nav.import_studio', 'Import studio'), permission: 'ops.import', hidden: true },
                { href: '/admin/ops-tools', label: t('admin.nav.ops_tools', 'Webhooks & web services'), permission: 'ops.tools', match: ['/admin/ops-tools'] },
            ],
        },
        {
            id: 'catalog',
            label: t('admin.nav.catalog', 'Catalog'),
            icon: 'package',
            items: [
                { href: '/admin/products', label: t('admin.nav.product', 'Product'), permission: 'catalog.products' },
                { href: '/admin/stock-alerts', label: t('admin.nav.stock_alerts', 'Stock Alerts'), permission: 'catalog.products', match: ['/admin/stock-alerts'], hidden: !stockAlerts },
                { href: '/admin/inventory', label: t('admin.nav.inventory', 'Inventory'), permission: 'catalog.inventory' },
                { href: '/admin/inventory/journal', label: t('admin.nav.stock_journal', 'Stock journal'), permission: 'catalog.inventory' },
                { href: '/admin/warehouses', label: t('admin.nav.warehouses', 'Warehouses'), permission: 'catalog.warehouses' },
                { href: '/admin/purchase-orders', label: t('admin.nav.purchase_orders', 'Purchase orders'), permission: 'catalog.purchase-orders' },
                { href: '/admin/gift-cards', label: t('admin.nav.gift_cards', 'Gift cards'), permission: 'catalog.gift-cards' },
                { href: '/admin/categories', label: t('admin.nav.category', 'Category'), permission: 'catalog.categories' },
                { href: '/admin/brands', label: t('admin.nav.brand', 'Brand'), permission: 'catalog.brands' },
                { href: '/admin/suppliers', label: t('admin.nav.supplier', 'Supplier'), permission: 'catalog.suppliers' },
                { href: '/admin/features', label: t('admin.nav.feature', 'Feature'), permission: 'catalog.features' },
                { href: '/admin/attributes', label: t('admin.nav.attribute', 'Attribute'), permission: 'catalog.features' },
                { href: '/admin/files', label: t('admin.nav.files', 'Files'), permission: 'catalog.files' },
                { href: '/admin/subscription-plans', label: t('admin.nav.subscription_plans', 'Subscription plans'), permission: 'catalog.manage' },
                { href: '/admin/customer-subscriptions', label: t('admin.nav.customer_subscriptions', 'Subscribers'), permission: 'catalog.manage' },
            ],
        },
        {
            id: 'orders',
            label: t('admin.nav.orders', 'Orders'),
            icon: 'orders',
            items: [
                { href: '/admin/orders', label: t('admin.nav.order', 'Order'), permission: 'orders.manage' },
                { href: '/admin/orders/preorders', label: t('admin.nav.preorders', 'Pre-orders'), permission: 'orders.manage' },
                { href: '/admin/order-stages', label: t('admin.nav.order_statuses', 'Order statuses'), permission: 'orders.manage' },
                { href: '/admin/invoices', label: t('admin.nav.invoice', 'Invoice'), permission: 'orders.documents' },
                { href: '/admin/credit-slips', label: t('admin.nav.credit_slip', 'Credit Slip'), permission: 'orders.documents' },
                { href: '/admin/delivery-slips', label: t('admin.nav.delivery_slip', 'Delivery Slip'), permission: 'orders.documents' },
            ],
        },
        {
            id: 'customers',
            label: t('admin.nav.customers', 'Customers'),
            icon: 'users',
            items: [
                { href: '/admin/customers', label: t('admin.nav.customer', 'Customer'), permission: 'customers.customers' },
                { href: '/admin/customer-groups', label: t('admin.nav.customer_group', 'Customer Group'), permission: 'customers.groups' },
                { href: '/admin/quotes', label: t('admin.nav.quotes', 'Quotes'), permission: 'customers.customers' },
                { href: '/admin/companies', label: t('admin.nav.companies', 'Companies'), permission: 'customers.customers' },
                { href: '/admin/addresses', label: t('admin.nav.addresses', 'Addresses'), permission: 'customers.addresses' },
                { href: '/admin/reviews', label: t('admin.nav.reviews', 'Reviews'), permission: 'customers.reviews' },
                { href: '/admin/questions', label: t('admin.nav.questions', 'Questions'), permission: 'customers.questions' },
                { href: '/admin/support', label: t('admin.nav.support', 'Support'), permission: 'customers.support' },
                { href: '/admin/gdpr-requests', label: t('admin.nav.gdpr_requests', 'GDPR Data Requests'), permission: 'customers.gdpr' },
            ],
        },
        ...(multivendor ? [{
            id: 'multivendor',
            label: t('admin.nav.multivendor', 'Multivendor Management'),
            icon: 'bag',
            items: [
                { href: '/admin/multivendor/dashboard', label: t('admin.nav.marketplace_dashboard', 'Marketplace dashboard'), permission: 'multivendor.dashboard' },
                { href: '/admin/multivendor/seller-requests', label: t('admin.nav.seller_request', 'Applications'), permission: 'multivendor.seller-requests' },
                { href: '/admin/multivendor/sellers', label: t('admin.nav.sellers', 'Sellers'), permission: 'multivendor.sellers' },
                { href: '/admin/multivendor/seller-profiles', label: t('admin.nav.seller_profile', 'Seller Profile'), permission: 'multivendor.seller-profiles' },
                { href: '/admin/multivendor/seller-shops', label: t('admin.nav.seller_shop', 'Seller Shop'), permission: 'multivendor.seller-shops' },
                { href: '/admin/multivendor/products', label: t('admin.nav.seller_product_list', 'Products'), permission: 'multivendor.products' },
                { href: '/admin/multivendor/discounts', label: t('admin.nav.seller_discounts', 'Discounts'), permission: 'multivendor.discounts' },
                { href: '/admin/multivendor/categories', label: t('admin.nav.seller_category', 'Category'), permission: 'multivendor.categories' },
                { href: '/admin/multivendor/features', label: t('admin.nav.seller_features', 'Features'), permission: 'multivendor.features' },
                { href: '/admin/multivendor/brands', label: t('admin.nav.seller_brands', 'Brands'), permission: 'multivendor.brands' },
                { href: '/admin/multivendor/suppliers', label: t('admin.nav.seller_suppliers', 'Suppliers'), permission: 'multivendor.suppliers' },
                { href: '/admin/multivendor/orders', label: t('admin.nav.seller_orders', 'Seller Orders'), permission: 'multivendor.orders' },
                { href: '/admin/multivendor/returns', label: t('admin.nav.seller_returns', 'Seller Returns'), permission: 'multivendor.returns' },
                { href: '/admin/multivendor/earnings', label: t('admin.nav.seller_earnings', 'Seller Earnings'), permission: 'multivendor.earnings' },
                { href: '/admin/multivendor/ledger', label: t('admin.nav.seller_ledger', 'Seller Ledger'), permission: 'multivendor.earnings' },
                { href: '/admin/multivendor/commissions', label: t('admin.nav.seller_commission', 'Seller Commission'), permission: 'multivendor.commissions' },
                { href: '/admin/multivendor/commission-rules', label: t('admin.nav.commission_rules', 'Commission Rules'), permission: 'multivendor.commissions' },
                { href: '/admin/multivendor/shipping', label: t('admin.nav.seller_shipping', 'Seller Shipping'), permission: 'multivendor.shipping' },
                { href: '/admin/multivendor/payments', label: t('admin.nav.seller_payments', 'Seller Payout Methods'), permission: 'multivendor.payments' },
                { href: '/admin/multivendor/payouts', label: t('admin.nav.seller_payouts', 'Seller Payouts'), permission: 'multivendor.payouts' },
                { href: '/admin/multivendor/reviews', label: t('admin.nav.seller_reviews', 'Seller Reviews'), permission: 'multivendor.reviews' },
                { href: '/admin/multivendor/messages', label: t('admin.nav.seller_messages', 'Seller Messages'), permission: 'multivendor.messages' },
                { href: '/admin/multivendor/audits', label: t('admin.nav.marketplace_audits', 'Audit trail'), permission: 'multivendor.manage' },
                { href: '/admin/multivendor/settings', label: t('admin.nav.marketplace_settings', 'Marketplace settings'), permission: 'multivendor.settings' },
            ],
        }] : []),
        {
            id: 'marketing',
            label: t('admin.nav.marketing', 'Marketing'),
            icon: 'tag',
            items: [
                { href: '/admin/discounts', label: t('admin.nav.discounts', 'Discounts'), permission: 'marketing.discounts' },
                { href: '/admin/newsletter', label: t('admin.nav.newsletter', 'Newsletter'), permission: 'marketing.newsletter' },
            ],
        },
        {
            id: 'shipping',
            label: t('admin.nav.shipping', 'Shipping'),
            icon: 'truck',
            items: [
                { href: '/admin/shipping', label: t('admin.nav.shipping_methods', 'Shipping methods'), permission: 'shipping.manage' },
                ...shippingAppNav,
            ],
        },
        {
            id: 'payment',
            label: t('admin.nav.payment', 'Payment'),
            icon: 'check-circle',
            items: [
                { href: '/admin/payments', label: t('admin.nav.payment_methods', 'Payment methods'), permission: 'payments.manage' },
                ...paymentAppNav,
            ],
        },
        ...moduleNav,
        {
            id: 'design',
            label: t('admin.nav.design', 'Design'),
            icon: 'file',
            items: [
                { href: '/admin/cms', label: t('admin.nav.pages', 'Pages'), permission: 'cms.pages' },
                { href: '/admin/blocks', label: t('admin.nav.blocks', 'Blocks'), permission: 'cms.blocks' },
                { href: '/admin/themes', label: t('admin.nav.themes', 'Themes'), permission: 'design.themes' },
                { href: '/admin/themes/settings', label: t('admin.nav.theme_settings', 'Theme settings'), permission: 'design.themes' },
            ],
        },
        {
            id: 'extensions',
            label: t('admin.nav.apps', 'Extensions'),
            icon: 'layers',
            permission: 'apps.manage',
            ...(generalAppNav.length
                ? {
                    items: [
                        { href: '/admin/extensions', label: t('admin.nav.apps', 'Extensions'), permission: 'apps.manage', match: ['/admin/apps'] },
                        ...generalAppNav,
                    ],
                }
                : { href: '/admin/extensions' }),
        },
        {
            id: 'ai',
            label: t('admin.nav.ai', 'AI Assistant'),
            icon: 'sparkles',
            permission: 'settings.ai',
            items: [
                { href: '/admin/ai', label: t('admin.nav.ai_settings', 'Settings'), permission: 'settings.ai' },
                { href: '/admin/ai/logs', label: t('admin.nav.ai_admin_chats', 'Admin chats'), permission: 'settings.ai' },
                { href: '/admin/ai/customer-chats', label: t('admin.nav.ai_customer_chats', 'Customer chats'), permission: 'settings.ai' },
            ],
        },
        {
            id: 'international',
            label: t('admin.nav.international', 'International'),
            icon: 'globe',
            items: [
                { href: '/admin/localization', label: t('admin.nav.localization', 'Localization'), permission: 'localization.manage' },
                { href: '/admin/localization/countries', label: t('admin.nav.countries', 'Countries'), permission: 'localization.countries' },
                { href: '/admin/localization/zones', label: t('admin.nav.zones', 'Zones'), permission: 'localization.countries' },
                { href: '/admin/localization/currencies', label: t('admin.nav.currencies', 'Currencies'), permission: 'localization.currencies' },
                { href: '/admin/localization/languages', label: t('admin.nav.languages', 'Languages'), permission: 'localization.languages' },
                { href: '/admin/localization/translations', label: t('admin.nav.translations', 'Translations'), permission: 'localization.translations' },
                { href: '/admin/tax', label: t('admin.nav.taxes', 'Taxes'), permission: 'taxes.manage' },
            ],
        },
        {
            id: 'staff',
            label: t('admin.nav.staff', 'Staff'),
            icon: 'users',
            super: true,
            items: [
                { href: '/admin/staff/employees', label: t('admin.nav.employees', 'Employees') },
                { href: '/admin/staff/teams', label: t('admin.nav.teams', 'Teams') },
                { href: '/admin/staff/profiles', label: t('admin.nav.profiles', 'Profiles') },
            ],
        },
        {
            id: 'configure',
            label: t('admin.nav.configure', 'Configure'),
            icon: 'settings',
            items: configureItems,
        },
    ];
}

export function pathFromAdminUrl(url) {
    if (!url) {
        return '/admin';
    }
    try {
        return new URL(url, 'http://127.0.0.1').pathname.replace(/\/+$/, '') || '/admin';
    } catch {
        return String(url).split('?')[0].replace(/\/+$/, '') || '/admin';
    }
}

export function searchParamsFromAdminUrl(url) {
    try {
        return new URL(url || '/admin', 'http://127.0.0.1').searchParams;
    } catch {
        const query = String(url || '').split('?')[1] || '';
        return new URLSearchParams(query);
    }
}

function menuItemHrefs(item) {
    return [item.href, ...(Array.isArray(item.match) ? item.match : [])].filter(Boolean);
}

function collectHrefs(nodes) {
    const hrefs = [];
    nodes.forEach((node) => {
        if (node.href) {
            hrefs.push(node.href);
        }
        (node.items || []).forEach((item) => {
            menuItemHrefs(item).forEach((href) => hrefs.push(href));
        });
    });
    return hrefs;
}

function isMenuMatch(pathname, href, allHrefs) {
    if (href === '/admin/dashboards') {
        return pathname === '/admin/dashboards' || pathname.startsWith('/admin/dashboards/');
    }
    if (href === '/admin') {
        return pathname === '/admin';
    }
    const matches = pathname === href || pathname.startsWith(`${href}/`);
    if (!matches) {
        return false;
    }
    return !allHrefs.some((other) => (
        other !== href
        && other.length > href.length
        && (pathname === other || pathname.startsWith(`${other}/`))
    ));
}

function sectionHomeHref(node) {
    const first = (node.items || []).find((row) => row.href);
    return first?.href || node.href;
}

/**
 * Sidebar trail only: one parent, then its child.
 * Configure > General. No Dashboard prefix and no third Edit crumb.
 */
export function resolveAdminBreadcrumbs(url, {
    multishop = false,
    multivendor = false,
    stockAlerts = false,
    appNav = [],
    moduleNav = [],
} = {}) {
    const pathname = pathFromAdminUrl(url);
    const menu = buildAdminMenu(multishop, appNav || [], moduleNav || [], multivendor, stockAlerts);
    const allHrefs = collectHrefs(menu);
    const dashboardLabel = t('admin.nav.dashboard', 'Dashboard');

    if (pathname === '/admin' || pathname === '/admin/dashboards') {
        return [{ label: dashboardLabel }];
    }

    for (const node of menu) {
        if (node.items?.length) {
            const item = node.items
                .filter((row) => row.href)
                .sort((a, b) => Math.max(...menuItemHrefs(b).map((href) => href.length)) - Math.max(...menuItemHrefs(a).map((href) => href.length)))
                .find((row) => menuItemHrefs(row).some((href) => isMenuMatch(pathname, href, allHrefs)));

            if (item) {
                const sectionHref = sectionHomeHref(node);
                return [
                    {
                        href: sectionHref && sectionHref !== pathname ? sectionHref : undefined,
                        label: node.label,
                    },
                    { label: item.label },
                ].filter((crumb) => crumb.label);
            }
            continue;
        }

        if (node.href && isMenuMatch(pathname, node.href, allHrefs)) {
            return [{ label: node.label }];
        }
    }

    return [];
}

