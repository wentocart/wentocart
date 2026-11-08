import { Link, router, usePage } from '@inertiajs/react';
import { Icon, Select, ui, t, setI18nMessages } from '../../ui';

export default function AuthShell({ children }) {
    const { i18n, shop, app } = usePage().props;
    setI18nMessages(i18n?.messages);
    const languages = i18n?.languages || [];
    const brand = app?.name || shop?.name || t('admin.login.brand', 'Admin');
    const year = new Date().getFullYear();
    const home = shop?.url || '/';

    return (
        <div className={ui.loginPage}>
            <div className={ui.loginDecor} aria-hidden>
                <div className={ui.loginBlobTr} />
                <div className={ui.loginBlobBl} />
                <div className={ui.loginDotsTl} />
                <div className={ui.loginDotsBr} />
            </div>
            <div className={ui.loginMain}>
                <div className={ui.loginBrand}>
                    <div className={ui.loginMark}>
                        <Icon name="bag" className={ui.iconXl} />
                    </div>
                    <div className={ui.loginBrandName}>{brand}</div>
                    <div className={ui.loginBrandSub}>{t('admin.login.panel', 'Admin Panel')}</div>
                </div>
                {languages.length > 1 ? (
                    <div className={ui.loginLang}>
                        <Select
                            wide
                            value={i18n?.locale || ''}
                            onChange={(e) => router.post('/admin/locale', { locale: e.target.value })}
                            aria-label={t('admin.shell.current', 'Current')}
                        >
                            {languages.map((language) => (
                                <option key={language.code} value={language.code}>{language.name}</option>
                            ))}
                        </Select>
                    </div>
                ) : null}
                {children}
            </div>
            <footer className={ui.loginFooter}>
                <p>
                    © {year} {brand}. {t('admin.login.rights', 'All rights reserved.')}
                </p>
                <nav className={ui.loginFooterLinks}>
                    <a className={ui.loginFooterLink} href={`${home}privacy`}>
                        {t('admin.login.privacy', 'Privacy Policy')}
                    </a>
                    <span aria-hidden>|</span>
                    <a className={ui.loginFooterLink} href={`${home}terms`}>
                        {t('admin.login.terms', 'Terms of Service')}
                    </a>
                    <span aria-hidden>|</span>
                    <a className={ui.loginFooterLink} href={`${home}help`}>
                        {t('admin.login.help', 'Help')}
                    </a>
                </nav>
            </footer>
        </div>
    );
}

export function AuthField({ icon, type = 'text', trailing, invalid, ...props }) {
    return (
        <div className={ui.loginField}>
            {icon ? (
                <span className={ui.loginFieldIcon}>
                    <Icon name={icon} />
                </span>
            ) : null}
            <input
                type={type}
                className={trailing ? ui.loginFieldInputEnd : ui.loginFieldInput}
                aria-invalid={invalid || undefined}
                {...props}
            />
            {trailing}
        </div>
    );
}

export function AuthLink({ href, className, children }) {
    return (
        <Link href={href} className={className}>
            {children}
        </Link>
    );
}
