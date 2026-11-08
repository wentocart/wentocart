import { Link, useNavigate } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';
import PrefetchLink from './PrefetchLink.jsx';
import api from '../api.js';
import { applyLocaleDirection, switchStorefrontCurrency, switchStorefrontLocale } from '../lib/storefrontI18n.js';
import { t } from '../i18n.js';
import { formatMoney, shopName } from '../lib/format.js';
import { pricesHidden } from '../lib/commerce.js';
import { resolveConfiguredLink, resolveMenuLink, isExternalMenuUrl, entityState } from '../lib/links.js';
import { useStore } from '../state/StoreState.jsx';
import SearchSuggest from './SearchSuggest.jsx';
import { Icon } from './Icons.jsx';
import { compareEnabled, guestLookupEnabled, isCatalogMode, suggestionsEnabled, wishlistEnabled } from '../lib/commerce.js';
import { classicAssetUrl, classicDemoLogo } from '../classicAssets.js';
import { pickLocalizedText } from '../lib/localizedText.js';

function languageOptionLabel(language) {
    const name = String(language?.name || language?.code || '').trim();
    const code = String(language?.code || '').trim().toUpperCase();
    if (name && code && name.toUpperCase() !== code) {
        return `${name} (${code})`;
    }
    return name || code;
}

function Count({ count }) {
    return <span>{Number(count) || 0}</span>;
}

function categoryChildren(cat) {
    return Array.isArray(cat?.children) ? cat.children.filter(Boolean) : [];
}

function navLinkState(item) {
    return item?.isCms ? undefined : entityState('category');
}

/** Map CMS / extension menu items into the same shape as category nav nodes. */
function normalizeCmsMenuItems(items = []) {
    return (items || []).filter(Boolean).map((item) => ({
        id: item.id ?? item.url ?? item.label,
        name: item.label || item.name || '',
        url: resolveMenuLink(item.url || '/'),
        children: normalizeCmsMenuItems(item.children || []),
        isCms: true,
        openInNewWindow: !!(item.open_in_new_window || item.new_window),
    }));
}

function normalizeExtensionMenuItems(items = []) {
    return normalizeCmsMenuItems(items);
}

/** Internal RR Link or native <a> for external / new-window menu URLs. */
function NavHref({ to, state, className, onClick, target, rel, children, openInNewWindow = false, ...rest }) {
    const href = to || '/';
    const blank = openInNewWindow || target === '_blank' || isExternalMenuUrl(href);
    if (isExternalMenuUrl(href) || blank) {
        return (
            <a
                href={href}
                className={className}
                onClick={onClick}
                target={blank ? '_blank' : undefined}
                rel={blank ? (rel || 'noopener noreferrer') : rel}
                {...rest}
            >
                {children}
            </a>
        );
    }
    return (
        <Link to={href} state={state} className={className} onClick={onClick} {...rest}>
            {children}
        </Link>
    );
}

