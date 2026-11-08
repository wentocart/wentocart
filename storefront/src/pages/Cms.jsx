import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import Breadcrumbs from '../components/Breadcrumbs.jsx';
import { CmsPageSkeleton } from '../components/Feedback.jsx';
import { Icon } from '../components/Icons.jsx';
import { t } from '../i18n.js';
import { contactFormEnabled } from '../lib/commerce.js';
import OrderStatus from './OrderStatus.jsx';
import { applySeoHead } from '../lib/seoHead.js';
import { useStore } from '../state/StoreState.jsx';
import NotFound from './NotFound.jsx';

const HERO_ART_ICONS = {
    shipping: 'truck',
    returns: 'return',
    contact: 'mail',
    legal: 'shield',
    about: 'store',
};

/**
 * Section chip above the CMS page title. Keeps every CMS page (shipping,
 * returns, faq, contact, legal, about) in one consistent header language.
 */
const HERO_SECTIONS = {
    support: { icon: 'help', key: 'storefront.cms.section_support', label: 'Help & Support' },
    policies: { icon: 'shield', key: 'storefront.cms.section_policies', label: 'Policies' },
    company: { icon: 'store', key: 'storefront.cms.section_company', label: 'Company' },
};

function heroSection(heroType) {
    if (heroType === 'legal') {
        return HERO_SECTIONS.policies;
    }
    if (heroType === 'about') {
        return HERO_SECTIONS.company;
    }
    return HERO_SECTIONS.support;
}

function CmsHeroArt({ type }) {
    const label = type || 'help';
    return (
        <div className={`shop-cms-hero-art is-${label}`} aria-hidden="true">
            <span className="shop-cms-hero-orb" />
            <span className="shop-cms-hero-orb is-sm" />
            <div className="shop-cms-hero-icon">
                <Icon name={HERO_ART_ICONS[label] || 'help'} className="h-6 w-6" />
            </div>
        </div>
    );
}

