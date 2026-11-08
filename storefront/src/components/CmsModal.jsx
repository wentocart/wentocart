import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import api from '../api.js';
import { t } from '../i18n.js';

export default function CmsModal({ slug, titleFallback, open, onClose }) {
    const [page, setPage] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!slug) {
            return undefined;
        }
        // Prefetch on mount so the modal opens instantly with content
        // instead of flashing a loading state on click.
        let active = true;
        setPage(null);
        setFailed(false);
        api.get(`/catalog/page/${slug}`)
            .then((r) => {
                if (active) {
                    setPage(r.data.page || null);
                    setFailed(!r.data.page);
                }
            })
            .catch(() => {
                if (active) {
                    setPage(null);
                    setFailed(true);
                }
            });
        return () => {
            active = false;
        };
    }, [slug]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                onClose?.();
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    const title = page?.title || titleFallback || slug;

    return createPortal(
        <div className="cx-modal" role="dialog" aria-modal="true" aria-label={title}>
            <button type="button" className="cx-modal-backdrop" aria-label={t('storefront.common.close', 'Close')} onClick={onClose} />
            <div className="cx-modal-panel is-cms">
                <header className="cx-modal-head">
                    <h2>{title}</h2>
                    <button type="button" className="cx-modal-close" onClick={onClose} aria-label={t('storefront.common.close', 'Close')}>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M3 3l10 10M13 3L3 13" />
                        </svg>
                    </button>
                </header>
                <div className="cx-modal-body">
                    {failed ? (
                        <p>{t('storefront.cms.unavailable', 'This page is not available right now.')}</p>
                    ) : page === null ? (
                        <p className="cx-modal-loading">{t('storefront.common.loading', 'Loading…')}</p>
                    ) : (
                        <div className="shop-prose" dangerouslySetInnerHTML={{ __html: page.body || '' }} />
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}

export function MessageModal({ open, title, message, onClose, confirmLabel }) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                onClose?.();
            }
        };
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prev;
            window.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div className="cx-modal" role="dialog" aria-modal="true" aria-label={title}>
            <button type="button" className="cx-modal-backdrop" aria-label={t('storefront.common.close', 'Close')} onClick={onClose} />
            <div className="cx-modal-panel is-compact">
                <header className="cx-modal-head">
                    <h2>{title}</h2>
                    <button type="button" className="cx-modal-close" onClick={onClose} aria-label={t('storefront.common.close', 'Close')}>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
                            <path d="M3 3l10 10M13 3L3 13" />
                        </svg>
                    </button>
                </header>
                <div className="cx-modal-body">
                    <p className="cx-modal-message">{message}</p>
                    <button type="button" className="cx-modal-action" onClick={onClose}>
                        {confirmLabel || t('storefront.common.close', 'Close')}
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
