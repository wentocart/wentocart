import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    Icon,
    PageHeader,
    KpiCards,
    Select,
    ui,
    t,
} from '../../ui';

export default function Index({ counts = {}, packs = [], defaults = {}, default_options = {} }) {
    const defaultForm = useForm({
        country_code: defaults.country_code || '',
        language_code: defaults.language_code || '',
        currency_code: defaults.currency_code || '',
    });
    const defaultInline = ['country_code', 'language_code', 'currency_code'];
    const saveDefaults = (event) => {
        event.preventDefault();
        defaultForm.clearErrors();
        const errors = {};
        if (!defaultForm.data.language_code) {
            errors.language_code = t('admin.localization.language_required', 'Choose a default language.');
        }
        if (!defaultForm.data.country_code) {
            errors.country_code = t('admin.localization.country_required', 'Choose a default country.');
        }
        if (!defaultForm.data.currency_code) {
            errors.currency_code = t('admin.localization.currency_required', 'Choose a default currency.');
        }
        if (Object.keys(errors).length) {
            defaultForm.setError(errors);
            return;
        }
        defaultForm.post('/admin/localization/defaults', { preserveScroll: true });
    };
    const kpis = [
        { key: 'countries', label: t('admin.localization.countries_enabled', 'Countries enabled'), value: (counts.countries ?? 0).toLocaleString(), trend: 0 },
        { key: 'currencies', label: t('admin.localization.currencies_enabled', 'Currencies enabled'), value: (counts.currencies ?? 0).toLocaleString(), trend: 0 },
        { key: 'languages', label: t('admin.localization.languages_enabled', 'Languages enabled'), value: (counts.languages ?? 0).toLocaleString(), trend: 0 },
        { key: 'tax_rules', label: t('admin.localization.tax_rules', 'Tax rules'), value: (counts.tax_rules ?? 0).toLocaleString(), trend: 0 },
    ];

    const links = [
        { href: '/admin/localization/languages', label: t('admin.localization.languages', 'Languages'), hint: t('admin.localization.enable_locales_the_shop_and_admin_can_switch_to', 'Enable locales the shop and admin can switch to') },
        { href: '/admin/localization/translations', label: t('admin.localization.translations', 'Translations'), hint: t('admin.localization.translate_admin_and_storefront_ui_page_by_page', 'Translate admin and storefront UI page by page') },
        { href: '/admin/localization/countries', label: t('admin.localization.countries', 'Countries'), hint: t('admin.localization.iso_codes_regions_address_labels', 'ISO codes, regions, address labels') },
        { href: '/admin/localization/zones', label: t('admin.nav.zones', 'Zones'), hint: t('admin.zones.module_hint', 'Geographic groups. Each country belongs to one zone.') },
        { href: '/admin/localization/currencies', label: t('admin.localization.currencies', 'Currencies'), hint: t('admin.localization.symbols_decimals_fx_rates', 'Symbols, decimals, FX rates') },
        { href: '/admin/tax', label: t('admin.localization.taxes', 'Taxes'), hint: t('admin.localization.classes_zones_rates_rules', 'Classes, zones, rates, rules') },
        { href: '/admin/localization/packs', label: t('admin.localization.install_pack', 'Install pack'), hint: t('admin.localization.pick_a_country_what_to_install', 'Pick a country + what to install') },
        { href: '/admin/localization/import', label: t('admin.localization.import_export', 'Import / Export'), hint: t('admin.localization.staged_csv_for_countries_currencies', 'Staged CSV for countries & currencies') },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.localization.localization', 'Localization')}
                crumbs={[{ href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') }, { label: t('admin.localization.localization', 'Localization') }]}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t('admin.localization.configure_countries_currencies_taxes_and_country', 'Configure countries, currencies, taxes, and country packs for your store.')}
            </p>

            <KpiCards items={kpis} />

            <section className={`${ui.sectionCard} mb-5`}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{t('admin.localization.modules', 'Modules')}</h2>
                        <p className={ui.muted}>{t('admin.localization.open_a_localization_area', 'Open a localization area.')}</p>
                    </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {links.map((item) => (
                        <a key={item.href} href={item.href} className="rounded-xl border border-neutral-200 p-4 hover:border-sky-300 transition-colors">
                            <div className={ui.cellStrong}>{item.label}</div>
                            <div className={ui.cellSub}>{item.hint}</div>
                        </a>
                    ))}
                </div>
            </section>

            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{t('admin.localization.recently_installed_packs', 'Recently installed packs')}</h2>
                        <p className={ui.muted}>{t('admin.localization.install_or_update_any_country_from_the_packs_scr', 'Install or update any country from the packs screen.')}</p>
                    </div>
                    <Button href="/admin/localization/packs" icon="download">{t('admin.localization.install_pack', 'Install pack')}</Button>
                </div>

                {packs.length === 0 ? (
                    <p className={ui.muted}>{t('admin.localization.no_packs_installed_yet_open_install_pack_to_choo', 'No packs installed yet. Open Install pack to choose a country.')}</p>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {packs.map((pack) => (
                            <div key={pack.country_code} className="rounded-xl border border-neutral-200 p-4">
                                <div className={ui.row}>
                                    <span className={ui.cellIcon}><Icon name="globe" /></span>
                                    <div className={ui.cellStack}>
                                        <span className={ui.cellStrong}>{pack.country_name || pack.name}</span>
                                        <span className={ui.cellSub}>
                                            {pack.currency_code ? `Currency: ${pack.currency_code}` : 'Currency: —'}
                                            {pack.regions_installed ? ` · States: ${pack.regions_installed}` : ''}
                                        </span>
                                    </div>
                                    <Badge tone="success" dot>{t('admin.localization.installed', 'Installed')}</Badge>
                                </div>
                                <div className={`${ui.toolbar} mt-3`}>
                                    <Button variant="secondary" href={`/admin/localization/countries?q=${encodeURIComponent(pack.country_code)}`}>
                                        {t('admin.localization.configure', 'Configure')}
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <section className={`${ui.sectionCard} mt-5`}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{t('admin.localization.store_defaults', 'Store defaults')}</h2>
                        <p className={ui.muted}>
                            {t(
                                'admin.localization.store_defaults_hint',
                                'These are the language, country, and currency a new shopper sees until they choose another.',
                            )}
                        </p>
                    </div>
                </div>
                <form onSubmit={saveDefaults} className="grid gap-4">
                    <FormErrors errors={defaultForm.errors} except={defaultInline} />
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label={t('admin.localization.default_language', 'Default language')} error={defaultForm.errors.language_code} required>
                            <Select
                                wide
                                invalid={!!defaultForm.errors.language_code}
                                value={defaultForm.data.language_code}
                                onChange={(e) => defaultForm.setData('language_code', e.target.value)}
                            >
                                <option value="">{t('admin.common.choose', 'Choose')}</option>
                                {(default_options.languages || []).map((row) => (
                                    <option key={row.code} value={row.code}>{row.name} ({row.code})</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('admin.localization.default_country', 'Default country')} error={defaultForm.errors.country_code} required>
                            <Select
                                wide
                                invalid={!!defaultForm.errors.country_code}
                                value={defaultForm.data.country_code}
                                onChange={(e) => defaultForm.setData('country_code', e.target.value)}
                            >
                                <option value="">{t('admin.common.choose', 'Choose')}</option>
                                {(default_options.countries || []).map((row) => (
                                    <option key={row.code} value={row.code}>{row.name} ({row.code})</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('admin.localization.default_currency', 'Default currency')} error={defaultForm.errors.currency_code} required>
                            <Select
                                wide
                                invalid={!!defaultForm.errors.currency_code}
                                value={defaultForm.data.currency_code}
                                onChange={(e) => defaultForm.setData('currency_code', e.target.value)}
                            >
                                <option value="">{t('admin.common.choose', 'Choose')}</option>
                                {(default_options.currencies || []).map((row) => (
                                    <option key={row.code} value={row.code}>{row.code} — {row.name} ({row.symbol})</option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <div className={ui.toolbar}>
                        <Button type="submit" icon="check" busy={defaultForm.processing}>
                            {t('admin.localization.save_defaults', 'Save defaults')}
                        </Button>
                    </div>
                </form>
            </section>
        </Layout>
    );
}
