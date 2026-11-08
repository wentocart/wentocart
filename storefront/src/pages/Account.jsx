import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { couponsEnabled, outOfStockNotificationEnabled, reviewsEnabled, wishlistEnabled } from '../lib/commerce.js';
import { formatMoney } from '../lib/format.js';
import { AccountPageSkeleton, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ExtensionIcon from '../components/ExtensionIcon.jsx';

const links = [
    { to: '/account', label: 'Dashboard', end: true, icon: 'grid', key: 'dashboard' },
    { to: '/account/profile', label: 'Profile', icon: 'user', key: 'profile' },
    { to: '/account/gdpr', label: 'GDPR Requests', icon: 'shield', key: 'gdpr', end: true },
    { to: '/account/gdpr/cookies', label: 'Cookie Preferences', icon: 'settings', key: 'gdpr_cookies' },
    { to: '/account/orders', label: 'Orders', icon: 'bag', key: 'orders' },
    { to: '/account/preorders', label: 'Pre-orders', icon: 'calendar', key: 'preorders' },
    { to: '/account/stock-alerts', label: 'Stock Alerts', icon: 'bell', key: 'stock_alerts' },
    { to: '/account/addresses', label: 'Addresses', icon: 'pin', key: 'addresses' },
    { to: '/account/wishlist', label: 'Wishlist', icon: 'heart', key: 'wishlist' },
    { to: '/account/vouchers', label: 'Vouchers', icon: 'tag', key: 'vouchers' },
    { to: '/account/gift-cards', label: 'Gift cards', icon: 'tag', key: 'gift_cards' },
    { to: '/account/downloads', label: 'Downloads', icon: 'bag', key: 'downloads' },
    { to: '/account/quotes', label: 'Quotes', icon: 'tag', key: 'quotes' },
    { to: '/account/subscriptions', label: 'Subscriptions', icon: 'bag', key: 'subscriptions' },
    { to: '/account/reviews', label: 'Reviews', icon: 'star', key: 'reviews' },
    { to: '/account/help', label: 'Help', icon: 'help', key: 'help' },
];

function statusBucket(status) {
    const s = String(status || '').toLowerCase();
    if (['cancelled', 'canceled', 'refunded', 'void'].includes(s)) {
        return 'cancelled';
    }
    if (['completed', 'delivered', 'done'].includes(s)) {
        return 'delivered';
    }
    if (['shipped', 'transit', 'in_transit'].includes(s)) {
        return 'shipped';
    }
    if (['pending', 'processing', 'pack', 'paid', 'new'].includes(s)) {
        return 'processing';
    }
    return s || 'processing';
}

function statusTone(bucket) {
    if (bucket === 'delivered') return 'delivered';
    if (bucket === 'shipped') return 'shipped';
    if (bucket === 'cancelled') return 'cancelled';
    return 'processing';
}

function statusLabel(bucket, raw) {
    if (bucket === 'delivered') return t('storefront.account.status_delivered', 'Delivered');
    if (bucket === 'shipped') return t('storefront.account.status_shipped', 'Shipped');
    if (bucket === 'cancelled') return t('storefront.account.status_cancelled', 'Cancelled');
    if (bucket === 'processing') return t('storefront.account.status_processing', 'Processing');
    return raw || bucket;
}

const SELLER_APPLY_PATH = '/account/become-seller';

function sellerAccountNav(seller) {
    if (!seller) {
        return {
            kind: 'apply',
            to: SELLER_APPLY_PATH,
            labelKey: 'storefront.seller.become',
            label: 'Become a seller',
        };
    }
    if (seller.can_access_portal || seller.is_approved) {
        return {
            kind: 'portal',
            to: '/account/seller',
            labelKey: 'storefront.seller.dashboard',
            label: 'Seller Dashboard',
        };
    }
    if (seller.status === 'pending') {
        return {
            kind: 'apply',
            to: SELLER_APPLY_PATH,
            labelKey: 'storefront.seller.application_status',
            label: 'Application status',
        };
    }
    if (seller.status === 'rejected') {
        return {
            kind: 'apply',
            to: SELLER_APPLY_PATH,
            labelKey: 'storefront.seller.reapply_nav',
            label: 'Seller application',
        };
    }
    return {
        kind: 'apply',
        to: SELLER_APPLY_PATH,
        labelKey: 'storefront.seller.application_status',
        label: 'Application status',
    };
}

function initials(name, email) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    if (parts.length === 1 && parts[0].length) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return String(email || 'A').slice(0, 2).toUpperCase();
}