/** Mobile accordion: expand/collapse nested levels. */
function CategoryMobileAccordionList({ items, onNavigate, depth = 1 }) {
    const [expanded, setExpanded] = useState(() => ({}));
    if (!items?.length) {
        return null;
    }
    return (
        <ul className={`cx-cat-acc-list cx-cat-acc-depth-${Math.min(depth, 6)}`} data-depth={depth}>
            {items.map((item) => {
                const kids = categoryChildren(item);
                const hasKids = kids.length > 0;
                const isOpen = !!expanded[item.id];
                return (
                    <li key={item.id} className={`cx-cat-acc-item${hasKids ? ' has-children' : ''}${isOpen ? ' is-open' : ''}`}>
                        <div className="cx-cat-acc-row">
                            <NavHref
                                to={item.url}
                                state={navLinkState(item)}
                                className="cx-cat-acc-link"
                                onClick={onNavigate}
                                openInNewWindow={!!item.openInNewWindow}
                            >
                                {item.name}
                            </NavHref>
                            {hasKids ? (
                                <button
                                    type="button"
                                    className="cx-cat-acc-toggle"
                                    aria-expanded={isOpen}
                                    aria-label={isOpen ? 'Collapse' : 'Expand'}
                                    onClick={() => setExpanded((prev) => ({ ...prev, [item.id]: !prev[item.id] }))}
                                >
                                    <i className="material-icons" aria-hidden="true">{isOpen ? 'expand_less' : 'expand_more'}</i>
                                </button>
                            ) : null}
                        </div>
                        {hasKids && isOpen ? (
                            <CategoryMobileAccordionList items={kids} onNavigate={onNavigate} depth={depth + 1} />
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

/** Desktop mega panel: parent categories with every child level nested underneath. */
function CategoryTreeLinks({ nodes, onNavigate, depth = 0 }) {
    if (!nodes?.length) {
        return null;
    }
    return (
        <ul className={depth === 0 ? 'cx-cat-tree' : 'cx-cat-tree-children'}>
            {nodes.map((node) => {
                const kids = categoryChildren(node);
                return (
                    <li key={node.id || node.url} className="cx-cat-tree-item">
                        <NavHref
                            className="cx-cat-tree-link"
                            to={node.url}
                            state={navLinkState(node)}
                            onClick={onNavigate}
                            openInNewWindow={!!node.openInNewWindow}
                        >
                            {node.name}
                        </NavHref>
                        {kids.length ? <CategoryTreeLinks nodes={kids} onNavigate={onNavigate} depth={depth + 1} /> : null}
                    </li>
                );
            })}
        </ul>
    );
}

function CategoryMegaPanelBody({ cat, onNavigate }) {
    const kids = categoryChildren(cat);
    if (!kids.length) {
        return null;
    }
    return (
        <div className="cx-cat-mega-panel-inner">
            <CategoryTreeLinks nodes={kids} onNavigate={onNavigate} />
        </div>
    );
}

/** Desktop flyout: all root categories as mega columns. */
function CategoryNavItem({ cat, onNavigate }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);
    const kids = categoryChildren(cat);
    const hasKids = kids.length > 0;

    useEffect(() => {
        if (!open) return undefined;
        const onPointer = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    if (!hasKids) {
        return (
            <NavHref
                className="cx-cat-nav-link"
                to={cat.url}
                state={navLinkState(cat)}
                openInNewWindow={!!cat.openInNewWindow}
                onClick={onNavigate}
            >
                <span className="cx-cat-nav-label">{cat.name}</span>
            </NavHref>
        );
    }

    return (
        <div
            className={`cx-cat-nav-item${open ? ' is-open' : ''}`}
            ref={rootRef}
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
        >
            <NavHref
                className="cx-cat-nav-link"
                to={cat.url}
                state={navLinkState(cat)}
                openInNewWindow={!!cat.openInNewWindow}
                aria-expanded={open}
                onClick={onNavigate}
            >
                <span className="cx-cat-nav-label">{cat.name}</span>
                <Icon name="chevron-down" className="cx-nav-chevron" aria-hidden="true" />
            </NavHref>
            <div className={`cx-cat-mega-panel cx-cat-nav-flyout${open ? ' is-open' : ''}`} role="region" aria-label={cat.name}>
                <CategoryMegaPanelBody cat={cat} onNavigate={() => { setOpen(false); onNavigate?.(); }} />
            </div>
        </div>
    );
}

export default function ClassicHeader() {
    const store = useStore();
    const navigate = useNavigate();
    const [q, setQ] = useState('');
    const [mobileOpen, setMobileOpen] = useState(false);
    const [suggestions, setSuggestions] = useState([]);
    const [suggestTotal, setSuggestTotal] = useState(null);
    const [suggestLoading, setSuggestLoading] = useState(false);
    const [suggestOpen, setSuggestOpen] = useState(false);
    const [sticky, setSticky] = useState(false);
    const [extensionMenuItems, setExtensionMenuItems] = useState(null);
    const [topBarDropdown, setTopBarDropdown] = useState(null); // null | 'language' | 'currency'
    const searchRef = useRef(null);
    const languageRef = useRef(null);
    const currencyRef = useRef(null);

    const shop = store.shop;
    const themeSettings = shop?.shop?.theme?.settings || window.__WENTO__?.theme?.settings || {};
    const topBar = themeSettings.top_bar || {};
    const headerCfg = themeSettings.home?.header || {};
    const locale = shop?.locale || window.__WENTO__?.locale || 'en';
    const name = pickLocalizedText(themeSettings.logo_text, locale, '') || shop?.shop?.name || shopName();
    const logoUrl = classicAssetUrl(themeSettings.logo_url) || classicDemoLogo();
    const categories = shop?.categories || [];
    const currencies = shop?.currencies || [];
    const languages = shop?.languages || [];
    const customer = store.customer;
    const contactUrl = resolveConfiguredLink(topBar.contact?.url || '', { categories });
    const contactLabel = pickLocalizedText(topBar.contact?.label, locale, '').trim();
    const topBarEnabled = topBar.enabled !== false;
    const showCurrency = topBar.show_currency !== false;
    const showLanguage = topBar.show_language !== false;
    const showSearch = headerCfg.show_search !== false;
    const showCompare = headerCfg.show_compare !== false;
    const showWishlist = headerCfg.show_wishlist !== false;
    const showLogin = headerCfg.show_login !== false;
    const showCart = headerCfg.show_cart !== false;
    const cartStyle = headerCfg.cart_style === 'icon' ? 'icon' : 'pill';
    const searchPlaceholder = pickLocalizedText(
        headerCfg.search_placeholder,
        locale,
        t('storefront.shell.search_for_products', 'Search for products'),
    );
    const topLinks = Array.isArray(topBar.links) ? topBar.links.filter((row) => row?.label) : [];
    // Theme setting: categories | main_menu (legacy mega_menu → main_menu).
    // Category menu is the shop payload, already limited to Header → Choose categories.
    const navSourceRaw = String(headerCfg.nav_source || 'categories');
    const navSource = (navSourceRaw === 'mega_menu' || navSourceRaw === 'main_menu') ? 'main_menu' : 'categories';
    const shopCategories = categories.length ? categories : [];
    const extensionNav = Array.isArray(extensionMenuItems) ? normalizeExtensionMenuItems(extensionMenuItems) : [];
    const extensionReady = navSource !== 'main_menu' || extensionMenuItems !== null;
    const navItems = navSource === 'main_menu'
        ? (extensionReady ? (extensionNav.length ? extensionNav : shopCategories) : [])
        : shopCategories;
    const usingCmsNav = navSource === 'main_menu' && extensionNav.length > 0;

    useEffect(() => {
        if (navSource !== 'main_menu') {
            setExtensionMenuItems(null);
            return undefined;
        }
        let cancelled = false;
        api.get('/modules/weblytic/mainmenu/menu', { params: { locale } })
            .then((response) => {
                if (cancelled) return;
                const items = response?.data?.items;
                setExtensionMenuItems(Array.isArray(items) ? items : []);
            })
            .catch(() => {
                if (!cancelled) setExtensionMenuItems([]);
            });
        return () => { cancelled = true; };
    }, [locale, navSource]);

    useEffect(() => {
        if (!suggestionsEnabled(shop) || !q.trim()) {
            setSuggestions([]);
            setSuggestTotal(null);
            setSuggestLoading(false);
            setSuggestOpen(false);
            return undefined;
        }
        setSuggestLoading(true);
        setSuggestOpen(true);
        const handle = window.setTimeout(() => {
            api.get('/catalog/search', { params: { q } })
                .then((r) => {
                    setSuggestions(r.data.data || []);
                    setSuggestTotal(r.data.total ?? (r.data.data || []).length);
                })
                .catch(() => {
                    setSuggestions([]);
                    setSuggestTotal(null);
                })
                .finally(() => setSuggestLoading(false));
        }, 280);
        return () => window.clearTimeout(handle);
    }, [q, shop]);

    useEffect(() => {
        if (!suggestOpen) return undefined;
        const onPointer = (event) => {
            if (searchRef.current && !searchRef.current.contains(event.target)) setSuggestOpen(false);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') setSuggestOpen(false);
        };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [suggestOpen]);

    useEffect(() => {
        const updateSticky = () => setSticky(window.scrollY >= 40);
        updateSticky();
        window.addEventListener('scroll', updateSticky, { passive: true });
        return () => window.removeEventListener('scroll', updateSticky);
    }, []);

    useEffect(() => {
        if (!mobileOpen) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape') setMobileOpen(false);
        };
        document.body.classList.add('cx-header-mobile-open');
        document.addEventListener('keydown', onKey);
        return () => {
            document.body.classList.remove('cx-header-mobile-open');
            document.removeEventListener('keydown', onKey);
        };
    }, [mobileOpen]);

    useEffect(() => {
        if (!topBarDropdown) return undefined;
        const onPointer = (event) => {
            const root = topBarDropdown === 'language' ? languageRef.current : currencyRef.current;
            if (root && !root.contains(event.target)) {
                setTopBarDropdown(null);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') setTopBarDropdown(null);
        };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [topBarDropdown]);

    const toggleTopBarDropdown = (key) => {
        setTopBarDropdown((prev) => (prev === key ? null : key));
    };
    const switchLocale = (code) => {
        setTopBarDropdown(null);
        switchStorefrontLocale(code);
    };
    const switchCurrency = (code) => {
        setTopBarDropdown(null);
        switchStorefrontCurrency(code);
    };

    const search = (e) => {
        e.preventDefault();
        const next = q.trim();
        if (!next) return;
        navigate(`/search?q=${encodeURIComponent(next)}`);
        setSuggestOpen(false);
    };

    const closeSuggest = () => setSuggestOpen(false);
    const pickSuggestion = (label) => {
        setQ(label);
        navigate(`/search?q=${encodeURIComponent(label)}`);
        setSuggestOpen(false);
    };
    const closeMobile = () => setMobileOpen(false);

    useEffect(() => {
        applyLocaleDirection(locale);
    }, [locale]);

    const langCode = String(locale).slice(0, 2).toUpperCase();
    const currencyCode = shop?.currency || window.__WENTO__?.currency || 'USD';
    const cartTotal = pricesHidden(store.shop, store.customer)
        ? ''
        : formatMoney(store.cart?.totals?.grand_total ?? store.cart?.totals?.subtotal ?? 0);

    const announcementText = pickLocalizedText(topBar.announcement || shop?.shop?.announcement || '', locale, '').trim();
    const announcementHref = resolveConfiguredLink(
        topBar.announcement_url || shop?.shop?.announcement_url || '',
        { categories },
    );
    const phoneText = String(topBar.contact?.phone || shop?.shop?.phone || '').trim();
    const phoneTel = phoneText.replace(/[^\d+]/g, '');
    const contactTopLink = contactLabel
        ? { label: contactLabel, url: contactUrl || '/contact' }
        : null;
    const topBarStyle = {
        '--cx-top-bar-bg': topBar.background || '#3361cf',
        '--cx-top-bar-fg': topBar.text_color || '#f8fafc',
    };

    const renderTopLink = (link, key) => {
        const label = pickLocalizedText(link?.label, locale, '').trim();
        if (!label) return null;
        if (link.open_in_new_tab) {
            return (
                <a key={key || label} className="cx-top-bar-link" href={link.url || '/'} target="_blank" rel="noreferrer">
                    {label}
                </a>
            );
        }
        return (
            <Link key={key || label} className="cx-top-bar-link" to={resolveConfiguredLink(link.url || '/', { categories })}>
                {label}
            </Link>
        );
    };

    const visibleCats = navItems;
    const overflowCats = [];

    return (
        <header id="header" className={`cx-header-wood${sticky ? ' is-sticky' : ''}${mobileOpen ? ' is-mobile-open' : ''}`}>
            {topBarEnabled ? (
                <div className="header-banner cx-top-bar" style={topBarStyle}>
                    <div className="container cx-top-bar-inner">
                        <div className="cx-top-bar-left">
                            {announcementText ? (
                                announcementHref ? (
                                    <Link className="cx-top-bar-link cx-top-bar-announce" to={announcementHref}>
                                        {announcementText}
                                    </Link>
                                ) : (
                                    <span className="cx-top-bar-announce">{announcementText}</span>
                                )
                            ) : null}
                            {phoneText ? (
                                phoneTel ? (
                                    <a className="cx-top-bar-link cx-top-bar-phone" href={`tel:${phoneTel}`}>
                                        {phoneText}
                                    </a>
                                ) : (
                                    <span className="cx-top-bar-phone">{phoneText}</span>
                                )
                            ) : null}
                        </div>
                        <div className="cx-top-bar-right">
                            {renderTopLink(contactTopLink, 'contact')}
                            {guestLookupEnabled(shop) ? (
                                <Link className="cx-top-bar-link" to="/order-status">
                                    {t('storefront.header.track', 'Track Your Order')}
                                </Link>
                            ) : null}
                            {topLinks.map((link, index) => renderTopLink(link, `link-${index}`))}
                            {showLanguage && languages.length ? (
                                <div id="_desktop_language_selector" className="cx-top-bar-tool" ref={languageRef}>
                                    <div className={`language-selector${topBarDropdown === 'language' ? ' is-open' : ''}`}>
                                        <button
                                            type="button"
                                            className="cx-top-select"
                                            aria-haspopup="listbox"
                                            aria-expanded={topBarDropdown === 'language'}
                                            onClick={() => toggleTopBarDropdown('language')}
                                        >
                                            <span>{langCode}</span>
                                            <Icon name="chevron-down" className="cx-top-chevron" aria-hidden="true" />
                                        </button>
                                        <ul className="dropdown-menu" role="listbox">
                                            {languages.map((language) => (
                                                <li key={language.code} className={language.code === locale ? 'current' : ''}>
                                                    <button type="button" className="dropdown-item" role="option" aria-selected={language.code === locale} onClick={() => switchLocale(language.code)}>
                                                        {languageOptionLabel(language)}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            ) : null}
                            {showCurrency && currencies.length ? (
                                <div id="_desktop_currency_selector" className="cx-top-bar-tool" ref={currencyRef}>
                                    <div className={`currency-selector${topBarDropdown === 'currency' ? ' is-open' : ''}`}>
                                        <button
                                            type="button"
                                            className="cx-top-select"
                                            aria-haspopup="listbox"
                                            aria-expanded={topBarDropdown === 'currency'}
                                            onClick={() => toggleTopBarDropdown('currency')}
                                        >
                                            <span>{currencyCode}</span>
                                            <Icon name="chevron-down" className="cx-top-chevron" aria-hidden="true" />
                                        </button>
                                        <ul className="dropdown-menu" role="listbox">
                                            {currencies.map((currency) => (
                                                <li key={currency.code} className={currency.code === currencyCode ? 'current' : ''}>
                                                    <button type="button" className="dropdown-item" role="option" aria-selected={currency.code === currencyCode} onClick={() => switchCurrency(currency.code)}>
                                                        {currency.code} ({currency.symbol || currency.code})
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            <div className={`header-top${sticky ? ' is-stuck' : ''}`}>
                <div className="header-div">
                    <div className="container cx-wood-header-row">
                        <button
                            type="button"
                            className="cx-mobile-toggle"
                            aria-expanded={mobileOpen}
                            aria-controls="cx-mobile-drawer"
                            aria-label={mobileOpen ? t('storefront.shell.close_menu', 'Close menu') : t('storefront.shell.open_menu', 'Open menu')}
                            onClick={() => setMobileOpen((v) => !v)}
                        >
                            <Icon name={mobileOpen ? 'close' : 'menu'} className="cx-mobile-toggle-icon" aria-hidden="true" />
                        </button>

                        <div className="header-left cx-wood-col-left">
                            <div className="header_logo" id="_desktop_logo">
                                <Link to="/" onClick={closeMobile}>
                                    <img className="logo" src={logoUrl} alt={name} width="160" height="46" />
                                </Link>
                            </div>
                        </div>

                        {showSearch ? (
                            <div className="header-center cx-wood-col-center">
                                <div id="search_block_top" className="cx-wood-search">
                                    <div className="searchtoggle" ref={searchRef}>
                                        <form id="searchbox" className="searchform cx-search-pill" onSubmit={search} autoComplete="off">
                                            <span className="cx-search-leading" aria-hidden="true">
                                                <Icon name="search" className="cx-search-leading-icon" />
                                            </span>
                                            <div className="cpsearch-main">
                                                <input
                                                    className="search_query form-control s"
                                                    type="search"
                                                    id="search_query_top"
                                                    name="s"
                                                    placeholder={searchPlaceholder}
                                                    value={q}
                                                    onChange={(e) => setQ(e.target.value)}
                                                    onFocus={() => { if (q.trim()) setSuggestOpen(true); }}
                                                    aria-label={t('storefront.shell.search', 'Search')}
                                                    aria-expanded={suggestOpen}
                                                    aria-controls="cx-search-suggest"
                                                />
                                            </div>
                                            <button type="submit" className="btn btn-default cx-wood-search-btn searchsubmit sr-only" tabIndex={-1} aria-hidden="true">
                                                {t('storefront.shell.search', 'Search')}
                                            </button>
                                        </form>
                                        {suggestOpen && q.trim() ? (
                                            <div id="cx-search-suggest">
                                                <SearchSuggest
                                                    query={q}
                                                    products={suggestions}
                                                    total={suggestTotal}
                                                    loading={suggestLoading}
                                                    onClose={closeSuggest}
                                                    onPickSuggestion={pickSuggestion}
                                                />
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        ) : <div className="header-center cx-wood-col-center" />}

                        <div className="header-right cx-wood-actions cx-wood-col-right">
                            {showCompare && compareEnabled(shop) ? (
                                <PrefetchLink
                                    className="cx-action-icon cx-compare-btn"
                                    to="/compare"
                                    aria-label={t('storefront.shell.compare_products', 'Compare Products')}
                                    title={t('storefront.shell.compare_products', 'Compare Products')}
                                    rel="nofollow"
                                >
                                    <Icon name="compare" className="cx-compare-icon" aria-hidden="true" />
                                    <span className="cx-action-badge"><Count count={store.compare?.length} /></span>
                                </PrefetchLink>
                            ) : null}
                            {showWishlist && wishlistEnabled(shop) ? (
                                <PrefetchLink
                                    className="cx-action-icon cx-wishlist-btn"
                                    to="/account/wishlist"
                                    aria-label={t('storefront.shell.wishlist', 'Wishlist')}
                                    rel="nofollow"
                                >
                                    <Icon name="heart" className="cx-wishlist-icon" aria-hidden="true" />
                                    <span className="cx-action-badge"><Count count={store.wishlistIds.length} /></span>
                                </PrefetchLink>
                            ) : null}
                            {showLogin ? (
                                <PrefetchLink
                                    className="cx-account-pill"
                                    to={customer ? '/account' : '/login'}
                                    aria-label={customer ? t('storefront.shell.my_account', 'My Account') : t('storefront.shell.login_register', 'Login / Register')}
                                    rel="nofollow"
                                >
                                    <Icon name="user" className="cx-action-svg" aria-hidden="true" />
                                    <span className="cx-account-pill-text">
                                        {customer
                                            ? t('storefront.shell.my_account', 'My Account')
                                            : t('storefront.shell.login_register', 'Login / Register')}
                                    </span>
                                </PrefetchLink>
                            ) : null}
                            {showCart && !isCatalogMode(shop) ? (
                                <div id="_desktop_cart" className="cx-cart-wood">
                                    <div className="blockcart">
                                        <div className="header blockcart-header">
                                            <button
                                                type="button"
                                                className={`icon_menu cx-wood-cart cx-cart-btn${cartStyle === 'icon' ? ' is-icon' : ''}`}
                                                aria-label={t('storefront.shell.cart', 'Cart')}
                                                onClick={() => store.openCart?.()}
                                            >
                                                <span className="cx-cart-btn-icon-wrap">
                                                    <Icon name="cart" className="cx-cart-btn-icon" aria-hidden="true" />
                                                </span>
                                                {cartStyle === 'pill' && cartTotal ? <span className="cx-cart-btn-total">{cartTotal}</span> : null}
                                                <span className="cx-action-badge"><Count count={store.cartCount} /></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>

            <div className="header-top-main bg_main cx-wood-nav">
                <div className="container cx-wood-nav-inner">
                    <nav className="cx-cat-nav" aria-label={usingCmsNav ? t('storefront.shell.menu', 'Menu') : t('storefront.shell.categories', 'Categories')}>
                        {visibleCats.map((cat) => (
                            <CategoryNavItem key={cat.id} cat={cat} />
                        ))}
                        {overflowCats.length ? (
                            <div className="cx-cat-nav-more">
                                <span className="cx-cat-nav-link">{t('storefront.shell.more', 'More')}</span>
                                <div className="cx-cat-nav-more-menu">
                                    {overflowCats.map((cat) => (
                                        <Link key={cat.id} to={cat.url} state={navLinkState(cat)}>{cat.name}</Link>
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </nav>
                </div>
            </div>

            <div className={`cx-mobile-backdrop${mobileOpen ? ' is-open' : ''}`} onClick={closeMobile} aria-hidden="true" />
            <div id="cx-mobile-drawer" className={`cx-mobile-drawer${mobileOpen ? ' is-open' : ''}`} hidden={!mobileOpen}>
                <div className="cx-mobile-drawer-inner">
                    {showSearch ? (
                        <form className="cx-mobile-search" onSubmit={(e) => { search(e); closeMobile(); }}>
                            <Icon name="search" className="cx-search-leading-icon" aria-hidden="true" />
                            <input
                                type="search"
                                placeholder={searchPlaceholder}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                aria-label={t('storefront.shell.search', 'Search')}
                            />
                        </form>
                    ) : null}
                    <div className="cx-mobile-primary">
                        {showLogin ? (
                            <PrefetchLink to={customer ? '/account' : '/login'} onClick={closeMobile} rel="nofollow">
                                {customer ? t('storefront.shell.my_account', 'My Account') : t('storefront.shell.login_register', 'Login / Register')}
                            </PrefetchLink>
                        ) : null}
                        <Link to={contactUrl} onClick={closeMobile}>{contactLabel}</Link>
                        {guestLookupEnabled(shop) ? (
                            <Link to="/order-status" onClick={closeMobile}>{t('storefront.header.track', 'Track Your Order')}</Link>
                        ) : null}
                        <Link to="/help" onClick={closeMobile}>{t('storefront.shell.help', 'Help')}</Link>
                        <Link to="/about" onClick={closeMobile}>{t('storefront.shell.about_us', 'About Us')}</Link>
                    </div>
                    <div className="cx-mobile-cats">
                        <p className="cx-mobile-cats-title">{usingCmsNav ? t('storefront.shell.menu', 'Menu') : t('storefront.shell.categories', 'Categories')}</p>
                        <CategoryMobileAccordionList items={navItems} onNavigate={closeMobile} depth={1} />
                    </div>
                </div>
            </div>
        </header>
    );
}
