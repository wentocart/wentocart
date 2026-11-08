import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Badge,
    Button,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    StatCards,
    ui,
    t,
} from '../../ui';

const OPTION_ICONS = {
    currency: 'tag',
    language: 'globe',
    regions: 'globe',
    tax: 'file',
    units: 'package',
};

export default function Packs({ catalog = [] }) {
    const [filter, setFilter] = useState('');
    const [listTab, setListTab] = useState('all'); // all | full | installed

    const form = useForm({
        country_code: catalog.find((c) => c.country_code === 'IN')?.country_code
            || catalog.find((c) => c.has_json_pack)?.country_code
            || catalog[0]?.country_code
            || '',
        options: {
            currency: true,
            language: true,
            regions: true,
            tax: true,
            units: true,
        },
        select_all: true,
    });

    const fullPacks = useMemo(() => catalog.filter((c) => c.has_json_pack), [catalog]);
    const installedCount = useMemo(() => catalog.filter((c) => c.is_installed).length, [catalog]);

    const filtered = useMemo(() => {
        let rows = catalog;
        if (listTab === 'full') rows = rows.filter((c) => c.has_json_pack);
        if (listTab === 'installed') rows = rows.filter((c) => c.is_installed);

        const q = filter.trim().toLowerCase();
        if (!q) return rows;
        return rows.filter((c) => {
            const hay = `${c.name} ${c.country_code} ${c.currency_code || ''}`.toLowerCase();
            return hay.includes(q);
        });
    }, [catalog, filter, listTab]);

    const selected = useMemo(
        () => catalog.find((c) => c.country_code === form.data.country_code) || null,
        [catalog, form.data.country_code],
    );

    const components = (selected?.components || []).filter((c) => c.key !== 'country');
    const availableCount = components.filter((c) => c.available).length;
    const selectedCount = components.filter((c) => c.available && form.data.options[c.key]).length;

    const setCountry = (code) => {
        const next = catalog.find((c) => c.country_code === code);
        const opts = { currency: true, language: true, regions: true, tax: true, units: true };
        (next?.components || []).forEach((c) => {
            if (c.key === 'country') return;
            opts[c.key] = !!c.available;
        });
        form.setData({
            country_code: code,
            options: opts,
            select_all: true,
        });
    };

    const toggleAll = (checked) => {
        const opts = { ...form.data.options };
        components.forEach((c) => {
            opts[c.key] = c.available ? checked : false;
        });
        form.setData({ ...form.data, select_all: checked, options: opts });
    };

    const toggleOption = (key, available) => {
        if (!available) return;
        const checked = !form.data.options[key];
        const opts = { ...form.data.options, [key]: checked };
        const availableKeys = components.filter((c) => c.available).map((c) => c.key);
        const allOn = availableKeys.every((k) => opts[k]);
        form.setData({ ...form.data, options: opts, select_all: allOn });
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            country_code: data.country_code,
            options: data.options,
        }));
        form.post('/admin/localization/packs', { preserveScroll: true });
    };

    const stats = [
        {
            key: 'countries',
            label: t('admin.packs.stat_countries', 'Countries'),
            value: catalog.length,
            hint: t('admin.packs.stat_countries_hint', 'Worldwide catalog'),
            icon: 'globe',
            tone: 'primary',
        },
        {
            key: 'full',
            label: t('admin.packs.stat_full', 'Full packs'),
            value: fullPacks.length,
            hint: t('admin.packs.stat_full_hint', 'States + starter tax'),
            icon: 'sparkles',
            tone: 'success',
        },
        {
            key: 'installed',
            label: t('admin.packs.stat_installed', 'Installed'),
            value: installedCount,
            hint: t('admin.packs.stat_installed_hint', 'Already applied'),
            icon: 'package',
            tone: 'neutral',
        },
    ];

    return (
        <Layout>
            <PageHeader
                title={t('admin.packs.localization_packs', 'Localization packs')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.packs.localization', 'Localization') },
                    { label: t('admin.packs.packs', 'Packs') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" href="/admin/tax">{t('admin.nav.taxes', 'Taxes')}</Button>
                        <Button variant="secondary" href="/admin/localization">{t('admin.packs.overview', 'Overview')}</Button>
                    </div>
                )}
            />
            <p className={`${ui.muted} -mt-3 mb-5`}>
                {t(
                    'admin.packs.choose_any_country_worldwide_then_pick_what_to_i',
                    'Pick a country, choose what to install, then apply. Full packs (India, US, Canada, Australia, UAE) include states and starter tax. Other countries still get profile, currency, language, and units.',
                )}
            </p>

            <StatCards items={stats} />

            <form onSubmit={submit} className={`${ui.packLayout} mb-5`}>
                <aside className={ui.packSide}>
                    <div className={ui.packSideHead}>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className={ui.sectionTitle}>{t('admin.packs.countries', 'Countries')}</h2>
                            <span className={ui.cellSub}>{filtered.length}</span>
                        </div>
                        <Input
                            wide
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder={t('admin.packs.type_name_or_iso_code_e_g_brazil_br', 'Type name or ISO code (e.g. Brazil, BR)…')}
                            aria-label={t('admin.packs.search_countries', 'Search countries')}
                        />
                        <div className="flex flex-wrap gap-1.5">
                            {[
                                { key: 'all', label: t('admin.packs.filter_all', 'All') },
                                { key: 'full', label: t('admin.packs.filter_full', 'Full packs') },
                                { key: 'installed', label: t('admin.packs.filter_installed', 'Installed') },
                            ].map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    className={listTab === tab.key ? ui.i18nPageBtnActive : ui.i18nPageBtn}
                                    onClick={() => setListTab(tab.key)}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {filtered.length === 0 ? (
                        <p className={`${ui.muted} px-4 py-8`}>
                            {t('admin.packs.no_countries_match', 'No countries match your search.')}
                        </p>
                    ) : (
                        <div className={ui.packSideList}>
                            {filtered.map((c) => {
                                const active = c.country_code === form.data.country_code;
                                return (
                                    <button
                                        key={c.country_code}
                                        type="button"
                                        className={active ? ui.packCountryBtnActive : ui.packCountryBtn}
                                        onClick={() => setCountry(c.country_code)}
                                    >
                                        <span className={active ? ui.packCountryCodeActive : ui.packCountryCode}>
                                            {c.country_code}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] font-medium text-neutral-900">
                                                {c.name}
                                            </span>
                                            <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                {c.has_json_pack ? (
                                                    <Badge tone="success" dot={false}>
                                                        {t('admin.packs.full_pack_badge', 'Full')}
                                                    </Badge>
                                                ) : (
                                                    <span className={ui.cellSub}>
                                                        {t('admin.packs.basic_badge', 'Basic')}
                                                    </span>
                                                )}
                                                {c.is_installed ? (
                                                    <Badge tone="primary" dot={false}>
                                                        {t('admin.packs.installed_badge', 'Installed')}
                                                    </Badge>
                                                ) : null}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </aside>

                <section className={ui.packMain}>
                    {!selected ? (
                        <div className={`${ui.empty} px-5 py-16`}>
                            {t('admin.packs.select_a_country', 'Select a country…')}
                        </div>
                    ) : (
                        <>
                            <div className={ui.packMainHead}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className={ui.sectionKicker}>
                                            {selected.has_json_pack
                                                ? t('admin.packs.full_json_pack_kicker', 'Full pack')
                                                : t('admin.packs.worldwide_profile_kicker', 'Worldwide profile')}
                                        </p>
                                        <h2 className={`${ui.sectionTitle} text-xl`}>
                                            {selected.name}
                                            <span className="ml-2 text-base font-normal text-neutral-400">
                                                {selected.country_code}
                                            </span>
                                        </h2>
                                        <p className={ui.sectionLead}>
                                            {selected.currency_code
                                                ? `${t('admin.packs.currency_label', 'Currency')}: ${selected.currency_code}`
                                                : t('admin.packs.no_currency_mapped', 'No currency mapped')}
                                            {selected.regions_count
                                                ? ` · ${selected.regions_count} ${t('admin.packs.states_regions', 'states/regions')}`
                                                : ` · ${t('admin.packs.no_states_in_pack', 'No states list in pack')}`}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {selected.is_installed ? (
                                            <Badge tone="success" dot>
                                                {t('admin.packs.previously_installed', 'Previously installed')}
                                            </Badge>
                                        ) : (
                                            <Badge tone="neutral" dot={false}>
                                                {t('admin.packs.not_installed', 'Not installed yet')}
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <FormErrors errors={form.errors} />
                            </div>

                            <div className={ui.packMainBody}>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className={ui.sectionTitle}>
                                            {t('admin.packs.what_to_install', 'What to install')}
                                        </h3>
                                        <p className={ui.muted}>
                                            {t('admin.packs.country_profile_always', 'Country profile is always applied.')}
                                            {' '}
                                            {selectedCount}/{availableCount}{' '}
                                            {t('admin.packs.options_selected', 'options selected')}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => toggleAll(!form.data.select_all)}
                                    >
                                        {form.data.select_all
                                            ? t('admin.packs.clear_options', 'Clear options')
                                            : t('admin.packs.install_all_available_options', 'Install all available options')}
                                    </Button>
                                </div>

                                <div className={ui.packOptionGrid}>
                                    {components.map((c) => {
                                        const on = !!c.available && !!form.data.options[c.key];
                                        const cls = !c.available
                                            ? ui.packOptionOff
                                            : (on ? ui.packOptionOn : ui.packOption);
                                        return (
                                            <button
                                                key={c.key}
                                                type="button"
                                                className={cls}
                                                disabled={!c.available}
                                                onClick={() => toggleOption(c.key, c.available)}
                                                aria-pressed={on}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white border border-neutral-200 text-violet-700">
                                                        <Icon name={OPTION_ICONS[c.key] || 'package'} className="h-4 w-4" />
                                                    </span>
                                                    {c.available ? (
                                                        on ? (
                                                            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-violet-800">
                                                                <Icon name="check" className="h-3.5 w-3.5" />
                                                                {t('admin.packs.selected', 'Selected')}
                                                            </span>
                                                        ) : (
                                                            <span className="text-[11px] font-medium text-neutral-400">
                                                                {t('admin.packs.tap_to_include', 'Tap to include')}
                                                            </span>
                                                        )
                                                    ) : (
                                                        <span className="text-[11px] font-medium text-neutral-400">
                                                            {t('admin.packs.unavailable', 'Unavailable')}
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="mt-3 text-sm font-semibold text-neutral-900">{c.label}</p>
                                                <p className="mt-1 text-xs text-neutral-500 leading-relaxed">
                                                    {c.available
                                                        ? c.hint
                                                        : `${c.hint} — ${t('admin.packs.unavailable_for_country', 'unavailable for this country')}`}
                                                </p>
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className={`${ui.toolbar} pt-1`}>
                                    <Button type="submit" icon="download" busy={form.processing}>
                                        {selected.is_installed
                                            ? t('admin.packs.re_install_selected', 'Re-install selected')
                                            : t('admin.packs.install_selected', 'Install selected')}
                                    </Button>
                                    {selected.is_installed ? (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            href={`/admin/localization/countries?q=${encodeURIComponent(selected.country_code)}`}
                                        >
                                            {t('admin.packs.view_country', 'View country')}
                                        </Button>
                                    ) : null}
                                    {selected.has_json_pack ? (
                                        <Button type="button" variant="secondary" href="/admin/tax">
                                            {t('admin.packs.open_taxes', 'Open taxes')}
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        </>
                    )}
                </section>
            </form>

            <section className={ui.sectionCard}>
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{t('admin.packs.pack_coverage', 'Pack coverage')}</h2>
                        <p className={ui.muted}>
                            {t(
                                'admin.packs.full_json_packs_include_states_tax_all_other_cou',
                                'Full packs include states and starter tax. Click one to load it in the installer.',
                            )}
                        </p>
                    </div>
                </div>
                <div className={ui.packCoverageGrid}>
                    {fullPacks.map((c) => {
                        const active = c.country_code === form.data.country_code;
                        return (
                            <button
                                key={c.country_code}
                                type="button"
                                className={active ? ui.packCoverageCardActive : ui.packCoverageCard}
                                onClick={() => {
                                    setListTab('full');
                                    setCountry(c.country_code);
                                }}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className={ui.packCountryCode}>{c.country_code}</span>
                                        <span className={ui.cellStrong}>{c.name}</span>
                                    </div>
                                    {c.is_installed ? <Badge tone="success" dot={false}>{t('admin.packs.installed_badge', 'Installed')}</Badge> : null}
                                </div>
                                <p className={`${ui.cellSub} mt-2`}>
                                    {c.regions_count
                                        ? `${c.regions_count} ${t('admin.packs.states_regions', 'states/regions')}`
                                        : t('admin.packs.regions_included', 'Regions included')}
                                    {c.regions_installed ? ` · ${c.regions_installed} ${t('admin.packs.in_database', 'in database')}` : ''}
                                </p>
                            </button>
                        );
                    })}
                    <div className="rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 p-4">
                        <p className={ui.cellStrong}>
                            {t('admin.packs.everyone_else', 'Everyone else')}
                        </p>
                        <p className={`${ui.cellSub} mt-1`}>
                            {catalog.length - fullPacks.length}{' '}
                            {t('admin.packs.other_countries_basic', 'countries: profile + currency + language + units (tax when we add a pack).')}
                        </p>
                    </div>
                </div>
            </section>
        </Layout>
    );
}