function linkLabel(link) {
    if (link.key === 'dashboard') return t('storefront.account.dashboard', 'Dashboard');
    if (link.key === 'orders') return t('storefront.account.orders', 'Orders');
    if (link.key === 'preorders') return t('storefront.account.preorders', 'Pre-orders');
    if (link.key === 'stock_alerts') return t('storefront.account.stock_alerts', 'Stock Alerts');
    if (link.key === 'profile') return t('storefront.account.profile', 'Profile');
    if (link.key === 'gdpr') return t('storefront.gdpr.requests', 'GDPR Requests');
    if (link.key === 'gdpr_cookies') return t('storefront.gdpr.cookie_prefs_short', 'Cookie Preferences');
    if (link.key === 'addresses') return t('storefront.account.addresses', 'Addresses');
    if (link.key === 'wishlist') return t('storefront.shell.wishlist', 'Wishlist');
    if (link.key === 'vouchers') return t('storefront.account.vouchers', 'Vouchers');
    if (link.key === 'gift_cards') return t('storefront.account.gift_cards', 'Gift cards');
    if (link.key === 'downloads') return t('storefront.account.downloads', 'Downloads');
    if (link.key === 'quotes') return t('storefront.account.quotes', 'Quotes');
    if (link.key === 'subscriptions') return t('storefront.account.subscriptions', 'Subscriptions');
    if (link.key === 'reviews') return t('storefront.account.reviews', 'Reviews');
    return t('storefront.account.help', 'Help');
}

function orderDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

export function AccountLayout() {
    const store = useStore();
    const nav = useNavigate();
    const [menuScrollerOn, setMenuScrollerOn] = useState(false);

    if (store.customer === undefined) {
        return <PageSkeleton />;
    }
    if (!store.customer) {
        return (
            <section className="shop-account-stage">
                <div className="shop-container shop-account-guest">
                    <div className="shop-account-guest-card">
                        <h1>{t('storefront.account.signin_title', 'Sign in to continue')}</h1>
                        <p>{t('storefront.account.signin_body', 'Orders, addresses, reviews, and saved items live in your account.')}</p>
                        <div className="shop-account-guest-actions">
                            <Link to="/login" className="shop-btn shop-btn-primary">{t('storefront.login.submit', 'Sign in')}</Link>
                            <Link to="/register" className="shop-btn shop-btn-ghost">{t('storefront.register.title', 'Create an account')}</Link>
                        </div>
                    </div>
                </div>
            </section>
        );
    }

    const logout = async () => {
        await csrf();
        await api.post('/auth/logout');
        store.setCustomer(null);
        nav('/');
    };

    const name = store.customer.name || [store.customer.first_name, store.customer.last_name].filter(Boolean).join(' ') || store.customer.email;
    const sellerNav = store.shop?.features?.multivendor ? sellerAccountNav(store.customer?.seller) : null;

    return (
        <section className="shop-account-stage">
            <div className="shop-container shop-account-layout">
                <aside className="shop-account-aside">
                    <div className="shop-account-nav">
                        <div className="shop-account-profile">
                            <span className="shop-account-avatar" aria-hidden="true">{initials(name, store.customer.email)}</span>
                            <div>
                                <strong>{name}</strong>
                                <em>{store.customer.email}</em>
                            </div>
                        </div>
                        <nav
                            className={`shop-account-menu${menuScrollerOn ? ' is-scroller-on' : ''}`}
                            onMouseEnter={() => setMenuScrollerOn(true)}
                            onMouseLeave={() => setMenuScrollerOn(false)}
                        >
                            {links.filter((link) => {
                                if (link.key === 'gdpr' || link.key === 'gdpr_cookies') return !!store.shop?.commerce?.gdpr?.enabled;
                                if (link.key === 'wishlist') return wishlistEnabled(store.shop);
                                if (link.key === 'stock_alerts') return outOfStockNotificationEnabled(store.shop);
                                if (link.key === 'reviews') return reviewsEnabled(store.shop);
                                if (link.key === 'vouchers') return couponsEnabled(store.shop);
                                return true;
                            }).map((link) => (
                                <NavLink
                                    key={link.to}
                                    to={link.to}
                                    end={link.end}
                                    className={({ isActive }) => `shop-account-link${isActive ? ' is-active' : ''}`}
                                >
                                    <Icon name={link.icon} className="h-4 w-4" />
                                    <span>{linkLabel(link)}</span>
                                </NavLink>
                            ))}
                            {sellerNav ? (
                                sellerNav.kind === 'portal' ? (
                                    <a
                                        href={sellerNav.to}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="shop-account-link"
                                        data-seller-portal-link="1"
                                    >
                                        <Icon name="store" className="h-4 w-4" />
                                        <span>{t(sellerNav.labelKey, sellerNav.label)}</span>
                                    </a>
                                ) : (
                                    <NavLink
                                        to={sellerNav.to}
                                        className={({ isActive }) => `shop-account-link${isActive ? ' is-active' : ''}`}
                                        data-seller-apply-link="1"
                                    >
                                        <Icon name="store" className="h-4 w-4" />
                                        <span>{t(sellerNav.labelKey, sellerNav.label)}</span>
                                    </NavLink>
                                )
                            ) : null}
                            {(store.shop?.modules?.account_menu || []).filter((item) => item.href).map((item) => (
                                <NavLink
                                    key={item.href}
                                    to={item.href}
                                    className={({ isActive }) => `shop-account-link${isActive ? ' is-active' : ''}`}
                                >
                                    <ExtensionIcon icon={item.icon} className="h-4 w-4" fallback="grid" />
                                    <span>{item.label || item.text}</span>
                                </NavLink>
                            ))}
                            {store.shop?.customers?.allow_account_deletion && !store.shop?.commerce?.gdpr?.enabled ? (
                                <NavLink
                                    to="/account/profile#delete-account"
                                    className="shop-account-link is-danger"
                                >
                                    <Icon name="trash" className="h-4 w-4" />
                                    <span>{t('storefront.account.delete_title', 'Delete account')}</span>
                                </NavLink>
                            ) : null}
                            <button type="button" className="shop-account-link is-danger" onClick={logout}>
                                <Icon name="logout" className="h-4 w-4" />
                                <span>{t('storefront.account.sign_out', 'Sign out')}</span>
                            </button>
                        </nav>
                    </div>
                </aside>
                <div className="shop-account-main">
                    <Outlet />
                </div>
            </div>
        </section>
    );
}

