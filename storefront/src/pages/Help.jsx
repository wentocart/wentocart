import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { t } from '../i18n.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { Icon } from '../components/Icons.jsx';
import { applyCmsPageSeo, applySeoHead } from '../lib/seoHead.js';

const faqs = [
    {
        key: 'track',
        q: () => t('storefront.help.faq_track_q', 'How do I track an order?'),
        a: () => t('storefront.help.faq_track_a', 'Use Track Your Order in the header with your order number and email, or sign in and open My orders.'),
    },
    {
        key: 'coupons',
        q: () => t('storefront.help.faq_coupons_q', 'Which coupons work in this demo?'),
        a: () => t('storefront.help.faq_coupons_a', 'Use SAVE10 for 10% off, or WELCOME15 for $15 off carts over $80.'),
    },
    {
        key: 'returns',
        q: () => t('storefront.help.faq_returns_q', 'How do returns work?'),
        a: () => t('storefront.help.faq_returns_a', 'Most unused items can be returned within 7 days. Start from the order page or email the shop.'),
    },
    {
        key: 'demo',
        q: () => t('storefront.help.faq_demo_q', 'Demo customer login?'),
        a: () => t('storefront.help.faq_demo_a', 'Use the email and password from your account. This shop does not publish a sample login.'),
    },
    {
        key: 'sell',
        q: () => t('storefront.help.faq_sell_q', 'How do I sell on this store?'),
        a: () => t('storefront.help.faq_sell_a', 'Sign in, open Become a seller, and send a shop request. After an admin accepts it, Seller Dashboard appears in My Account.'),
    },
];

export default function Help({ compact = false }) {
    const [page, setPage] = useState(null);
    const [openKey, setOpenKey] = useState(faqs[0]?.key || null);

    useEffect(() => {
        api.get('/catalog/page/help').then((r) => {
            const row = r.data.page || null;
            setPage(row);
            const faqLd = {
                '@context': 'https://schema.org',
                '@type': 'FAQPage',
                mainEntity: faqs.map((row) => ({
                    '@type': 'Question',
                    name: row.q(),
                    acceptedAnswer: { '@type': 'Answer', text: row.a() },
                })),
            };
            if (row) {
                applySeoHead({
                    title: row.seo?.title || row.meta_title || row.title,
                    description: row.seo?.description || row.meta_description || row.short_description || '',
                    robots: row.seo?.robots || row.robots || 'index,follow',
                    canonical: row.seo?.canonical || row.canonical_url || (window.location.origin + window.location.pathname),
                    og_type: 'article',
                    og_image: row.seo?.og_image || null,
                    clear_og_image: !row.seo?.og_image,
                    hreflang: row.seo?.hreflang || [],
                    json_ld: faqLd,
                });
            } else {
                applyCmsPageSeo(null, 'Help');
                applySeoHead({
                    title: `Help | ${window.__WENTO__?.shop || 'Shop'}`,
                    description: '',
                    robots: 'noindex,follow',
                    canonical: window.location.origin + window.location.pathname,
                    og_type: 'website',
                    clear_og_image: true,
                    hreflang: [],
                    json_ld: faqLd,
                });
            }
        }).catch(() => {
            setPage(false);
            applyCmsPageSeo(null, 'Help');
        });
    }, []);

    if (page === null) {
        return <PageSkeleton />;
    }

    const toggle = (key) => {
        setOpenKey((current) => (current === key ? null : key));
    };

    return (
        <div className={compact ? 'shop-help' : 'shop-container shop-help py-10'}>
            {compact ? null : (
                <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: t('storefront.shell.help', 'Help') }]} />
            )}
            <header className="shop-help-head">
                <h1>{page?.title || t('storefront.help.center', 'Help center')}</h1>
                <p>{page?.short_description || t('storefront.help.intro', 'Orders, shipping, returns, and your account.')}</p>
            </header>

            <div className="shop-help-layout">
                <div className="shop-help-main">
                    <div className="shop-help-faq" role="list">
                        {faqs.map((row) => {
                            const open = openKey === row.key;
                            const panelId = `help-faq-${row.key}`;
                            return (
                                <div key={row.key} className={`shop-help-item${open ? ' is-open' : ''}`} role="listitem">
                                    <button
                                        type="button"
                                        className="shop-help-trigger"
                                        aria-expanded={open}
                                        aria-controls={panelId}
                                        onClick={() => toggle(row.key)}
                                    >
                                        <span>{row.q()}</span>
                                        <Icon name="chevronDown" className="h-4 w-4" />
                                    </button>
                                    <div
                                        id={panelId}
                                        className="shop-help-panel"
                                        role="region"
                                        hidden={!open}
                                    >
                                        <p>{row.a()}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {page?.body ? (
                        <article className="shop-prose shop-help-cms" dangerouslySetInnerHTML={{ __html: page.body }} />
                    ) : null}
                </div>

                <aside className="shop-help-guides">
                    <h2>{t('storefront.help.guides', 'Guides')}</h2>
                    <nav>
                        <Link to="/shipping">{t('storefront.help.shipping', 'Shipping and delivery')}</Link>
                        <Link to="/returns">{t('storefront.help.returns', 'Returns and refunds')}</Link>
                        <Link to="/faq">{t('storefront.help.faq', 'FAQ')}</Link>
                        <Link to="/contact">{t('storefront.help.contact', 'Contact')}</Link>
                        <Link to="/privacy">{t('storefront.shell.privacy', 'Privacy')}</Link>
                        <Link to="/terms">{t('storefront.help.terms', 'Terms')}</Link>
                        <Link to="/order-status">{t('storefront.shell.track', 'Track Order')}</Link>
                    </nav>
                </aside>
            </div>
        </div>
    );
}
