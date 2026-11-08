import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import api from '../api.js';
import Product from './Product.jsx';
import Category from './Category.jsx';
import Brand from './Brand.jsx';
import Supplier from './Supplier.jsx';
import Cms from './Cms.jsx';
import NotFound from './NotFound.jsx';
import {
    BrandPageSkeleton,
    CategoryPageSkeleton,
    CmsPageSkeleton,
    PageSkeleton,
    ProductPageSkeleton,
    skeletonForPath,
} from '../components/Feedback.jsx';
import { jumpToTop } from '../lib/scroll.js';

const HINTS = new Set(['product', 'category', 'brand', 'supplier', 'cms']);

function serverMarkedMissing(path) {
    const boot = typeof window !== 'undefined' ? window.__WENTO__ : null;
    if (!boot?.not_found) {
        return false;
    }
    const bootPath = String(boot.path || '').replace(/^\//, '');
    return bootPath === String(path || '').replace(/^\//, '');
}

/**
 * Paint the matching page as soon as the click tells us the entity type
 * (Link state). Confirm with /catalog/open. A URL the server already
 * marked missing paints the 404 immediately instead of an empty frame.
 */
export default function SmartPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const path = location.pathname.replace(/^\//, '');
    const hint = HINTS.has(location.state?.entityType) ? location.state.entityType : null;
    const [kind, setKind] = useState(hint || (serverMarkedMissing(path) ? 'missing' : 'loading'));
    const [activePath, setActivePath] = useState(path);
    const [payload, setPayload] = useState(null);
    const resolvedPath = useRef(path);

    // Query-only updates (filters, sort, page) drop Link state. Resetting here
    // would paint the skeleton and never refetch because the path did not change.
    useLayoutEffect(() => {
        if (resolvedPath.current === path) {
            if (hint) {
                setKind((current) => (current === 'loading' ? hint : current));
            }
            return;
        }
        resolvedPath.current = path;
        jumpToTop();
        setPayload(null);
        setActivePath(path);
        if (hint) {
            setKind(hint);
        } else if (serverMarkedMissing(path)) {
            setKind('missing');
        } else {
            setKind('loading');
        }
    }, [path, hint]);

    useEffect(() => {
        let ignore = false;
        const params = Object.fromEntries(new URLSearchParams(location.search));
        api.get(`/catalog/open/${path}`, { params })
            .then((r) => {
                if (ignore) {
                    return;
                }
                if (r.data.kind === 'redirect' && r.data.redirect_to && r.data.redirect_to !== path) {
                    navigate('/' + r.data.redirect_to, { replace: true });
                    return;
                }
                resolvedPath.current = path;
                setActivePath(path);
                setPayload(r.data.payload || null);
                setKind(r.data.kind || 'missing');
            })
            .catch(() => {
                if (!ignore) {
                    resolvedPath.current = path;
                    setActivePath(path);
                    setPayload(null);
                    setKind('missing');
                }
            });
        return () => { ignore = true; };
    }, [path, navigate]);

    if (kind === 'loading') {
        return skeletonForPath(path);
    }
    if (kind === 'missing') {
        return <NotFound />;
    }
    if (kind === 'product') {
        return activePath === path && payload ? <Product slug={activePath} initial={payload} /> : <ProductPageSkeleton />;
    }
    if (kind === 'category') {
        return activePath === path && payload ? <Category slug={activePath} initial={payload} /> : <CategoryPageSkeleton />;
    }
    if (kind === 'brand') {
        return activePath === path && payload ? <Brand slug={activePath} initial={payload} /> : <BrandPageSkeleton />;
    }
    if (kind === 'supplier') {
        return payload ? <Supplier slug={activePath} initial={payload} /> : <PageSkeleton />;
    }
    return payload ? <Cms slug={activePath} initial={payload} /> : <CmsPageSkeleton />;
}
