import { Link, NavLink, useLocation } from 'react-router-dom';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { t } from '../i18n.js';
import { switchStorefrontCurrency, switchStorefrontLocale } from '../lib/storefrontI18n.js';
import { useStore } from '../state/StoreState.jsx';
import { useShopSession } from '../state/useShopSession.js';
import { Icon } from './Icons.jsx';

function shortLanguageLabel(code) {
    const value = String(code || 'en').trim();
    if (value.length <= 3) {
        return value.slice(0, 2).toUpperCase();
    }
    return value.slice(0, 2).toUpperCase();
}

function currencyLabel(row, fallbackCode = 'USD') {
    if (!row) {
        return fallbackCode;
    }
    return `${row.symbol || '$'} ${row.code}`;
}

function HeaderInlineDropdown({ label, value, options, onChange, displayLabel, menuClassName = '' }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);
    const listId = useId();
    const currentLabel = displayLabel ?? options.find((opt) => opt.value === value)?.label ?? value;

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onDocClick = (event) => {
            if (!rootRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const onEsc = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDocClick);
        document.addEventListener('keydown', onEsc);
        return () => {
            document.removeEventListener('mousedown', onDocClick);
            document.removeEventListener('keydown', onEsc);
        };
    }, [open]);

    const pick = (next) => {
        setOpen(false);
        if (next !== value) {
            onChange(next);
        }
    };

    return (
        <div className="mv-portal-header-dropdown" ref={rootRef}>
            <button
                type="button"
                className="mv-portal-header-dropdown-trigger"
                aria-label={label}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={listId}
                onClick={() => setOpen((current) => !current)}
            >
                <span className="mv-portal-header-dropdown-text">{currentLabel}</span>
                <Icon
                    name="chevron-down"
                    className={`mv-portal-header-dropdown-chevron${open ? ' is-open' : ''}`}
                    width="18"
                    height="18"
                />
            </button>
            {open ? (
                <ul
                    className={`mv-portal-header-dropdown-menu${menuClassName ? ` ${menuClassName}` : ''}`}
                    id={listId}
                    role="listbox"
                    aria-label={label}
                >
                    {options.map((opt) => (
                        <li key={opt.value} role="none">
                            <button
                                type="button"
                                role="option"
                                aria-selected={opt.value === value}
                                className={`mv-portal-header-dropdown-item${opt.value === value ? ' is-selected' : ''}`}
                                onClick={() => pick(opt.value)}
                            >
                                {opt.label}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

export default function SellerPortalHeaderActions({ seller }) {
    const { pathname } = useLocation();
    const store = useStore();
    const shop = store.shop;

    const session = useShopSession();
    const locale = session.locale || shop?.locale || 'en';
    const currencyCode = session.currency || shop?.currency || 'USD';
    const languages = shop?.languages || [];
    const currencies = shop?.currencies || [];

    const showLanguageSelect = languages.length > 1;
    const showCurrencySelect = currencies.length > 1;

    const languageOptions = useMemo(
        () => languages.map((row) => ({
            value: row.code,
            label: shortLanguageLabel(row.code),
        })),
        [languages],
    );

    const currencyOptions = useMemo(
        () => currencies.map((row) => ({
            value: row.code,
            label: currencyLabel(row),
        })),
        [currencies],
    );

    const currentLanguageLabel = useMemo(() => {
        const row = languages.find((item) => item.code === locale);
        return shortLanguageLabel(row?.code || locale);
    }, [languages, locale]);

    const currentCurrencyLabel = useMemo(() => {
        const row = currencies.find((item) => item.code === currencyCode);
        return currencyLabel(row, currencyCode);
    }, [currencies, currencyCode]);

    const shopHref = seller?.shop_slug ? `/shop/${seller.shop_slug}` : '';
    const profileActive = pathname === '/account/seller/profile'
        || pathname.startsWith('/account/seller/profile/')
        || pathname.startsWith('/account/seller/shop');

    return (
        <div className="mv-portal-header-actions">
            {showLanguageSelect ? (
                <HeaderInlineDropdown
                    label={t('storefront.header.language', 'Language')}
                    value={locale}
                    options={languageOptions}
                    displayLabel={currentLanguageLabel}
                    menuClassName="mv-portal-header-dropdown-menu--language"
                    onChange={switchStorefrontLocale}
                />
            ) : (
                <span className="mv-portal-header-plain">{currentLanguageLabel}</span>
            )}

            {showCurrencySelect ? (
                <HeaderInlineDropdown
                    label={t('storefront.header.currency', 'Currency')}
                    value={currencyCode}
                    options={currencyOptions}
                    displayLabel={currentCurrencyLabel}
                    menuClassName="mv-portal-header-dropdown-menu--currency"
                    onChange={switchStorefrontCurrency}
                />
            ) : (
                <span className="mv-portal-header-plain">{currentCurrencyLabel}</span>
            )}

            {shopHref ? (
                <Link
                    to={shopHref}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mv-portal-header-icon-btn"
                    title={t('storefront.seller.shop', 'Shop')}
                    aria-label={t('storefront.seller.shop', 'Shop')}
                >
                    <Icon name="store" className="mv-portal-header-icon" />
                </Link>
            ) : (
                <button
                    type="button"
                    className="mv-portal-header-icon-btn is-disabled"
                    disabled
                    title={t('storefront.seller.shop_url_unavailable', 'Shop URL is not available yet')}
                    aria-label={t('storefront.seller.shop_url_unavailable', 'Shop URL is not available yet')}
                >
                    <Icon name="store" className="mv-portal-header-icon" />
                </button>
            )}

            <NavLink
                to="/account/seller/profile"
                className={`mv-portal-header-icon-btn${profileActive ? ' is-active' : ''}`}
                title={t('storefront.seller.profile', 'Profile')}
                aria-label={t('storefront.seller.profile', 'Profile')}
            >
                <Icon name="user" className="mv-portal-header-icon" />
            </NavLink>

            <a
                href="/account"
                target="_blank"
                rel="noopener noreferrer"
                data-customer-account-link="1"
                className="mv-portal-header-icon-btn"
                title={t('storefront.seller.my_account', 'My Account')}
                aria-label={t('storefront.seller.my_account', 'My Account')}
                onClick={(event) => {
                    event.preventDefault();
                    window.open(`${window.location.origin}/account`, '_blank', 'noopener,noreferrer');
                }}
            >
                <Icon name="user-circle" className="mv-portal-header-icon" />
            </a>
        </div>
    );
}
