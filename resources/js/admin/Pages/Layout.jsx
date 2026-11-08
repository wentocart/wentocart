import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Flash,
    GrowlProvider,
    Icon,
    Select,
    ShopTree,
    Badge,
    Avatar,
    AiAssistantWidget,
    CommandPalette,
    NotificationBell,
    useCommandPalette,
    Loader,
    ui,
    cx,
    t,
    setI18nMessages,
    setPriceFormat,
    buildAdminMenu,
    pathFromAdminUrl,
} from '../ui';
import { recordJumpRecent } from '../ui/CommandPalette';
import { adminPathname } from '../webPath';
import { HeaderFixProgress } from './Settings/SeoFixLive';
import AdminSidebar from './AdminSidebar';

const SIDEBAR_COLLAPSED_KEY = 'wento-admin-sidebar-collapsed';

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

function visibleItems(items, canItem) {
    const visible = items.filter((item) => !item.hidden && (item.heading || canItem(item)));
    return visible.filter((item, index) => {
        if (!item.heading) {
            return true;
        }
        for (let next = index + 1; next < visible.length; next += 1) {
            if (visible[next].heading) {
                return false;
            }
            if (visible[next].href) {
                return true;
            }
        }
        return false;
    });
}

function shopHomeUrl(shop) {
    const raw = String(shop?.url || '/').trim();
    if (raw === '') {
        return '/';
    }

    return raw.endsWith('/') ? raw : `${raw}/`;
}

