import { Link, Outlet, useLocation } from 'react-router-dom';
import PrefetchLink from './components/PrefetchLink.jsx';
import { useEffect, useLayoutEffect, useRef } from 'react';
import { bootI18n, t } from './i18n.js';
import { shopName } from './lib/format.js';
import { jumpToHash, jumpToTop } from './lib/scroll.js';
import { applySeoHead, applyUtilitySeoIfNeeded } from './lib/seoHead.js';
import { useStore } from './state/StoreState.jsx';
import { Icon } from './components/Icons.jsx';
import ModuleBlocks from './components/ModuleBlocks.jsx';
import DealBellBanner from './components/DealBellBanner.jsx';
import ShopAiWidget from './components/ShopAiWidget.jsx';
import ClassicHeader from './components/ClassicHeader.jsx';
import MiniCart from './components/MiniCart.jsx';
import { resolveConfiguredLink } from './lib/links.js';
import { pickLocalizedText } from './lib/localizedText.js';
import { useShopSession } from './state/useShopSession.js';
import { classicAssetUrl, classicDemoLogo, classicDemoPaymentStrip } from './classicAssets.js';
import { webPath } from './webPath.js';
import { guestLookupEnabled, isCatalogMode } from './lib/commerce.js';
import CookieConsent, { PixelTags } from './components/CookieConsent.jsx';
import AgeGate from './components/AgeGate.jsx';
import api, { csrf } from './api.js';

const FOOTER_SOCIAL = [
    { label: 'Facebook', href: 'https://facebook.com', src: '/themes/classic/assets/img/social/facebook.png' },
    { label: 'Twitter', href: 'https://twitter.com', src: '/themes/classic/assets/img/social/twitter.png' },
    { label: 'Instagram', href: 'https://instagram.com', src: '/themes/classic/assets/img/social/instagram.png' },
    { label: 'YouTube', href: 'https://youtube.com', src: '/themes/classic/assets/img/social/youtube.png' },
];

export default function Shell() {
    bootI18n();
    const store = useStore();
    const { locale } = useShopSession();
    const location = useLocation();
    const prevPathname = useRef(location.pathname);
    const shop = store.shop;
    const themeSettings = shop?.shop?.theme?.settings || window.__WENTO__?.theme?.settings || {};
    const footerShow = shop?.shop?.footer || {};
    const name = pickLocalizedText(themeSettings.logo_text, locale, '') || shop?.shop?.name || shopName();
    const footerSlots = shop?.modules?.footer || [];
    const headerTop = shop?.modules?.header_top || [];
    const categories = shop?.categories || [];
    const email = footerShow.email === false ? '' : (shop?.shop?.email || '');
    const phone = footerShow.phone === false ? '' : (shop?.shop?.phone || '');
    const address = footerShow.address === false ? '' : (shop?.shop?.address || '');
    const businessNumber = footerShow.business_number
        ? (shop?.shop?.business_number || shop?.shop?.gst || shop?.shop?.vat || '')
        : '';
    const onHome = location.pathname === '/';
    const heroCfg = themeSettings.home?.hero || {};
    const heroLayout = [1, 2, 3].includes(Number(heroCfg.layout))
        ? Number(heroCfg.layout)
        : (Array.isArray(heroCfg.columns) && heroCfg.columns.length ? Math.min(3, heroCfg.columns.length) : 0);
    const homeHeroOne = onHome && heroLayout === 1;
    const footer = themeSettings.home?.footer || {};
    const customColumns = Array.isArray(footer.columns) && footer.columns.length
        ? footer.columns
        : null;
    const logoUrl = classicAssetUrl(themeSettings.logo_url) || classicDemoLogo();
    const footerColumns = withGuestTracking(buildFooterColumns(customColumns, categories, locale), guestLookupEnabled(shop), locale);

    // Scroll to top only on pathname changes (new page), not query-only updates (filters, tabs, pagination).
    useLayoutEffect(() => {
        const pathChanged = prevPathname.current !== location.pathname;
        prevPathname.current = location.pathname;

        if (location.hash) {
            jumpToHash(location.hash);
            return;
        }
        if (pathChanged) {
            jumpToTop();
        }
    }, [location.pathname, location.hash]);

    useEffect(() => {
        applyUtilitySeoIfNeeded(location.pathname);
    }, [location.pathname]);

    return (
        <div
            className={`cx-page min-h-screen bg-white${onHome ? ' page-index' : ''}${homeHeroOne ? ' cx-home-hero-1' : ''}`}
        >
            {headerTop.length ? (
                <div className="bg-[var(--shop-accent-soft)] text-sm">
                    <div className="shop-container py-2">
                        <ModuleBlocks slots={headerTop} />
                    </div>
                </div>
            ) : null}

            <ClassicHeader />

            {store.customer?.impersonating ? (
                <div className="bg-amber-100 text-amber-950 text-sm">
                    <div className="shop-container py-2 flex flex-wrap items-center justify-between gap-2">
                        <span>{t('storefront.account.impersonating', 'You are viewing the store as this customer.')}</span>
                        <button
                            type="button"
                            className="underline"
                            onClick={async () => {
                                await csrf();
                                await api.post('/account/stop-impersonation');
                                window.location.href = '/admin/customers';
                            }}
                        >
                            {t('storefront.account.stop_impersonation', 'Return to admin')}
                        </button>
                    </div>
                </div>
            ) : null}

            <div className="cx-page-body">
                <Outlet />
            </div>
            <DealBellBanner />
            <ShopAiWidget />
            {isCatalogMode(shop) ? null : <MiniCart />}
            <CookieConsent shop={shop} />
            <AgeGate shop={shop} />
            <PixelTags shop={shop} />

            <footer className="cx-footer">
                <div className="shop-container cx-footer-grid">
                    {footerColumns.map((col) => (
                        <FooterColumn
                            key={col.key}
                            column={col}
                            name={name}
                            logoUrl={logoUrl}
                            email={email}
                            phone={phone}
                            address={address}
                            about={footer.about || themeSettings.tagline || ''}
                            businessNumber={businessNumber}
                        />
                    ))}
                </div>
                {footerSlots.length ? (
                    <div className="shop-container pb-6">
                        <ModuleBlocks slots={footerSlots} />
                    </div>
                ) : null}
                <div className="cx-footer-after">
                    <div className="shop-container">
                        <p>© {new Date().getFullYear()} {name}. {pickLocalizedText(footer.copyright, locale, '') || t('storefront.shell.rights', 'All rights reserved.')}</p>
                    </div>
                </div>
            </footer>
        </div>
    );
}

