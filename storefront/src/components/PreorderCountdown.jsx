import { useEffect, useMemo, useState } from 'react';
import { t } from '../i18n.js';
import {
    preorderRemainingMs,
    preorderRemainingParts,
    preorderShopNowMs,
    shouldShowPreorderCountdown,
} from '../lib/preorderCountdown.js';

function pad(value) {
    return String(value).padStart(2, '0');
}

function runningLabel(kind) {
    if (kind === 'start') {
        return t('storefront.preorder.starts_in', 'Starts in');
    }
    if (kind === 'release') {
        return t('storefront.preorder.releases_in', 'Releases in');
    }

    return null;
}

function doneLabel(kind) {
    if (kind === 'start') {
        return t('storefront.preorder.started', 'Pre-order started');
    }
    if (kind === 'release') {
        return t('storefront.preorder.released', 'Released');
    }

    return t('storefront.preorder.ended', 'Pre-order ended');
}

function Unit({ value, label }) {
    return (
        <div className="shop-preorder-countdown__unit">
            <strong>{pad(value)}</strong>
            <span>{label}</span>
        </div>
    );
}

export default function PreorderCountdown({ preorder, placement = 'pdp' }) {
    const fetchedAt = useMemo(() => Date.now(), [preorder?.countdown_at, preorder?.server_now]);
    const [nowMs, setNowMs] = useState(() => preorderShopNowMs(preorder?.server_now, fetchedAt));

    useEffect(() => {
        if (!shouldShowPreorderCountdown(preorder, placement)) {
            return undefined;
        }
        const tick = () => setNowMs(preorderShopNowMs(preorder.server_now, fetchedAt));
        tick();
        const id = window.setInterval(tick, 1000);

        return () => window.clearInterval(id);
    }, [fetchedAt, placement, preorder]);

    if (!shouldShowPreorderCountdown(preorder, placement)) {
        return null;
    }

    const remaining = preorderRemainingMs(preorder.countdown_at, nowMs);
    const done = remaining <= 0;
    const kind = preorder.countdown_kind || 'end';
    const parts = preorderRemainingParts(remaining);
    const title = done ? null : runningLabel(kind);

    return (
        <div
            className={`shop-preorder-countdown shop-preorder-countdown--${placement}${done ? ' is-done' : ''}`}
            role="timer"
            aria-live="polite"
        >
            <p className="shop-preorder-countdown__kicker">{t('storefront.preorder.availability', 'Pre-order availability')}</p>
            {done ? (
                <p className="shop-preorder-countdown__done">{doneLabel(kind)}</p>
            ) : (
                <>
                    {title ? <p className="shop-preorder-countdown__title">{title}</p> : null}
                    <div className="shop-preorder-countdown__units">
                        <Unit value={parts.days} label={t('storefront.preorder.days', 'Days')} />
                        <Unit value={parts.hours} label={t('storefront.preorder.hours', 'Hours')} />
                        <Unit value={parts.minutes} label={t('storefront.preorder.minutes', 'Mins')} />
                        <Unit value={parts.seconds} label={t('storefront.preorder.seconds', 'Secs')} />
                    </div>
                </>
            )}
        </div>
    );
}
