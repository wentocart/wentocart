import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { Icon } from './Icons.jsx';

const DISMISS_KEY = 'wento_deal_bell_dismissed';

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

export default function DealBellBanner() {
    const store = useStore();
    const location = useLocation();
    const theme = store.shop?.shop?.theme?.settings || window.__WENTO__?.theme?.settings || {};
    const dealEnabled = theme.home?.sections?.deal !== false;

    const [deal, setDeal] = useState(null);
    const [endsAt, setEndsAt] = useState('');
    const [remaining, setRemaining] = useState('');
    const [dismissed, setDismissed] = useState(() => {
        try {
            return sessionStorage.getItem(DISMISS_KEY) === '1';
        } catch {
            return false;
        }
    });

    useEffect(() => {
        if (!dealEnabled) {
            return undefined;
        }
        let alive = true;
        api.get('/catalog/deal')
            .then((r) => {
                if (!alive) {
                    return;
                }
                setDeal(r.data?.deal || null);
                setEndsAt(r.data?.deal_ends_at || '');
            })
            .catch(() => {
                if (alive) {
                    setDeal(null);
                }
            });
        return () => {
            alive = false;
        };
    }, [dealEnabled]);

    useEffect(() => {
        if (!endsAt) {
            return undefined;
        }
        const tick = () => setRemaining(formatRemaining(endsAt));
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [endsAt]);

    if (!dealEnabled || !deal || location.pathname === '/deal') {
        return null;
    }

    const dismiss = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setDismissed(true);
        try {
            sessionStorage.setItem(DISMISS_KEY, '1');
        } catch {
            /* ignore */
        }
    };

    if (dismissed) {
        return (
            <Link
                to="/deal"
                className="shop-deal-bell-fab"
                aria-label={t('storefront.deal.bell', 'Deal of the Day')}
            >
                <span className="shop-deal-bell-icon shop-deal-bell-ring" aria-hidden="true">
                    <Icon name="bell" className="h-5 w-5" />
                </span>
                <span className="shop-deal-bell-dot" aria-hidden="true" />
            </Link>
        );
    }

    return (
        <Link to="/deal" className="shop-deal-bell-banner">
            <span className="shop-deal-bell-icon shop-deal-bell-ring" aria-hidden="true">
                <Icon name="bell" className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-[11px] font-bold uppercase tracking-wider text-[var(--shop-sale,#ff4d4d)]">
                    {t('storefront.home.deal', 'Deal of the Day')}
                </span>
                <span className="mt-0.5 block truncate text-sm font-semibold text-[var(--shop-ink)]">
                    {deal.name}
                </span>
                {remaining ? (
                    <span className="mt-0.5 block font-mono text-xs text-[var(--shop-muted)]">
                        {t('storefront.deal.ends_in', 'Ends in')} {remaining}
                    </span>
                ) : null}
            </span>
            <span className="shrink-0 text-xs font-semibold text-[var(--shop-accent)]">
                {t('storefront.deal.view', 'View')} →
            </span>
            <button
                type="button"
                className="ml-1 grid h-7 w-7 shrink-0 place-items-center text-[var(--shop-muted)] hover:text-[var(--shop-ink)]"
                aria-label={t('storefront.deal.dismiss', 'Dismiss')}
                onClick={dismiss}
            >
                <Icon name="close" className="h-3.5 w-3.5" />
            </button>
        </Link>
    );
}