function footerPlain(value) {
    if (value && typeof value === 'object') {
        return Object.values(value).filter((row) => typeof row === 'string').join(' ');
    }

    return String(value || '');
}

function withGuestTracking(columns, enabled, locale = 'en') {
    return (columns || []).map((column) => {
        if (column.type !== 'links') {
            return column;
        }
        let links = (column.links || []).map((link) => {
            const path = String(link?.url || '').split('?')[0];
            if (enabled && (path === '/order-tracking' || path === '/order-status')) {
                return { ...link, url: '/order-status' };
            }
            if (!enabled && path === '/order-status') {
                return null;
            }
            return link;
        }).filter(Boolean);
        const company = column.key === 'company' || /company|about/i.test(footerPlain(column.title));
        if (enabled && company && !links.some((link) => String(link.url || '').split('?')[0] === '/order-status')) {
            links = [...links, { label: t('storefront.header.track', 'Track Your Order'), url: '/order-status' }];
        }
        return { ...column, links };
    });
}

function localizeLinks(links, locale) {
    return (links || []).map((link) => ({
        ...link,
        label: pickLocalizedText(link?.label, locale, ''),
    })).filter((link) => link.label);
}

function buildFooterColumns(customColumns, categories, locale = 'en') {
    const source = Array.isArray(customColumns) && customColumns.length ? customColumns : defaultColumns();

    return source.map((col, index) => {
        const type = col?.type || 'links';
        const title = pickLocalizedText(col?.title, locale, '');
        if (type === 'contact') {
            return {
                key: `brand-${index}`,
                type: 'brand',
                title: title || t('storefront.shell.contact_us', 'Contact Us'),
                links: [],
            };
        }
        if (type === 'categories') {
            return {
                key: `categories-${index}`,
                type: 'links',
                title: title || t('storefront.shell.trending', 'Trending Categories'),
                links: localizeLinks(col?.links, locale).map((link) => ({
                    ...link,
                    url: resolveConfiguredLink(link.url, { categories }),
                })),
            };
        }

        return {
            key: `links-${index}`,
            type: 'links',
            title: title || t('storefront.shell.links', 'Links'),
            links: localizeLinks(col?.links, locale),
        };
    });
}

