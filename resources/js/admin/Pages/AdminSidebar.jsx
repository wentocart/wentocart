import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { catalogImageUrl } from '../lib/catalogMedia';
import { Icon, Tooltip, shopContextTitle, ui, cx, t } from '../ui';
import {
    findActiveSection,
    isActive,
    itemIsActive,
    openStateForActiveSection,
    parentRowIsHighlighted,
    sectionKey,
} from '../ui/adminSidebarActive';
import { sidebarLogoPath } from '../ui/adminSidebarLogo';

export {
    findActiveSection,
    isActive,
    itemIsActive,
    openStateForActiveSection,
    parentRowIsHighlighted,
    sectionKey,
} from '../ui/adminSidebarActive';
export { sidebarLogoPath, DEFAULT_SHOP_LOGO, DEFAULT_SHOP_LOGO_SHORT } from '../ui/adminSidebarLogo';

function CollapsedGroup({ node, pathname, allHrefs, groupKey, activeSectionId }) {
    const [flyoutOpen, setFlyoutOpen] = useState(false);
    const [coords, setCoords] = useState(null);
    const triggerRef = useRef(null);
    const flyoutRef = useRef(null);
    const closeTimer = useRef(null);
    const childActive = node.items.some((item) => item.href && itemIsActive(pathname, item, allHrefs));
    const highlighted = parentRowIsHighlighted(childActive, groupKey, activeSectionId);

    const clearClose = () => {
        if (closeTimer.current) {
            window.clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    };

    const openFlyout = () => {
        clearClose();
        setFlyoutOpen(true);
    };

    const scheduleClose = () => {
        clearClose();
        closeTimer.current = window.setTimeout(() => setFlyoutOpen(false), 120);
    };

    const updatePosition = useCallback(() => {
        const trigger = triggerRef.current;
        if (!trigger) {
            return;
        }
        const rect = trigger.getBoundingClientRect();
        const gap = 6;
        const pad = 8;
        const flyout = flyoutRef.current;
        const height = flyout?.offsetHeight || 240;
        let top = rect.top;
        if (top + height > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - height - pad);
        }
        setCoords({ top, left: rect.right + gap });
    }, []);

    useLayoutEffect(() => {
        if (!flyoutOpen) {
            return undefined;
        }
        updatePosition();
        const frame = window.requestAnimationFrame(updatePosition);
        const onReposition = () => updatePosition();
        window.addEventListener('scroll', onReposition, true);
        window.addEventListener('resize', onReposition);
        return () => {
            window.cancelAnimationFrame(frame);
            window.removeEventListener('scroll', onReposition, true);
            window.removeEventListener('resize', onReposition);
        };
    }, [flyoutOpen, updatePosition, node.items.length]);

    useEffect(() => () => clearClose(), []);

    const flyout = flyoutOpen && typeof document !== 'undefined'
        ? createPortal(
            <div
                ref={flyoutRef}
                className={ui.navFlyout}
                data-admin-sidebar-flyout=""
                style={{
                    top: coords?.top ?? -9999,
                    left: coords?.left ?? -9999,
                    visibility: coords ? 'visible' : 'hidden',
                }}
                role="menu"
                aria-label={node.label}
                onMouseEnter={openFlyout}
                onMouseLeave={scheduleClose}
            >
                <div className={ui.navFlyoutTitle}>{node.label}</div>
                {node.items.map((item) => (
                    item.heading ? (
                        <div key={item.heading} className={ui.navFlyoutSection}>{item.heading}</div>
                    ) : (
                        <Link
                            key={item.href}
                            href={item.href}
                            role="menuitem"
                            className={itemIsActive(pathname, item, allHrefs) ? ui.navFlyoutLinkActive : ui.navFlyoutLink}
                            onClick={() => setFlyoutOpen(false)}
                        >
                            {item.label}
                        </Link>
                    )
                ))}
            </div>,
            document.body,
        )
        : null;

    return (
        <div
            className={ui.navGroup}
            onMouseEnter={openFlyout}
            onMouseLeave={scheduleClose}
        >
            <button
                ref={triggerRef}
                type="button"
                className={highlighted ? ui.navIconBtnActive : ui.navIconBtn}
                aria-label={node.label}
                aria-expanded={flyoutOpen}
                aria-haspopup="menu"
                data-nav-group={groupKey}
                onClick={() => {
                    if (flyoutOpen) {
                        scheduleClose();
                    } else {
                        openFlyout();
                    }
                }}
            >
                {highlighted ? <span className={ui.navIconActiveBar} aria-hidden /> : null}
                <Icon name={node.icon} />
            </button>
            {flyout}
        </div>
    );
}