export default function Account() {
    const store = useStore();
    const [data, setData] = useState(null);

    useEffect(() => {
        api.get('/account').then((r) => setData(r.data)).catch(() => setData({ stats: {}, recent_orders: [] }));
    }, []);

    if (!data) {
        return <AccountPageSkeleton />;
    }

    const stats = data?.stats || {};
    const orders = data?.recent_orders || [];
    const name = store.customer?.name
        || [store.customer?.first_name, store.customer?.last_name].filter(Boolean).join(' ')
        || store.customer?.email
        || '';

    return (
        <div className="shop-account-dash">
            <header className="shop-account-dash-head">
                <h1>
                    {t('storefront.account.hello', 'Hello')} {name}
                </h1>
                <p>{t('storefront.account.dash_body', 'Your orders, addresses, and reviews in one place.')}</p>
            </header>

            <div className="shop-account-stats">
                <StatCard
                    to="/account/orders"
                    label={t('storefront.account.orders', 'Orders')}
                    value={stats.orders ?? 0}
                    icon="bag"
                    tone="orders"
                    action={t('storefront.account.view', 'View')}
                />
                {wishlistEnabled(store.shop) ? (
                <StatCard
                    to="/account/wishlist"
                    label={t('storefront.shell.wishlist', 'Wishlist')}
                    value={stats.wishlist ?? 0}
                    icon="heart"
                    tone="wishlist"
                    action={t('storefront.account.view', 'View')}
                />
                ) : null}
                <StatCard
                    to="/account/addresses"
                    label={t('storefront.account.addresses', 'Addresses')}
                    value={stats.addresses ?? 0}
                    icon="pin"
                    tone="addresses"
                    action={t('storefront.account.manage', 'Manage')}
                />
                {reviewsEnabled(store.shop) ? (
                <StatCard
                    to="/account/reviews"
                    label={t('storefront.account.reviews', 'Reviews')}
                    value={stats.reviews ?? 0}
                    icon="star"
                    tone="reviews"
                    action={t('storefront.account.view', 'View')}
                />
                ) : null}
            </div>

            <section className="shop-account-panel">
                <header className="shop-account-panel-head">
                    <h2>{t('storefront.account.recent', 'Recent orders')}</h2>
                    <Link to="/account/orders">
                        {t('storefront.account.view_all_orders', 'View All Orders')}
                        <Icon name="chevron" className="h-3.5 w-3.5" />
                    </Link>
                </header>

                {orders.length > 0 ? (
                    <div className="shop-account-order-table">
                        <div className="shop-account-order-head" aria-hidden="true">
                            <span>{t('storefront.account.order_number', 'Order #')}</span>
                            <span>{t('storefront.account.order_date', 'Date')}</span>
                            <span>{t('storefront.account.order_status', 'Status')}</span>
                            <span>{t('storefront.account.order_total', 'Total')}</span>
                            <span>{t('storefront.account.order_actions', 'Actions')}</span>
                        </div>
                        <ul className="shop-account-orders">
                        {orders.map((order) => {
                            const bucket = statusBucket(order.status);
                            const tone = statusTone(bucket);
                            return (
                            <li key={order.id}>
                                <Link to={`/account/orders/${order.number}`} className="shop-account-order">
                                    <span className="shop-account-order-id">{order.number}</span>
                                    <span>{orderDate(order.created_at || order.date)}</span>
                                    <span className={`shop-account-order-status is-${tone}`}>
                                        <i />
                                        {statusLabel(bucket, order.status)}
                                    </span>
                                    <strong>{formatMoney(order.grand_total, order.currency_code)}</strong>
                                    <span className="shop-account-order-view">{t('storefront.account.view', 'View')} <Icon name="chevron" className="h-3 w-3" /></span>
                                </Link>
                            </li>
                            );
                        })}
                        </ul>
                    </div>
                ) : (
                    <div className="shop-account-empty">
                        <EmptyOrdersArt />
                        <p className="shop-account-empty-title">{t('storefront.account.no_orders', 'No orders yet.')}</p>
                        <p className="shop-account-empty-body">{t('storefront.account.no_orders_body', "Looks like you haven't placed any orders yet.")}</p>
                        <Link to="/catalog" className="shop-account-empty-cta">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>
                    </div>
                )}
            </section>

            <div className="shop-account-banners">
                <article className="shop-account-banner is-benefits">
                    <span className="shop-account-banner-icon"><Icon name="gift" className="h-5 w-5" /></span>
                    <div>
                        <strong>{t('storefront.account.benefits_title', 'Exclusive member benefits')}</strong>
                        <p>{t('storefront.account.benefits_body', 'Early access to sales, member-only offers, and more.')}</p>
                        {couponsEnabled(store.shop) ? (
                        <Link to="/account/vouchers">
                            {t('storefront.account.explore_benefits', 'Explore benefits')}
                            <Icon name="chevron" className="h-3.5 w-3.5" />
                        </Link>
                        ) : null}
                    </div>
                </article>
                <article className="shop-account-banner is-secure">
                    <span className="shop-account-banner-icon"><Icon name="shield" className="h-5 w-5" /></span>
                    <div>
                        <strong>{t('storefront.account.secure_title', 'Secure & safe shopping')}</strong>
                        <p>{t('storefront.account.secure_body', 'Your data is protected with 256-bit SSL encryption.')}</p>
                        <Link to="/account/help">
                            {t('storefront.account.learn_more', 'Learn more')}
                            <Icon name="chevron" className="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </article>
            </div>
        </div>
    );
}

