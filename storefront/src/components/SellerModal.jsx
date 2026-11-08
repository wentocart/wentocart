import { useEffect } from 'react';
import { Icon } from './Icons.jsx';
import { t } from '../i18n.js';

export function SellerModalShell({ title, subtitle, children, footer, onClose, closeDisabled = false }) {
    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape' && !closeDisabled) {
                onClose?.();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, closeDisabled]);

    return (
        <div
            className="mv-modal-backdrop"
            role="presentation"
            onClick={() => {
                if (!closeDisabled) {
                    onClose?.();
                }
            }}
        >
            <div
                className="mv-modal"
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(event) => event.stopPropagation()}
            >
                <div className="mv-modal-head">
                    <div>
                        <h2 className="mv-modal-title">{title}</h2>
                        {subtitle ? <p className="mv-modal-sub">{subtitle}</p> : null}
                    </div>
                    <button
                        type="button"
                        className="mv-list-settings-btn"
                        onClick={onClose}
                        disabled={closeDisabled}
                        aria-label={t('storefront.seller.close', 'Close')}
                    >
                        <Icon name="close" className="h-4 w-4" />
                    </button>
                </div>
                <div className="mv-modal-body">{children}</div>
                {footer ? <div className="mv-modal-foot">{footer}</div> : null}
            </div>
        </div>
    );
}

export function SellerConfirmDialog({
    open,
    title,
    description,
    detail,
    confirmLabel,
    cancelLabel,
    busy = false,
    onConfirm,
    onCancel,
}) {
    if (!open) {
        return null;
    }

    return (
        <SellerModalShell
            title={title}
            onClose={onCancel}
            closeDisabled={busy}
            footer={(
                <>
                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={onCancel} disabled={busy}>
                        {cancelLabel || t('storefront.seller.cancel', 'Cancel')}
                    </button>
                    <button
                        type="button"
                        className="shop-btn shop-btn-primary shop-btn--sm"
                        onClick={onConfirm}
                        disabled={busy}
                        aria-busy={busy || undefined}
                    >
                        {busy
                            ? t('storefront.seller.working', 'Working…')
                            : (confirmLabel || t('storefront.seller.confirm', 'Confirm'))}
                    </button>
                </>
            )}
        >
            {description ? <p className="mv-confirm-copy">{description}</p> : null}
            {detail ? <p className="mv-confirm-detail">{detail}</p> : null}
        </SellerModalShell>
    );
}
