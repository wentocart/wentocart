import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { saveGdprPrefs } from '../components/CookieConsent.jsx';

export default function GdprRequests() {
    const store = useStore();
    const gdpr = store.shop?.commerce?.gdpr || {};
    const [rows, setRows] = useState([]);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const load = async () => {
        try {
            const { data } = await api.get('/account/gdpr');
            setRows(data.requests || []);
        } catch {
            setRows([]);
        }
    };

    useEffect(() => {
        if (gdpr.enabled) load();
    }, [gdpr.enabled]);

    if (!gdpr.enabled) {
        return <p>{t('storefront.gdpr.disabled', 'GDPR requests are turned off for this shop.')}</p>;
    }

    const create = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            await csrf();
            await api.post('/account/gdpr', {
                type: 'delete',
                message,
            });
            setMessage('');
            await load();
        } catch (err) {
            const data = err.response?.data;
            const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
            setError(firstError || data?.message || t('storefront.gdpr.create_failed', 'Could not save the GDPR request.'));
        } finally {
            setBusy(false);
        }
    };

    const statusTone = (status) => ({
        pending: 'is-warning',
        processing: 'is-info',
        completed: 'is-success',
        declined: 'is-danger',
        revoked: 'is-neutral',
    }[status] || 'is-neutral');
    const statusLabel = (status) => {
        const labels = {
            pending: t('storefront.gdpr.status_pending', 'Pending'),
            processing: t('storefront.gdpr.status_processing', 'Processing'),
            completed: t('storefront.gdpr.status_completed', 'Completed'),
            declined: t('storefront.gdpr.status_declined', 'Declined'),
            revoked: t('storefront.gdpr.status_revoked', 'Revoked'),
        };
        return labels[status] || status;
    };

    const revoke = async (id) => {
        setError('');
        try {
            await csrf();
            await api.post(`/account/gdpr/${id}/revoke`);
            load();
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.gdpr.revoke_failed', 'Could not revoke the GDPR request.'));
        }
    };

    const [exporting, setExporting] = useState('');

    const exportFile = async (row, format) => {
        const key = `${row.id}-${format}`;
        setError('');
        setExporting(key);
        try {
            const { data } = await api.get(`/account/gdpr/export.${format}`, {
                responseType: 'blob',
                headers: { Accept: format === 'pdf' ? 'application/pdf' : 'text/html' },
            });
            const blob = data instanceof Blob ? data : new Blob([data], { type: format === 'pdf' ? 'application/pdf' : 'text/html' });
            const url = URL.createObjectURL(blob);
            if (format === 'html') {
                const win = window.open(url, '_blank', 'noopener');
                if (!win) {
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = 'my-data.html';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                }
            } else {
                const link = document.createElement('a');
                link.href = url;
                link.download = 'my-data.pdf';
                document.body.appendChild(link);
                link.click();
                link.remove();
            }
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (err) {
            setError(err.response?.data?.message || t('storefront.gdpr.export_failed', 'Could not download your data.'));
        } finally {
            setExporting('');
        }
    };

    return (
        <div className="space-y-4">
            <div className="shop-profile-card">
                <h1 className="shop-profile-card-title">{t('storefront.gdpr.requests', 'GDPR Requests')}</h1>
                <p className="shop-profile-card-lead">
                    {t('storefront.gdpr.requests_help', 'You can change your details anytime in your Profile. This page is for asking the shop to erase your account, and for downloading a copy of your data.')}
                </p>
                <div className="shop-gdpr-links">
                    <Link className="shop-cookie-link" to="/account/profile">{t('storefront.gdpr.edit_profile', 'Edit my details in Profile →')}</Link>
                    <Link className="shop-cookie-link" to="/account/gdpr/cookies">{t('storefront.gdpr.manage_cookies', 'Manage cookie preferences →')}</Link>
                </div>
            </div>
            {error ? <p className="shop-profile-error">{error}</p> : null}
            {(() => {
                const open = rows.find((row) => row.type === 'delete' && (row.status === 'pending' || row.status === 'processing'));
                if (open) {
                    return (
                        <div className="shop-profile-card">
                            <h2 className="shop-profile-card-title">{t('storefront.gdpr.delete_title', 'Request Account Deletion')}</h2>
                            <p className="shop-profile-card-lead">
                                {t('storefront.gdpr.delete_open', 'You already have an open deletion request below. Revoke it if you changed your mind, or wait for the shop to review it.')}
                            </p>
                        </div>
                    );
                }
                return (
            <form className="shop-profile-card shop-gdpr-form" onSubmit={create}>
                <h2 className="shop-profile-card-title">{t('storefront.gdpr.delete_title', 'Request Account Deletion')}</h2>
                <div className="shop-profile-fields">
                    <p className="shop-field-hint">
                        {t('storefront.gdpr.delete_hint', 'This asks the shop to erase the account. The account stays until the shop marks the request completed.')}
                    </p>
                    <label className="shop-field">
                        <span className="shop-field-label">{t('storefront.gdpr.message', 'Reason')}</span>
                        <textarea className="shop-input" rows={4} maxLength={500} required value={message} onChange={(e) => setMessage(e.target.value)} />
                    </label>
                </div>
                <div className="shop-gdpr-submit">
                    <button type="submit" className="shop-profile-save" disabled={busy}>
                        {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.gdpr.send_delete', 'Send Deletion Request')}
                    </button>
                </div>
            </form>
                );
            })()}
            <div className="shop-profile-card shop-gdpr-list">
                <div className="shop-gdpr-table-wrap">
                    <table className="shop-gdpr-table">
                        <thead>
                            <tr>
                                <th className="is-id">{t('storefront.gdpr.col_reference', 'Reference')}</th>
                                <th className="is-type">{t('storefront.gdpr.col_type', 'Type')}</th>
                                <th className="is-date">{t('storefront.gdpr.col_date', 'Date')}</th>
                                <th className="is-message">{t('storefront.gdpr.col_message', 'Message')}</th>
                                <th className="is-status">{t('storefront.gdpr.col_status', 'Status')}</th>
                                <th className="is-actions">{t('storefront.gdpr.col_actions', 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td className="shop-gdpr-empty" colSpan={6}>{t('storefront.gdpr.empty', 'No GDPR requests yet.')}</td></tr>
                            ) : rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="is-id">{row.reference || `#${row.id}`}</td>
                                    <td className="is-type">{row.type_label}</td>
                                    <td className="is-date">{row.created_at ? new Date(row.created_at).toLocaleDateString() : ''}</td>
                                    <td className="is-message">
                                        {row.summary ? <div>{row.summary}</div> : null}
                                        {row.message && row.message !== row.summary ? <div>{row.message}</div> : null}
                                    </td>
                                    <td className="is-status">
                                        <span className={`shop-module-badge ${statusTone(row.status)}`}>{statusLabel(row.status)}</span>
                                    </td>
                                    <td className="is-actions">
                                        <div className="shop-gdpr-actions">
                                            <button type="button" className="shop-btn shop-btn--sm" disabled={exporting === `${row.id}-html`} onClick={() => exportFile(row, 'html')}>{t('storefront.gdpr.html', 'HTML')}</button>
                                            <button type="button" className="shop-btn shop-btn--sm shop-btn-primary" disabled={exporting === `${row.id}-pdf`} onClick={() => exportFile(row, 'pdf')}>{t('storefront.gdpr.pdf', 'PDF')}</button>
                                            <button type="button" className="shop-btn shop-btn--sm" disabled={!row.can_revoke} onClick={() => revoke(row.id)}>
                                                {t('storefront.gdpr.revoke', 'Revoke')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

export function CookiePreferences() {
    const store = useStore();
    const gdpr = store.shop?.commerce?.gdpr || {};
    const categories = gdpr.categories || {};
    const [form, setForm] = useState({
        basic: false,
        experience: false,
        measurements: false,
        targeting: false,
    });
    const [saved, setSaved] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        api.get('/account/gdpr').then(({ data }) => {
            if (data.cookies) setForm((current) => ({ ...current, ...data.cookies }));
        }).catch(() => {});
    }, []);

    if (!gdpr.enabled) {
        return <p>{t('storefront.gdpr.disabled', 'GDPR requests are turned off for this shop.')}</p>;
    }

    const toggle = (key) => {
        const next = { ...form, [key]: !form[key] };
        persist(next);
    };

    const persist = async (next) => {
        setForm(next);
        setSaved(false);
        setSaving(true);
        saveGdprPrefs(next);
        try {
            await csrf();
            await api.put('/account/gdpr/cookies', next);
        } catch {
            // The browser choice is already stored.
        }
        setSaving(false);
        setSaved(true);
    };

    const acceptAll = () => persist({ basic: true, experience: true, measurements: true, targeting: true });
    const rejectAll = () => persist({ basic: false, experience: false, measurements: false, targeting: false });

    const rows = [
        { key: 'strictly_necessary', label: t('storefront.gdpr.cat_necessary', 'Strictly Necessary'), locked: true, icon: 'lock', fallback: 'Required for the cart, sign-in and checkout. Always on.' },
        { key: 'basic', label: t('storefront.gdpr.cat_basic', 'Basic Interactions & Functionalities'), locked: false, icon: 'sliders', fallback: 'Remembers basics like language and recently viewed items.' },
        { key: 'experience', label: t('storefront.gdpr.cat_experience', 'Experience Enhancements'), locked: false, icon: 'spark', fallback: 'Personalizes what you see in the shop.' },
        { key: 'measurements', label: t('storefront.gdpr.cat_measurements', 'Measurements'), locked: false, icon: 'chart', fallback: 'Measures traffic and how the shop is used.' },
        { key: 'targeting', label: t('storefront.gdpr.cat_targeting', 'Targeting & Advertising'), locked: false, icon: 'target', fallback: 'Used for personalized marketing and ads.' },
    ];

    const icons = {
        lock: (<><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></>),
        sliders: (<><path d="M4 8h9M17 8h3M4 16h5M13 16h7" /><circle cx="15" cy="8" r="2" /><circle cx="11" cy="16" r="2" /></>),
        spark: (<path d="M12 3l1.9 5.4L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.6z" />),
        chart: (<><path d="M4 4v16h16" /><path d="M8.5 15.5v-4M13 15.5V8.5M17.5 15.5v-6.5" /></>),
        target: (<><circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="4.5" /><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" /></>),
    };

    return (
        <form className="shop-profile-card shop-cookie" onSubmit={(e) => e.preventDefault()}>
            <div className="shop-cookie-head">
                <span className="shop-cookie-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M12 3l7.5 3v5.5c0 4.6-3.2 7.9-7.5 9.5-4.3-1.6-7.5-4.9-7.5-9.5V6z" />
                        <path d="M9.5 12l2 2 3.5-4" />
                    </svg>
                </span>
                <div>
                    <h1 className="shop-profile-card-title">{t('storefront.gdpr.cookie_prefs', 'Your Cookie Consent Preferences')}</h1>
                    <p className="shop-cookie-sub">{t('storefront.gdpr.cookie_prefs_sub', 'Choose which cookies the shop may use. Strictly necessary cookies are always on so the store keeps working.')}</p>
                </div>
            </div>
            {saving || saved ? (
                <p className={`shop-cookie-saved${saving ? ' is-saving' : ''}`} role="status">
                    {saving ? (
                        t('storefront.gdpr.cookie_saving', 'Saving your preferences…')
                    ) : (
                        <>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M4.5 12.5l5 5 10-11" />
                            </svg>
                            {t('storefront.gdpr.cookie_saved', 'Your preferences have been saved.')}
                        </>
                    )}
                </p>
            ) : null}
            <div className="shop-cookie-rows">
                {rows.map((row) => {
                    const on = row.locked || !!form[row.key];
                    return (
                        <label key={row.key} className={`shop-cookie-row${on ? ' is-on' : ''}${row.locked ? ' is-locked' : ''}`}>
                            <span className="shop-cookie-ic" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                                    {icons[row.icon]}
                                </svg>
                            </span>
                            <span className="shop-cookie-text">
                                <strong>
                                    {row.label}
                                    {row.locked ? <em className="shop-cookie-pill">{t('storefront.gdpr.always_on', 'Always on')}</em> : null}
                                </strong>
                                <small>{categories[row.key] || row.fallback}</small>
                            </span>
                            <span className="shop-cookie-switch">
                                <input type="checkbox" checked={on} disabled={row.locked} onChange={() => toggle(row.key)} aria-label={row.label} />
                                <span className="shop-cookie-knob" aria-hidden="true" />
                            </span>
                        </label>
                    );
                })}
            </div>
            <div className="shop-cookie-foot">
                <button type="button" className="shop-cookie-link is-strong" onClick={acceptAll}>{t('storefront.gdpr.accept_all', 'Accept all')}</button>
                <span className="shop-cookie-dot" aria-hidden="true">·</span>
                <button type="button" className="shop-cookie-link" onClick={rejectAll}>{t('storefront.gdpr.reject_all', 'Reject all')}</button>
            </div>
        </form>
    );
}
