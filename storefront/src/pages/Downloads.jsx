import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { EmptyState, PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import ProductImage from '../components/ProductImage.jsx';
import { t } from '../i18n.js';
import { applyDownloadEntitlement, downloadAccountFile } from '../lib/accountDownload.js';

const TABS = [
    { key: 'all', label: 'All files' },
    { key: 'available', label: 'Available' },
    { key: 'expired', label: 'Expired' },
];

function tabLabel(key) {
    if (key === 'available') return t('storefront.account.downloads_available', 'Available');
    if (key === 'expired') return t('storefront.account.downloads_expired', 'Expired');
    return t('storefront.account.downloads_all', 'All files');
}

function formatDate(value) {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
}

function daysLabel(row) {
    if (row.expired) {
        return t('storefront.account.download_link_expired', 'Link expired');
    }
    const days = Number(row.days_left || 0);
    if (days <= 0) {
        return t('storefront.account.download_expires_today', 'Expires today');
    }
    return `${days} ${days === 1
        ? t('storefront.account.day_left', 'day left')
        : t('storefront.account.days_left', 'days left')}`;
}

export default function Downloads() {
    const [rows, setRows] = useState(null);
    const [tab, setTab] = useState('all');
    const [q, setQ] = useState('');
    const [busyTokens, setBusyTokens] = useState(() => ({}));
    const inflightRef = useRef(new Set());

    useEffect(() => {
        api.get('/account/downloads')
            .then((r) => setRows(r.data.downloads || []))
            .catch(() => setRows([]));
    }, []);

    const counts = useMemo(() => {
        const next = { all: 0, available: 0, expired: 0 };
        (rows || []).forEach((row) => {
            next.all += 1;
            if (row.available) {
                next.available += 1;
            } else {
                next.expired += 1;
            }
        });
        return next;
    }, [rows]);

    const filtered = useMemo(() => {
        const list = rows || [];
        const query = q.trim().toLowerCase();
        return list.filter((row) => {
            if (tab === 'available' && !row.available) {
                return false;
            }
            if (tab === 'expired' && row.available) {
                return false;
            }
            if (!query) {
                return true;
            }
            const hay = [
                row.filename,
                row.original_filename,
                row.name,
                row.description,
                row.product?.name,
                row.order?.number,
            ].filter(Boolean).join(' ').toLowerCase();
            return hay.includes(query);
        });
    }, [rows, tab, q]);

    const handleDownload = async (row) => {
        const token = String(row?.token || '');
        if (!token || !row.available || inflightRef.current.has(token)) {
            return;
        }

        inflightRef.current.add(token);
        setBusyTokens((prev) => ({ ...prev, [token]: true }));

        try {
            const entitlement = await downloadAccountFile(api, row);
            if (!entitlement) {
                // Successful file delivery but headers missing — reconcile from list API.
                const list = await api.get('/account/downloads');
                const fresh = (list.data.downloads || []).find((item) => item.token === token);
                if (fresh) {
                    setRows((prev) => (prev || []).map((item) => (item.token === token ? fresh : item)));
                }
                return;
            }

            setRows((prev) => (prev || []).map((item) => (
                item.token === token ? applyDownloadEntitlement(item, entitlement) : item
            )));
        } catch {
            // Failed / unauthorized / missing file — keep existing counts.
        } finally {
            inflightRef.current.delete(token);
            setBusyTokens((prev) => {
                const next = { ...prev };
                delete next[token];
                return next;
            });
        }
    };

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-orders shop-downloads">
            <header className="shop-orders-head">
                <div>
                    <p className="shop-orders-kicker">{t('storefront.account.downloads', 'Downloads')}</p>
                    <h1>{t('storefront.account.downloads_title', 'My downloads')}</h1>
                    <p>{t('storefront.account.downloads_body', 'Files from virtual products on your orders.')}</p>
                </div>
                <div className="shop-orders-summary" aria-hidden="true">
                    <span className="shop-orders-summary-count">{counts.all}</span>
                    <span className="shop-orders-summary-label">{t('storefront.account.total_files', 'Files')}</span>
                </div>
            </header>

            <div className="shop-orders-toolbar">
                <nav className="shop-orders-tabs" aria-label={t('storefront.account.download_filters', 'Download filters')}>
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            className={tab === item.key ? 'is-active' : ''}
                            onClick={() => setTab(item.key)}
                        >
                            <span>{tabLabel(item.key)}</span>
                            <em>{counts[item.key] ?? 0}</em>
                        </button>
                    ))}
                </nav>
                <label className="shop-orders-search">
                    <Icon name="search" className="h-4 w-4" />
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('storefront.account.search_download', 'Search file...')}
                        aria-label={t('storefront.account.search_download', 'Search file...')}
                    />
                </label>
            </div>

            {rows.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_downloads', 'No downloads yet.')}
                        body={t('storefront.account.no_downloads_body', 'Files appear here once you buy a virtual or downloadable product.')}
                        action={<Link to="/catalog" className="shop-btn shop-btn-primary">{t('storefront.account.start_shopping', 'Start Shopping')}</Link>}
                    />
                </div>
            ) : filtered.length === 0 ? (
                <div className="shop-orders-empty">
                    <EmptyState
                        title={t('storefront.account.no_downloads_match', 'No matching files')}
                        body={t('storefront.account.no_downloads_match_body', 'Try another filter or search term.')}
                    />
                </div>
            ) : (
                <div className="shop-orders-list">
                    {filtered.map((row, index) => {
                        const product = row.product || {};
                        const order = row.order || {};
                        const tone = row.available ? 'delivered' : 'cancelled';
                        const productName = product.name || row.filename;
                        const productUrl = product.url || null;
                        const fileTitle = String(row.name || '').trim();
                        const fileDescription = String(row.description || '').trim();
                        const originalName = String(row.original_filename || row.filename || '').trim();
                        const downloadLabel = t('storefront.account.download', 'Download');
                        const viewOrderLabel = t('storefront.account.view_order', 'View order');
                        const busy = Boolean(busyTokens[row.token]);

                        return (
                            <article
                                key={row.id}
                                className={`shop-order-card is-${tone} shop-download-card shop-fade-up`}
                                style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}
                            >
                                <div className="shop-order-rail" aria-hidden="true" />

                                <header className="shop-order-card-head shop-download-card-head">
                                    <div className="shop-order-id-block shop-download-id-block">
                                        <div className="shop-download-id-row">
                                            <strong>
                                                <Icon name="package" className="h-4 w-4" />
                                                {order.number ? `#${order.number}` : row.filename}
                                            </strong>
                                            <em>
                                                {t('storefront.account.placed_on', 'Placed on')} {formatDate(order.created_at)}
                                            </em>
                                        </div>
                                        {productUrl ? (
                                            <Link to={productUrl} className="shop-download-product-name">
                                                {productName}
                                            </Link>
                                        ) : (
                                            <p className="shop-download-product-name is-static">{productName}</p>
                                        )}
                                    </div>
                                    <div className="shop-order-card-head-right">
                                        <span className={`shop-order-status is-${tone}`}>
                                            <i />
                                            {row.available
                                                ? t('storefront.account.download_ready', 'Ready')
                                                : t('storefront.account.expired', 'Expired')}
                                        </span>
                                    </div>
                                </header>

                                <div className="shop-order-card-body">
                                    <div className="shop-order-product shop-download-file">
                                        {productUrl ? (
                                            <Link
                                                to={productUrl}
                                                className="shop-order-thumb shop-download-thumb"
                                                aria-hidden="true"
                                                tabIndex={-1}
                                            >
                                                <ProductImage product={{
                                                    name: productName,
                                                    cover: product.cover_path,
                                                    cover_url: product.cover_url,
                                                }} />
                                            </Link>
                                        ) : (
                                            <div className="shop-order-thumb shop-download-thumb" aria-hidden="true">
                                                <ProductImage product={{
                                                    name: productName,
                                                    cover: product.cover_path,
                                                    cover_url: product.cover_url,
                                                }} />
                                            </div>
                                        )}
                                        <div className="shop-download-file-copy">
                                            {fileTitle ? (
                                                <p className="shop-download-file-title">{fileTitle}</p>
                                            ) : null}
                                            {fileDescription ? (
                                                <p className="shop-download-file-description">{fileDescription}</p>
                                            ) : null}
                                            {(originalName || row.file_type_label || row.file_size_label) ? (
                                                <p className="shop-order-qty shop-download-filename">
                                                    <Icon name="file" className="h-3.5 w-3.5" />
                                                    <span>
                                                        {originalName || '—'}
                                                        {row.file_type_label ? ` · ${row.file_type_label}` : ''}
                                                        {row.file_size_label ? ` · ${row.file_size_label}` : ''}
                                                    </span>
                                                </p>
                                            ) : null}
                                            <p className="shop-download-usage">
                                                {row.remaining}/{row.max_uses} {t('storefront.account.downloads_remaining', 'downloads left')}
                                                {' · '}
                                                {daysLabel(row)}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="shop-order-actions shop-download-actions">
                                        {row.available ? (
                                            <button
                                                type="button"
                                                className={`shop-download-icon-btn shop-download-icon-btn-primary${busy ? ' is-busy' : ''}`}
                                                title={downloadLabel}
                                                aria-label={`${downloadLabel}${fileTitle ? ` ${fileTitle}` : ''}`}
                                                aria-busy={busy ? 'true' : 'false'}
                                                disabled={busy}
                                                onClick={() => handleDownload(row)}
                                            >
                                                <Icon name="download" className="shop-download-icon-svg" />
                                            </button>
                                        ) : (
                                            <span className="shop-download-unavailable">
                                                {t('storefront.account.download_unavailable', 'No downloads left')}
                                            </span>
                                        )}
                                        {order.url ? (
                                            <Link
                                                to={order.url}
                                                className="shop-download-icon-btn"
                                                title={viewOrderLabel}
                                                aria-label={`${viewOrderLabel}${order.number ? ` #${order.number}` : ''}`}
                                            >
                                                <Icon name="eye" className="shop-download-icon-svg" />
                                            </Link>
                                        ) : null}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