export default function Cms({ slug, initial = null }) {
    const [page, setPage] = useState(initial?.page ?? null);

    useEffect(() => {
        if (initial?.page) {
            const row = initial.page;
            setPage(row);
            applySeoHead({
                title: row.seo?.title || row.meta_title || row.title,
                description: row.seo?.description || row.meta_description || row.short_description || '',
                canonical: row.seo?.canonical || row.canonical_url || (window.location.origin + window.location.pathname),
                robots: row.seo?.robots || row.robots || 'index,follow',
                og_type: row.seo?.og_type || 'article',
                og_image: row.seo?.og_image || null,
                clear_og_image: !row.seo?.og_image,
                json_ld: row.seo?.json_ld ?? null,
                hreflang: row.seo?.hreflang || [],
            });
            return undefined;
        }
        api.get(`/catalog/page/${slug}`).then((r) => {
            const row = r.data.page;
            setPage(row);
            if (row) {
                applySeoHead({
                    title: row.seo?.title || row.meta_title || row.title,
                    description: row.seo?.description || row.meta_description || row.short_description || '',
                    canonical: row.seo?.canonical || row.canonical_url || (window.location.origin + window.location.pathname),
                    robots: row.seo?.robots || row.robots || 'index,follow',
                    og_type: row.seo?.og_type || 'article',
                    og_image: row.seo?.og_image || null,
                    clear_og_image: !row.seo?.og_image,
                    json_ld: row.seo?.json_ld ?? null,
                    hreflang: row.seo?.hreflang || [],
                });
            }
        }).catch(() => setPage(false));
    }, [slug]);

    if (page === null) {
        return <CmsPageSkeleton />;
    }
    if (!page) {
        return <NotFound />;
    }

    const layout = page.layout || {};
    const highlights = layout.highlights || [];
    const sidebar = layout.sidebar || [];
    const trust = layout.trust || null;
    const panels = layout.panels || [];
    const title = layout.display_title || page.title;
    const hero = layout.hero || 'help';
    const section = heroSection(hero);

    return (
        <article className="shop-cms">
            <div className="shop-container shop-cms-inner">
                <Breadcrumbs items={[{ name: t('storefront.shell.home', 'Home'), url: '/' }, { name: page.title }]} />

                <header className={`shop-cms-hero is-${hero}`}>
                    <div className="shop-cms-hero-copy">
                        <p className="shop-cms-hero-chip">
                            <span className="shop-cms-hero-chip-ico">
                                <Icon name={section.icon} className="h-4 w-4" />
                            </span>
                            {t(section.key, section.label)}
                        </p>
                        <h1>{title}</h1>
                        {page.short_description ? (
                            <p className="shop-cms-hero-desc">{page.short_description}</p>
                        ) : null}
                    </div>
                    <CmsHeroArt type={hero} />
                </header>

                {highlights.length ? (
                    <section className="shop-cms-highlights" aria-label={t('storefront.cms.highlights', 'Highlights')}>
                        {highlights.map((item) => (
                            <div className="shop-cms-highlight" key={item.title}>
                                <span className="shop-cms-highlight-ico">
                                    <Icon name={item.icon || 'check'} className="h-5 w-5" />
                                </span>
                                <div>
                                    <h2>{item.title}</h2>
                                    <p>{item.body}</p>
                                </div>
                            </div>
                        ))}
                    </section>
                ) : null}

                <div className="shop-cms-grid">
                    <div className="shop-cms-main">
                        <div
                            className="shop-prose shop-cms-body"
                            dangerouslySetInnerHTML={{ __html: page.body || '' }}
                        />
                        {slug === 'contact' ? <ContactForm /> : null}
                        {slug === 'order-tracking' ? <OrderStatus embedded /> : null}

                        {panels.length ? (
                            <div className="shop-cms-panels">
                                {panels.map((panel) => (
                                    <section className="shop-cms-panel" key={panel.title}>
                                        <h2>{panel.title}</h2>
                                        <p>{panel.body}</p>
                                        {panel.action ? (
                                            <Link className="shop-btn shop-btn-primary" to={panel.action.url}>
                                                {panel.action.label}
                                            </Link>
                                        ) : null}
                                        {panel.links?.length ? (
                                            <ul className="shop-cms-panel-links">
                                                {panel.links.map((link) => (
                                                    <li key={link.url}>
                                                        <Link to={link.url}>
                                                            <span>{link.label}</span>
                                                            <Icon name="chevron" className="h-4 w-4" />
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : null}
                                    </section>
                                ))}
                            </div>
                        ) : null}
                    </div>

                    <aside className="shop-cms-side">
                        {sidebar.length ? (
                            <section className="shop-cms-card">
                                <h2>{t('storefront.cms.quick_links', 'Quick Links')}</h2>
                                <nav className="shop-cms-links">
                                    {sidebar.map((link) => (
                                        <Link key={link.url} to={link.url}>
                                            <span>{link.label}</span>
                                            <Icon name="chevron" className="h-4 w-4" />
                                        </Link>
                                    ))}
                                </nav>
                            </section>
                        ) : null}

                        {trust ? (
                            <section className="shop-cms-card shop-cms-trust">
                                <div className="shop-cms-trust-ico">
                                    <Icon name="shield" className="h-7 w-7" />
                                </div>
                                <h2>{trust.title}</h2>
                                <p>{trust.body}</p>
                            </section>
                        ) : null}
                    </aside>
                </div>
            </div>
        </article>
    );
}

function ContactForm() {
    const store = useStore();
    const [form, setForm] = useState({ name: '', email: '', order_number: '', message: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');

    if (!contactFormEnabled(store.shop)) {
        return null;
    }

    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));

    return (
        <form
            className="shop-contact-form"
            onSubmit={async (e) => {
                e.preventDefault();
                setBusy(true);
                setError('');
                setOk('');
                try {
                    await csrf();
                    await api.post('/contact', form);
                    setOk(t('storefront.contact.sent', 'Message sent. We will reply by email.'));
                    setForm({ name: '', email: '', order_number: '', message: '' });
                } catch (err) {
                    setError(err.response?.data?.message || t('storefront.contact.failed', 'Could not send your message.'));
                } finally {
                    setBusy(false);
                }
            }}
        >
            <h2>{t('storefront.contact.title', 'Send us a message')}</h2>
            {error ? <p className="shop-contact-note is-error">{error}</p> : null}
            {ok ? <p className="shop-contact-note is-ok">{ok}</p> : null}
            <label className="shop-field">
                <span className="shop-field-label">{t('storefront.contact.name', 'Name')}</span>
                <input className="shop-input" required value={form.name} onChange={(e) => set('name', e.target.value)} />
            </label>
            <label className="shop-field">
                <span className="shop-field-label">{t('storefront.contact.email', 'Email')}</span>
                <input className="shop-input" type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} />
            </label>
            <label className="shop-field">
                <span className="shop-field-label">{t('storefront.contact.order', 'Order number (optional)')}</span>
                <input className="shop-input" value={form.order_number} onChange={(e) => set('order_number', e.target.value)} />
            </label>
            <label className="shop-field">
                <span className="shop-field-label">{t('storefront.contact.message', 'Message')}</span>
                <textarea className="shop-input" rows="5" required value={form.message} onChange={(e) => set('message', e.target.value)} />
            </label>
            <button type="submit" className="shop-btn shop-btn-primary" disabled={busy}>
                {busy ? t('storefront.common.please_wait', 'Please wait…') : t('storefront.contact.send', 'Send message')}
            </button>
        </form>
    );
}
