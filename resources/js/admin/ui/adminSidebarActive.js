/**
 * Pure sidebar active-route helpers (no React).
 * Used by AdminSidebar and unit-tested without a browser.
 */

export function menuItemHrefs(item) {
    return [item.href, ...(Array.isArray(item.match) ? item.match : [])].filter(Boolean);
}

export function isActive(pathname, href, allHrefs) {
    if (href === '/admin') {
        return pathname === '/admin' || pathname === '/admin/';
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

export function itemIsActive(pathname, item, allHrefs) {
    return menuItemHrefs(item).some((href) => isActive(pathname, href, allHrefs));
}

/**
 * Pick the menu section that owns the best (longest) matching href.
 * Avoids the first-match win when two sections could partially overlap.
 */
export function findActiveSection(menu, pathname, allHrefs) {
    let best = null;
    let bestLen = -1;

    (menu || []).forEach((node) => {
        if (!Array.isArray(node?.items)) {
            return;
        }
        node.items.forEach((item) => {
            if (!item?.href) {
                return;
            }
            menuItemHrefs(item).forEach((href) => {
                if (isActive(pathname, href, allHrefs) && href.length > bestLen) {
                    best = node;
                    bestLen = href.length;
                }
            });
        });
    });

    return best;
}

export function sectionKey(node) {
    return node?.id || node?.label;
}

/**
 * Parent row highlight must follow the route, not accordion expand state.
 * Only the winning active section (longest href match) may highlight — never
 * an expanded-but-unrelated parent, even if a shorter href also matched.
 */
export function parentRowIsHighlighted(childActive, sectionId = null, activeSectionId = null) {
    if (!childActive) {
        return false;
    }
    if (activeSectionId == null || sectionId == null) {
        return true;
    }

    return String(sectionId) === String(activeSectionId);
}

/**
 * After navigation (or correcting a stale session), open only the active section.
 * Preserve explicit all-collapsed state (`{ key: false }`) and idle `{}`.
 */
export function openStateForActiveSection(currentOpen, activeKey, { pathChanged } = {}) {
    if (!activeKey) {
        return currentOpen || {};
    }
    if (pathChanged) {
        return { [activeKey]: true };
    }

    const cur = currentOpen && typeof currentOpen === 'object' ? currentOpen : {};
    const entries = Object.entries(cur);
    const openKeys = entries.filter(([, value]) => value === true).map(([key]) => key);

    if (openKeys.length === 0) {
        return cur;
    }
    if (openKeys.length === 1 && openKeys[0] === activeKey) {
        return cur;
    }

    return { [activeKey]: true };
}
