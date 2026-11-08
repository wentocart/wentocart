import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { applyThinShellSeo } from '../lib/seoHead.js';

export default function Blog() {
    useEffect(() => { applyThinShellSeo('Blog'); }, []);

    const [pages, setPages] = useState(null);

    useEffect(() => {
        api.get('/catalog/pages').then((r) => setPages(r.data.pages || [])).catch(() => setPages([]));
    }, []);

    if (pages === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-container py-10">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: t('storefront.blog.title', 'Blog') }]} />
            <h1 className="mt-4 text-3xl font-extrabold tracking-tight">{t('storefront.blog.title', 'Blog')}</h1>
            <p className="mt-2 max-w-2xl text-neutral-500">{t('storefront.blog.lead', 'Guides, policies, and stories from the shop.')}</p>
            {pages.length === 0 ? (
                <div className="mt-8">
                    <EmptyState title={t('storefront.blog.empty', 'No posts yet')} action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.shell.shop_now', 'Shop Now')}</Link>} />
                </div>
            ) : (
                <div className="mt-8 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {pages.map((page) => (
                        <Link key={page.id} to={page.url} className="shop-card flex flex-col p-5 transition hover:-translate-y-0.5">
                            <h2 className="font-semibold">{page.title}</h2>
                            <p className="mt-2 line-clamp-3 text-sm text-neutral-500">{page.short_description || ''}</p>
                            <span className="mt-4 text-sm font-semibold text-[var(--shop-accent)]">{t('storefront.blog.read', 'Read more')} →</span>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
