/** Shop folder when admin is not at the domain root (e.g. /shop). */
export function adminSegment() {
    const meta = document.querySelector('meta[name="admin-segment"]')?.getAttribute('content') || 'admin';

    return /^[A-Za-z][A-Za-z0-9-]{2,39}$/.test(meta) ? meta : 'admin';
}

/** Replace the public /admin segment. Other /admin text in a path is left alone. */
export function toPublicAdminPath(path) {
    const segment = adminSegment();
    if (segment === 'admin' || typeof path !== 'string' || path === '') {
        return path;
    }
    const base = shopBasePath();
    const from = `${base}/admin`;
    const to = `${base}/${segment}`;
    if (path === from || path.startsWith(`${from}/`) || path.startsWith(`${from}?`) || path.startsWith(`${from}#`)) {
        return to + path.slice(from.length);
    }

    return path;
}

export function shopBasePath() {
    const meta = document.querySelector('meta[name="app-base"]')?.getAttribute('content');
    if (meta) {
        return String(meta).replace(/\/$/, '');
    }
    const path = window.location.pathname || '';
    const segment = adminSegment();
    const match = path.match(new RegExp(`^(/.*?)/${segment}(?:/|$)`));

    return match ? match[1] : '';
}

export function adminUrl(href) {
    if (href == null || href === '') {
        return href;
    }
    if (typeof href !== 'string') {
        return href;
    }
    if (/^(mailto:|tel:|#)/i.test(href)) {
        return href;
    }
    if (/^https?:\/\//i.test(href)) {
        try {
            const parsed = new URL(href);
            if (parsed.origin === window.location.origin) {
                parsed.pathname = toPublicAdminPath(prefixPath(parsed.pathname));

                return `${parsed.pathname}${parsed.search}${parsed.hash}`;
            }
        } catch {
            return href;
        }

        return href;
    }

    const path = href.startsWith('/') ? href : `/${href}`;

    return toPublicAdminPath(prefixPath(path));
}

function prefixPath(path) {
    const base = shopBasePath();
    if (!base) {
        return path;
    }
    while (path.startsWith(base + base)) {
        path = path.slice(base.length);
    }
    if (path === base || path.startsWith(`${base}/`)) {
        return path;
    }

    return `${base}${path}`;
}

/** Admin route path without the Apache folder prefix (e.g. /shop/admin → /admin). */
export function adminPathname(pathname) {
    const raw = String(pathname || '').split('?')[0];
    let trimmed = raw.replace(/\/+$/, '') || '/';
    const base = shopBasePath();
    const segment = adminSegment();
    if (segment !== 'admin') {
        const from = `${base}/${segment}`;
        if (trimmed === from || trimmed.startsWith(`${from}/`)) {
            trimmed = `${base}/admin${trimmed.slice(from.length)}`;
        }
    }
    if (base && (trimmed === base || trimmed.startsWith(`${base}/`))) {
        return trimmed.slice(base.length) || '/';
    }

    return trimmed;
}
