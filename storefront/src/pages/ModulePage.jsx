import { useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../api.js';
import { FormErrorBanner } from '../components/Form.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import ModuleBlocks from '../components/ModuleBlocks.jsx';
import ModuleList from '../components/ModuleList.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';

const MODULE_CONFIG_PREFIX = 'module-config:';

export default function ModulePage() {
    const { vendor, name, page } = useParams();
    const [searchParams] = useSearchParams();
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        setError('');
        setData(null);
        const query = searchParams.toString();
        const suffix = query ? `?${query}` : '';
        api.get(`/modules/${vendor}/${name}/${page}${suffix}`)
            .then((r) => setData(r.data))
            .catch((err) => {
                const status = err?.response?.status;
                if (status === 401) {
                    setError('Please sign in to view this page.');
                } else if (status === 404) {
                    setError('This app page was not found. Is the extension enabled?');
                } else {
                    setError('This app page is not available.');
                }
            });
    }, [vendor, name, page, searchParams]);

    useEffect(() => {
        if (data?.document?.redirect) {
            window.location.replace(data.document.redirect);
        }
    }, [data?.document?.redirect]);

    useEffect(() => {
        if (!data?.js?.length) {
            return undefined;
        }
        const scripts = data.js.map((src) => {
            const el = document.createElement('script');
            el.src = src;
            el.defer = true;
            document.body.appendChild(el);
            return el;
        });
        return () => {
            scripts.forEach((el) => el.remove());
        };
    }, [data?.js]);

    const parsed = useMemo(() => {
        if (!data) {
            return { blocks: [], moduleConfig: null, mountId: null };
        }
        let moduleConfig = null;
        const blocks = (data.document?.blocks || []).filter((block) => {
            if (block?.type === 'text' && String(block.text || '').startsWith(MODULE_CONFIG_PREFIX)) {
                try {
                    moduleConfig = JSON.parse(String(block.text).replace(MODULE_CONFIG_PREFIX, ''));
                } catch {
                    moduleConfig = null;
                }
                return false;
            }
            return true;
        });
        const mountId = data.document?.mount
            || moduleConfig?.mount
            || `${vendor}-${name}-module-root`;
        return { blocks, moduleConfig, mountId };
    }, [data, vendor, name]);

    useEffect(() => {
        if (!data || !parsed.mountId) {
            return undefined;
        }
        window.__WENTO_MODULE_PAGE__ = {
            vendor,
            name,
            page,
            mount: parsed.mountId,
            config: parsed.moduleConfig,
        };
        document.dispatchEvent(new CustomEvent('wento:module-page:ready', {
            detail: window.__WENTO_MODULE_PAGE__,
        }));
        return () => {
            document.dispatchEvent(new CustomEvent('wento:module-page:leave', {
                detail: { vendor, name, page, mount: parsed.mountId },
            }));
        };
    }, [data, parsed.mountId, parsed.moduleConfig, vendor, name, page]);

    if (error) {
        return (
            <div className="shop-container py-16">
                <EmptyState
                    title={error}
                    action={<Link to="/" className="shop-btn shop-btn-primary">Back to home</Link>}
                />
            </div>
        );
    }
    if (!data) {
        return <PageSkeleton />;
    }

    const hasModuleAssets = (data.js || []).length > 0;
    const title = data.document?.title || data.app?.display_name;
    const list = data.document?.list || null;
    const crumbItems = (data.document?.crumbs || []).map((crumb) => ({
        name: crumb.name,
        url: crumb.href,
    }));
    const breadcrumbs = crumbItems.length
        ? crumbItems
        : [
            { name: t('storefront.shell.home', 'Home'), url: '/' },
            { name: title },
        ];

    return (
        <div className="shop-container py-8">
            {(data.css || []).map((href) => <link key={href} rel="stylesheet" href={href} />)}
            <Breadcrumbs items={breadcrumbs} />
            <p className="mt-4 text-sm font-semibold text-[var(--shop-accent)]">{data.app?.display_name}</p>
            <h1 className="mt-1 text-3xl font-extrabold">{title}</h1>
            {data.document?.hint ? <p className="mt-2 text-neutral-500">{data.document.hint}</p> : null}
            {data.document?.error_placement !== 'bottom' ? <FormErrorBanner messages={data.document?.errors} /> : null}
            <div className="mt-8 space-y-8">
                {list ? <ModuleList list={list} /> : null}
                {hasModuleAssets ? <div id={parsed.mountId} className="module-page-root" data-vendor={vendor} data-name={name} /> : null}
                <ModuleBlocks slots={parsed.blocks} />
            </div>
            {data.document?.error_placement === 'bottom' ? <FormErrorBanner messages={data.document?.errors} /> : null}
        </div>
    );
}