/** Exclusive accordion: at most one section key is true. */
export function toggleExclusiveSection(open, key, currentlyExpanded) {
    if (currentlyExpanded) {
        // Explicit close — do not fall back to the active-route parent.
        return { [key]: false };
    }
    return { [key]: true };
}

/**
 * Resolve whether a section is expanded.
 * - One explicit `true` wins (exclusive open).
 * - Any explicit `false` means the user collapsed something: keep all closed
 *   (do not auto-reopen the active page's parent until navigation resets state).
 * - Empty `{}` follows the active route parent (initial load / unset).
 */
export function sectionIsExpanded(open, key, isActiveParent) {
    const entries = Object.entries(open || {});
    const explicitOpen = entries.find(([, value]) => value === true);
    if (explicitOpen) {
        return explicitOpen[0] === key;
    }
    if (entries.some(([, value]) => value === false)) {
        return false;
    }
    return Boolean(isActiveParent);
}

export default function AdminSidebar({
    shop,
    visibleMenu,
    pathname,
    allHrefs,
    open,
    setOpen,
    collapsed,
    onToggleCollapse,
}) {
    const pathRef = useRef(pathname);
    const activeSection = findActiveSection(visibleMenu, pathname, allHrefs);
    const activeSectionId = activeSection ? sectionKey(activeSection) : null;

    useEffect(() => {
        const key = activeSectionId;
        const pathChanged = pathRef.current !== pathname;
        pathRef.current = pathname;
        if (!key) {
            return;
        }
        // Navigation / refresh: keep the active route's parent expanded; correct
        // stale sessionStorage opens (e.g. Main menu still open on a Design URL).
        setOpen((cur) => openStateForActiveSection(cur, key, { pathChanged }));
    }, [pathname, activeSectionId, setOpen]);

    const groupOpen = (node) => {
        const key = sectionKey(node);
        const isActiveParent = key === activeSectionId;
        return sectionIsExpanded(open, key, isActiveParent);
    };

    // Single collapsed flag (same as layout width) — no lagged iconMode remount mid-transition.
    const toggleLabel = collapsed
        ? t('admin.shell.expand_sidebar', 'Expand sidebar')
        : t('admin.shell.collapse_sidebar', 'Collapse sidebar');

    const toggleButton = (
        <button
            type="button"
            className={ui.sidebarToggle}
            onClick={onToggleCollapse}
            aria-label={toggleLabel}
            aria-pressed={collapsed}
            title={toggleLabel}
            data-sidebar-toggle="brand"
        >
            <Icon name={collapsed ? 'chevron-right' : 'chevron-left'} />
        </button>
    );

    const brand = shop?.context === 'group'
        ? shopContextTitle(shop)
        : (shop?.name || '');
    // Logo follows the same collapsed flag as chrome/width so it never desyncs mid-animation.
    const logoPath = sidebarLogoPath({ logoUrl: shop?.logo_url, collapsed });
    const logoSrc = catalogImageUrl(logoPath);
    const [logoBroken, setLogoBroken] = useState(false);

    useEffect(() => {
        setLogoBroken(false);
    }, [logoSrc]);

    const showLogo = Boolean(logoSrc) && !logoBroken;
    const brandMark = showLogo ? (
        <img
            src={logoSrc}
            alt={brand || 'Store'}
            className={collapsed ? ui.brandLogoCollapsed : ui.brandLogo}
            style={collapsed
                ? { height: 32, width: 'auto', maxWidth: 40, objectFit: 'contain' }
                : { height: 46, width: 'auto', maxWidth: 168, objectFit: 'contain', objectPosition: 'left' }}
            onError={() => setLogoBroken(true)}
        />
    ) : (
        <span className={ui.brandMark}>{(brand || 'W').slice(0, 1).toUpperCase()}</span>
    );

    return (
        <aside
            className={collapsed ? ui.sidebarCollapsed : ui.sidebar}
            data-admin-sidebar={collapsed ? 'collapsed' : 'expanded'}
        >
            <div className={collapsed ? ui.brandCollapsed : ui.brand}>
                {collapsed ? (
                    <Tooltip content={brand || t('admin.nav.dashboard', 'Dashboard')} placement="right" portal>
                        <Link href="/admin" className={ui.brandLinkCollapsed} aria-label={brand || t('admin.nav.dashboard', 'Dashboard')}>
                            {brandMark}
                        </Link>
                    </Tooltip>
                ) : (
                    <Link href="/admin" className={ui.brandLink} aria-label={brand || t('admin.nav.dashboard', 'Dashboard')}>
                        {brandMark}
                        {showLogo ? null : <span className={ui.brandName}>{brand}</span>}
                    </Link>
                )}
                {toggleButton}
            </div>
            <nav
                className={cx(collapsed ? ui.navCollapsed : ui.nav, ui.navScroll)}
                data-admin-sidebar-nav=""
                aria-label={t('admin.shell.main_nav', 'Main navigation')}
            >
                {visibleMenu.map((node) => {
                    if (!node.items) {
                        const active = isActive(pathname, node.href, allHrefs);
                        const linkClass = collapsed
                            ? (active ? ui.navIconBtnActive : ui.navIconBtn)
                            : (active ? ui.navLinkActive : ui.navLink);
                        const body = (
                            <>
                                {collapsed && active ? <span className={ui.navIconActiveBar} aria-hidden /> : null}
                                <Icon name={node.icon} />
                                {collapsed ? null : node.label}
                            </>
                        );
                        const link = node.native ? (
                            <a
                                key={node.href}
                                href={node.href}
                                className={linkClass}
                                aria-label={collapsed ? node.label : undefined}
                                aria-current={active ? 'page' : undefined}
                            >
                                {body}
                            </a>
                        ) : (
                            <Link
                                key={node.href}
                                href={node.href}
                                className={linkClass}
                                aria-label={collapsed ? node.label : undefined}
                                aria-current={active ? 'page' : undefined}
                            >
                                {body}
                            </Link>
                        );

                        if (!collapsed) {
                            return link;
                        }

                        return (
                            <Tooltip key={node.href} content={node.label} placement="right" portal>
                                {link}
                            </Tooltip>
                        );
                    }

                    const key = sectionKey(node);

                    if (collapsed) {
                        return (
                            <CollapsedGroup
                                key={key}
                                node={node}
                                pathname={pathname}
                                allHrefs={allHrefs}
                                groupKey={key}
                                activeSectionId={activeSectionId}
                            />
                        );
                    }

                    const expanded = groupOpen(node);
                    const childActive = key === activeSectionId;
                    const highlighted = parentRowIsHighlighted(childActive, key, activeSectionId);

                    return (
                        <div key={key} className={ui.navGroup} data-nav-accordion-group={key}>
                            <button
                                type="button"
                                className={highlighted ? ui.navParentOpen : ui.navParent}
                                onClick={() => setOpen((cur) => toggleExclusiveSection(cur, key, expanded))}
                                aria-expanded={expanded}
                                data-nav-group={key}
                                data-nav-active={highlighted ? 'true' : 'false'}
                            >
                                <Icon name={node.icon} />
                                {node.label}
                                <Icon name="chevron-right" className={expanded ? ui.navCaretOpen : ui.navCaret} />
                            </button>
                            <div
                                className={expanded ? ui.navAccordionOpen : ui.navAccordion}
                                data-nav-accordion={expanded ? 'open' : 'closed'}
                            >
                                <div className={ui.navAccordionClip}>
                                    <div className={ui.navChildren} role="group">
                                        {node.items.map((item) => (
                                            item.heading ? (
                                                <div key={item.heading} className={ui.navSection}>{item.heading}</div>
                                            ) : (
                                                <Link
                                                    key={item.href}
                                                    href={item.href}
                                                    className={itemIsActive(pathname, item, allHrefs) ? ui.navChildLinkActive : ui.navChildLink}
                                                    tabIndex={expanded ? 0 : -1}
                                                    onClick={() => setOpen(() => ({ [key]: true }))}
                                                    aria-current={itemIsActive(pathname, item, allHrefs) ? 'page' : undefined}
                                                >
                                                    {item.label}
                                                </Link>
                                            )
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </nav>
            {collapsed ? (
                <Tooltip content={t('admin.shell.log_out', 'Log out')} placement="right" portal>
                    <button
                        type="button"
                        className={ui.logoutCollapsed}
                        onClick={() => router.post('/admin/logout')}
                        aria-label={t('admin.shell.log_out', 'Log out')}
                    >
                        <Icon name="logout" />
                    </button>
                </Tooltip>
            ) : (
                <button type="button" className={ui.logout} onClick={() => router.post('/admin/logout')}>
                    <Icon name="logout" />
                    {t('admin.shell.log_out', 'Log out')}
                </button>
            )}
        </aside>
    );
}