function defaultColumns() {
    return [
        { title: t('storefront.shell.contact_us', 'Contact Us'), type: 'contact', links: [] },
        { title: t('storefront.shell.trending', 'Trending Categories'), type: 'categories', links: [] },
        { title: t('storefront.shell.products', 'Products'), type: 'links', links: [
            { label: t('storefront.shell.prices_drop', 'Prices drop'), url: '/deal' },
            { label: t('storefront.shell.new_products', 'New products'), url: '/new-arrivals' },
            { label: t('storefront.shell.best_sellers', 'Best sellers'), url: '/catalog' },
        ] },
        { title: t('storefront.shell.company', 'Our Company'), type: 'links', links: [
            { label: t('storefront.about.title', 'About us'), url: '/about' },
            { label: t('storefront.help.contact', 'Contact us'), url: '/contact' },
            { label: t('storefront.shell.stores', 'Stores'), url: '/stores' },
            { label: t('storefront.help.shipping', 'Shipping & delivery'), url: '/shipping' },
            { label: t('storefront.help.returns', 'Refunds & returns'), url: '/returns' },
            { label: t('storefront.help.terms', 'Terms & conditions'), url: '/terms' },
            { label: t('storefront.shell.privacy', 'Privacy policy'), url: '/privacy' },
            { label: t('storefront.shell.payment_security', 'Payment & security'), url: '/payment-security' },
            { label: t('storefront.shell.warranty', 'Warranty & authenticity'), url: '/warranty' },
            { label: t('storefront.shell.faq', 'FAQ'), url: '/faq' },
            { label: t('storefront.shell.order_tracking', 'Order tracking'), url: '/order-tracking' },
        ] },
    ];
}

function FooterColumn({ column, name, logoUrl, email, phone, address, about, businessNumber }) {
    if (column.type === 'brand') {
        const legal = [businessNumber && `${t('storefront.shell.business_number', 'Business no.')}: ${businessNumber}`].filter(Boolean);
        return (
            <div className="cx-footer-brand">
                <Link to="/" className="cx-footer-logo">
                    <img src={logoUrl} alt={name} />
                </Link>
                <p className="cx-footer-about">
                    {about
                        || t('storefront.shell.footer_about', 'Premium products, trusted service, and a smoother way to shop every day.')}
                </p>
                {column.title ? <h2>{column.title}</h2> : null}
                {(address || email || phone || legal.length) ? (
                    <ul className="cx-footer-contact">
                        {address ? (
                            <li>
                                <span className="cx-footer-ico" aria-hidden="true"><Icon name="pin" className="h-4 w-4" /></span>
                                <span className="whitespace-pre-line">{address}</span>
                            </li>
                        ) : null}
                        {email ? (
                            <li>
                                <span className="cx-footer-ico" aria-hidden="true"><Icon name="mail" className="h-4 w-4" /></span>
                                <a href={`mailto:${email}`}>{email}</a>
                            </li>
                        ) : null}
                        {phone ? (
                            <li>
                                <span className="cx-footer-ico" aria-hidden="true"><Icon name="phone" className="h-4 w-4" /></span>
                                <a href={`tel:${String(phone).replace(/[^\d+]/g, '')}`}>{phone}</a>
                            </li>
                        ) : null}
                        {legal.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                ) : null}
                <p className="cx-follow">{t('storefront.shell.subscribe_us', 'Subscribe us')}</p>
                <div className="cx-social">
                    {FOOTER_SOCIAL.map((item) => (
                        <a
                            key={item.label}
                            href={item.href}
                            target="_blank"
                            rel="noreferrer"
                            aria-label={item.label}
                        >
                            <img src={webPath(item.src)} alt="" width="25" height="25" />
                        </a>
                    ))}
                </div>
            </div>
        );
    }

    const links = (column.links || [])
        .filter((link) => link?.label)
        .map((link) => ({
            ...link,
            url: resolveConfiguredLink(link.url),
        }));

    return (
        <div className="cx-footer-col">
            <h2>{column.title}</h2>
            <div className="cx-footer-links">
                {links.map((link, i) => (
                    <FooterLink key={`${link.url}-${i}`} url={link.url}>{link.label}</FooterLink>
                ))}
            </div>
        </div>
    );
}

function FooterLink({ url, children }) {
    const href = url || '/';
    if (/^https?:\/\//i.test(href) || /\.xml($|\?)/i.test(href)) {
        return <a href={href}>{children}</a>;
    }
    const type = href.startsWith('/brand/')
        ? 'brand'
        : ['/about', '/shipping', '/privacy', '/terms', '/contact', '/stores', '/help', '/blog', '/faq', '/returns', '/payment-security', '/order-tracking', '/warranty', '/new-arrivals'].includes(href.split('?')[0])
            ? 'cms'
            : (href === '/' || href.split('?')[0] === '/order-status' ? null : 'category');
    return <PrefetchLink to={href} state={type ? { entityType: type } : undefined}>{children}</PrefetchLink>;
}
