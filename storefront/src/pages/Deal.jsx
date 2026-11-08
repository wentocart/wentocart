import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { Icon } from '../components/Icons.jsx';
import Price from '../components/Price.jsx';
import ProductImage from '../components/ProductImage.jsx';
import Stars from '../components/Stars.jsx';
import { EmptyState, ProductSkeleton } from '../components/Feedback.jsx';
import { applyThinShellSeo } from '../lib/seoHead.js';

function formatRemaining(endsAt) {
    if (!endsAt) {
        return '';
    }
    const ms = new Date(endsAt).getTime() - Date.now();
    if (ms <= 0) {
        return '00:00:00';
    }
    const hrs = String(Math.floor(ms / 3600000)).padStart(2, '0');
    const mins = String(Math.floor((ms % 3600000) / 60000)).padStart(2, '0');
    const secs = String(Math.floor((ms % 60000) / 1000)).padStart(2, '0');
    return `${hrs}:${mins}:${secs}`;
}

export default function Deal() {
    useEffect(() => { applyThinShellSeo('Deal'); }, []);

    const store = useStore();
    const [deal, setDeal] = useState(null);
    const [endsAt, setEndsAt] = useState('');
    const [remaining, setRemaining] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/catalog/home')
            .then((r) => {
                setDeal(r.data?.deal || null);
                setEndsAt(r.data?.deal_ends_at || '');
            })
            .catch(() => setError(t('storefront.deal.error', 'Could not load today’s deal.')))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (!endsAt) {
            return undefined;
        }
        const tick = () => setRemaining(formatRemaining(endsAt));
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [endsAt]);

    if (loading) {
        return (
            <div className="shop-container py-10">
                <ProductSkeleton />
            </div>
        );
    }

    if (error || !deal) {
        return (
            <div className="shop-container py-10">
                <EmptyState
                    title={t('storefront.deal.empty', 'No deal right now')}
                    body={error || t('storefront.deal.empty_body', 'Check back soon for today’s offer.')}
                />
                <div className="mt-6 text-center">
                    <Link to="/" className="shop-btn shop-btn-primary">{t('storefront.deal.back_home', 'Back to home')}</Link>
                </div>
            </div>
        );
    }

    return (
        <div className="shop-container py-8 md:py-12">
            <nav className="mb-6 text-sm text-[var(--shop-muted)]">
                <Link to="/" className="hover:text-[var(--shop-accent)]">{t('storefront.shell.home', 'Home')}</Link>
                <span className="mx-2">/</span>
                <span className="text-[var(--shop-ink)]">{t('storefront.home.deal', 'Deal of the Day')}</span>
            </nav>

            <article className="overflow-hidden border border-[var(--shop-border,#e5e7eb)] bg-[var(--shop-card,#fff)] shadow-[0_18px_48px_-28px_rgba(0,0,0,0.35)]">
                <div className="grid items-stretch md:grid-cols-[1.1fr_0.9fr]">
                    <div className="flex flex-col justify-center p-6 md:p-10">
                        <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-[var(--shop-sale,#ff4d4d)]">
                            <span className="shop-deal-bell-icon shop-deal-bell-ring inline-grid place-items-center text-[var(--shop-sale,#ff4d4d)]" aria-hidden="true">
                                <Icon name="bell" className="h-4 w-4" />
                            </span>
                            {t('storefront.home.deal', 'Deal of the Day')}
                        </p>
                        <h1 className="mt-3 text-3xl font-bold text-[var(--shop-ink)] md:text-4xl">{deal.name}</h1>
                        <div className="mt-3">
                            {deal.rating?.average != null && Number(deal.rating?.count || 0) > 0 ? (
                                <Stars value={deal.rating.average} count={deal.rating.count} />
                            ) : (
                                <Stars value={null} />
                            )}
                        </div>
                        <div className="mt-4">
                            <Price product={deal} />
                        </div>
                        {remaining ? (
                            <p className="mt-4 font-mono text-sm text-[var(--shop-muted)]">
                                {t('storefront.deal.ends_in', 'Ends in')}{' '}
                                <span className="font-semibold text-[var(--shop-accent)]">{remaining}</span>
                            </p>
                        ) : null}
                        {deal.short_description || deal.description ? (
                            <p className="mt-4 max-w-xl text-sm leading-relaxed text-[var(--shop-muted)] line-clamp-3">
                                {deal.short_description || deal.description}
                            </p>
                        ) : null}
                        <div className="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                className="shop-btn shop-btn-primary rounded-none"
                                onClick={() => store.addToCart(deal.id)}
                                disabled={deal.available_for_order === false}
                            >
                                <Icon name="cart" className="h-4 w-4" /> {t('storefront.product.add', 'Add to Cart')}
                            </button>
                            <Link
                                to={deal.url || `/${deal.slug}`}
                                className="shop-btn rounded-none border border-[var(--shop-border,#e5e7eb)] bg-transparent text-[var(--shop-ink)]"
                            >
                                {t('storefront.deal.details', 'View product')}
                            </Link>
                        </div>
                    </div>
                    <div className="relative min-h-[280px] bg-[var(--shop-accent-soft,#f4f5f8)] md:min-h-[420px]">
                        <Link to={deal.url || `/${deal.slug}`} className="absolute inset-0 block">
                            <ProductImage product={deal} fit="cover" priority className="h-full w-full" />
                        </Link>
                    </div>
                </div>
            </article>
        </div>
    );
}