function readCollapsedPreference() {
    try {
        return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function ShopContextBar({ shop }) {
    return (
        <div className={ui.shopContextBar}>
            <ShopTree shop={shop} bar />
        </div>
    );
}

export default function Layout({ children, mainClassName }) {
    const { auth, shop, url, i18n, tools, appNav, moduleNav, jump } = usePage().props;
    const { open: paletteOpen, setOpen: setPaletteOpen } = useCommandPalette();
    setI18nMessages(i18n?.messages);
    setPriceFormat(shop?.pricing);

    useEffect(() => {
        const path = adminPathname(url) || window.location.pathname;
        const title = typeof document !== 'undefined' ? document.title.replace(/\s*[·|].*$/, '').trim() : '';
        recordJumpRecent(path, title || path);
    }, [url]);
    const menu = useMemo(
        () => buildAdminMenu(
            Boolean(shop?.multishop),
            appNav || [],
            moduleNav || [],
            Boolean(shop?.multivendor),
            Boolean(shop?.products?.out_of_stock_notification),
        ),
        [i18n?.locale, i18n?.messages, shop?.multishop, shop?.multivendor, shop?.products?.out_of_stock_notification, appNav, moduleNav],
    );
    const pathname = adminPathname(typeof window !== 'undefined' ? window.location.pathname : pathFromAdminUrl(url));
    useEffect(() => {
        window.__wentoAdminDirty = false;
        const mark = (event) => {
            const target = event.target;
            if (target && target.closest && target.closest('main')) {
                window.__wentoAdminDirty = true;
            }
        };
        document.addEventListener('input', mark);
        document.addEventListener('change', mark);

        return () => {
            document.removeEventListener('input', mark);
            document.removeEventListener('change', mark);
        };
    }, [pathname]);
    const perms = auth.admin?.permissions || [];
    const isSuper = Boolean(auth.admin?.is_super);
    const can = (item) => {
        if (!item) {
            return true;
        }
        if (item.super) {
            return isSuper;
        }
        const p = typeof item === 'string' ? item : item.permission;
        return !p || p === '*' || perms.includes('*') || perms.includes(p);
    };
    const admin = auth.admin;
    const roleLabel = isSuper ? t('admin.shell.super_admin', 'Super admin') : (admin?.profile || admin?.job_title || t('admin.shell.staff', 'Staff'));
    const allHrefs = useMemo(() => collectHrefs(menu), [menu]);
    const [open, setOpen] = useState(() => {
        try {
            const parsed = JSON.parse(sessionStorage.getItem('wento-admin-nav-open') || '{}');
            if (!parsed || typeof parsed !== 'object') {
                return {};
            }
            // Exclusive accordion: keep at most one open section from prior sessions.
            const openKeys = Object.entries(parsed).filter(([, value]) => value === true).map(([key]) => key);
            if (openKeys.length <= 1) {
                return parsed;
            }
            return { [openKeys[0]]: true };
        } catch {
            return {};
        }
    });
    const [collapsed, setCollapsed] = useState(readCollapsedPreference);
    const [profileOpen, setProfileOpen] = useState(false);
    const [navBusy, setNavBusy] = useState(false);
    const syncNavCount = useRef(0);
    const profileRef = useRef(null);
    const languages = i18n?.languages || [];

    const toggleCollapsed = () => {
        setCollapsed((cur) => {
            const next = !cur;
            try {
                localStorage.setItem(SIDEBAR_COLLAPSED_KEY, next ? '1' : '0');
            } catch {
                // Private mode may block localStorage.
            }
            return next;
        });
    };

    useEffect(() => {
        // Async visits (e.g. per-card extension Install/Delete) keep their own loaders.
        const isAsyncVisit = (event) => Boolean(event?.detail?.visit?.async);
        const offStart = router.on('start', (event) => {
            if (isAsyncVisit(event)) {
                return;
            }
            syncNavCount.current += 1;
            setNavBusy(true);
        });
        const offFinish = router.on('finish', (event) => {
            if (isAsyncVisit(event)) {
                return;
            }
            syncNavCount.current = Math.max(0, syncNavCount.current - 1);
            if (syncNavCount.current === 0) {
                setNavBusy(false);
            }
        });
        // A cancelled or non-Inertia response must not leave the white cover up.
        const clearNav = () => {
            syncNavCount.current = 0;
            setNavBusy(false);
        };
        const offHttpException = router.on('httpException', clearNav);
        return () => {
            offStart();
            offFinish();
            offHttpException();
        };
    }, []);

    useEffect(() => {
        if (!profileOpen) {
            return undefined;
        }
        const onDoc = (e) => {
            if (profileRef.current && !profileRef.current.contains(e.target)) {
                setProfileOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [profileOpen]);

    useEffect(() => {
        try {
            const openKeys = Object.entries(open).filter(([, value]) => value === true).map(([key]) => key);
            const exclusive = openKeys.length
                ? { [openKeys[0]]: true }
                : open;
            sessionStorage.setItem('wento-admin-nav-open', JSON.stringify(exclusive));
        } catch {
            // Private mode may block sessionStorage.
        }
    }, [open]);

    const visibleMenu = menu.map((node) => {
        if (node.super && !isSuper) {
            return null;
        }
        if (!node.items) {
            return can(node) ? node : null;
        }
        const items = visibleItems(
            node.items,
            (item) => can({ ...node, ...item, permission: item.permission || node.permission, super: item.super || node.super }),
        );
        if (!items.length) {
            return null;
        }
        return { ...node, items };
    }).filter(Boolean);

    return (
        <GrowlProvider>
        <div
            className={ui.layout}
            style={{ '--admin-sidebar-width': collapsed ? '64px' : '248px' }}
            data-admin-layout={collapsed ? 'sidebar-collapsed' : 'sidebar-expanded'}
        >
            <AdminSidebar
                shop={shop}
                visibleMenu={visibleMenu}
                pathname={pathname}
                allHrefs={allHrefs}
                open={open}
                setOpen={setOpen}
                collapsed={collapsed}
                onToggleCollapse={toggleCollapsed}
            />
            <div className={ui.shell}>
                <header className={ui.topbar}>
                    <button type="button" className={ui.topSearch} onClick={() => setPaletteOpen(true)}>
                        <Icon name="search" />
                        <span>{t('admin.shell.search', 'Search products, orders, customers…')}</span>
                        <span className="ml-auto text-xs text-neutral-400">{jump?.label || 'Ctrl+F'}</span>
                    </button>
                    <div className={ui.topActions}>
                        <HeaderFixProgress />
                        {tools?.profiler ? (
                            <Badge tone="warning" dot={false}>{t('admin.shell.profiler_on', 'Profiler on')}</Badge>
                        ) : null}
                        {tools?.maintenance ? (
                            <Badge tone="danger" dot={false}>{t('admin.shell.maintenance_on', 'Maintenance')}</Badge>
                        ) : null}
                        {languages.length > 1 ? (
                            <div className="flex items-center gap-2">
                                <span className={ui.muted}>
                                    {t('admin.shell.default', 'Default')}: {i18n?.default_name || i18n?.default}
                                </span>
                                <Select
                                    value={i18n?.locale || ''}
                                    onChange={(e) => router.post('/admin/locale', { locale: e.target.value }, { preserveScroll: true })}
                                    aria-label={t('admin.shell.current', 'Current')}
                                >
                                    {languages.map((language) => (
                                        <option key={language.code} value={language.code}>{language.name}</option>
                                    ))}
                                </Select>
                            </div>
                        ) : null}
                        <a
                            href={shopHomeUrl(shop)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={ui.topShopLink}
                        >
                            <Icon name="globe" />
                            {t('admin.shell.view_shop', 'View Shop')}
                        </a>
                        <NotificationBell />
                        <div className={ui.topProfile} ref={profileRef}>
                            <button
                                type="button"
                                className={ui.topProfileBtn}
                                aria-expanded={profileOpen}
                                aria-haspopup="menu"
                                onClick={() => setProfileOpen((v) => !v)}
                            >
                                <Avatar name={admin?.name || 'Admin'} src={admin?.avatar_url} />
                                <div className="text-right leading-tight">
                                    <div className={ui.topProfileName}>{admin?.name || 'Admin'}</div>
                                    <div className={ui.topProfileRole}>{roleLabel}</div>
                                </div>
                            </button>
                            {profileOpen ? (
                                <div className={ui.topProfileMenu} role="menu">
                                    <Link
                                        href="/admin/account"
                                        className={ui.topProfileMenuItem}
                                        role="menuitem"
                                        onClick={() => setProfileOpen(false)}
                                    >
                                        <Icon name="eye" />
                                        {t('admin.account.view_profile', 'View profile')}
                                    </Link>
                                    <Link
                                        href="/admin/account/edit"
                                        className={ui.topProfileMenuItem}
                                        role="menuitem"
                                        onClick={() => setProfileOpen(false)}
                                    >
                                        <Icon name="pencil" />
                                        {t('admin.account.edit_profile', 'Edit profile')}
                                    </Link>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </header>
                {shop?.multishop ? <ShopContextBar shop={shop} /> : null}
                <main className={cx(ui.main, mainClassName)} scroll-region="true">
                    <Flash />
                    {children}
                </main>
                <AiAssistantWidget />
                <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
                {navBusy ? (
                    <>
                        <div className={ui.navProgress} role="progressbar" aria-hidden />
                        <div className={ui.navProgressScrim} role="status" aria-live="polite">
                            <Loader />
                        </div>
                    </>
                ) : null}
            </div>
        </div>
        </GrowlProvider>
    );
}
