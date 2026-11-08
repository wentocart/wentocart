import { Link } from 'react-router-dom';
import { useEffect } from 'react';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { t } from '../i18n.js';
import { applyNotFoundSeo } from '../lib/seoHead.js';

export default function NotFound() {
    useEffect(() => {
        applyNotFoundSeo();
    }, []);

    return (
        <div className="shop-container py-10">
            <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: '404' }]} />
            <div className="shop-card mx-auto mt-8 max-w-xl px-8 py-16 text-center">
                <p className="text-sm font-semibold uppercase tracking-wide text-[var(--shop-accent)]">404</p>
                <h1 className="mt-2 text-3xl font-extrabold tracking-tight">{t('storefront.not_found.title', 'Page not found')}</h1>
                <p className="mt-3 text-neutral-500">
                    {t('storefront.not_found.body', 'This link is missing or has moved. Browse the catalog or open Help if you need a hand.')}
                </p>
                <div className="mt-6 flex flex-wrap justify-center gap-3">
                    <Link to="/" className="shop-btn shop-btn-primary">{t('storefront.shell.home', 'Home')}</Link>
                    <Link to="/catalog" className="shop-btn shop-btn-ghost">{t('storefront.shell.shop', 'Shop')}</Link>
                    <Link to="/help" className="shop-btn shop-btn-ghost">{t('storefront.shell.help', 'Help')}</Link>
                </div>
            </div>
        </div>
    );
}