function StatCard({ to, label, value, icon, tone, action }) {
    return (
        <Link to={to} className={`shop-account-stat is-${tone}`}>
            <span className="shop-account-stat-icon"><Icon name={icon} className="h-5 w-5" /></span>
            <div className="shop-account-stat-body">
                <em>{label}</em>
                <strong>{value}</strong>
                <span>
                    {action}
                    <Icon name="chevron" className="h-3 w-3" />
                </span>
            </div>
        </Link>
    );
}

function EmptyOrdersArt() {
    return (
        <svg className="shop-account-empty-art" viewBox="0 0 160 110" fill="none" aria-hidden="true">
            <rect x="28" y="34" width="104" height="58" rx="14" fill="#EEF2FF" stroke="#C7D2FE" strokeWidth="2" />
            <path d="M52 34v-8a28 28 0 0 1 56 0v8" stroke="#818CF8" strokeWidth="2.2" strokeLinecap="round" />
            <path d="M52 58h56M52 72h36" stroke="#A5B4FC" strokeWidth="2" strokeLinecap="round" />
            <circle cx="118" cy="28" r="9" fill="#ECFDF5" stroke="#6EE7B7" strokeWidth="2" />
            <path d="m114.5 28 2.2 2.2 4.8-5" stroke="#059669" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}
