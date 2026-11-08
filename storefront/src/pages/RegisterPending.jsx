import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import AuthShell from '../components/AuthShell.jsx';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';

const EMAIL_KEY = 'wento.register.pendingEmail';

function rememberedEmail(stateEmail) {
    const fromState = String(stateEmail || '').trim();
    if (fromState) {
        return fromState;
    }
    try {
        return String(sessionStorage.getItem(EMAIL_KEY) || '').trim();
    } catch {
        return '';
    }
}

export default function RegisterPending() {
    const location = useLocation();
    const [email] = useState(() => rememberedEmail(location.state?.email));

    useEffect(() => {
        if (!location.state?.email) {
            return;
        }
        try {
            sessionStorage.setItem(EMAIL_KEY, String(location.state.email));
        } catch {
            /* private mode */
        }
    }, [location.state]);

    return (
        <AuthShell variant="register">
            <div className="shop-auth-card shop-pending">
                <div className="shop-pending-burst" aria-hidden="true">
                    <span /><span /><span /><span /><span /><span /><span /><span />
                </div>
                <div className="shop-pending-mark" aria-hidden="true">
                    <span className="shop-pending-ring" />
                    <span className="shop-pending-ring shop-pending-ring--delay" />
                    <span className="shop-pending-badge">
                        <Icon name="check" className="h-7 w-7" />
                    </span>
                </div>
                <p className="shop-pending-kicker">{t('storefront.register.pending_kicker', 'Account created')}</p>
                <h1>{t('storefront.register.pending_title', 'Sent for admin approval')}</h1>
                <p className="shop-auth-card-lead">
                    {t(
                        'storefront.register.pending_body',
                        'Your account has been created and sent for admin approval. You can sign in after a store admin approves it.',
                    )}
                </p>
                {email ? (
                    <p className="shop-pending-email">
                        <Icon name="mail" className="h-4 w-4" />
                        <span>{email}</span>
                    </p>
                ) : null}
                <ol className="shop-pending-steps">
                    <li className="is-done">
                        <span className="shop-pending-step-mark"><Icon name="check" className="h-3.5 w-3.5" /></span>
                        <span>
                            <strong>{t('storefront.register.pending_step_created', 'Account created')}</strong>
                            <em>{t('storefront.register.pending_step_created_text', 'Your details are saved.')}</em>
                        </span>
                    </li>
                    <li className="is-now">
                        <span className="shop-pending-step-mark"><span className="shop-pending-dot" /></span>
                        <span>
                            <strong>{t('storefront.register.pending_step_review', 'Waiting for approval')}</strong>
                            <em>{t('storefront.register.pending_step_review_text', 'A store admin is reviewing this account.')}</em>
                        </span>
                    </li>
                    <li>
                        <span className="shop-pending-step-mark">3</span>
                        <span>
                            <strong>{t('storefront.register.pending_step_signin', 'Sign in')}</strong>
                            <em>{t('storefront.register.pending_step_signin_text', 'Available once the account is approved.')}</em>
                        </span>
                    </li>
                </ol>
                <Link to="/" className="shop-auth-submit shop-pending-home">
                    {t('storefront.register.pending_shop', 'Continue shopping')}
                    <span className="shop-auth-submit-icon">
                        <Icon name="chevron" className="h-3.5 w-3.5" />
                    </span>
                </Link>
                <p className="shop-auth-foot">
                    {t('storefront.register.pending_later', 'Already approved?')}
                    {' '}
                    <Link to="/login">{t('storefront.login.title', 'Sign in')}</Link>
                </p>
            </div>
        </AuthShell>
    );
}
