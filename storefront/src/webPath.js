const PUBLIC_ASSET = /^\/(build|themes|plugins|hot|images|storage)(\/|$)/;

/** Shop folder plus virtual path (e.g. /shop/outlet). */
export function shopBasePath() {
    return String(window.__WENTO__?.base_path || '').replace(/\/$/, '');
}

/** Install folder only. Built files and uploads are served from here. */
export function assetBasePath() {
    if (window.__WENTO__ && Object.prototype.hasOwnProperty.call(window.__WENTO__, 'asset_base')) {
        return String(window.__WENTO__.asset_base || '').replace(/\/$/, '');
    }

    return shopBasePath();
}

function looksLikePublicAsset(pathname) {
    if (PUBLIC_ASSET.test(pathname)) {
        return true;
    }
    for (const base of [shopBasePath(), assetBasePath()]) {
        if (base && pathname.startsWith(`${base}/`) && PUBLIC_ASSET.test(pathname.slice(base.length))) {
            return true;
        }
    }

    return false;
}

export function webPath(path) {
    const value = String(path || '');
    if (!value || value.startsWith('blob:') || value.startsWith('//')) {
        return value;
    }
    let pathname = value;
    let suffix = '';
    if (/^https?:\/\//i.test(value)) {
        try {
            const parsed = new URL(value);
            if (parsed.origin !== window.location.origin) {
                return value;
            }
            pathname = parsed.pathname;
            suffix = `${parsed.search}${parsed.hash}`;
        } catch {
            return value;
        }
    } else if (!value.startsWith('/')) {
        pathname = `/${value}`;
    }
    if (looksLikePublicAsset(pathname)) {
        const shop = shopBasePath();
        const install = assetBasePath();
        if (shop && shop !== install && (pathname === shop || pathname.startsWith(`${shop}/`))) {
            pathname = pathname.slice(shop.length) || '/';
        }
        if (install) {
            while (pathname.startsWith(install + install)) {
                pathname = pathname.slice(install.length);
            }
            if (pathname !== install && !pathname.startsWith(`${install}/`)) {
                pathname = `${install}${pathname}`;
            }
        }

        return `${pathname}${suffix}`;
    }
    const base = shopBasePath();
    if (base) {
        while (pathname.startsWith(base + base)) {
            pathname = pathname.slice(base.length);
        }
        if (pathname !== base && !pathname.startsWith(`${base}/`)) {
            pathname = `${base}${pathname}`;
        }
    }

    return `${pathname}${suffix}`;
}
