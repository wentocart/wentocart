import { NavLink, Outlet, Navigate, useLocation } from 'react-router-dom';
import { useEffect, useMemo, useState } from 'react';
import api from '../../api.js';
import { t } from '../../i18n.js';
import { useStore } from '../../state/StoreState.jsx';
import { PageSkeleton } from '../../components/Feedback.jsx';
import Breadcrumbs from '../../components/Breadcrumbs.jsx';
import { Icon } from '../../components/Icons.jsx';
import SellerPortalHeaderActions from '../../components/SellerPortalHeaderActions.jsx';
import { mediaUrl } from '../../lib/format.js';
import { navIsActive, sellerMenus } from './sellerNav.js';
import { buildSellerBreadcrumbs } from './sellerBreadcrumbs.js';
import '../../multivendor.css';

/** Application / status page lives under the customer account (same tab). */
export const SELLER_APPLY_PATH = '/account/become-seller';

function canAccessSellerPortal(seller) {
    if (!seller) {
        return false;
    }
    if (typeof seller.can_access_portal === 'boolean') {
        return seller.can_access_portal;
    }
    return !!seller.is_approved;
}

function shopInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return 'S';
    }
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

function SellerBrand({ seller }) {
    const name = seller?.shop_name || t('storefront.seller.dashboard', 'Seller Dashboard');
    return (
        <div className="mv-portal-brand">
            {seller?.logo_url ? (
                <img src={mediaUrl(seller.logo_url) || seller.logo_url} alt="" className="mv-portal-logo" />
            ) : (
                <span className="mv-portal-logo-fallback" aria-hidden="true">{shopInitials(name)}</span>
            )}
            <span className="mv-portal-shop-name">{name}</span>
        </div>
    );
}

function navLabel(item) {
    const key = item.to.split('/').pop()?.split('#')[0] || 'home';
    return t(`storefront.seller.menu.${key}`, item.label);
}

function SellerNav({ onNavigate }) {
    const { pathname } = useLocation();

    return (
        <nav className="mv-portal-nav" aria-label={t('storefront.seller.dashboard', 'Seller Dashboard')}>
            {sellerMenus.map((item) => (
                <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className={({ isActive }) => {
                        const active = isActive || navIsActive(item, pathname);
                        return `mv-portal-nav-link${active ? ' is-active' : ''}`;
                    }}
                    onClick={onNavigate}
                >
                    {item.icon ? <Icon name={item.icon} className="mv-portal-nav-icon" /> : null}
                    {navLabel(item)}
                </NavLink>
            ))}
        </nav>
    );
}

function PortalLoading() {
    return (
        <div className="mv-portal">
            <div className="mv-portal-loading">
                <PageSkeleton />
            </div>
        </div>
    );
}

/**
 * Dedicated Seller Portal shell.
 * Only approved/active sellers (canAccessPortal) may stay here.
 * Everyone else is sent to the in-account application/status page.
 */
export default function SellerLayout() {
    const store = useStore();
    const location = useLocation();
    const [seller, setSeller] = useState(undefined);
    const [menuOpen, setMenuOpen] = useState(false);
    const [sellerBreadcrumb, setSellerBreadcrumb] = useState(null);

    const breadcrumbItems = useMemo(
        () => buildSellerBreadcrumbs(location.pathname, { tailLabel: sellerBreadcrumb }),
        [location.pathname, sellerBreadcrumb],
    );

    useEffect(() => {
        setSellerBreadcrumb(null);
    }, [location.pathname]);

    useEffect(() => {
        // Wait for shop bootstrap — do not treat "shop still loading" as multivendor off.
        if (!store.shop) {
            return undefined;
        }
        if (!store.shop.features?.multivendor) {
            setSeller(null);
            return undefined;
        }
        if (store.customer === undefined) {
            return undefined;
        }
        if (store.customer === null) {
            setSeller(null);
            return undefined;
        }

        let cancelled = false;
        api.get('/account/seller')
            .then((r) => {
                if (!cancelled) {
                    setSeller(r.data.seller ?? null);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    // Fall back to session payload so a transient API blip does not bounce approved sellers out.
                    setSeller(store.customer?.seller ?? null);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [store.customer?.id, store.shop, store.customer]);

    useEffect(() => {
        setMenuOpen(false);
    }, [location.pathname]);

    if (!store.shop || store.customer === undefined || seller === undefined) {
        return <PortalLoading />;
    }

    if (!store.shop.features?.multivendor) {
        return <Navigate to="/account" replace />;
    }

    if (!store.customer) {
        return <Navigate to="/login" replace />;
    }

    if (!canAccessSellerPortal(seller)) {
        return <Navigate to={SELLER_APPLY_PATH} replace />;
    }

    return (
        <div className={`mv-portal${menuOpen ? ' is-menu-open' : ''}`}>
            <header className="mv-portal-header">
                <button
                    type="button"
                    className="mv-portal-menu-btn"
                    aria-expanded={menuOpen}
                    aria-controls="mv-portal-sidebar"
                    onClick={() => setMenuOpen((open) => !open)}
                >
                    {menuOpen
                        ? t('storefront.seller.close_menu', 'Close')
                        : t('storefront.seller.menu', 'Menu')}
                </button>
                <SellerBrand seller={seller} />
                <SellerPortalHeaderActions seller={seller} />
            </header>

            {menuOpen ? (
                <button
                    type="button"
                    className="mv-portal-backdrop"
                    aria-label={t('storefront.seller.close_menu', 'Close')}
                    onClick={() => setMenuOpen(false)}
                />
            ) : null}

            <div className="mv-portal-shell">
                <aside id="mv-portal-sidebar" className="mv-portal-sidebar">
                    <SellerNav onNavigate={() => setMenuOpen(false)} />
                </aside>
                <main className="mv-portal-content">
                    <Breadcrumbs items={breadcrumbItems} />
                    <Outlet context={{ seller, setSeller, setSellerBreadcrumb }} />
                </main>
            </div>
        </div>
    );
}

export function RequireSeller({ children }) {
    const store = useStore();
    if (store.customer && store.customer.seller && !canAccessSellerPortal(store.customer.seller)) {
        return <Navigate to={SELLER_APPLY_PATH} replace />;
    }
    return children;
}
