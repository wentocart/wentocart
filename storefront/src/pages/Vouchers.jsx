import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';

export default function Vouchers() {
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get('/account/vouchers').then((r) => setRows(r.data.vouchers || [])).catch(() => setRows([]));
    }, []);

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-vouchers">
            <header className="shop-vouchers-head">
                <div>
                    <h1>{t('storefront.account.vouchers', 'My vouchers')}</h1>
                    <p>{t('storefront.account.vouchers_body', 'All discounts and offers available for your account.')}</p>
                </div>
                <Link to="/catalog" className="shop-vouchers-browse">
                    {t('storefront.account.browse_products', 'Browse all products')}
                </Link>
            </header>

            {rows.length === 0 ? (
                <section className="shop-vouchers-empty">
                    <VoucherEmptyArt />
                    <h2>{t('storefront.account.no_vouchers', "You don't have any vouchers yet")}</h2>
                    <p>{t('storefront.account.no_vouchers_body', 'When the shop creates a discount for you, it will show up here.')}</p>
                    <Link to="/catalog" className="shop-vouchers-cta">{t('storefront.shell.shop_now', 'Shop Now')}</Link>
                </section>
            ) : (
                <div className="shop-vouchers-grid">
                    {rows.map((row) => (
                        <article key={row.id} className="shop-voucher-card">
                            <div className="shop-voucher-card-top">
                                <span className="shop-voucher-icon"><Icon name="tag" className="h-5 w-5" /></span>
                                <div>
                                    <h2>{row.name}</h2>
                                    <p>{row.summary}</p>
                                </div>
                            </div>
                            <div className="shop-voucher-card-meta">
                                <div>
                                    <em>{t('storefront.cart.coupon', 'Code')}</em>
                                    <strong>{row.code || '—'}</strong>
                                </div>
                                <div>
                                    <em>{t('storefront.account.voucher_expires', 'Expires')}</em>
                                    <strong>{row.expires_at || t('storefront.account.no_expiry', 'No expiry')}</strong>
                                </div>
                            </div>
                            {row.code ? (
                                <button
                                    type="button"
                                    className="shop-voucher-copy"
                                    onClick={async () => {
                                        try {
                                            await navigator.clipboard.writeText(row.code);
                                        } catch {
                                            /* ignore */
                                        }
                                    }}
                                >
                                    {t('storefront.account.copy_code', 'Copy code')}
                                </button>
                            ) : null}
                        </article>
                    ))}
                </div>
            )}

            <section className="shop-vouchers-how">
                <h2>{t('storefront.account.how_vouchers', 'How vouchers work?')}</h2>
                <div className="shop-vouchers-how-grid">
                    <HowItem
                        icon="gift"
                        title={t('storefront.account.voucher_tip_1_title', 'Get exclusive offers')}
                        body={t('storefront.account.voucher_tip_1_body', 'Shops send special discounts and member offers just for you.')}
                    />
                    <HowItem
                        icon="cart"
                        title={t('storefront.account.voucher_tip_2_title', 'Apply at checkout')}
                        body={t('storefront.account.voucher_tip_2_body', 'Use vouchers to get instant discounts on your orders.')}
                    />
                    <HowItem
                        icon="tag"
                        title={t('storefront.account.voucher_tip_3_title', 'Save more')}
                        body={t('storefront.account.voucher_tip_3_body', 'Combine offers and save more on every purchase.')}
                    />
                </div>
            </section>
        </div>
    );
}

function HowItem({ icon, title, body }) {
    return (
        <article>
            <span><Icon name={icon} className="h-5 w-5" /></span>
            <strong>{title}</strong>
            <p>{body}</p>
        </article>
    );
}

function VoucherEmptyArt() {
    return (
        <svg className="shop-vouchers-art" viewBox="0 0 140 100" fill="none" aria-hidden="true">
            <circle cx="22" cy="28" r="4" fill="color-mix(in srgb, var(--shop-accent, #4545e6) 25%, #fff)" />
            <circle cx="118" cy="22" r="3" fill="color-mix(in srgb, var(--shop-accent, #4545e6) 35%, #fff)" />
            <circle cx="112" cy="72" r="5" fill="color-mix(in srgb, var(--shop-accent, #4545e6) 18%, #fff)" />
            <rect x="28" y="24" width="84" height="52" rx="12" fill="color-mix(in srgb, var(--shop-accent, #4545e6) 12%, #fff)" stroke="var(--shop-accent, #4545e6)" strokeWidth="2.5" />
            <circle cx="28" cy="50" r="8" fill="#f3f4f7" />
            <circle cx="112" cy="50" r="8" fill="#f3f4f7" />
            <path d="M52 50h36" stroke="var(--shop-accent, #4545e6)" strokeWidth="2" strokeDasharray="4 4" />
            <circle cx="70" cy="50" r="14" fill="var(--shop-accent, #4545e6)" />
            <text x="70" y="55" textAnchor="middle" fill="#fff" fontSize="14" fontWeight="700">%</text>
        </svg>
    );
}
